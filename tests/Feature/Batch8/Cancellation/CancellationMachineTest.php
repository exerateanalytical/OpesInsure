<?php

declare(strict_types=1);

use App\Application\Events\Catalogue\DomainEventCatalogue;
use App\Application\Payments\WebhookProcessingService;
use App\Application\Policies\Cancellation\CancellationService;
use App\Application\Policies\PolicyIssuanceService;
use App\Models\Document;
use App\Models\PolicyIssuanceRequest;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';

function b8cUser(string $name): User
{
    return User::create(['full_name' => $name, 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
}

function b8cPolicy(string $basis = 'PRO_RATA', int $shortRateBp = 10000, int $fee = 0): array
{
    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $f['proposal']->update(['status' => 'PAYMENT_PENDING', 'terms_snapshot' => [
        'offer_id' => null, 'quote_id' => $f['quote']->id, 'premium_minor' => 90000, 'tax_minor' => 5000, 'fee_minor' => 5000, 'total_minor' => 100000, 'currency' => 'XAF',
        'coverage_snapshot' => ['coverages' => [['code' => 'RC', 'name' => ['en' => 'Liability'], 'mandatory' => true, 'optional' => false, 'limit_minor' => 50_000_000, 'deductible_minor' => null]]],
    ]]);
    $f['payment'] = makeMobileTestPayment($f['proposal'], $f['tenant'], ['status' => 'PENDING_CUSTOMER', 'requested_by' => $f['user']->id]);
    app(WebhookProcessingService::class)->process('fake', 'evt-'.Str::uuid(), [
        'payment_reference' => $f['payment']->provider_reference, 'amount_minor' => $f['payment']->amount_minor,
        'currency' => $f['payment']->currency, 'status' => 'SUCCEEDED',
    ], 'sig');
    $request = PolicyIssuanceRequest::where('proposal_id', $f['proposal']->id)->firstOrFail();
    $f['policy'] = app(PolicyIssuanceService::class)->approve($request, ['carrier_reference' => 'CR-8C'], b8cUser('Issuer'));

    DB::table('cancellation_rule_versions')->insert([
        'id' => (string) Str::uuid(), 'line_code' => $f['quote']->line_code, 'version' => 1, 'status' => 'APPROVED', 'basis' => $basis,
        'short_rate_basis_points' => $shortRateBp, 'admin_fee_minor' => $fee, 'effective_from' => now()->subYear()->toDateString(),
        'effective_until' => null, 'created_by' => $f['user']->id, 'created_at' => now(), 'updated_at' => now(),
    ]);
    $f['maker'] = b8cUser('Servicing Maker');
    $f['checker'] = b8cUser('Servicing Checker');

    return $f;
}

beforeEach(function () {
    Storage::fake('local');
    Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 'ticket']]])]);
});

it('REQ-CAN-001 runs request → review → approve with refund, chronology, notice and document revocation', function () {
    $f = b8cPolicy();
    $policy = $f['policy'];
    $svc = app(CancellationService::class);
    $effective = now()->addDays(30)->toDateString();
    $docsBefore = Document::where('policy_id', $policy->id)->whereIn('status', ['GENERATED', 'PENDING_SIGNATURE', 'ISSUED', 'VALID'])->whereIn('document_origin', ['INSURER', 'BROKER', 'SYSTEM'])->pluck('id');
    expect($docsBefore)->not->toBeEmpty();

    $case = $svc->request($policy, ['effective_at' => $effective, 'reason_code' => 'INSURED_REQUEST', 'initiated_by' => 'INSURED'], $f['maker']);
    expect($case->status)->toBe('REQUESTED')->and($case->refund_basis)->toBe('PRO_RATA')->and($case->refund_minor)->toBeGreaterThan(0)
        ->and($case->notice_served_at)->not->toBeNull()
        ->and($policy->refresh()->status)->toBe('CANCELLATION_PENDING');

    // Maker can neither review nor approve; approve needs review first.
    expect(fn () => $svc->review($case, $f['maker']))->toThrow(ValidationException::class);
    expect(fn () => $svc->approve($case, $f['checker']))->toThrow(ValidationException::class);

    $case = $svc->review($case, $f['checker'], 'Checked unused days');
    expect($case->status)->toBe('UNDER_REVIEW');
    $case = $svc->approve($case, $f['checker'], 'OK');

    expect($case->status)->toBe('APPROVED')->and($policy->refresh()->status)->toBe('CANCELLED')
        ->and($case->refund_id)->not->toBeNull()
        ->and(Refund::find($case->refund_id)->amount_minor)->toBe($case->refund_minor)
        ->and(Refund::find($case->refund_id)->status)->toBe('REQUESTED');

    $version = DB::table('policy_versions')->where('id', $case->policy_version_id)->first();
    expect($version->kind)->toBe('CANCELLATION')->and($version->version_no)->toBe($policy->version);

    expect($case->documents_revoked)->toBeGreaterThan(0);
    foreach ($docsBefore as $id) {
        // The engine's cancellation pack may already have CANCELLED the proof of cover; everything else is REVOKED.
        $doc = Document::find($id);
        expect($doc->status)->toBeIn(['REVOKED', 'CANCELLED']);
        if ($doc->status === 'REVOKED') {
            expect(DB::table('document_status_changes')->where('document_id', $id)->where('action', 'REVOKE')->where('status', 'APPROVED')->exists())->toBeTrue();
        }
    }
    expect(Document::whereIn('id', $docsBefore)->where('status', 'REVOKED')->count())->toBeGreaterThan(0);
    expect(DB::table('policy_certificates')->where('policy_id', $policy->id)->where('status', 'VALID')->exists())->toBeFalse();

    expect(DB::table('authority_checks')->where('id', $case->authority_check_id)->value('authority_type'))->toBe('CANCEL');
    foreach (['policy.cancellation.requested', 'policy.cancellation.reviewed', 'policy.cancelled'] as $event) {
        expect(DB::table('outbox_messages')->where('event_name', $event)->exists())->toBeTrue();
    }
});

it('REQ-CAN-001 applies short-rate + fee for insured requests and pro-rata for insurer-initiated', function () {
    $f = b8cPolicy('SHORT_RATE', 8000, 1000);
    $svc = app(CancellationService::class);
    $at = now()->addDays(20)->toDateString();
    $insured = $svc->quote($f['policy'], $at, 'INSURED');
    $insurer = $svc->quote($f['policy'], $at, 'INSURER');
    expect($insured['basis'])->toBe('SHORT_RATE')->and($insurer['basis'])->toBe('PRO_RATA')
        ->and($insured['refund_minor'])->toBeLessThan($insurer['refund_minor'])
        ->and($insured['refund_minor'])->toBe(max(0, (int) round(round($f['policy']->premium_minor * $insured['unused_days'] / $insured['total_days']) * 8000 / 10000) - 1000));
});

it('REQ-CAN-001 enforces the insurer notice period, no backdating and one open cancellation', function () {
    $f = b8cPolicy();
    $svc = app(CancellationService::class);
    expect(fn () => $svc->request($f['policy'], ['effective_at' => now()->addDays(3)->toDateString(), 'reason_code' => 'NON_DISCLOSURE', 'initiated_by' => 'INSURER'], $f['maker']))
        ->toThrow(ValidationException::class);
    expect(fn () => $svc->request($f['policy'], ['effective_at' => now()->subDays(3)->toDateString(), 'reason_code' => 'X', 'initiated_by' => 'INSURED'], $f['maker']))
        ->toThrow(ValidationException::class);
    expect(fn () => $svc->request($f['policy'], ['effective_at' => now()->addDays(15)->toDateString(), 'reason_code' => 'X', 'initiated_by' => 'NOBODY'], $f['maker']))
        ->toThrow(ValidationException::class);

    $case = $svc->request($f['policy'], ['effective_at' => now()->addDays(15)->toDateString(), 'reason_code' => 'NON_DISCLOSURE', 'initiated_by' => 'INSURER'], $f['maker']);
    expect($case->notice_days)->toBe(10)->and($case->refund_basis)->toBe('PRO_RATA');
    expect(fn () => $svc->request($f['policy']->refresh(), ['effective_at' => now()->addDays(20)->toDateString(), 'reason_code' => 'X', 'initiated_by' => 'INSURED'], $f['maker']))
        ->toThrow(ValidationException::class);
});

it('REQ-CAN-001 rejection restores the policy and keeps documents valid', function () {
    $f = b8cPolicy();
    $svc = app(CancellationService::class);
    $case = $svc->request($f['policy'], ['effective_at' => now()->addDays(10)->toDateString(), 'reason_code' => 'INSURED_REQUEST', 'initiated_by' => 'INSURED'], $f['maker']);
    $case = $svc->reject($case, $f['checker'], 'Customer changed mind');
    expect($case->status)->toBe('REJECTED')->and($f['policy']->refresh()->status)->toBe('ACTIVE')
        ->and(Document::where('policy_id', $f['policy']->id)->where('status', 'REVOKED')->exists())->toBeFalse()
        ->and(DB::table('outbox_messages')->where('event_name', 'policy.cancellation.rejected')->exists())->toBeTrue();
});

it('REQ-CAN-001 blocks approval above the approver CANCEL authority limit and records the denial', function () {
    $f = b8cPolicy();
    $svc = app(CancellationService::class);
    $case = $svc->review($svc->request($f['policy'], ['effective_at' => now()->addDays(10)->toDateString(), 'reason_code' => 'INSURED_REQUEST', 'initiated_by' => 'INSURED'], $f['maker']), $f['checker']);
    DB::table('authority_limits')->insert([
        'id' => (string) Str::uuid(), 'carrier_id' => $f['policy']->carrier_id, 'holder_type' => 'USER', 'holder_id' => $f['checker']->id,
        'authority_type' => 'CANCEL', 'line_code' => null, 'max_amount_minor' => 1, 'currency' => 'XAF', 'territories' => '[]',
        'effective_from' => now()->subYear()->toDateString(), 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
    ]);
    expect(fn () => $svc->approve($case, $f['checker']))->toThrow(ValidationException::class);
    expect($case->refresh()->status)->toBe('UNDER_REVIEW')->and($f['policy']->refresh()->status)->toBe('CANCELLATION_PENDING')
        ->and(DB::table('authority_checks')->where('subject_id', $case->id)->value('outcome'))->toBe('DENIED');
});

it('REQ-CAN-001 registers its outbox events in the catalogue', function () {
    foreach (['policy.cancellation.requested', 'policy.cancellation.reviewed', 'policy.cancellation.rejected', 'policy.cancelled'] as $e) {
        expect(DomainEventCatalogue::has($e))->toBeTrue();
    }
});
