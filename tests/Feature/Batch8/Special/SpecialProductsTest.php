<?php

declare(strict_types=1);

/**
 * Batch 8-6 — REQ-PRD-011 life & special products (PRE §66–73): group master + dated member schedule,
 * fleet schedule, open-cover cargo declarations, construction/agriculture schedules, rules-driven life surrender.
 */

use App\Application\Events\Catalogue\DomainEventCatalogue;
use App\Application\Policies\Special\LifeSurrenderService;
use App\Application\Policies\Special\PolicyScheduleService;
use App\Models\Policy;
use App\Models\RiskAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';
require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';

uses(RefreshDatabase::class);

const B86_ALL = ['special_policies.view', 'special_policies.manage', 'special_policies.schedule.manage', 'cargo_declarations.declare', 'cargo_declarations.cancel',
    'life_surrender.scales.manage', 'life_surrender.scales.approve', 'life_surrender.quote'];

beforeEach(function () {
    $this->f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $this->tenant = $this->f['tenant'];
    $this->h = ['X-Tenant-Id' => $this->tenant->id];
    $this->maker = makeAuthTestUser($this->tenant, B86_ALL);
    $this->checker = makeAuthTestUser($this->tenant, B86_ALL);
    Passport::actingAs($this->maker);
});

function b86Policy(string $line = 'GROUP', string $starts = '2026-01-01', string $ends = '2026-12-31', string $status = 'ACTIVE'): Policy
{
    $f = test()->f;

    return Policy::create(['tenant_id' => $f['tenant']->id, 'proposal_id' => $f['proposal']->id, 'carrier_id' => $f['carrier']->id, 'party_id' => $f['party']->id,
        'policy_number' => 'POL-'.Str::upper(Str::random(8)), 'status' => $status, 'coverage_starts_at' => $starts, 'coverage_ends_at' => $ends,
        'terms_snapshot' => ['line_code' => $line], 'version' => 1, 'currency' => 'XAF', 'premium_minor' => 1_000_000, 'issued_at' => now()]);
}

function b86Asset(string $type): string
{
    return RiskAsset::create(['tenant_id' => test()->tenant->id, 'party_id' => test()->f['party']->id, 'type' => $type, 'display_name' => $type.' asset',
        'facts' => [], 'facts_hash' => str_repeat('0', 64), 'status' => 'ACTIVE'])->id;
}

it('REQ-PRD-011 group master policy: dated member add/remove, pro-rata premium, point-in-time schedule, audit + outbox', function () {
    $p = b86Policy();
    $this->postJson("/api/v1/policies/{$p->id}/special-profile", ['kind' => 'GROUP_MASTER', 'terms' => ['scheme' => 'STAFF']], $this->h)->assertCreated()->assertJsonPath('data.kind', 'GROUP_MASTER');
    $this->postJson("/api/v1/policies/{$p->id}/special-profile", ['kind' => 'FLEET'], $this->h)->assertStatus(422);

    $a = $this->postJson("/api/v1/policies/{$p->id}/schedule-items", ['item_key' => 'EMP-001', 'display_name' => 'Awa N.', 'category' => 'STAFF',
        'sum_insured_minor' => 10_000_000, 'annual_premium_minor' => 36_400, 'effective_from' => '2026-01-01'], $this->h)->assertCreated();
    expect($a->json('data.item_type'))->toBe('GROUP_MEMBER')->and($a->json('data.adjustment_premium_minor'))->toBe(36_400); // full term remaining
    $b = $this->postJson("/api/v1/policies/{$p->id}/schedule-items", ['item_key' => 'EMP-002', 'display_name' => 'Paul M.', 'annual_premium_minor' => 36_400,
        'effective_from' => '2026-07-01'], $this->h)->assertCreated();
    // duplicate open key
    $this->postJson("/api/v1/policies/{$p->id}/schedule-items", ['item_key' => 'EMP-002', 'display_name' => 'x', 'effective_from' => '2026-08-01'], $this->h)->assertStatus(422);
    // outside cover
    $this->postJson("/api/v1/policies/{$p->id}/schedule-items", ['item_key' => 'EMP-003', 'display_name' => 'x', 'effective_from' => '2027-02-01'], $this->h)->assertStatus(422);

    $r = $this->postJson("/api/v1/policy-schedule-items/{$a->json('data.id')}/remove", ['effective_until' => '2026-10-01', 'reason' => 'Left the company'], $this->h)->assertOk();
    expect($r->json('data.return_premium_minor'))->toBeLessThan(0)->and($r->json('data.effective_until'))->toBe('2026-10-01');
    $this->postJson("/api/v1/policy-schedule-items/{$a->json('data.id')}/remove", ['effective_until' => '2026-10-02', 'reason' => 'again'], $this->h)->assertStatus(422);

    expect($this->getJson("/api/v1/policies/{$p->id}/schedule?as_of=2026-03-01", $this->h)->assertOk()->json('data.count'))->toBe(1)
        ->and($this->getJson("/api/v1/policies/{$p->id}/schedule?as_of=2026-08-01", $this->h)->json('data.count'))->toBe(2)
        ->and($this->getJson("/api/v1/policies/{$p->id}/schedule?as_of=2026-11-01", $this->h)->json('data.items.0.item_key'))->toBe('EMP-002')
        ->and($this->getJson("/api/v1/policies/{$p->id}/schedule?history=1", $this->h)->json('data.count'))->toBe(2);

    // Re-adding a removed member creates a new dated entry (history kept).
    $this->postJson("/api/v1/policies/{$p->id}/schedule-items", ['item_key' => 'EMP-001', 'display_name' => 'Awa N.', 'effective_from' => '2026-11-15'], $this->h)->assertCreated();
    expect(DB::table('policy_schedule_items')->where('policy_id', $p->id)->count())->toBe(3)
        ->and(DB::table('outbox_messages')->where('aggregate_id', $p->id)->pluck('event_name')->all())
        ->toContain('special_policy.profile.created', 'group_policy.member.added', 'group_policy.member.removed')
        ->and(DB::table('audit_log')->where('subject_id', $p->id)->where('action', 'group_policy.member.removed')->exists())->toBeTrue();
    // Read-only on the policy row.
    expect($p->fresh()->premium_minor)->toBe(1_000_000);
});

it('REQ-PRD-011 fleet schedule links VEHICLE risk assets only; closed policies are refused', function () {
    $p = b86Policy('MOTOR');
    $this->postJson("/api/v1/policies/{$p->id}/special-profile", ['kind' => 'FLEET'], $this->h)->assertCreated();
    $this->postJson("/api/v1/policies/{$p->id}/schedule-items", ['item_key' => 'LT-123-AB', 'display_name' => 'Toyota Hilux', 'risk_asset_id' => b86Asset('VEHICLE'),
        'effective_from' => '2026-02-01', 'annual_premium_minor' => 250_000], $this->h)->assertCreated()->assertJsonPath('data.item_type', 'FLEET_VEHICLE');
    $this->postJson("/api/v1/policies/{$p->id}/schedule-items", ['item_key' => 'CE-1', 'display_name' => 'x', 'risk_asset_id' => b86Asset('CARGO'),
        'effective_from' => '2026-02-01'], $this->h)->assertStatus(422);
    $this->postJson("/api/v1/policies/{$p->id}/schedule-items", ['item_type' => 'GROUP_MEMBER', 'item_key' => 'M1', 'display_name' => 'x', 'effective_from' => '2026-02-01'], $this->h)->assertStatus(422);
    expect(DB::table('outbox_messages')->where('event_name', 'fleet_policy.vehicle.added')->count())->toBe(1);

    $closed = b86Policy('MOTOR', status: 'CANCELLED');
    $this->postJson("/api/v1/policies/{$closed->id}/special-profile", ['kind' => 'FLEET'], $this->h)->assertStatus(422);
});

it('REQ-PRD-011 construction and agriculture schedules accept their item types', function () {
    $c = b86Policy('CONSTRUCTION');
    $this->postJson("/api/v1/policies/{$c->id}/special-profile", ['kind' => 'CONSTRUCTION', 'terms' => ['project' => 'Bridge', 'maintenance_months' => 12]], $this->h)->assertCreated();
    $this->postJson("/api/v1/policies/{$c->id}/schedule-items", ['item_key' => 'SITE-A', 'display_name' => 'Contract works section A', 'risk_asset_id' => b86Asset('CONSTRUCTION'),
        'sum_insured_minor' => 500_000_000, 'effective_from' => '2026-03-01'], $this->h)->assertCreated()->assertJsonPath('data.item_type', 'CONSTRUCTION_WORK');

    $a = b86Policy('AGRICULTURE');
    $this->postJson("/api/v1/policies/{$a->id}/special-profile", ['kind' => 'AGRICULTURE'], $this->h)->assertCreated();
    $this->postJson("/api/v1/policies/{$a->id}/schedule-items", ['item_key' => 'P1', 'display_name' => 'Maize plot', 'effective_from' => '2026-03-01'], $this->h)->assertStatus(422); // type required (two allowed)
    $this->postJson("/api/v1/policies/{$a->id}/schedule-items", ['item_type' => 'AGRI_PLOT', 'item_key' => 'P1', 'display_name' => 'Maize plot', 'risk_asset_id' => b86Asset('CROP'),
        'facts' => ['hectares' => 4], 'effective_from' => '2026-03-01'], $this->h)->assertCreated();
    $this->postJson("/api/v1/policies/{$a->id}/schedule-items", ['item_type' => 'AGRI_HERD', 'item_key' => 'H1', 'display_name' => 'Cattle herd', 'risk_asset_id' => b86Asset('CROP'),
        'effective_from' => '2026-03-01'], $this->h)->assertStatus(422);
    expect(PolicyScheduleService::referencedAssetTypes())->toContain('VEHICLE', 'CROP', 'LIVESTOCK', 'CONSTRUCTION');
});

it('REQ-PRD-011 cargo declarations under an open cover: rate, per-shipment + aggregate limits, cancellation', function () {
    $p = b86Policy('CARGO');
    $this->postJson("/api/v1/policies/{$p->id}/special-profile", ['kind' => 'OPEN_COVER', 'terms' => ['rate_bps' => 0]], $this->h)->assertStatus(422);
    $this->postJson("/api/v1/policies/{$p->id}/special-profile", ['kind' => 'OPEN_COVER', 'terms' => ['per_shipment_limit_minor' => 100_000_000, 'aggregate_limit_minor' => 150_000_000,
        'rate_bps' => 35, 'conveyances' => ['SEA', 'ROAD']]], $this->h)->assertCreated();
    $ship = fn (int $v, string $conv = 'SEA') => ['conveyance' => $conv, 'goods_description' => 'Cocoa beans', 'origin' => 'Douala', 'destination' => 'Antwerp',
        'shipment_date' => '2026-04-10', 'insured_value_minor' => $v];

    $d1 = $this->postJson("/api/v1/policies/{$p->id}/cargo-declarations", $ship(80_000_000), $this->h)->assertCreated();
    expect($d1->json('data.premium_minor'))->toBe(280_000)->and($d1->json('data.sequence'))->toBe(1)->and($d1->json('data.reference'))->toEndWith('-D0001');
    $this->postJson("/api/v1/policies/{$p->id}/cargo-declarations", $ship(120_000_000), $this->h)->assertStatus(422); // per shipment
    $this->postJson("/api/v1/policies/{$p->id}/cargo-declarations", $ship(10_000_000, 'AIR'), $this->h)->assertStatus(422); // conveyance
    $this->postJson("/api/v1/policies/{$p->id}/cargo-declarations", $ship(80_000_000), $this->h)->assertStatus(422); // aggregate
    $this->postJson("/api/v1/cargo-declarations/{$d1->json('data.id')}/cancel", ['reason' => 'Shipment did not sail'], $this->h)->assertOk()->assertJsonPath('data.status', 'CANCELLED');
    $this->postJson("/api/v1/policies/{$p->id}/cargo-declarations", $ship(80_000_000), $this->h)->assertCreated()->assertJsonPath('data.sequence', 2);
    $list = $this->getJson("/api/v1/policies/{$p->id}/cargo-declarations", $this->h)->assertOk();
    expect($list->json('data.totals.count'))->toBe(1)->and(count($list->json('data.declarations')))->toBe(2);

    // Declarations only on an OPEN_COVER profile.
    $g = b86Policy();
    $this->postJson("/api/v1/policies/{$g->id}/special-profile", ['kind' => 'GROUP_MASTER'], $this->h)->assertCreated();
    $this->postJson("/api/v1/policies/{$g->id}/cargo-declarations", $ship(1_000), $this->h)->assertStatus(422);
});

it('REQ-PRD-011 life surrender: carrier scale maker-checker, rules engine gate, value calculation', function () {
    $life = \App\Models\InsuranceProduct::create(['carrier_id' => $this->f['carrier']->id, 'line_code' => 'LIFE', 'code' => 'LIFE-'.Str::random(6), 'name' => 'Savings Life', 'version' => 1, 'effective_from' => '2020-01-01', 'status' => 'ACTIVE']);
    $offerId = DB::table('proposals')->where('id', $this->f['proposal']->id)->value('quote_offer_id');
    DB::table('quote_offers')->where('id', $offerId)->update(['product_id' => $life->id]);
    $p = b86Policy('LIFE', '2021-06-01', '2041-05-31');

    $scale = $this->postJson('/api/v1/life/surrender-scales', ['insurance_product_id' => $life->id, 'min_years_in_force' => 2,
        'factors_bps' => ['2' => 6000, '5' => 8000, '10' => 9500], 'surrender_charge_bps' => 500, 'source_reference' => 'ACT-NOTE-2026'], $this->h)->assertCreated();
    $this->postJson("/api/v1/policies/{$p->id}/surrender-quotes", ['basis_minor' => 5_000_000], $this->h)->assertStatus(422); // no active scale
    $this->postJson("/api/v1/life/surrender-scales/{$scale->json('data.id')}/activate", [], $this->h)->assertStatus(422); // maker cannot check
    Passport::actingAs($this->checker);
    $this->postJson("/api/v1/life/surrender-scales/{$scale->json('data.id')}/activate", [], $this->h)->assertOk()->assertJsonPath('data.status', 'ACTIVE');

    $q = $this->postJson("/api/v1/policies/{$p->id}/surrender-quotes", ['as_of' => '2026-09-25', 'basis_minor' => 5_000_000, 'loans_outstanding_minor' => 100_000], $this->h)->assertCreated();
    // 5 full years → 8000 bps: gross 4,000,000; charge 5% = 200,000; loans 100,000 → net 3,700,000
    expect($q->json('data.outcome'))->toBe('ELIGIBLE')->and($q->json('data.years_in_force'))->toBe(5)->and($q->json('data.gross_value_minor'))->toBe(4_000_000)
        ->and($q->json('data.net_value_minor'))->toBe(3_700_000);

    // Min years floor.
    $young = b86Policy('LIFE', '2025-06-01', '2045-05-31');
    $this->postJson("/api/v1/policies/{$young->id}/surrender-quotes", ['as_of' => '2026-09-25', 'basis_minor' => 1_000_000], $this->h)->assertCreated()
        ->assertJsonPath('data.outcome', 'INELIGIBLE')->assertJsonPath('data.net_value_minor', 0)->assertJsonPath('data.reasons.0', 'SURRENDER_MIN_YEARS_NOT_REACHED');

    // Rules engine: an approved LIFE line rule set refers large surrenders to underwriting.
    $setId = (string) Str::uuid();
    DB::table('rule_sets')->insert(['id' => $setId, 'code' => 'LIFE_SURRENDER_LARGE', 'domain' => 'ELIGIBILITY', 'scope_type' => 'LINE', 'line_code' => 'LIFE', 'version' => 1,
        'status' => 'APPROVED', 'effective_from' => '2020-01-01', 'created_at' => now(), 'updated_at' => now()]);
    DB::table('rules')->insert(['id' => (string) Str::uuid(), 'rule_set_id' => $setId, 'code' => 'LARGE_SURRENDER', 'name_en' => 'Large surrender', 'priority' => 10,
        'condition' => json_encode(['op' => 'GT', 'left' => ['fact' => 'surrender.basis_minor'], 'right' => ['value' => 10_000_000]]),
        'outcome' => json_encode(['result' => 'REFER_TO_UNDERWRITING', 'reason_code' => 'SURRENDER_LARGE_AMOUNT']), 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
    $big = $this->postJson("/api/v1/policies/{$p->id}/surrender-quotes", ['as_of' => '2026-09-25', 'basis_minor' => 20_000_000], $this->h)->assertCreated();
    expect($big->json('data.outcome'))->toBe('REFER_TO_UNDERWRITING')->and($big->json('data.requires_review'))->toBeTrue()
        ->and($big->json('data.reasons'))->toContain('SURRENDER_LARGE_AMOUNT')->and($big->json('data.trace.0.rule_set'))->toBe('LIFE_SURRENDER_LARGE');
    expect(DB::table('life_surrender_quotes')->where('policy_id', $p->id)->count())->toBe(2)
        ->and(DB::table('outbox_messages')->where('event_name', 'life_surrender.quoted')->count())->toBe(3);

    // Non-life policy refused.
    DB::table('quote_offers')->where('id', $offerId)->update(['product_id' => $this->f['product']->id]);
    $this->postJson('/api/v1/policies/'.b86Policy('AUTO')->id.'/surrender-quotes', ['basis_minor' => 1], $this->h)->assertStatus(422);
    expect(LifeSurrenderService::factorFor(['2' => 6000, '5' => 8000], 1))->toBe(0)->and(LifeSurrenderService::factorFor(['2' => 6000, '5' => 8000], 12))->toBe(8000);
});

it('REQ-PRD-011 permissions and tenant isolation are enforced; events are catalogued', function () {
    $p = b86Policy();
    $viewer = makeAuthTestUser($this->tenant, ['special_policies.view']);
    Passport::actingAs($viewer);
    $this->postJson("/api/v1/policies/{$p->id}/special-profile", ['kind' => 'GROUP_MASTER'], $this->h)->assertForbidden();

    $other = makeAuthTestTenant('b86other');
    $stranger = makeAuthTestUser($other, B86_ALL);
    Passport::actingAs($stranger);
    $this->postJson("/api/v1/policies/{$p->id}/special-profile", ['kind' => 'GROUP_MASTER'], ['X-Tenant-Id' => $other->id])->assertNotFound();

    foreach (['special_policy.profile.created', 'group_policy.member.added', 'group_policy.member.removed', 'fleet_policy.vehicle.added', 'fleet_policy.vehicle.removed',
        'special_policy.schedule_item.added', 'special_policy.schedule_item.removed', 'cargo_declaration.declared', 'cargo_declaration.cancelled',
        'life_surrender_scale.activated', 'life_surrender.quoted'] as $e) {
        expect(DomainEventCatalogue::has($e))->toBeTrue($e);
    }
});
