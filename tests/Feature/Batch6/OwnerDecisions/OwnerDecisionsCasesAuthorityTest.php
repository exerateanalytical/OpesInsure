<?php

declare(strict_types=1);

/**
 * Owner decisions 2026-09-25 — #12 REQ-AUTH-001, #13 REQ-CAS-001, #17 REQ-POL-008, #22 REQ-CAL-001,
 * #32 REQ-QUO-006, complaints REQ-CPL-001, institutional datasets (public holidays / hazard zones).
 */

use App\Application\Cases\CaseService;
use App\Application\Cases\CaseTypeCatalogue;
use App\Application\Cases\Models\CaseType;
use App\Application\Cases\Models\SlaClock;
use App\Application\Cases\Models\WorkCase;
use App\Application\Cases\Sla\BusinessHoursCalendar;
use App\Application\Rules\PremiumCover\PremiumCoverEvaluator;
use App\Domain\Shared\Clock\Clock;
use App\Domain\Shared\Clock\FrozenClock;
use App\Providers\CasesServiceProvider;
use App\Providers\OwnerDecisionsServiceProvider;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->app->register(CasesServiceProvider::class);
    $this->app->register(OwnerDecisionsServiceProvider::class);
    $this->clock = new FrozenClock('2026-10-02T16:00:00+01:00'); // Friday 16:00 Douala
    $this->app->instance(Clock::class, $this->clock);
    $this->tenant = makeAuthTestTenant('owner-dec');
});

function odHours(string $opens = '08:00', string $closes = '17:00'): void
{
    foreach ([1, 2, 3, 4, 5] as $wd) {
        DB::table('calendar_business_hours')->insert(['id' => (string) Str::uuid(), 'jurisdiction' => 'CM', 'branch_id' => null, 'weekday' => $wd,
            'opens' => $opens, 'closes' => $closes, 'valid_from' => '2026-01-01', 'created_at' => now(), 'updated_at' => now()]);
    }
}

function odOpen(string $type, array $attrs = []): WorkCase
{
    return app(CaseService::class)->open(test()->tenant->id, $type, $attrs + ['title' => 'Owner decision case'], null);
}

function odClock(WorkCase $case, string $metric): SlaClock
{
    return SlaClock::where('case_id', $case->id)->where('metric', $metric)->firstOrFail();
}

// ---------------------------------------------------------------- #12 authority types

it('REQ-AUTH-001 #12: authority types are a catalogue with blueprint, owner and legacy types; authority_limits must reference it', function () {
    $codes = DB::table('authority_types')->pluck('source', 'code');
    foreach (['QUOTE', 'BIND', 'CLAIM_PAYMENT', 'REINSURANCE_PLACEMENT', 'WRITE_OFF'] as $c) {
        expect($codes[$c] ?? null)->toBe('BLUEPRINT');
    }
    foreach (['ENDORSE', 'BACKDATE', 'CANCEL', 'OVERRIDE', 'RESERVE_APPROVE', 'CLAIM_SETTLE', 'REFUND_APPROVE', 'REINSTATE', 'PAYMENT_OVERRIDE', 'JOURNAL_APPROVE', 'FACULTATIVE_APPROVE'] as $c) {
        expect($codes[$c] ?? null)->toBe('OWNER_DECISION');
    }
    expect($codes['POLICY_PREMIUM'] ?? null)->toBe('LEGACY')->and(count($codes))->toBe(26);

    $p = \App\Models\Party::create(['type' => 'ORGANIZATION', 'display_name' => 'OD Insurer', 'status' => 'ACTIVE']);
    $carrier = \App\Models\Carrier::create(['party_id' => $p->id, 'cima_code' => 'OD-'.Str::upper(Str::random(5)), 'status' => 'ACTIVE']);
    $row = ['carrier_id' => $carrier->id, 'holder_type' => 'USER', 'holder_id' => 'u1', 'max_amount_minor' => 100, 'currency' => 'XAF', 'territories' => '[]', 'effective_from' => '2026-01-01', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()];
    DB::table('authority_limits')->insert($row + ['id' => (string) Str::uuid(), 'authority_type' => 'BACKDATE']);
    expect(fn () => DB::table('authority_limits')->insert($row + ['id' => (string) Str::uuid(), 'authority_type' => 'NOT_A_TYPE']))->toThrow(QueryException::class);
});

it('REQ-AUTH-001 #12: admins extend and retire catalogue entries through the API (manage permission required)', function () {
    $admin = makeAuthTestUser($this->tenant, ['authority.types.view', 'authority.types.manage']);
    Passport::actingAs($admin, [], 'api');
    $h = ['X-Tenant-ID' => $this->tenant->id];

    $this->postJson('/api/v1/authority-types', ['code' => 'SALVAGE_APPROVE', 'name' => 'Salvage approval'], $h)->assertCreated()->assertJsonPath('data.source', 'ADMIN');
    $this->postJson('/api/v1/authority-types', ['code' => 'SALVAGE_APPROVE', 'name' => 'Again'], $h)->assertStatus(409);
    $this->postJson('/api/v1/authority-types', ['code' => 'bad code', 'name' => 'x'], $h)->assertStatus(422);
    expect(collect($this->getJson('/api/v1/authority-types?active=1', $h)->assertOk()->json('data'))->pluck('code'))->toContain('SALVAGE_APPROVE', 'FACULTATIVE_APPROVE');
    $this->postJson('/api/v1/authority-types/SALVAGE_APPROVE/retire', ['reason' => 'Not needed'], $h)->assertOk()->assertJsonPath('data.status', 'RETIRED');

    Passport::actingAs(makeAuthTestUser($this->tenant, ['authority.types.view']), [], 'api');
    $this->postJson('/api/v1/authority-types', ['code' => 'OTHER', 'name' => 'Other'], $h)->assertForbidden();
});

// ---------------------------------------------------------------- #13 case aggregate

it('REQ-CAS-001 #13: one case aggregate — family, type, sub-type and domain reference; existing types mapped to families', function () {
    // Workflow Data Master v1: the owner's 10 families replaced the 8 reconstructed ones (kept inactive as history).
    expect(DB::table('case_families')->where('active', true)->count())->toBe(10)
        ->and(DB::table('case_families')->where('active', true)->distinct()->pluck('source')->all())->toBe(['OWNER_CONFIRMED'])
        ->and(CaseType::where('code', 'COMPLAINT')->value('family_code'))->toBe('COMPLAINT')
        ->and(CaseType::where('code', 'KYC_REVIEW')->value('family_code'))->toBe('KYC')
        ->and(CaseType::whereNull('family_code')->count())->toBe(0);

    $case = odOpen('RECOVERY', ['subject_type' => 'claim', 'subject_id' => (string) Str::uuid(), 'case_subtype' => 'SUBROGATION']);
    expect($case->case_family)->toBe('CLAIMS')->and($case->case_type_code)->toBe('RECOVERY')
        ->and($case->case_subtype)->toBe('SUBROGATION')->and($case->domain_reference)->toStartWith('claim:');

    // CARRIER_QUOTE_REQUEST v2 restricts sub-types.
    expect(fn () => odOpen('CARRIER_QUOTE_REQUEST', ['case_subtype' => 'WEIRD']))->toThrow(\App\Interfaces\Http\Errors\ApiProblemException::class);

    $viewer = makeAuthTestUser($this->tenant, ['cases.view']);
    Passport::actingAs($viewer, [], 'api');
    $h = ['X-Tenant-ID' => $this->tenant->id];
    expect($this->getJson('/api/v1/cases?case_family=CLAIMS', $h)->assertOk()->json('total'))->toBe(1)
        ->and($this->getJson('/api/v1/cases?case_family=KYC', $h)->json('total'))->toBe(0);
    $fam = collect($this->getJson('/api/v1/case-families', $h)->assertOk()->json('data'))->firstWhere('code', 'UNDERWRITING');
    expect(collect($fam['case_types'])->pluck('code')->unique()->values()->all())->toContain('CARRIER_QUOTE_REQUEST', 'UW_REFERRAL');

    // Drafting a type into an unknown family is refused.
    $admin = makeAuthTestUser($this->tenant, ['cases.admin']);
    Passport::actingAs($admin, [], 'api');
    $def = CaseTypeCatalogue::genericLifecycle(false);
    $this->postJson('/api/v1/admin/case-types', ['code' => 'OD_TEST', 'family_code' => 'NOPE'] + $def, $h)->assertStatus(422)->assertJsonPath('code', 'CASE_FAMILY_UNKNOWN');
});

// ---------------------------------------------------------------- #32 manual quote SLA

it('REQ-QUO-006 #32: manual quote PLATFORM_SLA defaults — 4 business hours ack, 2 business days, 5 when complex/referred', function () {
    odHours(); // Mon-Fri 08-17
    $type = CaseType::where('code', 'CARRIER_QUOTE_REQUEST')->where('status', 'EFFECTIVE')->firstOrFail();
    expect($type->version)->toBeGreaterThan(1)->and($type->pausesSla('WAITING_FOR_CUSTOMER'))->toBeTrue()->and($type->pausesSla('WAITING_FOR_EXTERNAL_EVIDENCE'))->toBeTrue();

    $case = odOpen('CARRIER_QUOTE_REQUEST');
    $ack = odClock($case, 'FIRST_RESPONSE');
    $res = odClock($case, 'RESOLUTION');
    expect($ack->due_at->setTimezone('Africa/Douala')->format('D H:i'))->toBe('Mon 11:00')   // Fri 16-17 + Mon 08-11
        ->and($ack->deadline_label)->toBe('PLATFORM_SLA')
        ->and($res->due_at->setTimezone('Africa/Douala')->format('D Y-m-d H:i'))->toBe('Tue 2026-10-06 16:00')
        ->and($res->target_business_minutes)->toBe(60 + 540 + 480)
        ->and($res->policy_source)->toStartWith('CASE_TYPE:CARRIER_QUOTE_REQUEST');

    // Referred → 5 business days, re-targeted from the same start.
    $out = app(CaseService::class)->reclassify($case, 'REFERRED', null, 'Needs underwriter referral');
    expect($out['retargeted'][0]['metric'] ?? null)->toBe('RESOLUTION')
        ->and(odClock($case, 'RESOLUTION')->due_at->setTimezone('Africa/Douala')->format('D Y-m-d H:i'))->toBe('Fri 2026-10-09 16:00');

    // Waiting for external evidence pauses the clock.
    $svc = app(CaseService::class);
    $svc->transition($case, 'start', null);
    $svc->transition($case, 'await_third_party', null);
    $case->refresh();
    expect($case->status)->toBe('WAITING_FOR_EXTERNAL_EVIDENCE')->and(odClock($case, 'RESOLUTION')->paused_since)->not->toBeNull();
    $this->clock->travelTo('2026-10-06T16:00:00+01:00'); // two business days later
    $svc->transition($case, 'info_received', null);
    expect(odClock($case, 'RESOLUTION')->due_at->setTimezone('Africa/Douala')->format('D Y-m-d H:i'))->toBe('Tue 2026-10-13 16:00');
});

it('REQ-QUO-006 #32: SLA overrides per insurer / product / branch / market beat the defaults, most specific first', function () {
    odHours();
    $admin = makeAuthTestUser($this->tenant, ['cases.admin', 'cases.view']);
    Passport::actingAs($admin, [], 'api');
    $h = ['X-Tenant-ID' => $this->tenant->id];
    $carrier = (string) Str::uuid();
    $product = (string) Str::uuid();

    $this->postJson('/api/v1/admin/sla-overrides', ['case_type_code' => 'CARRIER_QUOTE_REQUEST', 'metric' => 'RESOLUTION', 'carrier_id' => $carrier, 'target_business_days' => 3], $h)->assertCreated();
    $this->postJson('/api/v1/admin/sla-overrides', ['case_type_code' => 'CARRIER_QUOTE_REQUEST', 'metric' => 'RESOLUTION', 'carrier_id' => $carrier, 'product_id' => $product, 'target_business_days' => 1], $h)->assertCreated();
    $this->postJson('/api/v1/admin/sla-overrides', ['case_type_code' => 'CARRIER_QUOTE_REQUEST', 'metric' => 'NOPE', 'target_business_days' => 1], $h)->assertStatus(422);
    $this->postJson('/api/v1/admin/sla-overrides', ['case_type_code' => 'CARRIER_QUOTE_REQUEST', 'metric' => 'RESOLUTION', 'target_business_days' => 1, 'target_business_minutes' => 60], $h)->assertStatus(422);

    $insurerOnly = odOpen('CARRIER_QUOTE_REQUEST', ['carrier_id' => $carrier]);
    $insurerProduct = odOpen('CARRIER_QUOTE_REQUEST', ['carrier_id' => $carrier, 'product_id' => $product]);
    $other = odOpen('CARRIER_QUOTE_REQUEST', ['carrier_id' => (string) Str::uuid()]);
    expect(odClock($insurerOnly, 'RESOLUTION')->due_at->setTimezone('Africa/Douala')->format('D H:i'))->toBe('Wed 16:00')
        ->and(odClock($insurerProduct, 'RESOLUTION')->due_at->setTimezone('Africa/Douala')->format('D H:i'))->toBe('Mon 16:00')
        ->and(odClock($other, 'RESOLUTION')->due_at->setTimezone('Africa/Douala')->format('D H:i'))->toBe('Tue 16:00')
        ->and(odClock($insurerProduct, 'RESOLUTION')->policy_source)->toStartWith('OVERRIDE:');
    $policies = collect($this->getJson("/api/v1/cases/{$insurerOnly->id}/sla-policies", $h)->assertOk()->json('data'))->keyBy('metric');
    expect($policies['RESOLUTION']['target_business_days'])->toBe(3)->and($policies['FIRST_RESPONSE']['label'])->toBe('PLATFORM_SLA');
});

// ---------------------------------------------------------------- complaints

it('REQ-CPL-001: complaint deadlines are configurable PLATFORM_SLA targets; REGULATORY_DEADLINE needs a legal basis', function () {
    $admin = makeAuthTestUser($this->tenant, ['cases.admin']);
    Passport::actingAs($admin, [], 'api');
    $h = ['X-Tenant-ID' => $this->tenant->id];

    $this->postJson('/api/v1/admin/sla-overrides', ['case_type_code' => 'COMPLAINT', 'metric' => 'FIRST_RESPONSE', 'target_business_days' => 2, 'label' => 'REGULATORY_DEADLINE'], $h)
        ->assertStatus(422)->assertJsonPath('code', 'LEGAL_BASIS_REQUIRED');
    $this->postJson('/api/v1/admin/sla-overrides', ['case_type_code' => 'COMPLAINT', 'metric' => 'FIRST_RESPONSE', 'target_business_days' => 2], $h)
        ->assertCreated()->assertJsonPath('data.deadline_label', 'PLATFORM_SLA');
    $case = odOpen('COMPLAINT');
    expect(odClock($case, 'FIRST_RESPONSE')->deadline_label)->toBe('PLATFORM_SLA')->and(odClock($case, 'FIRST_RESPONSE')->legal_basis)->toBeNull();

    // Type definitions and the database refuse a regulatory label without a basis.
    expect(fn () => CaseTypeCatalogue::validate(CaseTypeCatalogue::complaintLifecycle() + ['sla_policies' => [['metric' => 'RESOLUTION', 'target_business_days' => 5, 'label' => 'REGULATORY_DEADLINE']]]))
        ->toThrow(InvalidArgumentException::class);
    expect(fn () => DB::table('sla_clocks')->where('case_id', $case->id)->update(['deadline_label' => 'REGULATORY_DEADLINE']))->toThrow(QueryException::class);
});

// ---------------------------------------------------------------- #22 calendar breaks + holiday datasets

it('REQ-CAL-001 #22: an optional lunch break is excluded only when configured; none is inferred', function () {
    odHours();
    $cal = app(BusinessHoursCalendar::class);
    expect($cal->addBusinessMinutes('2026-10-05T08:00:00+01:00', 300)->format('H:i'))->toBe('13:00'); // no break by default

    $admin = makeAuthTestUser($this->tenant, ['cases.calendar.manage']);
    Passport::actingAs($admin, [], 'api');
    $this->postJson('/api/v1/admin/calendars/breaks', ['jurisdiction' => 'CM', 'starts' => '12:00', 'ends' => '13:00', 'label' => 'Lunch', 'valid_from' => '2026-01-01'], ['X-Tenant-ID' => $this->tenant->id])->assertCreated();
    $cal->forget();
    expect($cal->addBusinessMinutes('2026-10-05T08:00:00+01:00', 300)->format('H:i'))->toBe('14:00')
        ->and($cal->isOpenAt('2026-10-05T12:30:00+01:00'))->toBeFalse()
        ->and($cal->businessMinutesBetween('2026-10-05T08:00:00+01:00', '2026-10-05T17:00:00+01:00'))->toBe(480);
});

it('REQ-CAL-001: public holidays come from versioned institutional datasets (maker-checker, provenance, verification)', function () {
    odHours();
    $maker = makeAuthTestUser($this->tenant, ['reference_datasets.manage', 'reference_datasets.view', 'reference_datasets.approve']);
    $checker = makeAuthTestUser($this->tenant, ['reference_datasets.approve', 'reference_datasets.view']);
    $h = ['X-Tenant-ID' => $this->tenant->id];
    Passport::actingAs($maker, [], 'api');
    $id = $this->postJson('/api/v1/reference-datasets', [
        'kind' => 'PUBLIC_HOLIDAYS', 'jurisdiction' => 'CM', 'code' => 'CM_PUBLIC_HOLIDAYS_TEST', 'source_name' => 'Test fixture (not an official source)',
        'effective_from' => '2026-01-01', 'entries' => [['date' => '2026-10-05', 'label' => 'Test holiday']],
    ], $h)->assertCreated()->assertJsonPath('data.status', 'DRAFT')->assertJsonPath('data.verification_status', 'UNVERIFIED')->json('data.id');

    $cal = app(BusinessHoursCalendar::class);
    expect($cal->addBusinessMinutes('2026-10-02T16:00:00+01:00', 240)->format('D H:i'))->toBe('Mon 11:00'); // DRAFT is not consumed

    $this->postJson("/api/v1/reference-datasets/{$id}/activate", [], $h)->assertForbidden()->assertJsonPath('code', 'MAKER_CHECKER_REQUIRED');
    Passport::actingAs($checker, [], 'api');
    $this->postJson("/api/v1/reference-datasets/{$id}/activate", ['verification_status' => 'VERIFIED'], $h)->assertStatus(422)->assertJsonPath('code', 'SOURCE_REFERENCE_REQUIRED');
    $this->postJson("/api/v1/reference-datasets/{$id}/activate", [], $h)->assertOk()->assertJsonPath('data.status', 'ACTIVE');
    $cal->forget();
    expect($cal->addBusinessMinutes('2026-10-02T16:00:00+01:00', 240)->format('D H:i'))->toBe('Tue 11:00');

    // Hazard zones: same versioned container, no data seeded.
    Passport::actingAs($maker, [], 'api');
    $hz = $this->postJson('/api/v1/reference-datasets', [
        'kind' => 'HAZARD_ZONES', 'jurisdiction' => 'CM', 'code' => 'CM_FLOOD_TEST', 'source_name' => 'Test fixture', 'effective_from' => '2026-01-01',
        'entries' => [['hazard_type' => 'FLOOD', 'zone_code' => 'Z1', 'name' => 'Zone one']],
    ], $h)->assertCreated()->json('data.id');
    expect($this->getJson("/api/v1/reference-datasets/{$hz}", $h)->assertOk()->json('data.entries.0.hazard_type'))->toBe('FLOOD')
        ->and(DB::table('reference_datasets')->where('code', 'not like', '%_TEST')->count())->toBe(0);
});

// ---------------------------------------------------------------- #17 premium-to-cover

it('REQ-POL-008 #17: premium-to-cover is a rule engine on the Rules expression evaluator, not a Boolean', function () {
    $maker = makeAuthTestUser($this->tenant, ['premium_cover.rules.manage', 'premium_cover.rules.view']);
    $checker = makeAuthTestUser($this->tenant, ['premium_cover.rules.approve']);
    $h = ['X-Tenant-ID' => $this->tenant->id];
    $product = (string) Str::uuid();
    $eval = fn (array $in) => app(PremiumCoverEvaluator::class)->evaluate($in + ['jurisdiction' => 'CM', 'effective_date' => '2026-10-02']);

    expect($eval(['premium_status' => 'UNPAID'])['outcome'])->toBe('UNDETERMINED')->and($eval(['premium_status' => 'UNPAID'])['cover_active'])->toBeNull();

    Passport::actingAs($maker, [], 'api');
    $mk = fn (array $d) => $this->postJson('/api/v1/premium-cover-rules', $d + ['effective_from' => '2026-01-01', 'jurisdiction' => 'CM'], $h)->assertCreated()->json('data.id');
    $this->postJson('/api/v1/premium-cover-rules', ['code' => 'BAD', 'name' => 'Bad', 'activation_rule' => ['op' => 'EVAL', 'code' => 'x'], 'outcome' => 'NO_COVER', 'effective_from' => '2026-01-01'], $h)
        ->assertStatus(422)->assertJsonPath('code', 'ACTIVATION_RULE_INVALID');
    $ids = [
        $mk(['code' => 'PAID_COVERS', 'name' => 'Paid premium activates cover', 'premium_statuses' => ['PAID'], 'activation_rule' => true, 'outcome' => 'COVER_ACTIVE']),
        $mk(['code' => 'UNPAID_NO_COVER', 'name' => 'No cover until paid', 'premium_statuses' => ['UNPAID', 'OVERDUE'], 'activation_rule' => true, 'outcome' => 'NO_COVER']),
        $mk(['code' => 'PRODUCT_GRACE', 'name' => 'Product grace window', 'product_id' => $product, 'premium_statuses' => ['OVERDUE'], 'outcome' => 'GRACE', 'grace_days' => 15,
            'activation_rule' => ['op' => 'LTE', 'left' => ['fact' => 'premium.days_overdue'], 'right' => ['value' => 15]]]),
        $mk(['code' => 'PRODUCT_EXCEPTION', 'name' => 'Waived by exception', 'product_id' => $product, 'is_exception' => true, 'outcome' => 'COVER_ACTIVE',
            'activation_rule' => ['op' => 'EQUAL', 'left' => ['fact' => 'policy.exception_approved'], 'right' => ['value' => true]]]),
    ];
    expect($eval(['premium_status' => 'PAID'])['outcome'])->toBe('UNDETERMINED'); // DRAFT rules are not applied

    $this->postJson("/api/v1/premium-cover-rules/{$ids[0]}/approve", [], $h)->assertForbidden(); // maker
    Passport::actingAs($checker, [], 'api');
    foreach ($ids as $id) {
        $this->postJson("/api/v1/premium-cover-rules/{$id}/approve", [], $h)->assertOk()->assertJsonPath('data.verification_status', 'UNVERIFIED');
    }

    expect($eval(['premium_status' => 'PAID'])['cover_active'])->toBeTrue()
        ->and($eval(['premium_status' => 'UNPAID'])['outcome'])->toBe('NO_COVER')
        ->and($eval(['premium_status' => 'OVERDUE', 'product_id' => $product, 'facts' => ['premium' => ['days_overdue' => 10], 'policy' => ['exception_approved' => false]]])['outcome'])->toBe('GRACE')
        ->and($eval(['premium_status' => 'OVERDUE', 'product_id' => $product, 'facts' => ['premium' => ['days_overdue' => 20], 'policy' => ['exception_approved' => false]]])['outcome'])->toBe('NO_COVER')
        ->and($eval(['premium_status' => 'OVERDUE', 'product_id' => $product, 'facts' => ['premium' => ['days_overdue' => 20], 'policy' => ['exception_approved' => true]]])['rule']['code'])->toBe('PRODUCT_EXCEPTION');
    $unknown = $eval(['premium_status' => 'OVERDUE', 'product_id' => $product]);
    expect($unknown['outcome'])->toBe('MORE_INFORMATION_REQUIRED')->and($unknown['missing_facts'])->toBe(['policy.exception_approved'])
        ->and($eval(['premium_status' => 'PAID', 'effective_date' => '2025-06-01'])['outcome'])->toBe('UNDETERMINED'); // before effective_from
});
