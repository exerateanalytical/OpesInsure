<?php

declare(strict_types=1);

/** Agent E3 — REQ-HLT-002 health preauthorization + guarantee of payment. */

use App\Application\Documents\Engine\DocumentTemplateService;
use App\Application\Health\Preauth\PreauthLifecycle;
use App\Application\Providers\ProviderNetworkService;
use App\Application\Providers\ProviderRegistry;
use App\Domain\Tenancy\TenantContext;
use App\Models\DocumentIssuanceProfile;
use App\Models\DocumentTemplate;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';
require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';

uses(RefreshDatabase::class);

const E3_PERMS = ['health.preauth.view', 'health.preauth.request', 'health.preauth.review', 'health.preauth.approve'];

function e3Staff($tenant, string $carrierId, ?int $limit, array $extra = []): User
{
    $u = makeAuthTestUser($tenant, [...E3_PERMS, ...$extra]);
    if ($limit !== null) {
        DB::table('authority_limits')->insert(['id' => (string) Str::uuid(), 'carrier_id' => $carrierId, 'holder_type' => 'USER', 'holder_id' => $u->id,
            'authority_type' => PreauthLifecycle::authorityType(), 'max_amount_minor' => $limit, 'currency' => 'XAF', 'effective_from' => now()->subMonth()->toDateString(),
            'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
    }

    return $u;
}

function e3Template(string $code): void
{
    $t = DocumentTemplate::create([
        'code' => DocumentTemplateService::lineage(['document_type_code' => $code, 'ownership' => 'PLATFORM', 'language' => 'BILINGUAL']), 'document_type_code' => $code,
        'ownership' => 'PLATFORM', 'language' => 'BILINGUAL', 'version' => 1, 'status' => 'PUBLISHED', 'title_en' => $code, 'title_fr' => $code,
        'content' => ['sections' => [['heading_en' => 'GOP', 'heading_fr' => 'PEC', 'body_en' => 'Policy {policy_number}', 'body_fr' => 'Police {policy_number}']]],
        'content_hash' => 'x', 'effective_from' => now()->subYear()->toDateString(), 'created_by' => makeAuthTestUser(test()->tenant, [])->id,
    ]);
    $t->update(['content_hash' => app(DocumentTemplateService::class)->hash($t)]);
}

beforeEach(function () {
    $root = storage_path('framework/testing/disks/e3-'.Str::random(10));
    Storage::set('local', Storage::createLocalDriver(['root' => $root]));
    $this->beforeApplicationDestroyed(fn () => \Illuminate\Support\Facades\File::deleteDirectory($root));

    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $this->f = $f;
    $this->tenant = $f['tenant'];
    $this->h = ['X-Tenant-ID' => $this->tenant->id];
    $this->policy = makeMobileTestPolicy($f['proposal'], $this->tenant, $f['carrier']->id, $f['party']->id, ['currency' => 'XAF', 'coverage_starts_at' => now()->subMonth()]);
    app(TenantContext::class)->set($this->tenant->id);

    $this->provider_user = e3Staff($this->tenant, $f['carrier']->id, null);
    $this->maker = e3Staff($this->tenant, $f['carrier']->id, 1_000_000);
    $this->checker = e3Staff($this->tenant, $f['carrier']->id, 1_000_000);
    $this->small = e3Staff($this->tenant, $f['carrier']->id, 10_000);
    $this->supervisor = e3Staff($this->tenant, $f['carrier']->id, 5_000_000, ['health.preauth.supervise']);

    $reg = app(ProviderRegistry::class);
    $p = $reg->register(['category' => 'HEALTH', 'name' => 'Clinique du Littoral', 'provider_type_code' => 'CLINIC'], null);
    foreach (['APPLICATION', 'UNDER_REVIEW', 'APPROVED', 'ACTIVE'] as $to) {
        $reg->transition($p->id, $to, null, null, null);
    }
    $this->provider = $reg->find($p->id);
    $net = app(ProviderNetworkService::class);
    $network = $net->createNetwork($this->tenant->id, ['code' => 'HMO_A', 'name' => 'Health network', 'network_type_code' => 'PPN', 'category' => 'HEALTH'], null);
    $net->addMember($this->tenant->id, $network->id, ['provider_id' => $this->provider->id, 'effective_from' => now()->subMonth()->toDateString()], null);
    $this->room = $net->addMedicalService(['code' => 'HOSP_ROOM_DAY', 'name' => 'Hospital room per day', 'category_code' => 'HOSPITALISATION']);
    $this->consult = $net->addMedicalService(['code' => 'CONSULT_GP', 'name' => 'GP consultation', 'category_code' => 'OUTPATIENT']);
    $this->cbc = $net->addMedicalService(['code' => 'LAB_CBC', 'name' => 'Complete blood count', 'category_code' => 'LABORATORY']);
    $net->mapProviderCode($this->provider->id, 'CH-101', $this->room->id);
    $contract = $net->createContract($this->tenant->id, $network->id, ['provider_id' => $this->provider->id, 'contract_number' => 'HC-001', 'effective_from' => now()->subMonth()->toDateString()], null);
    $tariff = $net->draftTariff($this->tenant->id, $contract->id, now()->subMonth()->toDateString(), 'XAF', [
        ['medical_service_id' => $this->room->id, 'price_minor' => 60000, 'contracted_price_minor' => 50000, 'copay_minor' => 0, 'insurer_share_percent' => 80],
        ['medical_service_id' => $this->consult->id, 'price_minor' => 15000, 'contracted_price_minor' => 10000, 'copay_minor' => 2000, 'insurer_share_percent' => 100],
    ], $this->maker->id);
    $net->approveTariff($this->tenant->id, $tariff->id, $this->checker->id);
    Passport::actingAs($this->provider_user, [], 'api');
});

function e3Admission(array $over = []): \Illuminate\Testing\TestResponse
{
    return test()->postJson('/api/v1/health/preauthorizations', array_replace_recursive([
        'request_type' => 'ADMISSION', 'policy_id' => test()->policy->id, 'provider_id' => test()->provider->id,
        'details' => ['admission_date' => now()->toDateString(), 'expected_discharge_date' => now()->addDays(3)->toDateString(), 'diagnosis_code' => 'K35.8', 'admission_reason' => 'Acute appendicitis'],
        'lines' => [['provider_code' => 'CH-101', 'quantity' => 3], ['service_code' => 'CONSULT_GP', 'quantity' => 1]],
    ], $over), test()->h);
}

it('REQ-HLT-002: admission request priced from tariff + provider code, maker-checker approval, GOP document with validity, SLA case, history', function () {
    DocumentIssuanceProfile::create(['carrier_id' => $this->f['carrier']->id, 'issuance_mode' => 'OPES_GENERATED', 'opes_rendering_authorized' => true, 'authorization_reference' => 'AUTH-1', 'default_language' => 'BILINGUAL']);
    $this->artisan('opesinsure:seed-document-catalogue')->assertSuccessful();
    foreach (['GUARANTEE_OF_PAYMENT', 'PREAUTHORIZATION_APPROVAL', 'HOSPITAL_ADMISSION_AUTHORIZATION', 'HOSPITAL_STAY_EXTENSION_AUTHORIZATION'] as $c) {
        e3Template($c);
    }

    $pa = e3Admission()->assertCreated()->assertJsonPath('data.status', 'REQUESTED')->assertJsonPath('data.eligible', true)->json('data');
    // room 3 × 50 000 at 80 % = 120 000; consultation (10 000 − 2 000 copay) × 100 % = 8 000.
    expect($pa['requested_amount_minor'])->toBe(160000)->and($pa['insurer_amount_minor'])->toBe(128000)
        ->and($pa['lines'][0]['service_code'])->toBe('HOSP_ROOM_DAY')->and($pa['lines'][0]['priced_from'])->toBe('TARIFF')
        ->and($pa['lines'][0]['eligibility']['source'])->toBe(class_exists('App\\Application\\Health\\Eligibility\\EligibilityService') ? 'ELIGIBILITY_SERVICE' : 'POLICY_FALLBACK');
    $case = DB::table('cases')->where('id', $pa['case_id'])->first();
    expect($case->case_type_code)->toBe('HEALTH_PREAUTHORIZATION')->and($case->case_subtype)->toBe('ADMISSION')->and($case->status)->toBe('REQUESTED');

    // The requester cannot review their own request.
    $this->postJson("/api/v1/health/preauthorizations/{$pa['id']}/proposal", ['decision' => 'APPROVED', 'valid_until' => now()->addDays(5)->toDateString()], $this->h)->assertForbidden();

    Passport::actingAs($this->maker, [], 'api');
    // No GOP validity is configured: the reviewer must give it.
    $this->postJson("/api/v1/health/preauthorizations/{$pa['id']}/proposal", ['decision' => 'APPROVED'], $this->h)->assertStatus(422)->assertJsonPath('code', 'GOP_VALIDITY_REQUIRED');
    $this->postJson("/api/v1/health/preauthorizations/{$pa['id']}/proposal", ['decision' => 'APPROVED', 'valid_until' => now()->addDays(5)->toDateString()], $this->h)
        ->assertOk()->assertJsonPath('data.status', 'PENDING_APPROVAL')->assertJsonPath('data.approved_amount_minor', 128000);
    // Maker cannot decide.
    $this->postJson("/api/v1/health/preauthorizations/{$pa['id']}/decision", [], $this->h)->assertForbidden();

    Passport::actingAs($this->checker, [], 'api');
    $done = $this->postJson("/api/v1/health/preauthorizations/{$pa['id']}/decision", [], $this->h)->assertOk()->assertJsonPath('data.status', 'APPROVED')->json('data');
    expect($done['decision'])->toBe('APPROVED')->and($done['gop_manifest_id'])->not->toBeNull()
        ->and(DB::table('cases')->where('id', $pa['case_id'])->value('status'))->toBe('APPROVED')
        ->and(DB::table('sla_clocks')->where('case_id', $pa['case_id'])->whereNull('stopped_at')->count())->toBe(0)
        ->and(DB::table('authority_checks')->where('subject_id', $pa['id'])->where('authority_type', PreauthLifecycle::authorityType())->count())->toBe(2);
    $gop = DB::table('documents')->where('pack_manifest_id', $done['gop_manifest_id'])->where('document_type_code', 'GUARANTEE_OF_PAYMENT')->first();
    expect($gop)->not->toBeNull()->and(substr((string) $gop->valid_until, 0, 10))->toBe(now()->addDays(5)->toDateString())
        ->and($gop->subject_key)->toBe('preauth:'.$pa['id'])->and($gop->generation_trigger)->toBe('PREAUTH_APPROVED')
        ->and(DB::table('documents')->where('pack_manifest_id', $done['gop_manifest_id'])->where('document_type_code', 'HOSPITAL_ADMISSION_AUTHORIZATION')->exists())->toBeTrue();
    $reservations = $done['benefit_reservations'];
    expect($reservations)->toHaveCount(2)->and($reservations[0]['benefit_code'])->toBe('HOSPITALISATION');

    // Admission → extension (own case, maker-checker) → discharge.
    Passport::actingAs($this->provider_user, [], 'api');
    $this->postJson("/api/v1/health/preauthorizations/{$pa['id']}/admission", ['admitted_on' => now()->toDateString()], $this->h)->assertOk()->assertJsonPath('data.status', 'ADMITTED');
    $this->postJson("/api/v1/health/preauthorizations/{$pa['id']}/extensions", ['requested_until' => now()->addDays(2)->toDateString(), 'reason' => 'x'], $this->h)
        ->assertStatus(422)->assertJsonPath('code', 'EXTENSION_NOT_LATER');
    $ext = $this->postJson("/api/v1/health/preauthorizations/{$pa['id']}/extensions", ['requested_until' => now()->addDays(6)->toDateString(), 'reason' => 'Post-operative infection',
        'lines' => [['provider_code' => 'CH-101', 'quantity' => 3]]], $this->h)->assertCreated()->assertJsonPath('data.status', 'REQUESTED')->assertJsonPath('data.requested_amount_minor', 120000)->json('data');
    expect(DB::table('cases')->where('id', $ext['case_id'])->value('case_subtype'))->toBe('EXTENSION');
    $this->postJson("/api/v1/health/preauthorizations/{$pa['id']}/discharge", [], $this->h)->assertStatus(409)->assertJsonPath('code', 'EXTENSION_PENDING');

    Passport::actingAs($this->maker, [], 'api');
    $this->postJson("/api/v1/health/preauthorizations/{$pa['id']}/extensions/{$ext['id']}/proposal", ['decision' => 'PARTIAL', 'approved_until' => now()->addDays(5)->toDateString(),
        'lines' => [['line_id' => $ext['lines'][0]['id'], 'approved_quantity' => 2, 'decline_reason' => 'Two extra days medically justified']]], $this->h)
        ->assertOk()->assertJsonPath('data.status', 'PENDING_APPROVAL')->assertJsonPath('data.approved_amount_minor', 80000);
    Passport::actingAs($this->checker, [], 'api');
    $this->postJson("/api/v1/health/preauthorizations/{$pa['id']}/extensions/{$ext['id']}/decision", [], $this->h)->assertOk()->assertJsonPath('data.status', 'PARTIALLY_APPROVED');
    $show = $this->getJson("/api/v1/health/preauthorizations/{$pa['id']}", $this->h)->assertOk()->json('data');
    expect($show['approved_amount_minor'])->toBe(208000)->and($show['approved_until'])->toBe(now()->addDays(5)->toDateString())
        ->and(DB::table('documents')->where('document_type_code', 'HOSPITAL_STAY_EXTENSION_AUTHORIZATION')->where('subject_key', 'preauth:'.$pa['id'].':ext:1')->exists())->toBeTrue()
        // The first GOP stays valid (different subject than the extension GOP).
        ->and(DB::table('documents')->where('id', $gop->id)->value('status'))->not->toBe('SUPERSEDED');

    Passport::actingAs($this->provider_user, [], 'api');
    $this->postJson("/api/v1/health/preauthorizations/{$pa['id']}/discharge", ['discharged_on' => now()->addDays(4)->toDateString()], $this->h)->assertOk()->assertJsonPath('data.status', 'DISCHARGED');
    $show = $this->getJson("/api/v1/health/preauthorizations/{$pa['id']}", $this->h)->json('data');
    expect(array_column($show['history'], 'event'))->toBe(['request', 'propose', 'approve', 'admit', 'extension_requested', 'extension_propose', 'extension_decided', 'discharge'])
        ->and($show['extensions'])->toHaveCount(1);
    expect(fn () => DB::transaction(fn () => DB::table('health_preauthorization_events')->where('health_preauthorization_id', $pa['id'])->delete()))->toThrow(QueryException::class);
    foreach (['health.preauth.requested', 'health.preauth.proposed', 'health.preauth.approved', 'health.preauth.admitted', 'health.preauth.extension_requested',
        'health.preauth.extension_decided', 'health.preauth.discharged'] as $e) {
        expect(DB::table('outbox_messages')->where('event_name', $e)->exists())->toBeTrue($e);
    }
    $this->getJson('/api/v1/health/preauthorizations?provider_id='.$this->provider->id, $this->h)->assertOk()->assertJsonCount(1, 'data');
});

it('REQ-HLT-002: outpatient / pharmacy / lab types carry their own required fields; out-of-tariff lines need a requested price', function () {
    $base = ['policy_id' => $this->policy->id, 'provider_id' => $this->provider->id];
    $this->postJson('/api/v1/health/preauthorizations', $base + ['request_type' => 'PHARMACY', 'details' => ['prescriber' => 'Dr A'],
        'lines' => [['service_code' => 'CONSULT_GP', 'quantity' => 1]]], $this->h)->assertStatus(422)->assertJsonValidationErrors(['details.prescription_reference', 'details.prescription_date']);
    $this->postJson('/api/v1/health/preauthorizations', $base + ['request_type' => 'LAB', 'details' => ['test_order_reference' => 'ORD-1', 'ordering_physician' => 'Dr B', 'order_date' => now()->toDateString()],
        'lines' => [['service_code' => 'LAB_CBC', 'quantity' => 1]]], $this->h)->assertStatus(409)->assertJsonPath('code', 'TARIFF_MISSING');
    $lab = $this->postJson('/api/v1/health/preauthorizations', $base + ['request_type' => 'LAB', 'details' => ['test_order_reference' => 'ORD-1', 'ordering_physician' => 'Dr B', 'order_date' => now()->toDateString(), 'foo' => 'dropped'],
        'lines' => [['service_code' => 'LAB_CBC', 'quantity' => 1, 'unit_price_minor' => 7000]]], $this->h)->assertCreated()->json('data');
    expect($lab['lines'][0]['priced_from'])->toBe('REQUESTED')->and($lab['type_details'])->not->toHaveKey('foo')
        ->and(DB::table('cases')->where('id', $lab['case_id'])->value('case_subtype'))->toBe('LAB');
    $this->postJson('/api/v1/health/preauthorizations', $base + ['request_type' => 'OUTPATIENT', 'details' => ['consultation_date' => now()->toDateString(), 'diagnosis_code' => 'J06.9'],
        'lines' => [['service_code' => 'NOPE', 'quantity' => 1]]], $this->h)->assertStatus(422)->assertJsonPath('code', 'MEDICAL_SERVICE_UNKNOWN');
    $this->postJson('/api/v1/health/preauthorizations', $base + ['request_type' => 'OUTPATIENT', 'details' => ['consultation_date' => now()->toDateString(), 'diagnosis_code' => 'J06.9'],
        'lines' => [['service_code' => 'CONSULT_GP', 'quantity' => 2]]], $this->h)->assertCreated()->assertJsonPath('data.insurer_amount_minor', 16000);
});

it('REQ-HLT-002: info requested round-trip, partial decision per line, decline, referral over authority and cancel', function () {
    $pa = e3Admission()->assertCreated()->json('data');
    Passport::actingAs($this->maker, [], 'api');
    $this->postJson("/api/v1/health/preauthorizations/{$pa['id']}/info-request", ['question' => 'Send the surgeon report.'], $this->h)
        ->assertOk()->assertJsonPath('data.status', 'INFO_REQUESTED')->assertJsonPath('data.decision', 'INFO_REQUESTED');
    expect(DB::table('cases')->where('id', $pa['case_id'])->value('status'))->toBe('INFO_REQUESTED');
    Passport::actingAs($this->provider_user, [], 'api');
    $this->postJson("/api/v1/health/preauthorizations/{$pa['id']}/info", ['answer' => 'Report attached: perforation risk.'], $this->h)->assertOk()->assertJsonPath('data.status', 'REQUESTED');

    Passport::actingAs($this->maker, [], 'api');
    $room = $pa['lines'][0]['id'];
    $this->postJson("/api/v1/health/preauthorizations/{$pa['id']}/proposal", ['decision' => 'PARTIAL', 'valid_until' => now()->addDays(4)->toDateString(),
        'lines' => [['line_id' => $room, 'approved_quantity' => 2]]], $this->h)->assertStatus(422)->assertJsonPath('code', 'REASON_REQUIRED');
    $this->postJson("/api/v1/health/preauthorizations/{$pa['id']}/proposal", ['decision' => 'PARTIAL', 'valid_until' => now()->addDays(4)->toDateString(),
        'lines' => [['line_id' => $room, 'approved_quantity' => 2, 'decline_reason' => 'Two days per protocol']]], $this->h)
        ->assertOk()->assertJsonPath('data.approved_amount_minor', 88000);
    Passport::actingAs($this->checker, [], 'api');
    $this->postJson("/api/v1/health/preauthorizations/{$pa['id']}/proposal/return", ['reason' => 'Check protocol'], $this->h)->assertOk()->assertJsonPath('data.status', 'REQUESTED');
    Passport::actingAs($this->maker, [], 'api');
    $this->postJson("/api/v1/health/preauthorizations/{$pa['id']}/proposal", ['decision' => 'PARTIAL', 'valid_until' => now()->addDays(4)->toDateString(),
        'lines' => [['line_id' => $room, 'approved_quantity' => 2, 'decline_reason' => 'Two days per protocol']]], $this->h)->assertOk();
    // A checker whose limit is below the amount refers it; then only a supervisor can decide.
    Passport::actingAs($this->small, [], 'api');
    $ref = $this->postJson("/api/v1/health/preauthorizations/{$pa['id']}/decision", [], $this->h)->assertOk()->assertJsonPath('data.status', 'REFERRED')->json('data');
    expect(DB::table('cases')->where('id', $ref['referral_case_id'])->value('case_type_code'))->toBe('AUTHORITY_REFERRAL')
        ->and(DB::table('cases')->where('id', $pa['case_id'])->value('status'))->toBe('REFERRED');
    Passport::actingAs($this->checker, [], 'api');
    $this->postJson("/api/v1/health/preauthorizations/{$pa['id']}/decision", [], $this->h)->assertForbidden()->assertJsonPath('code', 'AUTHORITY_SUPERVISOR_REQUIRED');
    Passport::actingAs($this->supervisor, [], 'api');
    $d =$this->postJson("/api/v1/health/preauthorizations/{$pa['id']}/decision", [], $this->h)->assertOk()->assertJsonPath('data.status', 'PARTIALLY_APPROVED')->json('data');
    expect(collect($d['lines'])->pluck('line_decision')->all())->toBe(['PARTIAL', 'APPROVED']);
    $this->postJson("/api/v1/health/preauthorizations/{$pa['id']}/cancel", ['reason' => 'Patient transferred'], $this->h)->assertOk()->assertJsonPath('data.status', 'CANCELLED');

    // Referral: a maker over authority refers; only a supervisor can decide.
    Passport::actingAs($this->provider_user, [], 'api');
    $pa2 = e3Admission()->json('data');
    Passport::actingAs($this->small, [], 'api');
    $r = $this->postJson("/api/v1/health/preauthorizations/{$pa2['id']}/proposal", ['decision' => 'APPROVED', 'valid_until' => now()->addDays(4)->toDateString()], $this->h)
        ->assertOk()->assertJsonPath('data.status', 'REFERRED')->json('data');
    expect($r['referral_case_id'])->not->toBeNull()->and(DB::table('cases')->where('id', $r['referral_case_id'])->value('case_type_code'))->toBe('AUTHORITY_REFERRAL');
    Passport::actingAs($this->checker, [], 'api');
    $this->postJson("/api/v1/health/preauthorizations/{$pa2['id']}/decision", [], $this->h)->assertForbidden()->assertJsonPath('code', 'AUTHORITY_SUPERVISOR_REQUIRED');
    Passport::actingAs($this->supervisor, [], 'api');
    $this->postJson("/api/v1/health/preauthorizations/{$pa2['id']}/decision", [], $this->h)->assertOk()->assertJsonPath('data.status', 'APPROVED');

    // Decline needs a reason code; no authority needed for zero.
    Passport::actingAs($this->provider_user, [], 'api');
    $pa3 = e3Admission()->json('data');
    Passport::actingAs($this->maker, [], 'api');
    $this->postJson("/api/v1/health/preauthorizations/{$pa3['id']}/proposal", ['decision' => 'DECLINED'], $this->h)->assertStatus(422);
    $this->postJson("/api/v1/health/preauthorizations/{$pa3['id']}/proposal", ['decision' => 'DECLINED', 'reason_code' => 'EXCLUDED_CONDITION'], $this->h)->assertOk();
    Passport::actingAs($this->checker, [], 'api');
    $this->postJson("/api/v1/health/preauthorizations/{$pa3['id']}/decision", [], $this->h)->assertOk()->assertJsonPath('data.status', 'DECLINED')->assertJsonPath('data.approved_amount_minor', 0);
    $this->postJson("/api/v1/health/preauthorizations/{$pa3['id']}/admission", [], $this->h)->assertStatus(409);
});

it('REQ-HLT-002: an ineligible member can only be declined; seeds no SLA target and no authority limit', function () {
    $this->policy->update(['status' => 'CANCELLED']);
    $pa = e3Admission()->assertCreated()->assertJsonPath('data.eligible', false)->json('data');
    Passport::actingAs($this->maker, [], 'api');
    $this->postJson("/api/v1/health/preauthorizations/{$pa['id']}/proposal", ['decision' => 'APPROVED', 'valid_until' => now()->addDays(4)->toDateString()], $this->h)
        ->assertStatus(409)->assertJsonPath('code', 'PREAUTH_NOT_ELIGIBLE');

    $type = DB::table('case_types')->where('code', PreauthLifecycle::CASE_TYPE)->first();
    expect(json_decode($type->sla_policies, true))->toBe([])
        ->and(PreauthLifecycle::authorityType())->toBe('CLAIM_SETTLE')
        ->and(config('health_preauth.gop_validity_days'))->toBeNull();

    // Unauthenticated-permission users are refused.
    Passport::actingAs(makeAuthTestUser($this->tenant, []), [], 'api');
    $this->getJson('/api/v1/health/preauthorizations', $this->h)->assertForbidden();
});
