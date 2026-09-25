<?php

declare(strict_types=1);

/**
 * Agent E8 — REQ-AML-001 / REQ-KYC-004 PEP / sanctions / watchlist screening: versioned list imports with maker-checker,
 * explainable fuzzy matching, onboarding + periodic rescreen, hit disposition (maker-checker), ComplianceGate at
 * bind / issue / payout (OFF | WARN | ENFORCE).
 */

use App\Application\Claims\ClaimTransitions;
use App\Application\Compliance\Aml\Screening\ComplianceGate;
use App\Application\Compliance\Aml\Screening\Models\ScreeningHit;
use App\Application\Compliance\Aml\Screening\NameMatcher;
use App\Application\Compliance\Aml\Screening\ScreeningListService;
use App\Application\Compliance\Aml\Screening\ScreeningService;
use App\Application\Kyc\Models\ScreeningCheck;
use App\Domain\Claims\ClaimTransitionBlocked;
use App\Interfaces\Http\Errors\ApiProblemException;
use App\Models\Claim;
use App\Models\Party;
use App\Models\Tenant;
use App\Models\TenantCustomer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';
require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';

function e8Mode(Tenant $t, string $mode): void
{
    DB::table('tenants')->where('id', $t->id)->update(['settings' => json_encode(['aml' => ['screening_gate_mode' => $mode]])]);
}

/** Fixture tenant with an ACTIVE sanctions list (fictitious names) imported by a maker and approved by a checker. */
function e8Lists(Tenant $tenant): array
{
    $maker = makeAuthTestUser($tenant, ['aml.screening.lists.manage']);
    $checker = makeAuthTestUser($tenant, ['aml.screening.lists.approve']);
    $svc = app(ScreeningListService::class);
    $src = $svc->createSource($tenant->id, ['code' => 'TEST_SANCTIONS', 'name' => 'Test sanctions list', 'list_type' => 'SANCTIONS'], $maker);
    $csv = "entry_ref,name,aliases,date_of_birth,country\nT-1,Zorblat Quenvik,Zorblat Kvenvik|Z. Quenvik,1970-01-02,ZZ\nT-2,Mirelle Oubangou-Traz,,,\n";
    $v = $svc->import($src, 'CSV', $csv, 'fixture-2026-10', $maker);

    return compact('maker', 'checker', 'src', 'v');
}

function e8Customer(Tenant $tenant, string $name, array $li = []): Party
{
    $p = Party::create(['type' => 'INDIVIDUAL', 'display_name' => $name, 'status' => 'ACTIVE', 'legal_identity' => $li]);
    TenantCustomer::create(['tenant_id' => $tenant->id, 'party_id' => $p->id, 'customer_number' => 'C-'.Str::random(8)]);

    return $p;
}

it('REQ-AML-001 normalises, transliterates and scores names with an explanation', function () {
    $m = new NameMatcher;
    expect($m->normalize('Mr. Zörblat  QUENVIK-jr'))->toBe('zorblat quenvik jr');
    expect($m->normalize('Зорблат Квенвик'))->toBe('zorblat kvenvik');
    $r = $m->compare('Quenvik, Zorblat', 'Zorblat Quenvik');
    expect($r['score'])->toBe(1.0)->and(array_column($r['pairs'], 'method'))->each->toBe('EXACT');
    $fuzzy = $m->compare('Zorblatt Quenvick', 'Zorblat Quenvik');
    expect($fuzzy['score'])->toBeGreaterThan(0.85)->toBeLessThan(1.0)->and($fuzzy['pairs'])->toHaveCount(2);
    expect($m->compare('John Smith', 'Zorblat Quenvik')['score'])->toBeLessThan(0.5);
    $partial = $m->compare('Zorblat', 'Zorblat Quenvik');
    expect($partial['coverage'])->toBe(0.5)->and($partial['score'])->toBe(0.9);
});

it('REQ-AML-001 list import is versioned and needs a different approver; entries are validated', function () {
    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $l = e8Lists($f['tenant']);
    expect($l['v']->status)->toBe('PENDING_APPROVAL')->and($l['v']->entry_count)->toBe(2)->and($l['v']->version)->toBe(1);
    expect(fn () => app(ScreeningListService::class)->decide($l['v'], true, null, $l['maker']))->toThrow(ApiProblemException::class);
    // nothing screened while pending
    expect(app(ScreeningService::class)->hasActiveLists($f['tenant']->id))->toBeFalse();
    $active = app(ScreeningListService::class)->decide($l['v'], true, 'ok', $l['checker']);
    expect($active->status)->toBe('ACTIVE');

    $v2 = app(ScreeningListService::class)->import($l['src'], 'JSON', [['entry_ref' => 'T-9', 'name' => 'Another Fictional']], null, $l['maker']);
    expect($v2->version)->toBe(2);
    app(ScreeningListService::class)->decide($v2, true, null, $l['checker']);
    expect($l['v']->fresh()->status)->toBe('SUPERSEDED');

    expect(fn () => app(ScreeningListService::class)->import($l['src'], 'JSON', [['entry_ref' => 'X', 'name' => '']], null, $l['maker']))
        ->toThrow(ApiProblemException::class);
    expect(DB::table('outbox_messages')->where('event_name', 'aml.screening.list_version_activated')->count())->toBe(2);
});

it('REQ-KYC-004 screens customers at onboarding and on list activation, with explainable hits and no duplicates', function () {
    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $l = e8Lists($f['tenant']);
    $early = e8Customer($f['tenant'], 'Zorblat Quenvik'); // before activation: nothing recorded
    expect(ScreeningCheck::where('party_id', $early->id)->count())->toBe(0);

    app(ScreeningListService::class)->decide($l['v'], true, null, $l['checker']); // rescreens tenant customers (LIST_UPDATE)
    $hit = ScreeningHit::where('party_id', $early->id)->sole();
    expect($hit->entry_ref)->toBe('T-1')->and($hit->status)->toBe('OPEN')->and($hit->score)->toBe(1.0)
        ->and($hit->explanation['source_code'])->toBe('TEST_SANCTIONS')->and($hit->explanation['pairs'])->toHaveCount(2);
    expect(ScreeningCheck::where('party_id', $early->id)->value('trigger'))->toBe('LIST_UPDATE');

    $alias = e8Customer($f['tenant'], 'Other Name', ['full_name' => 'Zorblat Kvenvik', 'date_of_birth' => '1970-01-02']);
    $h2 = ScreeningHit::where('party_id', $alias->id)->sole();
    expect($h2->explanation['matched_on'])->toBe('ALIAS')->and($h2->explanation['dob_match'])->toBeTrue();
    expect(ScreeningCheck::where('party_id', $alias->id)->value('trigger'))->toBe('ONBOARDING');

    $clear = e8Customer($f['tenant'], 'Jane Ordinary');
    expect(ScreeningCheck::where('party_id', $clear->id)->value('status'))->toBe('CLEAR')->and(ScreeningHit::where('party_id', $clear->id)->count())->toBe(0);

    // rescreen does not duplicate the same hit
    app(ScreeningService::class)->screenParty($f['tenant']->id, $early, 'MANUAL_RESCREEN');
    expect(ScreeningHit::where('party_id', $early->id)->count())->toBe(1);
    expect(DB::table('outbox_messages')->where('event_name', 'aml.screening.hit_raised')->count())->toBe(2);
});

it('REQ-AML-001 periodic rescreen only when an interval is configured', function () {
    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $l = e8Lists($f['tenant']);
    app(ScreeningListService::class)->decide($l['v'], true, null, $l['checker']);
    e8Customer($f['tenant'], 'Jane Ordinary');
    config(['aml.screening.rescreen_interval_days' => null]);
    expect(app(ScreeningService::class)->rescreenDue(now()->addYear()))->toBe(0);
    config(['aml.screening.rescreen_interval_days' => 30]);
    expect(app(ScreeningService::class)->rescreenDue(now()->addDays(10)))->toBe(0);
    expect(app(ScreeningService::class)->rescreenDue(now()->addDays(31)))->toBeGreaterThanOrEqual(1);
});

it('REQ-AML-001 disposition is maker-checker and drives the ComplianceGate at bind, issue and payout', function () {
    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $l = e8Lists($f['tenant']);
    app(ScreeningListService::class)->decide($l['v'], true, null, $l['checker']);
    DB::table('parties')->where('id', $f['party']->id)->update(['display_name' => 'Zorblat Quenvik']);
    TenantCustomer::create(['tenant_id' => $f['tenant']->id, 'party_id' => $f['party']->id, 'customer_number' => 'C-'.Str::random(8)]);
    $hit = ScreeningHit::where('party_id', $f['party']->id)->sole();
    $gate = app(ComplianceGate::class);

    // OFF (default) never blocks; WARN audits; ENFORCE blocks.
    expect($gate->blockingReason($f['tenant']->id, [$f['party']->id], 'BIND', 'proposal', $f['proposal']->id))->toBeNull();
    e8Mode($f['tenant'], 'WARN');
    expect($gate->blockingReason($f['tenant']->id, [$f['party']->id], 'BIND', 'proposal', $f['proposal']->id))->toBeNull();
    expect(DB::table('audit_events')->where('action', 'aml.screening.gate.warned')->exists())->toBeTrue();
    e8Mode($f['tenant'], 'ENFORCE');
    expect(fn () => $gate->assertMayProceed($f['tenant']->id, [$f['party']->id], 'ISSUE', 'proposal', $f['proposal']->id))->toThrow(ApiProblemException::class);

    // Claims payout guard
    $policy = (string) Str::uuid();
    DB::table('policies')->insert(['id' => $policy, 'tenant_id' => $f['tenant']->id, 'proposal_id' => $f['proposal']->id, 'carrier_id' => $f['carrier']->id, 'party_id' => $f['party']->id,
        'status' => 'ACTIVE', 'coverage_starts_at' => now()->subMonth(), 'coverage_ends_at' => now()->addYear(), 'issued_at' => now()->subMonth(),
        'terms_snapshot' => '{}', 'version' => 1, 'currency' => 'XAF', 'premium_minor' => 1000, 'is_demo' => false, 'created_at' => now(), 'updated_at' => now()]);
    $cid = (string) Str::uuid();
    DB::table('claims')->insert(['id' => $cid, 'tenant_id' => $f['tenant']->id, 'policy_id' => $policy, 'claimant_party_id' => null, 'claim_number' => 'CLM-'.Str::random(8),
        'status' => 'APPROVED', 'loss_occurred_at' => now()->subDays(3), 'loss_details' => '{}', 'currency' => 'XAF', 'priority' => 'NORMAL', 'version' => 1, 'is_demo' => false,
        'created_at' => now(), 'updated_at' => now()]);
    $claim = Claim::findOrFail($cid);
    $transitions = app(ClaimTransitions::class);
    expect(fn () => $transitions->guard($claim, 'settle', []))->toThrow(ClaimTransitionBlocked::class);
    $transitions->guard($claim, 'approve', []); // other events are not screened

    // maker-checker disposition
    $maker = makeAuthTestUser($f['tenant'], ['aml.screening.disposition.propose']);
    $checker = makeAuthTestUser($f['tenant'], ['aml.screening.disposition.approve']);
    $svc = app(ScreeningService::class);
    $svc->propose($hit, 'FALSE_POSITIVE', 'Different person: date of birth and nationality differ.', $maker);
    expect(fn () => $svc->decide($hit->fresh(), true, null, $maker))->toThrow(ApiProblemException::class);
    expect($gate->blockingReason($f['tenant']->id, [$f['party']->id], 'BIND', 'proposal', $f['proposal']->id))->toBe('AML_SCREENING_HOLD'); // PROPOSED still blocks
    $done = $svc->decide($hit->fresh(), true, 'agreed', $checker);
    expect($done->status)->toBe('DISPOSED')->and($done->disposition)->toBe('FALSE_POSITIVE');
    expect($gate->blockingReason($f['tenant']->id, [$f['party']->id], 'BIND', 'proposal', $f['proposal']->id))->toBeNull();
    $transitions->guard($claim, 'settle', []);

    // TRUE_MATCH blocks
    $h = ScreeningHit::create($hit->only(['tenant_id', 'party_id', 'source_id', 'version_id', 'entry_id', 'entry_ref', 'list_type', 'matched_name', 'party_name', 'score'])
        + ['entry_hash' => 'x', 'explanation' => [], 'status' => 'OPEN']);
    $svc->propose($h, 'TRUE_MATCH', 'Confirmed.', $maker);
    $svc->decide($h->fresh(), true, null, $checker);
    expect($gate->blockingReason($f['tenant']->id, [$f['party']->id], 'PAYOUT', 'claim', $cid))->toBe('AML_SCREENING_HOLD');
    expect(DB::table('outbox_messages')->where('event_name', 'aml.screening.hit_disposed')->count())->toBe(2);
});

it('REQ-AML-001 API: import, approve, list hits and dispose with permissions', function () {
    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $h = tenantHeaderFor($f['tenant']);
    $maker = makeAuthTestUser($f['tenant'], ['aml.screening.view', 'aml.screening.lists.manage', 'aml.screening.run', 'aml.screening.disposition.propose']);
    $checker = makeAuthTestUser($f['tenant'], ['aml.screening.view', 'aml.screening.lists.approve', 'aml.screening.disposition.approve']);
    $party = e8Customer($f['tenant'], 'Mirelle Oubangou Traz');

    Passport::actingAs($maker);
    $src = $this->postJson('/api/v1/aml/screening/lists', ['code' => 'PEP_TEST', 'name' => 'PEP test', 'list_type' => 'PEP'], $h)->assertCreated()->json('data.id');
    $ver = $this->postJson("/api/v1/aml/screening/lists/{$src}/versions", ['format' => 'JSON', 'entries' => [['entry_ref' => 'P-1', 'name' => 'Mirelle Oubangou-Traz']]], $h)
        ->assertCreated()->assertJsonPath('data.status', 'PENDING_APPROVAL')->json('data.id');
    $this->postJson("/api/v1/aml/screening/list-versions/{$ver}/decide", ['decision' => 'APPROVE'], $h)->assertForbidden();

    Passport::actingAs($checker);
    $this->postJson("/api/v1/aml/screening/list-versions/{$ver}/decide", ['decision' => 'APPROVE'], $h)->assertOk()->assertJsonPath('data.status', 'ACTIVE');
    $hitId = $this->getJson('/api/v1/aml/screening/hits?status=OPEN', $h)->assertOk()->assertJsonPath('data.0.party_id', $party->id)->json('data.0.id');
    $this->getJson("/api/v1/aml/screening/parties/{$party->id}/status", $h)->assertOk()->assertJsonPath('data.blocked', true);

    Passport::actingAs($maker);
    $this->postJson("/api/v1/aml/screening/parties/{$party->id}/screen", [], $h)->assertOk()->assertJsonPath('data.screened', true);
    $this->postJson("/api/v1/aml/screening/hits/{$hitId}/disposition", ['disposition' => 'ESCALATED', 'rationale' => 'Needs MLRO review.'], $h)->assertOk()->assertJsonPath('data.status', 'PROPOSED');
    Passport::actingAs($checker);
    $this->postJson("/api/v1/aml/screening/hits/{$hitId}/disposition/decide", ['decision' => 'APPROVE'], $h)->assertOk()->assertJsonPath('data.disposition', 'ESCALATED');
    $this->getJson("/api/v1/aml/screening/parties/{$party->id}/status", $h)->assertOk()->assertJsonPath('data.blocked', true);
});
