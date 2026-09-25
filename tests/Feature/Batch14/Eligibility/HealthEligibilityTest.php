<?php

declare(strict_types=1);

/** Batch 14A (agent E2) — REQ-HLT-001 health eligibility, members, digital health card + provider scan. */

use App\Application\Health\Eligibility\EligibilityService;
use App\Application\Health\Eligibility\HealthCardService;
use App\Models\Policy;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';
require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';

uses(RefreshDatabase::class);

const HLT_ALL = ['health.members.view', 'health.members.manage', 'health.cards.issue', 'health.benefits.manage', 'health.eligibility.check', 'health.eligibility.scan',
    'health.eligibility.view', 'providers.view', 'providers.manage', 'providers.credential', 'provider_networks.view', 'provider_networks.manage',
    'special_policies.manage', 'special_policies.schedule.manage'];

beforeEach(function () {
    $this->f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $this->tenant = $this->f['tenant'];
    $this->h = ['X-Tenant-ID' => $this->tenant->id];
    $this->admin = makeAuthTestUser($this->tenant, HLT_ALL);
    Passport::actingAs($this->admin, [], 'api');

    // policy + a version in force with an OUTPATIENT coverage (and no MATERNITY coverage)
    $this->policy = Policy::create(['tenant_id' => $this->tenant->id, 'proposal_id' => $this->f['proposal']->id, 'carrier_id' => $this->f['carrier']->id, 'party_id' => $this->f['party']->id,
        'policy_number' => 'POL-'.Str::upper(Str::random(8)), 'status' => 'ACTIVE', 'coverage_starts_at' => '2026-01-01', 'coverage_ends_at' => '2026-12-31',
        'terms_snapshot' => ['line_code' => 'HEALTH'], 'version' => 1, 'currency' => 'XAF', 'premium_minor' => 1_000_000, 'issued_at' => now()]);
    $this->versionId = (string) Str::uuid();
    DB::table('policy_versions')->insert(['id' => $this->versionId, 'tenant_id' => $this->tenant->id, 'policy_id' => $this->policy->id, 'version_no' => 1, 'kind' => 'ISSUANCE',
        'valid_from' => '2026-01-01', 'recorded_at' => now()->subDay(), 'snapshot' => '{}', 'snapshot_hash' => str_repeat('0', 64), 'created_at' => now(), 'updated_at' => now()]);
    $this->coverageId = (string) Str::uuid();
    DB::table('policy_coverages')->insert(['id' => $this->coverageId, 'policy_id' => $this->policy->id, 'policy_version_id' => $this->versionId, 'coverage_code' => 'OUTPATIENT',
        'limit_minor' => 500_000, 'deductible_minor' => 0, 'currency' => 'XAF', 'starts_at' => '2026-01-01', 'ends_at' => '2026-12-31', 'created_at' => now(), 'updated_at' => now()]);

    // catalogue + benefit schedule
    $this->postJson('/api/v1/medical-services', ['code' => 'CONS_GP', 'name' => 'GP consultation', 'category_code' => 'OUTPATIENT'], $this->h)->assertCreated();
    $this->postJson('/api/v1/medical-services', ['code' => 'DELIVERY', 'name' => 'Delivery', 'category_code' => 'MATERNITY'], $this->h)->assertCreated();
    $this->postJson('/api/v1/medical-services', ['code' => 'XRAY', 'name' => 'X-ray', 'category_code' => 'IMAGING'], $this->h)->assertCreated();
    $this->postJson('/api/v1/health-benefit-rules', ['service_category_code' => 'OUTPATIENT', 'coverage_code' => 'OUTPATIENT', 'benefit_code' => 'OP_CONSULT'], $this->h)->assertCreated();
    $this->postJson('/api/v1/health-benefit-rules', ['policy_id' => $this->policy->id, 'medical_service_code' => 'DELIVERY', 'coverage_code' => 'MATERNITY', 'benefit_code' => 'MAT'], $this->h)->assertCreated();

    // provider in a HEALTH network linked to the policy
    $this->provider = $this->postJson('/api/v1/providers', ['category' => 'HEALTH', 'name' => 'Clinique Bonanjo', 'provider_type_code' => 'CLINIC'], $this->h)->assertCreated()->json('data.id');
    foreach (['APPLICATION', 'UNDER_REVIEW', 'APPROVED', 'ACTIVE'] as $to) {
        $this->postJson("/api/v1/providers/{$this->provider}/credentialing", ['to' => $to], $this->h)->assertOk();
    }
    $this->network = $this->postJson('/api/v1/provider-networks', ['code' => 'GOLD', 'name' => 'Réseau Or', 'network_type_code' => 'PREFERRED'], $this->h)->assertCreated()->json('data.id');
    $this->postJson("/api/v1/provider-networks/{$this->network}/members", ['provider_id' => $this->provider, 'effective_from' => '2026-01-01'], $this->h)->assertCreated();
    $this->postJson("/api/v1/policies/{$this->policy->id}/health-networks", ['provider_network_id' => $this->network], $this->h)->assertCreated();

    $this->principal = $this->postJson("/api/v1/policies/{$this->policy->id}/health-members", ['relationship' => 'PRINCIPAL', 'display_name' => 'Awa Ngono Bella', 'effective_from' => '2026-01-01'], $this->h)
        ->assertCreated()->json('data');
});

function hltCheck(string $ref, ?string $provider, string $service = 'CONS_GP', string $date = '2026-03-10'): array
{
    return test()->postJson('/api/v1/health/eligibility/check', array_filter(['member_ref' => $ref, 'provider_id' => $provider, 'service_code' => $service, 'service_date' => $date]), test()->h)
        ->assertOk()->json('data');
}

it('REQ-HLT-001: principal + dependant are ELIGIBLE in network at the service date; every check is logged immutably', function () {
    $dep = $this->postJson("/api/v1/policies/{$this->policy->id}/health-members", ['relationship' => 'CHILD', 'display_name' => 'Junior Bella', 'principal_member_id' => $this->principal['id'], 'effective_from' => '2026-02-01'], $this->h)
        ->assertCreated()->json('data');
    $this->postJson("/api/v1/policies/{$this->policy->id}/health-members", ['relationship' => 'CHILD', 'display_name' => 'Orphan'], $this->h)->assertStatus(422);
    $this->postJson("/api/v1/policies/{$this->policy->id}/health-members", ['relationship' => 'PRINCIPAL'], $this->h)->assertStatus(409);

    $r = hltCheck($this->principal['member_number'], $this->provider);
    expect($r['outcome'])->toBe('ELIGIBLE')->and($r['reasons'])->toBe([])->and($r['policy_version_id'])->toBe($this->versionId)
        ->and($r['coverage']['code'])->toBe('OUTPATIENT')->and($r['benefit_code'])->toBe('OP_CONSULT');
    expect(hltCheck($dep['card_number'], $this->provider)['outcome'])->toBe('ELIGIBLE');
    // before the dependant joined
    $early = hltCheck($dep['member_number'], $this->provider, 'CONS_GP', '2026-01-15');
    expect($early['outcome'])->toBe('NOT_ELIGIBLE')->and(array_column($early['reasons'], 'code'))->toContain('MEMBER_NOT_COVERED_ON_DATE');
    // unknown member
    expect(hltCheck('HM-NOPE', $this->provider)['reasons'][0]['code'])->toBe('MEMBER_NOT_FOUND');

    expect(DB::table('health_eligibility_checks')->count())->toBe(4)
        ->and(DB::table('health_eligibility_checks')->where('health_member_id', $dep['id'])->count())->toBe(2);
    expect(fn () => DB::transaction(fn () => DB::table('health_eligibility_checks')->update(['outcome' => 'ELIGIBLE'])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('health_eligibility_checks')->delete()))->toThrow(QueryException::class);
    $this->getJson("/api/v1/health-members/{$dep['id']}/eligibility-checks", $this->h)->assertOk()->assertJsonCount(2, 'data');
});

it('REQ-HLT-001: coverage not in the version, unmapped service, out-of-network provider, waiting period, exhausted benefit', function () {
    $ref = $this->principal['member_number'];

    $mat = hltCheck($ref, $this->provider, 'DELIVERY');
    expect($mat['outcome'])->toBe('NOT_ELIGIBLE')->and(array_column($mat['reasons'], 'code'))->toContain('COVERAGE_NOT_IN_FORCE');

    $img = hltCheck($ref, $this->provider, 'XRAY');
    expect($img['outcome'])->toBe('REVIEW_REQUIRED')->and($img['reasons'][0]['code'])->toBe('BENEFIT_NOT_MAPPED');

    $other = $this->postJson('/api/v1/providers', ['category' => 'HEALTH', 'name' => 'Clinique Hors Réseau', 'provider_type_code' => 'CLINIC'], $this->h)->json('data.id');
    expect(hltCheck($ref, $other)['outcome'])->toBe('PROVIDER_NOT_IN_NETWORK');

    // policy-specific waiting period beats the tenant default rule
    $this->postJson('/api/v1/health-benefit-rules', ['policy_id' => $this->policy->id, 'service_category_code' => 'OUTPATIENT', 'coverage_code' => 'OUTPATIENT', 'benefit_code' => 'OP_CONSULT', 'waiting_period_days' => 90], $this->h)->assertCreated();
    $w = hltCheck($ref, $this->provider, 'CONS_GP', '2026-02-15');
    expect($w['outcome'])->toBe('WAITING_PERIOD')->and($w['waiting_period_ends_on'])->toBe('2026-04-01');
    expect(hltCheck($ref, $this->provider, 'CONS_GP', '2026-04-01')['outcome'])->toBe('ELIGIBLE');

    // aggregate limit fully consumed → BENEFIT_EXHAUSTED (fallback when no BenefitAccumulator)
    if (! class_exists(\App\Application\Health\Benefits\BenefitAccumulator::class)) {
        DB::table('policy_limits')->insert(['id' => (string) Str::uuid(), 'policy_id' => $this->policy->id, 'policy_version_id' => $this->versionId, 'policy_coverage_id' => $this->coverageId,
            'limit_type' => 'AGGREGATE', 'amount_minor' => 100_000, 'consumed_minor' => 100_000, 'currency' => 'XAF', 'created_at' => now(), 'updated_at' => now()]);
        expect(hltCheck($ref, $this->provider, 'CONS_GP', '2026-05-01')['outcome'])->toBe('BENEFIT_EXHAUSTED');
    }
});

it('REQ-HLT-001: policy suspension, lapse and service outside the policy period are NOT_ELIGIBLE', function () {
    $ref = $this->principal['member_number'];
    DB::table('policy_suspensions')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'policy_id' => $this->policy->id, 'status' => 'REINSTATED', 'source' => 'MANUAL',
        'reason_code' => 'PREMIUM_DEFAULT', 'suspended_at' => '2026-05-01 00:00:00', 'reinstated_at' => '2026-06-01 00:00:00', 'created_at' => now(), 'updated_at' => now()]);
    expect(array_column(hltCheck($ref, $this->provider, 'CONS_GP', '2026-05-15')['reasons'], 'code'))->toContain('POLICY_SUSPENDED');
    expect(hltCheck($ref, $this->provider, 'CONS_GP', '2026-06-15')['outcome'])->toBe('ELIGIBLE');
    expect(array_column(hltCheck($ref, $this->provider, 'CONS_GP', '2027-02-01')['reasons'], 'code'))->toContain('OUTSIDE_POLICY_PERIOD');

    $this->policy->update(['status' => 'LAPSED']);
    expect(array_column(hltCheck($ref, $this->provider)['reasons'], 'code'))->toContain('POLICY_LAPSED');
});

it('REQ-HLT-001: group members come from the Batch 8-6 group schedule and follow its dates', function () {
    $this->postJson("/api/v1/policies/{$this->policy->id}/special-profile", ['kind' => 'GROUP_MASTER', 'terms' => ['scheme' => 'STAFF']], $this->h)->assertCreated();
    $item = $this->postJson("/api/v1/policies/{$this->policy->id}/schedule-items", ['item_key' => 'EMP-001', 'display_name' => 'Paul Mbarga', 'annual_premium_minor' => 36_400, 'effective_from' => '2026-03-01'], $this->h)
        ->assertCreated()->json('data.id');
    $gm = $this->postJson("/api/v1/policies/{$this->policy->id}/health-members", ['relationship' => 'GROUP_MEMBER', 'schedule_item_id' => $item], $this->h)->assertCreated()->json('data');
    expect($gm['display_name'])->toBe('Paul Mbarga')->and($gm['effective_from'])->toStartWith('2026-03-01');
    $this->postJson("/api/v1/policies/{$this->policy->id}/health-members", ['relationship' => 'GROUP_MEMBER', 'schedule_item_id' => $item], $this->h)->assertStatus(409);

    expect(hltCheck($gm['member_number'], $this->provider, 'CONS_GP', '2026-04-01')['outcome'])->toBe('ELIGIBLE');
    $this->postJson("/api/v1/policy-schedule-items/{$item}/remove", ['effective_until' => '2026-06-01', 'reason' => 'Left the company'], $this->h)->assertOk();
    expect(array_column(hltCheck($gm['member_number'], $this->provider, 'CONS_GP', '2026-07-01')['reasons'], 'code'))->toContain('GROUP_MEMBER_NOT_ON_SCHEDULE');
});

it('REQ-HLT-001: digital health card — signed QR, provider scan with minimal disclosure, reissue revokes, tampering refused', function () {
    $card = $this->postJson("/api/v1/health-members/{$this->principal['id']}/card", [], $this->h)->assertCreated()->json('data');
    expect($card['qr_payload'])->toStartWith('OIHC1.')->and(DB::table('health_member_cards')->value('token_hash'))->not->toContain($card['qr_payload']);

    $scan = $this->postJson('/api/v1/health/eligibility/scan', ['qr' => $card['qr_payload'], 'provider_id' => $this->provider, 'service_code' => 'CONS_GP', 'service_date' => '2026-03-10'], $this->h)
        ->assertOk()->json('data');
    expect($scan['outcome'])->toBe('ELIGIBLE')->and($scan['member']['name'])->toBe('Awa N. B.')
        ->and($scan)->not->toHaveKey('policy_id')->and($scan['member'])->not->toHaveKey('date_of_birth');
    expect(DB::table('health_eligibility_checks')->where('channel', 'SCAN')->where('outcome', 'ELIGIBLE')->count())->toBe(1);

    // tampered payload (signature mismatch) and a forged token under a valid signature are both refused generically
    [$p, $body, $sig] = explode('.', $card['qr_payload']);
    $claims = json_decode(base64_decode(strtr($body, '-_', '+/')), true);
    $this->postJson('/api/v1/health/eligibility/scan', ['qr' => $p.'.'.$body.'.'.str_repeat('0', 64), 'provider_id' => $this->provider, 'service_code' => 'CONS_GP'], $this->h)->assertStatus(404);
    $forged = HealthCardService::sign(['c' => $claims['c'], 'v' => $claims['v'], 't' => 'guess']);
    $this->postJson('/api/v1/health/eligibility/scan', ['qr' => $forged, 'provider_id' => $this->provider, 'service_code' => 'CONS_GP'], $this->h)->assertStatus(404);

    // reissue revokes the old card
    $this->postJson("/api/v1/health-members/{$this->principal['id']}/card", [], $this->h)->assertCreated();
    $this->postJson('/api/v1/health/eligibility/scan', ['qr' => $card['qr_payload'], 'provider_id' => $this->provider, 'service_code' => 'CONS_GP'], $this->h)->assertStatus(404);
    expect(DB::table('health_eligibility_checks')->where('outcome', 'CARD_NOT_VERIFIED')->count())->toBe(3)
        ->and(DB::table('outbox_messages')->where('event_name', 'health_card.issued')->count())->toBe(2);

    // ending the member revokes the card too
    $this->postJson("/api/v1/health-members/{$this->principal['id']}/end", ['effective_to' => '2026-08-01', 'reason' => 'Left'], $this->h)->assertOk();
    expect(DB::table('health_member_cards')->where('status', 'ACTIVE')->count())->toBe(0);
});

it('REQ-HLT-001: the service contract used by preauth/claims/accumulator is callable directly and tenant-scoped', function () {
    $r = app(EligibilityService::class)->check($this->tenant->id, $this->principal['card_number'], $this->provider, 'CONS_GP', '2026-03-10');
    expect($r['outcome'])->toBe('ELIGIBLE');
    $other = makeAuthTestTenant('hlt-other');
    expect(app(EligibilityService::class)->check($other->id, $this->principal['card_number'], null, 'CONS_GP', '2026-03-10')['outcome'])->toBe('NOT_ELIGIBLE');
});
