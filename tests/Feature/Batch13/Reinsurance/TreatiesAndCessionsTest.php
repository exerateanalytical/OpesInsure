<?php

declare(strict_types=1);

/**
 * Batch 13C — REQ-REI-001 (reinsurers, treaties, effective versions, participants) and
 * REQ-REI-002 (cessions: gross/net exposure, ceded premium/commission/brokerage/tax, bordereaux).
 */

use App\Application\Reinsurance\CessionCalculator;
use App\Application\Reinsurance\CessionService;
use App\Models\Policy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';
require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';

uses(RefreshDatabase::class);

const REI_ALL = ['reinsurance.treaties.view', 'reinsurance.treaties.manage', 'reinsurance.reinsurers.manage', 'reinsurance.cessions.view', 'reinsurance.cessions.calculate'];

beforeEach(function () {
    $this->f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $this->tenant = $this->f['tenant'];
    $this->h = ['X-Tenant-Id' => $this->tenant->id];
    $this->maker = makeAuthTestUser($this->tenant, REI_ALL);
    $this->checker = makeAuthTestUser($this->tenant, ['reinsurance.treaties.view', 'reinsurance.treaties.approve']);
});

function reiPolicy(int $premium = 1_000_000, ?int $sumInsured = 100_000_000, string $starts = '2026-11-01'): Policy
{
    $f = test()->f;

    return Policy::create(['tenant_id' => $f['tenant']->id, 'proposal_id' => $f['proposal']->id, 'carrier_id' => $f['carrier']->id, 'party_id' => $f['party']->id,
        'policy_number' => 'POL-'.Str::upper(Str::random(8)), 'status' => 'ACTIVE', 'coverage_starts_at' => $starts, 'coverage_ends_at' => '2027-10-31',
        'terms_snapshot' => ['line_code' => 'PROPERTY'] + ($sumInsured === null ? [] : ['sum_insured_minor' => $sumInsured]), 'version' => 1, 'currency' => 'XAF',
        'premium_minor' => $premium, 'issued_at' => now()]);
}

function reiReinsurer(string $code, string $role = 'REINSURER'): string
{
    Passport::actingAs(test()->maker, [], 'api');

    return test()->postJson('/api/v1/reinsurance/reinsurers', ['code' => $code, 'name' => "Re {$code}", 'role' => $role, 'country_code' => 'FR', 'rating' => 'A+'], test()->h)
        ->assertCreated()->json('data.id');
}

/** Creates a treaty + version with the given terms, activates it by the checker; returns [treatyId, versionId]. */
function reiTreaty(string $code, string $type, array $terms, array $participants, bool $activate = true): array
{
    Passport::actingAs(test()->maker, [], 'api');
    $treaty = test()->postJson('/api/v1/reinsurance/treaties', ['code' => $code, 'name' => $code, 'treaty_type' => $type, 'currency' => 'XAF', 'underwriting_year' => 2026], test()->h)
        ->assertCreated()->json('data.id');
    $version = test()->postJson("/api/v1/reinsurance/treaties/{$treaty}/versions", $terms + ['effective_from' => '2026-01-01', 'participants' => $participants], test()->h)
        ->assertCreated()->json('data.id');
    if ($activate) {
        Passport::actingAs(test()->checker, [], 'api');
        test()->postJson("/api/v1/reinsurance/treaty-versions/{$version}/activate", ['reason' => 'Signed slip received'], test()->h)->assertOk();
    }

    return [$treaty, $version];
}

it('REQ-REI-001: reinsurer profiles are tenant-scoped with a role catalogue, audited', function () {
    $id = reiReinsurer('SCOR');
    reiReinsurer('AONRE', 'REINSURANCE_BROKER');
    $this->postJson('/api/v1/reinsurance/reinsurers', ['code' => 'SCOR', 'name' => 'dup'], $this->h)->assertUnprocessable();
    $this->postJson('/api/v1/reinsurance/reinsurers', ['code' => 'X', 'name' => 'x', 'role' => 'CEDANT'], $this->h)->assertUnprocessable();
    expect($this->getJson('/api/v1/reinsurance/reinsurers', $this->h)->assertOk()->json('data'))->toHaveCount(2);
    expect(DB::table('audit_log')->where('action', 'reinsurance.reinsurer.created')->where('subject_id', $id)->exists())->toBeTrue();

    $other = makeAuthTestTenant('rei-other');
    $outsider = makeAuthTestUser($other, REI_ALL);
    Passport::actingAs($outsider, [], 'api');
    expect($this->getJson('/api/v1/reinsurance/reinsurers', ['X-Tenant-Id' => $other->id])->assertOk()->json('data'))->toBe([]);
});

it('REQ-REI-001: permissions gate every route', function () {
    $viewer = makeAuthTestUser($this->tenant, ['reinsurance.treaties.view']);
    Passport::actingAs($viewer, [], 'api');
    $this->postJson('/api/v1/reinsurance/treaties', ['code' => 'Q', 'name' => 'Q', 'treaty_type' => 'QUOTA_SHARE', 'currency' => 'XAF'], $this->h)->assertForbidden();
    $this->postJson('/api/v1/reinsurance/policies/'.reiPolicy()->id.'/cessions', [], $this->h)->assertForbidden();
    $this->getJson('/api/v1/reinsurance/treaties', $this->h)->assertOk();
});

it('REQ-REI-001: treaty versions need valid terms, 100% signed shares and maker-checker activation', function () {
    $a = reiReinsurer('A');
    $b = reiReinsurer('B');
    $broker = reiReinsurer('BRK', 'REINSURANCE_BROKER');
    $treaty = $this->postJson('/api/v1/reinsurance/treaties', ['code' => 'QS26', 'name' => 'QS 2026', 'treaty_type' => 'QUOTA_SHARE', 'currency' => 'XAF'], $this->h)->assertCreated()->json('data.id');
    $this->postJson('/api/v1/reinsurance/treaties', ['code' => 'FAC', 'name' => 'f', 'treaty_type' => 'QUOTA_SHARE', 'reinsurance_type' => 'FACULTATIVE', 'currency' => 'XAF'], $this->h)->assertUnprocessable();

    // Missing cession percent; broker as participant.
    $this->postJson("/api/v1/reinsurance/treaties/{$treaty}/versions", ['effective_from' => '2026-01-01', 'participants' => [['reinsurer_id' => $a, 'share_percent' => 100]]], $this->h)->assertUnprocessable();
    $this->postJson("/api/v1/reinsurance/treaties/{$treaty}/versions", ['effective_from' => '2026-01-01', 'cession_percent' => 40, 'participants' => [['reinsurer_id' => $broker, 'share_percent' => 100]]], $this->h)->assertUnprocessable();

    $v = $this->postJson("/api/v1/reinsurance/treaties/{$treaty}/versions", ['effective_from' => '2026-01-01', 'cession_percent' => 40,
        'participants' => [['reinsurer_id' => $a, 'share_percent' => 60, 'is_lead' => true, 'broker_id' => $broker], ['reinsurer_id' => $b, 'share_percent' => 30]]], $this->h)->assertCreated()->json('data');
    expect($v['version'])->toBe(1)->and($v['status'])->toBe('DRAFT');

    // Maker cannot activate (and lacks the permission); checker blocked by 90% shares.
    $this->postJson("/api/v1/reinsurance/treaty-versions/{$v['id']}/activate", ['reason' => 'x'], $this->h)->assertForbidden();
    $makerChecker = makeAuthTestUser($this->tenant, [...REI_ALL, 'reinsurance.treaties.approve']);
    DB::table('reinsurance_treaty_versions')->where('id', $v['id'])->update(['created_by' => $makerChecker->id]);
    Passport::actingAs($makerChecker, [], 'api');
    $this->postJson("/api/v1/reinsurance/treaty-versions/{$v['id']}/activate", ['reason' => 'x'], $this->h)->assertUnprocessable()->assertJsonValidationErrors('approver');
    Passport::actingAs($this->checker, [], 'api');
    $this->postJson("/api/v1/reinsurance/treaty-versions/{$v['id']}/activate", ['reason' => 'x'], $this->h)->assertUnprocessable()->assertJsonValidationErrors('participants');

    DB::table('reinsurance_treaty_participants')->where('treaty_version_id', $v['id'])->where('reinsurer_id', $b)->update(['share_percent' => 40]);
    $this->postJson("/api/v1/reinsurance/treaty-versions/{$v['id']}/activate", ['reason' => 'Signed'], $this->h)->assertOk()->assertJsonPath('data.status', 'ACTIVE');
    expect(DB::table('reinsurance_treaties')->where('id', $treaty)->value('status'))->toBe('ACTIVE')
        ->and(DB::table('outbox_messages')->where('event_name', 'reinsurance.treaty_version.activated')->exists())->toBeTrue();
});

it('REQ-REI-001: a newer effective version closes the older one; cessions use the version effective at inception', function () {
    $a = reiReinsurer('A');
    [$treaty, $v1] = reiTreaty('QS', 'QUOTA_SHARE', ['cession_percent' => 40], [['reinsurer_id' => $a, 'share_percent' => 100]]);
    Passport::actingAs($this->maker, [], 'api');
    $v2 = $this->postJson("/api/v1/reinsurance/treaties/{$treaty}/versions", ['effective_from' => '2027-01-01', 'cession_percent' => 50, 'participants' => [['reinsurer_id' => $a, 'share_percent' => 100]]], $this->h)->json('data.id');
    Passport::actingAs($this->checker, [], 'api');
    $this->postJson("/api/v1/reinsurance/treaty-versions/{$v2}/activate", ['reason' => 'Renewal'], $this->h)->assertOk();

    $versions = $this->getJson("/api/v1/reinsurance/treaties/{$treaty}", $this->h)->assertOk()->json('data.versions');
    expect($versions[0]['effective_to'])->toBe('2026-12-31')->and($versions[1]['effective_to'])->toBeNull();

    $svc = app(CessionService::class);
    expect($svc->preview($this->tenant->id, reiPolicy(starts: '2026-11-01')->id)['cessions'][0]['treaty_version_id'])->toBe($v1)
        ->and($svc->preview($this->tenant->id, reiPolicy(starts: '2027-02-01')->id)['cessions'][0]['treaty_version_id'])->toBe($v2);
});

it('REQ-REI-002: QS then surplus then XL cede with commission, brokerage and tax; gross vs net exposure', function () {
    $a = reiReinsurer('A');
    $b = reiReinsurer('B');
    $c = reiReinsurer('C');
    reiTreaty('QS', 'QUOTA_SHARE', ['cession_percent' => 30, 'commission_percent' => 25, 'brokerage_percent' => 2.5, 'tax_percent' => 1],
        [['reinsurer_id' => $a, 'share_percent' => 33.3333], ['reinsurer_id' => $b, 'share_percent' => 33.3333], ['reinsurer_id' => $c, 'share_percent' => 33.3334]]);
    reiTreaty('SP', 'SURPLUS', ['retention_minor' => 20_000_000, 'lines' => 2, 'commission_percent' => 20], [['reinsurer_id' => $b, 'share_percent' => 100]]);
    reiTreaty('XL', 'EXCESS_OF_LOSS', ['layers' => [['layer' => 1, 'attachment_minor' => 10_000_000, 'limit_minor' => 5_000_000, 'rate_percent' => 3]]], [['reinsurer_id' => $c, 'share_percent' => 100]]);

    $policy = reiPolicy(1_000_000, 100_000_000);
    $before = $policy->fresh()->toArray();
    Passport::actingAs($this->maker, [], 'api');
    $r = $this->postJson("/api/v1/reinsurance/policies/{$policy->id}/cessions", [], $this->h)->assertCreated()->json('data');

    [$qs, $sp, $xl] = $r['cessions'];
    // QS 30% of 100m SI / 1m premium.
    expect($qs['ceded_sum_minor'])->toBe(30_000_000)->and($qs['ceded_premium_minor'])->toBe(300_000)
        ->and($qs['commission_minor'])->toBe(75_000)->and($qs['brokerage_minor'])->toBe(7_500)->and($qs['tax_minor'])->toBe(3_000)
        ->and($qs['net_ceded_premium_minor'])->toBe(214_500);
    // Shares reconcile to the cession amounts (largest-remainder rounding).
    expect(array_sum(array_column($qs['shares'], 'ceded_premium_minor')))->toBe(300_000)
        ->and(array_sum(array_column($qs['shares'], 'commission_minor')))->toBe(75_000);
    // Surplus on 70m retained: retention 20m, 2 lines => 40m ceded of 70m; premium 700k * 40/70 = 400k.
    expect($sp['subject_sum_minor'])->toBe(70_000_000)->and($sp['ceded_sum_minor'])->toBe(40_000_000)->and($sp['ceded_premium_minor'])->toBe(400_000);
    // XL on 30m net retained: layer 5m xs 10m fully exposed; 3% of 300k net premium.
    expect($xl['subject_sum_minor'])->toBe(30_000_000)->and($xl['ceded_sum_minor'])->toBe(5_000_000)->and($xl['ceded_premium_minor'])->toBe(9_000);

    expect($r['gross_sum_insured_minor'])->toBe(100_000_000)->and($r['net_sum_insured_minor'])->toBe(25_000_000)
        ->and($r['gross_premium_minor'])->toBe(1_000_000)->and($r['net_premium_minor'])->toBe(291_000);

    // Policy untouched; audit + outbox written.
    expect($policy->fresh()->toArray())->toEqual($before)
        ->and(DB::table('audit_log')->where('action', 'reinsurance.policy.ceded')->where('subject_id', $policy->id)->exists())->toBeTrue()
        ->and(DB::table('outbox_messages')->where('event_name', 'reinsurance.policy.ceded')->where('aggregate_id', $policy->id)->exists())->toBeTrue();
});

it('REQ-REI-002: cession runs are idempotent and superseded when the policy premium changes', function () {
    $a = reiReinsurer('A');
    reiTreaty('QS', 'QUOTA_SHARE', ['cession_percent' => 50], [['reinsurer_id' => $a, 'share_percent' => 100]]);
    $policy = reiPolicy(800_000);
    Passport::actingAs($this->maker, [], 'api');
    $this->postJson("/api/v1/reinsurance/policies/{$policy->id}/cessions", [], $this->h)->assertCreated()->assertJsonPath('data.run', 1);
    $this->postJson("/api/v1/reinsurance/policies/{$policy->id}/cessions", [], $this->h)->assertOk()->assertJsonPath('data.replayed', true)->assertJsonPath('data.run', 1);
    expect(DB::table('reinsurance_cessions')->where('policy_id', $policy->id)->count())->toBe(1);

    DB::table('policies')->where('id', $policy->id)->update(['premium_minor' => 900_000, 'version' => 2]); // endorsement by the policy module
    $this->postJson("/api/v1/reinsurance/policies/{$policy->id}/cessions", [], $this->h)->assertCreated()->assertJsonPath('data.run', 2)->assertJsonPath('data.ceded_premium_minor', 450_000);
    expect(DB::table('reinsurance_cessions')->where('policy_id', $policy->id)->where('status', 'SUPERSEDED')->count())->toBe(1);
    $this->getJson("/api/v1/reinsurance/policies/{$policy->id}/cessions", $this->h)->assertOk()->assertJsonPath('data.run', 2)->assertJsonPath('data.net_premium_minor', 450_000);
});

it('REQ-REI-002: line scope, currency and draft versions exclude treaties; surplus needs sum insured', function () {
    $a = reiReinsurer('A');
    reiTreaty('MOTOR', 'QUOTA_SHARE', ['cession_percent' => 50, 'line_codes' => ['AUTO']], [['reinsurer_id' => $a, 'share_percent' => 100]]);
    reiTreaty('DRAFT', 'QUOTA_SHARE', ['cession_percent' => 50], [['reinsurer_id' => $a, 'share_percent' => 100]], activate: false);
    $svc = app(CessionService::class);
    expect($svc->preview($this->tenant->id, reiPolicy()->id)['cessions'])->toBe([]);

    reiTreaty('SP', 'SURPLUS', ['retention_minor' => 1_000, 'lines' => 1], [['reinsurer_id' => $a, 'share_percent' => 100]]);
    expect(fn () => $svc->preview($this->tenant->id, reiPolicy(sumInsured: null)->id))->toThrow(DomainException::class);
});

it('REQ-REI-002: stop loss is priced on net retained premium; calculator shares reconcile', function () {
    $calc = new CessionCalculator;
    $out = $calc->calculate(null, 1_000_001, [
        ['id' => 'v1', 'treaty_id' => 't1', 'treaty_type' => 'STOP_LOSS', 'rate_percent' => 2.5, 'commission_percent' => 0, 'brokerage_percent' => 10, 'tax_percent' => 0,
            'participants' => [['reinsurer_id' => 'r1', 'share_percent' => 50], ['reinsurer_id' => 'r2', 'share_percent' => 50]]],
        ['id' => 'v0', 'treaty_id' => 't0', 'treaty_type' => 'QUOTA_SHARE', 'cession_percent' => 20, 'max_capacity_minor' => null, 'commission_percent' => 0, 'brokerage_percent' => 0, 'tax_percent' => 0,
            'participants' => [['reinsurer_id' => 'r1', 'share_percent' => 100]]],
    ]);
    expect($out[0]['treaty_type'])->toBe('QUOTA_SHARE')->and($out[0]['ceded_premium_minor'])->toBe(200_000)
        ->and($out[1]['ceded_premium_minor'])->toBe(20_000) // 2.5% of 800,001
        ->and($out[1]['brokerage_minor'])->toBe(2_000)
        ->and(array_sum(array_column($out[1]['shares'], 'ceded_premium_minor')))->toBe(20_000);
});

it('REQ-REI-002: premium bordereau lists each policy share with per-reinsurer totals', function () {
    $a = reiReinsurer('A');
    $b = reiReinsurer('B');
    [$treaty] = reiTreaty('QS', 'QUOTA_SHARE', ['cession_percent' => 40, 'commission_percent' => 10], [['reinsurer_id' => $a, 'share_percent' => 75], ['reinsurer_id' => $b, 'share_percent' => 25]]);
    Passport::actingAs($this->maker, [], 'api');
    foreach ([reiPolicy(1_000_000), reiPolicy(500_000), reiPolicy(700_000, starts: '2027-03-01')] as $p) {
        $this->postJson("/api/v1/reinsurance/policies/{$p->id}/cessions", [], $this->h)->assertCreated();
    }
    $b = $this->getJson("/api/v1/reinsurance/treaties/{$treaty}/bordereau?from=2026-10-01&to=2026-12-31", $this->h)->assertOk()->json('data');
    expect($b['lines'])->toHaveCount(4)->and($b['totals'])->toHaveCount(2);
    $totalA = collect($b['totals'])->firstWhere('reinsurer_code', 'A');
    expect($totalA['ceded_premium_minor'])->toBe(450_000)->and($totalA['commission_minor'])->toBe(45_000)->and($totalA['net_premium_minor'])->toBe(405_000);
});
