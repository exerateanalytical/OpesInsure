<?php

declare(strict_types=1);

/**
 * Agent E11 — REQ-CAT-001 exposure zones / risk locations / accumulation gross + net / snapshots,
 * REQ-CAT-002 capacity check (LOCK-019) at POST /capacity/check and in the underwriting evaluate step,
 * REQ-CAT-003 catastrophe events (declare, link claims, aggregate, recoverable by event id) + large-loss notifier.
 */

use App\Application\Accumulation\ExposureService;
use App\Application\Underwriting\UnderwritingDecisionService;
use App\Models\Claim;
use App\Models\Policy;
use App\Models\ProposalSubmission;
use App\Models\UnderwritingCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';
require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';

uses(RefreshDatabase::class);

const CAT_ALL = ['accumulation.view', 'accumulation.manage', 'accumulation.capacity.check', 'catastrophe.events.view', 'catastrophe.events.manage', 'claims.view',
    'reinsurance.treaties.view', 'reinsurance.treaties.manage', 'reinsurance.reinsurers.manage', 'reinsurance.cessions.view', 'reinsurance.cessions.calculate'];

beforeEach(function () {
    $this->f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $this->tenant = $this->f['tenant'];
    $this->h = ['X-Tenant-Id' => $this->tenant->id];
    $this->user = makeAuthTestUser($this->tenant, CAT_ALL);
    $this->checker = makeAuthTestUser($this->tenant, ['reinsurance.treaties.view', 'reinsurance.treaties.approve']);
    Passport::actingAs($this->user, [], 'api');
});

function catPolicy(int $sum, array $location, array $perils = ['FLOOD']): Policy
{
    $f = test()->f;

    return Policy::create(['tenant_id' => $f['tenant']->id, 'proposal_id' => $f['proposal']->id, 'carrier_id' => $f['carrier']->id, 'party_id' => $f['party']->id,
        'policy_number' => 'POL-'.Str::upper(Str::random(8)), 'status' => 'ACTIVE', 'coverage_starts_at' => now()->subMonth(), 'coverage_ends_at' => now()->addYear(),
        'terms_snapshot' => ['line_code' => 'PROPERTY', 'sum_insured_minor' => $sum, 'risk_location' => $location, 'perils' => $perils], 'version' => 1, 'currency' => 'XAF',
        'premium_minor' => 100_000, 'issued_at' => now()]);
}

function catZone(string $code, array $geo, ?array $polygon = null): string
{
    return test()->postJson('/api/v1/accumulation/zones', ['code' => $code, 'name' => $code, 'country_code' => 'CM', 'geography_codes' => $geo, 'polygon' => $polygon], test()->h)
        ->assertCreated()->json('data.id');
}

function catQuotaShare(int $percent): void
{
    $re = test()->postJson('/api/v1/reinsurance/reinsurers', ['code' => 'RE'.$percent, 'name' => 'Re', 'country_code' => 'FR'], test()->h)->assertCreated()->json('data.id');
    $t = test()->postJson('/api/v1/reinsurance/treaties', ['code' => 'QS'.$percent, 'name' => 'QS', 'treaty_type' => 'QUOTA_SHARE', 'currency' => 'XAF'], test()->h)->assertCreated()->json('data.id');
    $v = test()->postJson("/api/v1/reinsurance/treaties/{$t}/versions", ['effective_from' => '2020-01-01', 'cession_percent' => $percent,
        'participants' => [['reinsurer_id' => $re, 'share_percent' => 100, 'is_lead' => true]]], test()->h)->assertCreated()->json('data.id');
    Passport::actingAs(test()->checker, [], 'api');
    test()->postJson("/api/v1/reinsurance/treaty-versions/{$v}/activate", ['reason' => 'Signed'], test()->h)->assertOk();
    Passport::actingAs(test()->user, [], 'api');
}

it('REQ-CAT-001: resolves risk locations to zones (polygon, then geography) and accumulates gross and net of cessions per zone / peril', function () {
    $douala = catZone('DLA', ['Littoral', 'Douala'], [[3.9, 9.5], [3.9, 9.9], [4.2, 9.9], [4.2, 9.5]]);
    $yaounde = catZone('YDE', ['Centre']);
    catQuotaShare(40);
    $p1 = catPolicy(100_000_000, ['latitude' => 4.05, 'longitude' => 9.7]);       // polygon
    catPolicy(50_000_000, ['region' => 'littoral'], ['FLOOD', 'WINDSTORM']);        // geography, case-insensitive
    catPolicy(20_000_000, ['region' => 'Centre']);
    catPolicy(5_000_000, ['region' => 'Nowhere']);                                   // unresolved
    $this->postJson("/api/v1/reinsurance/policies/{$p1->id}/cessions", [], $this->h)->assertSuccessful();

    $this->postJson('/api/v1/accumulation/locations/rebuild', [], $this->h)->assertOk()->assertJsonPath('data.locations', 4)->assertJsonPath('data.unresolved', 1);
    expect(DB::table('accumulation_risk_locations')->where('policy_id', $p1->id)->value('resolved_by'))->toBe('POLYGON');

    $lines = collect($this->getJson('/api/v1/accumulation', $this->h)->assertOk()->json('data'));
    $flood = $lines->first(fn ($l) => $l['zone_id'] === $douala && $l['peril_code'] === 'FLOOD');
    expect($flood['gross_minor'])->toBe(150_000_000)->and($flood['net_minor'])->toBe(60_000_000 + 50_000_000)->and($flood['location_count'])->toBe(2)
        ->and($lines->first(fn ($l) => $l['zone_id'] === $douala && $l['peril_code'] === 'WINDSTORM')['gross_minor'])->toBe(50_000_000)
        ->and($lines->first(fn ($l) => $l['zone_id'] === $yaounde)['gross_minor'])->toBe(20_000_000);

    // Point-in-time snapshot is frozen.
    $snap = $this->postJson('/api/v1/accumulation/snapshots', ['currency' => 'XAF'], $this->h)->assertCreated()->json('data');
    expect($snap['gross_total_minor'])->toBe(175_000_000)->and($snap['unresolved_count'])->toBe(1)->and($snap['lines'])->not->toBeEmpty();
    catPolicy(1_000_000, ['region' => 'Centre']);
    $this->postJson('/api/v1/accumulation/locations/rebuild', [], $this->h)->assertOk();
    expect($this->getJson("/api/v1/accumulation/snapshots/{$snap['id']}", $this->h)->assertOk()->json('data.gross_total_minor'))->toBe(175_000_000)
        ->and(DB::table('outbox_messages')->where('event_name', 'accumulation.snapshot.taken')->exists())->toBeTrue();
    expect(ExposureService::inPolygon(5.0, 9.7, [[3.9, 9.5], [3.9, 9.9], [4.2, 9.9], [4.2, 9.5]]))->toBeFalse();
});

it('REQ-CAT-002: capacity check returns the four LOCK-019 outcomes and NOT_CONFIGURED without limits', function () {
    $zone = catZone('DLA', ['Littoral']);
    catPolicy(60_000_000, ['region' => 'Littoral']);
    $this->postJson('/api/v1/accumulation/locations/rebuild', [], $this->h)->assertOk();
    $check = fn (int $sum) => $this->postJson('/api/v1/capacity/check', ['location' => ['region' => 'Littoral'], 'peril_code' => 'FLOOD', 'sum_insured_minor' => $sum, 'currency' => 'XAF'], $this->h)
        ->assertOk()->json('data');

    expect($check(10_000_000)['result'])->toBe('NOT_CONFIGURED');

    $this->postJson('/api/v1/accumulation/capacity-limits', ['zone_id' => $zone, 'peril_code' => 'FLOOD', 'currency' => 'XAF'], $this->h)->assertUnprocessable();
    $this->postJson('/api/v1/accumulation/capacity-limits', ['zone_id' => $zone, 'peril_code' => 'FLOOD', 'currency' => 'XAF',
        'retention_limit_minor' => 90_000_000, 'gross_limit_minor' => 200_000_000], $this->h)->assertCreated();

    expect($check(10_000_000))->toMatchArray(['result' => 'WITHIN_RETENTION', 'current_gross_minor' => 60_000_000, 'zone_id' => $zone]);
    expect($check(50_000_000)['result'])->toBe('FACULTATIVE_REQUIRED'); // no treaty yet
    catQuotaShare(50);
    expect($check(50_000_000))->toMatchArray(['result' => 'TREATY_COVERED', 'treaty_absorbed_minor' => 25_000_000]);
    expect($check(150_000_000)['result'])->toBe('CAPACITY_EXCEEDED');
    expect(DB::table('accumulation_capacity_checks')->count())->toBe(5)
        ->and(DB::table('outbox_messages')->where('event_name', 'accumulation.capacity.breached')->count())->toBe(2);

    $viewer = makeAuthTestUser($this->tenant, ['accumulation.view']);
    Passport::actingAs($viewer, [], 'api');
    $this->postJson('/api/v1/capacity/check', ['sum_insured_minor' => 1, 'currency' => 'XAF'], $this->h)->assertForbidden();
});

it('REQ-CAT-002: the underwriting evaluate step adds the capacity factor and refers a breach', function () {
    $zone = catZone('DLA', ['Littoral']);
    DB::table('accumulation_capacity_limits')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'zone_id' => $zone, 'peril_code' => 'ALL', 'currency' => 'XAF',
        'retention_limit_minor' => 10_000_000, 'gross_limit_minor' => 20_000_000, 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
    $f = $this->f;
    $case = UnderwritingCase::create(['tenant_id' => $this->tenant->id, 'proposal_id' => $f['proposal']->id, 'carrier_id' => $f['carrier']->id, 'status' => 'QUEUED']);
    $snap = ['product_id' => $f['product']->id, 'line_code' => 'PROPERTY', 'risk_facts' => ['region' => 'Littoral', 'sum_insured_minor' => 50_000_000], 'terms' => ['currency' => 'XAF']];
    ProposalSubmission::create(['proposal_id' => $f['proposal']->id, 'sequence' => 1, 'kind' => 'SUBMIT', 'snapshot' => $snap, 'snapshot_hash' => str_repeat('a', 64), 'submitted_at' => now()]);

    $ev = app(UnderwritingDecisionService::class)->evaluate($case);
    expect($ev['recommendation'])->toBe('REFER')->and($ev['reasons'])->toContain('CAPACITY:CAPACITY_EXCEEDED')
        ->and($ev['capacity']['result'])->toBe('CAPACITY_EXCEEDED')->and($ev['capacity']['zone_id'])->toBe($zone);
});

it('REQ-CAT-003: declares an event, links in-window claims, aggregates losses with XL recoverable by event id, and notifies large losses once', function () {
    $zone = catZone('DLA', ['Littoral']);
    $policy = catPolicy(100_000_000, ['region' => 'Littoral']);
    $re = $this->postJson('/api/v1/reinsurance/reinsurers', ['code' => 'XLRE', 'name' => 'Re'], $this->h)->assertCreated()->json('data.id');
    $t = $this->postJson('/api/v1/reinsurance/treaties', ['code' => 'CATXL', 'name' => 'Cat XL', 'treaty_type' => 'EXCESS_OF_LOSS', 'currency' => 'XAF'], $this->h)->assertCreated()->json('data.id');
    $v = $this->postJson("/api/v1/reinsurance/treaties/{$t}/versions", ['effective_from' => '2020-01-01',
        'layers' => [['layer' => 1, 'attachment_minor' => 10_000_000, 'limit_minor' => 20_000_000, 'rate_percent' => 5]],
        'participants' => [['reinsurer_id' => $re, 'share_percent' => 100, 'is_lead' => true]]], $this->h)->assertCreated()->json('data.id');
    Passport::actingAs($this->checker, [], 'api');
    $this->postJson("/api/v1/reinsurance/treaty-versions/{$v}/activate", ['reason' => 'Signed'], $this->h)->assertOk();
    Passport::actingAs($this->user, [], 'api');

    $mk = fn (int $loss, string $at) => Claim::create(['tenant_id' => $this->tenant->id, 'policy_id' => $policy->id, 'claim_number' => 'CLM-'.Str::random(8), 'status' => 'SUBMITTED',
        'loss_occurred_at' => $at, 'loss_details' => [], 'currency' => 'XAF', 'current_reserve_minor' => $loss]);
    $c1 = $mk(15_000_000, '2026-09-10 12:00:00');
    $c2 = $mk(8_000_000, '2026-09-11 08:00:00');
    $late = $mk(1_000, '2026-10-30 08:00:00');

    $recipient = makeAuthTestUser($this->tenant, ['claims.view']);
    $this->postJson('/api/v1/large-loss/threshold', ['currency' => 'XAF', 'threshold_minor' => 10_000_000, 'recipient_user_ids' => [$recipient->id]], $this->h)->assertOk();

    $event = $this->postJson('/api/v1/catastrophe-events', ['code' => 'FLOOD-DLA-2026', 'name' => 'Douala floods', 'peril_code' => 'flood', 'zone_ids' => [$zone],
        'starts_at' => '2026-09-09T00:00:00Z', 'ends_at' => '2026-09-15T00:00:00Z', 'currency' => 'XAF'], $this->h)->assertCreated()->json('data.id');
    $this->postJson("/api/v1/catastrophe-events/{$event}/claims", ['claim_id' => $c1->id], $this->h)->assertOk();
    $this->postJson("/api/v1/catastrophe-events/{$event}/claims", ['claim_id' => $c2->id], $this->h)->assertOk();
    $this->postJson("/api/v1/catastrophe-events/{$event}/claims", ['claim_id' => $c1->id], $this->h)->assertUnprocessable();
    $this->postJson("/api/v1/catastrophe-events/{$event}/claims", ['claim_id' => $late->id], $this->h)->assertUnprocessable();

    // Large loss: c1 (15M) notified once, c2 (8M) below threshold.
    expect(DB::table('large_loss_notifications')->pluck('claim_id')->all())->toBe([$c1->id])
        ->and(DB::table('user_notifications')->where('user_id', $recipient->id)->count())->toBe(1);
    $this->postJson("/api/v1/claims/{$c1->id}/large-loss-check", [], $this->h)->assertOk()->assertJsonPath('data.reason', 'ALREADY_NOTIFIED');

    $agg = $this->postJson("/api/v1/catastrophe-events/{$event}/aggregate", [], $this->h)->assertOk()->json('data');
    expect($agg['gross_loss_minor'])->toBe(23_000_000)->and($agg['recoverable_minor'])->toBe(13_000_000)->and($agg['net_loss_minor'])->toBe(10_000_000)
        ->and($agg['claims'])->toHaveCount(2)
        ->and(DB::table('claims')->where('id', $c1->id)->value('catastrophe_event_id'))->toBe($event);
    $msg = DB::table('outbox_messages')->where('event_name', 'catastrophe.event.losses_aggregated')->first();
    expect(json_decode($msg->payload, true)['event_id'])->toBe($event);

    $this->postJson("/api/v1/catastrophe-events/{$event}/close", ['reason' => 'Event over'], $this->h)->assertOk()->assertJsonPath('data.status', 'CLOSED');
    $this->postJson("/api/v1/catastrophe-events/{$event}/claims", ['claim_id' => $late->id], $this->h)->assertUnprocessable();
});
