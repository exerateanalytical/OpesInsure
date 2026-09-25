<?php

declare(strict_types=1);

use App\Application\Payments\WebhookProcessingService;
use App\Application\Policies\Chronology\PolicyChronologyWriter;
use App\Application\Policies\PolicyIssuanceService;
use App\Domain\Policies\PolicyStateMachine;
use App\Models\Policy;
use App\Models\PolicyIssuanceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';

function b7cIssuedPolicy(): array
{
    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $f['proposal']->update(['status' => 'PAYMENT_PENDING', 'terms_snapshot' => [
        'offer_id' => null, 'quote_id' => $f['quote']->id, 'premium_minor' => 90000, 'tax_minor' => 5000, 'fee_minor' => 5000, 'total_minor' => 100000, 'currency' => 'XAF',
        'coverage_snapshot' => ['coverages' => [
            ['code' => 'RC', 'name' => ['en' => 'Liability'], 'mandatory' => true, 'optional' => false, 'limit_minor' => 50_000_000, 'deductible_minor' => null],
            ['code' => 'DOM', 'name' => ['en' => 'Own damage'], 'mandatory' => false, 'optional' => true, 'limit_minor' => 8_000_000, 'deductible_minor' => 100_000],
        ]],
    ]]);
    $f['payment'] = makeMobileTestPayment($f['proposal'], $f['tenant'], ['status' => 'PENDING_CUSTOMER', 'requested_by' => $f['user']->id]);
    app(WebhookProcessingService::class)->process('fake', 'evt-'.Str::uuid(), [
        'payment_reference' => $f['payment']->provider_reference, 'amount_minor' => $f['payment']->amount_minor,
        'currency' => $f['payment']->currency, 'status' => 'SUCCEEDED',
    ], 'sig');
    $request = PolicyIssuanceRequest::where('proposal_id', $f['proposal']->id)->firstOrFail();
    $approver = User::create(['full_name' => 'Carrier Desk', 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
    $f['policy'] = app(PolicyIssuanceService::class)->approve($request, ['carrier_reference' => 'CR-7C'], $approver);

    return $f;
}

beforeEach(function () {
    Storage::fake('local');
    Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 'ticket']]])]);
});

it('REQ-POL-001 maps every stored status onto a blueprint canonical state without renaming', function () {
    foreach (PolicyStateMachine::STATES as $s) {
        expect(PolicyStateMachine::BLUEPRINT_STATES)->toContain(PolicyStateMachine::blueprintState($s));
    }
    expect(PolicyStateMachine::blueprintState('PAID_PENDING_ISSUANCE'))->toBe('ISSUANCE_PENDING')
        ->and(PolicyStateMachine::blueprintStateFor('ACTIVE', now()->addDay()))->toBe('ISSUED')
        ->and(PolicyStateMachine::blueprintStateFor('ACTIVE', now()->subDay(), 2))->toBe('AMENDED')
        ->and(PolicyStateMachine::blueprintStateFor('EXPIRED', now()->subYear(), 1, true))->toBe('RENEWED')
        ->and(PolicyStateMachine::blueprintStateFor('ACTIVE', now()->subDay()))->toBe('ACTIVE');
    (new PolicyStateMachine())->assert('ACTIVE', 'SUSPENDED');
    expect(fn () => (new PolicyStateMachine())->assert('CANCELLED', 'ACTIVE'))->toThrow(DomainException::class);
});

it('REQ-POL-002/003 writes an immutable structured §84 snapshot and structured rows on approval', function () {
    $f = b7cIssuedPolicy();
    $policy = $f['policy'];

    $v = DB::table('policy_versions')->where('policy_id', $policy->id)->first();
    expect($v)->not->toBeNull()->and($v->version_no)->toBe(1)->and($v->kind)->toBe('ISSUANCE')
        ->and($v->terms_hash)->toBe($policy->terms_hash)->and($v->valid_to)->toBeNull();

    $snap = json_decode($v->snapshot, true);
    foreach (['version', 'coverages', 'limits', 'deductibles', 'premium', 'taxes', 'fees', 'risk', 'rules', 'documents', 'beneficiaries'] as $key) {
        expect($snap)->toHaveKey($key);
    }
    expect($snap['premium']['total_minor'])->toBe(100000)->and($snap['taxes']['total_minor'])->toBe(5000)
        ->and($snap['fees']['total_minor'])->toBe(5000)->and($snap['deductibles'])->toHaveCount(1)
        ->and($snap['risk'])->toHaveCount(1);

    expect(DB::table('policy_coverages')->where('policy_version_id', $v->id)->count())->toBe(2)
        ->and(DB::table('policy_limits')->where('policy_id', $policy->id)->where('limit_type', 'PER_CLAIM')->count())->toBe(2)
        ->and(DB::table('policy_limits')->where('policy_id', $policy->id)->where('limit_type', 'DEDUCTIBLE')->value('amount_minor'))->toBe(100_000)
        ->and(DB::table('policy_parties')->where('policy_version_id', $v->id)->where('role', 'POLICYHOLDER')->value('party_id'))->toBe($policy->party_id)
        ->and(DB::table('policy_risks')->where('policy_version_id', $v->id)->count())->toBe(1);

    // Immutable at the database level.
    expect(fn () => DB::table('policy_versions')->where('id', $v->id)->update(['snapshot' => '{}']))->toThrow(Illuminate\Database\QueryException::class);
});

it('REQ-POL-002 closes the previous version bitemporally and answers as-of reads', function () {
    $f = b7cIssuedPolicy();
    $policy = $f['policy'];
    $writer = app(PolicyChronologyWriter::class);
    $before = now()->subSecond();
    $amendAt = $policy->coverage_starts_at->copy()->addDays(30);

    $policy->update(['version' => 2]);
    $this->travel(1)->minutes();
    $writer->record($policy->refresh(), 'ENDORSEMENT', $amendAt);

    $v1 = DB::table('policy_versions')->where('policy_id', $policy->id)->where('version_no', 1)->first();
    expect($v1->superseded_at)->not->toBeNull()->and(Carbon\Carbon::parse($v1->valid_to)->equalTo($amendAt))->toBeTrue();

    expect($writer->asOf($policy->id, $amendAt->copy()->addDay())->version_no)->toBe(2)
        ->and($writer->asOf($policy->id, $amendAt->copy()->subDay())->version_no)->toBe(1)
        // What we believed before the endorsement was recorded: v1 still open-ended.
        ->and($writer->asOf($policy->id, $amendAt->copy()->addDay(), $before->copy()->addSecond())->version_no)->toBe(1);

    // Idempotent per version number.
    $writer->record($policy, 'ENDORSEMENT', $amendAt);
    expect(DB::table('policy_versions')->where('policy_id', $policy->id)->count())->toBe(2);
});

it('REQ-POL-002 backfills chronology for pre-existing policies idempotently', function () {
    $f = b7cIssuedPolicy();
    $policy = $f['policy'];
    DB::unprepared('ALTER TABLE policy_limits DISABLE TRIGGER USER; ALTER TABLE policy_coverages DISABLE TRIGGER USER; ALTER TABLE policy_parties DISABLE TRIGGER USER; ALTER TABLE policy_risks DISABLE TRIGGER USER; ALTER TABLE policy_versions DISABLE TRIGGER USER;');
    foreach (['policy_limits', 'policy_coverages', 'policy_parties', 'policy_risks', 'policy_versions'] as $t) {
        DB::table($t)->where('policy_id', $policy->id)->delete();
    }
    DB::unprepared('ALTER TABLE policy_limits ENABLE TRIGGER USER; ALTER TABLE policy_coverages ENABLE TRIGGER USER; ALTER TABLE policy_parties ENABLE TRIGGER USER; ALTER TABLE policy_risks ENABLE TRIGGER USER; ALTER TABLE policy_versions ENABLE TRIGGER USER;');

    $this->artisan('policies:backfill-chronology', ['--dry-run' => true])->expectsOutputToContain('1 policies')->assertSuccessful();
    $this->artisan('policies:backfill-chronology')->assertSuccessful();
    $this->artisan('policies:backfill-chronology')->assertSuccessful();

    expect(DB::table('policy_versions')->where('policy_id', $policy->id)->where('kind', 'BACKFILL')->count())->toBe(1)
        ->and(DB::table('policy_coverages')->where('policy_id', $policy->id)->count())->toBe(2);
});
