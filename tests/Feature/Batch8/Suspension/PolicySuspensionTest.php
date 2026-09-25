<?php

declare(strict_types=1);

use App\Application\Cases\Models\WorkCase;
use App\Application\Events\Catalogue\DomainEventCatalogue;
use App\Application\Payments\WebhookProcessingService;
use App\Application\Policies\PolicyIssuanceService;
use App\Application\Policies\PolicyServicingService;
use App\Application\Policies\Suspension\PolicySuspensionService;
use App\Models\Policy;
use App\Models\PolicyIssuanceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';

function b8sUser(string $name): User
{
    return User::create(['full_name' => $name, 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
}

function b8sPolicy(): Policy
{
    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $f['proposal']->update(['status' => 'PAYMENT_PENDING', 'terms_snapshot' => [
        'offer_id' => null, 'quote_id' => $f['quote']->id, 'premium_minor' => 90000, 'tax_minor' => 5000, 'fee_minor' => 5000, 'total_minor' => 100000, 'currency' => 'XAF',
        'coverage_snapshot' => ['coverages' => [
            ['code' => 'RC', 'name' => ['en' => 'Liability'], 'mandatory' => true, 'optional' => false, 'limit_minor' => 50_000_000, 'deductible_minor' => null],
        ]],
    ]]);
    $payment = makeMobileTestPayment($f['proposal'], $f['tenant'], ['status' => 'PENDING_CUSTOMER', 'requested_by' => $f['user']->id]);
    app(WebhookProcessingService::class)->process('fake', 'evt-'.Str::uuid(), [
        'payment_reference' => $payment->provider_reference, 'amount_minor' => $payment->amount_minor,
        'currency' => $payment->currency, 'status' => 'SUCCEEDED',
    ], 'sig');
    $request = PolicyIssuanceRequest::where('proposal_id', $f['proposal']->id)->firstOrFail();

    return app(PolicyIssuanceService::class)->approve($request, ['carrier_reference' => 'CR-8S'], b8sUser('Carrier Desk'));
}

beforeEach(function () {
    Storage::fake('local');
    Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 'ticket']]])]);
});

it('REQ-POL-006 WF-046 suspends an active policy with a SUSPENSION chronology version, history, outbox and is idempotent', function () {
    $policy = b8sPolicy();
    expect($policy->status)->toBe('ACTIVE');
    $maker = b8sUser('Ops Maker');

    $s = app(PolicyServicingService::class)->suspend($policy, 'CUSTOMER_REQUEST', $maker, ['notes' => 'Vehicle off road']);
    expect($s->status)->toBe('SUSPENDED')->and($s->source)->toBe('MANUAL')
        ->and($policy->refresh()->status)->toBe('SUSPENDED');

    $v = DB::table('policy_versions')->where('id', $s->suspension_version_id)->first();
    expect($v->kind)->toBe('SUSPENSION')->and($v->version_no)->toBe($policy->version)
        ->and(DB::table('policy_versions')->where('policy_id', $policy->id)->whereNull('superseded_at')->count())->toBe(1);
    expect(DB::table('policy_status_history')->where(['policy_id' => $policy->id, 'to_status' => 'SUSPENDED'])->exists())->toBeTrue()
        ->and(DB::table('outbox_messages')->where('event_name', 'policy.suspended')->where('aggregate_id', $policy->id)->exists())->toBeTrue();

    $again = app(PolicySuspensionService::class)->suspend($policy, 'CUSTOMER_REQUEST', $maker);
    expect($again->id)->toBe($s->id)->and(DB::table('policy_suspensions')->where('policy_id', $policy->id)->count())->toBe(1);
});

it('REQ-POL-006 exposes a system suspension (premium default source) with no actor, and refuses non-suspendable states', function () {
    $policy = b8sPolicy();
    $s = app(PolicySuspensionService::class)->suspend($policy, 'PREMIUM_UNPAID', null, ['source' => 'PREMIUM_DEFAULT']);
    expect($s->source)->toBe('PREMIUM_DEFAULT')->and($s->suspended_by)->toBeNull();

    $policy->refresh()->update(['status' => 'CANCELLED']);
    DB::table('policy_suspensions')->where('id', $s->id)->update(['status' => 'ENDED']);
    expect(fn () => app(PolicySuspensionService::class)->suspend($policy, 'X', null))->toThrow(ValidationException::class);
    expect(fn () => app(PolicySuspensionService::class)->suspend(b8sPolicy(), 'X', null, ['source' => 'NOPE']))->toThrow(ValidationException::class);
});

it('REQ-POL-006 WF-047/BRK-063 queues reinstatement as a case and enforces maker-checker', function () {
    $policy = b8sPolicy();
    $maker = b8sUser('Maker');
    $checker = b8sUser('Checker');
    $svc = app(PolicySuspensionService::class);
    $svc->suspend($policy, 'CUSTOMER_REQUEST', $maker);

    expect(fn () => $svc->reinstate($policy, 'RESUMED', $checker))->toThrow(ValidationException::class); // must be requested first

    $s = $svc->requestReinstatement($policy, 'VEHICLE_BACK_ON_ROAD', $maker);
    expect($s->status)->toBe('REINSTATEMENT_REQUESTED')
        ->and($svc->queue($policy->tenant_id)->pluck('id')->all())->toBe([$s->id]);
    $case = WorkCase::withoutGlobalScopes()->findOrFail($s->reinstatement_case_id);
    expect($case->case_type_code)->toBe('POLICY_REINSTATEMENT')->and($case->subject_id)->toBe($policy->id);
    expect(fn () => $svc->requestReinstatement($policy, 'AGAIN', $maker))->toThrow(ValidationException::class);

    expect(fn () => $svc->reinstate($policy, 'RESUMED', $maker))->toThrow(ValidationException::class);

    $reinstated = app(PolicyServicingService::class)->reinstate($policy, 'RESUMED', $checker);
    expect($reinstated->status)->toBe('ACTIVE');
    $s->refresh();
    expect($s->status)->toBe('REINSTATED')->and($s->reinstated_by)->toBe($checker->id)
        ->and(DB::table('policy_versions')->where('id', $s->reinstatement_version_id)->value('kind'))->toBe('REINSTATEMENT')
        ->and($case->refresh()->closed_at)->not->toBeNull()->and($case->outcome)->toBe('REINSTATED')
        ->and($svc->queue($policy->tenant_id))->toHaveCount(0)
        ->and(DB::table('outbox_messages')->where('event_name', 'policy.reinstated')->where('aggregate_id', $policy->id)->exists())->toBeTrue();
});

it('REQ-POL-006 rejects a reinstatement request back to SUSPENDED and allows a system reinstatement', function () {
    $policy = b8sPolicy();
    $maker = b8sUser('Maker');
    $checker = b8sUser('Checker');
    $svc = app(PolicySuspensionService::class);
    $svc->suspend($policy, 'PREMIUM_UNPAID', null, ['source' => 'PREMIUM_DEFAULT']);
    $s = $svc->requestReinstatement($policy, 'PAID', $maker);
    $caseId = $s->reinstatement_case_id;

    expect(fn () => $svc->rejectReinstatement($policy, 'nope', $maker))->toThrow(ValidationException::class);
    $s = $svc->rejectReinstatement($policy, 'Payment not reconciled', $checker);
    expect($s->status)->toBe('SUSPENDED')->and($policy->refresh()->status)->toBe('SUSPENDED')
        ->and(WorkCase::withoutGlobalScopes()->find($caseId)->status)->toBe('CANCELLED');

    $svc->reinstate($policy, 'PREMIUM_RECEIVED', null);
    expect($policy->refresh()->status)->toBe('ACTIVE')->and($s->refresh()->status)->toBe('REINSTATED');
});

it('REQ-POL-006 registers the suspension events in the domain event catalogue', function () {
    foreach (['policy.suspended', 'policy.reinstatement.requested', 'policy.reinstatement.rejected', 'policy.reinstated'] as $name) {
        expect(DomainEventCatalogue::get($name)->emitted)->toBeTrue();
    }
});
