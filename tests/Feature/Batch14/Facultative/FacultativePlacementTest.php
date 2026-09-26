<?php

declare(strict_types=1);

/**
 * Batch 14C — REQ-REI-003 facultative placements: slip, offered / written / signed lines (signing down),
 * FACULTATIVE_APPROVE maker-checker binding, cession alongside treaty cessions, ledger posting.
 */

use App\Application\Reinsurance\Facultative\FacultativePlacementService;
use App\Models\Policy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';
require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';

uses(RefreshDatabase::class);

const FAC_MAKER = ['reinsurance.treaties.view', 'reinsurance.treaties.manage', 'reinsurance.reinsurers.manage', 'reinsurance.cessions.view', 'reinsurance.cessions.calculate',
    'reinsurance.facultative.view', 'reinsurance.facultative.manage'];

beforeEach(function () {
    $this->f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $this->tenant = $this->f['tenant'];
    $this->h = ['X-Tenant-Id' => $this->tenant->id];
    $this->maker = makeAuthTestUser($this->tenant, FAC_MAKER);
    $this->checker = makeAuthTestUser($this->tenant, ['reinsurance.treaties.view', 'reinsurance.treaties.approve', 'reinsurance.facultative.view', 'reinsurance.facultative.approve']);
    $this->policy = Policy::create(['tenant_id' => $this->tenant->id, 'proposal_id' => $this->f['proposal']->id, 'carrier_id' => $this->f['carrier']->id, 'party_id' => $this->f['party']->id,
        'policy_number' => 'POL-'.Str::upper(Str::random(8)), 'status' => 'ACTIVE', 'coverage_starts_at' => '2026-11-01', 'coverage_ends_at' => '2027-10-31',
        'terms_snapshot' => ['line_code' => 'PROPERTY', 'sum_insured_minor' => 1_000_000_000], 'version' => 1, 'currency' => 'XAF', 'premium_minor' => 10_000_000, 'issued_at' => now()]);
});

function facLimit(string $userId, int $max): void
{
    DB::table('authority_limits')->insert(['id' => (string) Str::uuid(), 'carrier_id' => test()->f['carrier']->id, 'holder_type' => 'USER', 'holder_id' => $userId,
        'authority_type' => 'FACULTATIVE_APPROVE', 'max_amount_minor' => $max, 'currency' => 'XAF', 'effective_from' => now()->subMonth()->toDateString(),
        'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
}

function facReinsurer(string $code, string $role = 'REINSURER'): string
{
    Passport::actingAs(test()->maker, [], 'api');

    $id = test()->postJson('/api/v1/reinsurance/reinsurers', ['code' => $code, 'name' => "Re {$code}", 'role' => $role], test()->h)->assertCreated()->json('data.id');
    \Illuminate\Support\Facades\DB::table('reinsurers')->where('id', $id)->update(['approved_security_status' => 'TENANT_APPROVED']); // Gap Closure 07 approved-security gate

    return $id;
}

function facSlip(array $participants, array $extra = []): array
{
    Passport::actingAs(test()->maker, [], 'api');

    return test()->postJson('/api/v1/reinsurance/facultative', $extra + ['policy_id' => test()->policy->id, 'risk_description' => 'Warehouse, Douala port',
        'placed_share_percent' => 40, 'commission_percent' => 10, 'brokerage_percent' => 2.5, 'tax_percent' => 0, 'terms' => ['deductible_minor' => 5_000_000],
        'period_from' => '2026-11-01', 'period_to' => '2027-10-31', 'participants' => $participants], test()->h)->assertCreated()->json('data');
}

it('REQ-REI-003: signing down is proportional and totals exactly 100%', function () {
    expect(FacultativePlacementService::signLines([60, 60]))->toBe([50.0, 50.0])
        ->and(array_sum(FacultativePlacementService::signLines([50, 40, 30])))->toEqualWithDelta(100.0, 1e-9)
        ->and(FacultativePlacementService::signLines([50, 40, 30]))->toBe([41.6667, 33.3333, 25.0])
        ->and(FacultativePlacementService::signLines([70, 30]))->toBe([70.0, 30.0]);
    expect(fn () => FacultativePlacementService::signLines([50, 40]))->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('REQ-REI-003: slip with offered/written/signed lines, maker-checker FACULTATIVE_APPROVE binding, cession + ledger', function () {
    $a = facReinsurer('SCOR');
    $b = facReinsurer('SWISS');
    $broker = facReinsurer('AON', 'REINSURANCE_BROKER');

    // A broker cannot be a participant; treaties still reject FACULTATIVE.
    Passport::actingAs($this->maker, [], 'api');
    $this->postJson('/api/v1/reinsurance/facultative', ['policy_id' => $this->policy->id, 'risk_description' => 'x', 'placed_share_percent' => 40, 'period_from' => '2026-11-01',
        'period_to' => '2027-10-31', 'participants' => [['reinsurer_id' => $broker, 'offered_percent' => 100]]], $this->h)->assertUnprocessable();
    $this->postJson('/api/v1/reinsurance/treaties', ['code' => 'FAC', 'name' => 'f', 'treaty_type' => 'QUOTA_SHARE', 'reinsurance_type' => 'FACULTATIVE', 'currency' => 'XAF'], $this->h)->assertUnprocessable();

    $slip = facSlip([['reinsurer_id' => $a, 'offered_percent' => 60, 'is_lead' => true], ['reinsurer_id' => $b, 'offered_percent' => 60]], ['broker_id' => $broker]);
    expect($slip['status'])->toBe('DRAFT')->and((int) $slip['sum_insured_minor'])->toBe(1_000_000_000)->and($slip['terms'])->toBe(['deductible_minor' => 5_000_000]);

    // Under-placed written lines are refused at submit.
    $this->postJson("/api/v1/reinsurance/facultative/{$slip['id']}/lines", ['lines' => [['reinsurer_id' => $a, 'written_percent' => 50], ['reinsurer_id' => $b, 'written_percent' => 30]]], $this->h)->assertOk();
    $this->postJson("/api/v1/reinsurance/facultative/{$slip['id']}/submit", [], $this->h)->assertUnprocessable()->assertJsonValidationErrors('participants');

    // Oversubscribed 75 + 50 = 125% -> signed down to 60 / 40.
    $this->postJson("/api/v1/reinsurance/facultative/{$slip['id']}/lines", ['lines' => [['reinsurer_id' => $a, 'written_percent' => 75], ['reinsurer_id' => $b, 'written_percent' => 50]]], $this->h)->assertOk();
    $sub = $this->postJson("/api/v1/reinsurance/facultative/{$slip['id']}/submit", [], $this->h)->assertOk()->json('data');
    expect($sub['status'])->toBe('SUBMITTED')
        ->and(collect($sub['participants'])->pluck('signed_percent', 'reinsurer_id')->all())->toEqual([$a => 60, $b => 40]);
    $this->postJson("/api/v1/reinsurance/facultative/{$slip['id']}/lines", ['lines' => [['reinsurer_id' => $a, 'written_percent' => 10]]], $this->h)->assertUnprocessable();

    // Maker lacks approve permission; a maker holding it is still refused (maker-checker).
    $this->postJson("/api/v1/reinsurance/facultative/{$slip['id']}/approve", ['reason' => 'x'], $this->h)->assertForbidden();
    $makerChecker = makeAuthTestUser($this->tenant, [...FAC_MAKER, 'reinsurance.facultative.approve']);
    DB::table('facultative_placements')->where('id', $slip['id'])->update(['created_by' => $makerChecker->id]);
    Passport::actingAs($makerChecker, [], 'api');
    $this->postJson("/api/v1/reinsurance/facultative/{$slip['id']}/approve", ['reason' => 'x'], $this->h)->assertUnprocessable()->assertJsonValidationErrors('approver');
    DB::table('facultative_placements')->where('id', $slip['id'])->update(['created_by' => $this->maker->id]);

    // Checker below the ceded sum (40% of 1bn = 400m) -> referred, stays SUBMITTED.
    facLimit($this->checker->id, 100_000_000);
    Passport::actingAs($this->checker, [], 'api');
    $this->postJson("/api/v1/reinsurance/facultative/{$slip['id']}/approve", ['reason' => 'Signed slip'], $this->h)->assertStatus(409);
    expect(DB::table('facultative_placements')->where('id', $slip['id'])->value('status'))->toBe('SUBMITTED')
        ->and(DB::table('authority_checks')->where('subject_id', $slip['id'])->where('authority_type', 'FACULTATIVE_APPROVE')->value('outcome'))->toBe('REFERRED');

    DB::table('authority_limits')->where('holder_id', $this->checker->id)->update(['max_amount_minor' => 500_000_000]);
    $bound = $this->postJson("/api/v1/reinsurance/facultative/{$slip['id']}/approve", ['reason' => 'Signed slip'], $this->h)->assertOk()->json('data');
    expect($bound['status'])->toBe('BOUND')->and($bound['cession_id'])->not->toBeNull()->and($bound['journal_id'])->not->toBeNull();

    // Cession: 40% of premium 10m = 4m; commission 400k, brokerage 100k; split 60/40.
    $c = DB::table('reinsurance_cessions')->where('id', $bound['cession_id'])->first();
    expect($c->source)->toBe('FACULTATIVE')->and($c->treaty_id)->toBeNull()->and((int) $c->ceded_sum_minor)->toBe(400_000_000)
        ->and((int) $c->ceded_premium_minor)->toBe(4_000_000)->and((int) $c->net_ceded_premium_minor)->toBe(3_500_000);
    $shares = DB::table('reinsurance_cession_shares')->where('cession_id', $c->id)->pluck('ceded_premium_minor', 'reinsurer_id')->map(fn ($v) => (int) $v)->all();
    expect($shares)->toEqualCanonicalizing([$a => 2_400_000, $b => 1_600_000]);

    // Ledger: Dr 602000 / Cr 401200 for the ceded premium.
    $lines = DB::table('journal_lines as l')->join('ledger_accounts as ac', 'ac.id', '=', 'l.account_id')->where('l.journal_id', $bound['journal_id'])->get(['ac.code', 'l.debit_minor', 'l.credit_minor']);
    expect((int) $lines->firstWhere('code', '602000')->debit_minor)->toBe(4_000_000)->and((int) $lines->firstWhere('code', '401200')->credit_minor)->toBe(4_000_000);
    expect(DB::table('outbox_messages')->where('event_name', 'reinsurance.facultative.bound')->exists())->toBeTrue()
        ->and(DB::table('outbox_messages')->where('event_name', 'reinsurance.facultative.submitted')->exists())->toBeTrue();

    // A bound slip cannot be approved again.
    $this->postJson("/api/v1/reinsurance/facultative/{$slip['id']}/approve", ['reason' => 'again'], $this->h)->assertUnprocessable();
});

it('REQ-REI-003: policy cession totals are treaty + facultative; treaty re-runs keep the facultative cession', function () {
    $a = facReinsurer('SCOR');
    $b = facReinsurer('SWISS');
    // 30% quota share treaty.
    $treaty = $this->postJson('/api/v1/reinsurance/treaties', ['code' => 'QS', 'name' => 'QS', 'treaty_type' => 'QUOTA_SHARE', 'currency' => 'XAF'], $this->h)->assertCreated()->json('data.id');
    $v = $this->postJson("/api/v1/reinsurance/treaties/{$treaty}/versions", ['effective_from' => '2026-01-01', 'cession_percent' => 30, 'participants' => [['reinsurer_id' => $b, 'share_percent' => 100]]], $this->h)->assertCreated()->json('data.id');
    Passport::actingAs($this->checker, [], 'api');
    $this->postJson("/api/v1/reinsurance/treaty-versions/{$v}/activate", ['reason' => 'ok'], $this->h)->assertOk();

    $slip = facSlip([['reinsurer_id' => $a, 'offered_percent' => 100, 'written_percent' => 100]], ['placed_share_percent' => 20, 'commission_percent' => 0, 'brokerage_percent' => 0]);
    $this->postJson("/api/v1/reinsurance/facultative/{$slip['id']}/submit", [], $this->h)->assertOk();
    facLimit($this->checker->id, 1_000_000_000);
    Passport::actingAs($this->checker, [], 'api');
    $this->postJson("/api/v1/reinsurance/facultative/{$slip['id']}/approve", ['reason' => 'ok'], $this->h)->assertOk();

    Passport::actingAs($this->maker, [], 'api');
    $this->postJson("/api/v1/reinsurance/policies/{$this->policy->id}/cessions", [], $this->h)->assertCreated();
    DB::table('policies')->where('id', $this->policy->id)->update(['version' => 2]);
    $this->postJson("/api/v1/reinsurance/policies/{$this->policy->id}/cessions", [], $this->h)->assertCreated();

    $s = $this->getJson("/api/v1/reinsurance/policies/{$this->policy->id}/cessions", $this->h)->assertOk()->json('data');
    expect($s['treaty_ceded_premium_minor'])->toBe(3_000_000)->and($s['facultative_ceded_premium_minor'])->toBe(2_000_000)
        ->and($s['ceded_premium_minor'])->toBe(5_000_000)->and($s['net_premium_minor'])->toBe(5_000_000)
        ->and($s['net_sum_insured_minor'])->toBe(500_000_000)->and(collect($s['cessions'])->pluck('treaty_type')->all())->toBe(['FACULTATIVE', 'QUOTA_SHARE']);
    expect(DB::table('reinsurance_cessions')->where('source', 'FACULTATIVE')->value('status'))->toBe('BOUND');
});

it('REQ-REI-003: checker can reject; tenant isolation and permissions', function () {
    $a = facReinsurer('SCOR');
    $slip = facSlip([['reinsurer_id' => $a, 'offered_percent' => 100, 'written_percent' => 100]]);
    $this->postJson("/api/v1/reinsurance/facultative/{$slip['id']}/submit", [], $this->h)->assertOk();
    Passport::actingAs($this->checker, [], 'api');
    $this->postJson('/api/v1/reinsurance/facultative', ['policy_id' => $this->policy->id], $this->h)->assertForbidden();
    $this->postJson("/api/v1/reinsurance/facultative/{$slip['id']}/reject", ['reason' => 'Terms not acceptable'], $this->h)->assertOk()->assertJsonPath('data.status', 'REJECTED');
    expect(DB::table('outbox_messages')->where('event_name', 'reinsurance.facultative.rejected')->exists())->toBeTrue();

    $other = makeAuthTestTenant('fac-other');
    Passport::actingAs(makeAuthTestUser($other, FAC_MAKER), [], 'api');
    $this->getJson("/api/v1/reinsurance/facultative/{$slip['id']}", ['X-Tenant-Id' => $other->id])->assertNotFound();
});
