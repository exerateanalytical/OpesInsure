<?php

declare(strict_types=1);

/**
 * D4 (DOCUMENT_SECURITY_COMPLETION_PLAN) — provider documents issued by the document engine from the provider
 * portal flows: DOC-064 eligibility confirmation, DOC-065 preauthorization request, DOC-072 explanation of benefits,
 * DOC-198 provider settlement statement, DOC-215 provider contract, DOC-216 provider tariff schedule
 * (DOC-066..071 are the existing PREAUTH_* pack). REQ-HLT-001, REQ-HLT-002, REQ-HLT-003, REQ-PRV-003.
 */

use App\Application\Documents\Engine\DocumentTemplateService;
use App\Application\Documents\Verification\PublicVerificationService;
use App\Application\Health\Preauth\PreauthorizationService;
use App\Application\Health\ProviderClaims\ProviderClaimService;
use App\Application\Health\ProviderClaims\ProviderSettlementService;
use App\Application\Providers\ProviderNetworkService;
use App\Application\Providers\ProviderRegistry;
use App\Application\Providers\Workspace\ProviderDocumentService;
use App\Models\DocumentIssuanceProfile;
use App\Models\DocumentTemplate;
use App\Models\Party;
use App\Models\Policy;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

require_once __DIR__.'/../Concerns/wave_auth_helpers.php';
require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

uses(RefreshDatabase::class);

const D4_PERMS = ['provider.dashboard.view', 'provider.eligibility.check', 'provider.preauth.create', 'provider.preauth.view', 'provider.claim.create', 'provider.claim.submit',
    'provider.claim.view', 'provider.settlement.view', 'provider.contract.view', 'provider.tariff.view', 'provider.documents.view', 'provider.users.manage', 'provider_portal.profile.view'];

const D4_CODES = ['ELIGIBILITY_CONFIRMATION', 'PREAUTHORIZATION_REQUEST', 'EXPLANATION_OF_BENEFITS', 'PROVIDER_SETTLEMENT_STATEMENT', 'PROVIDER_CONTRACT', 'PROVIDER_TARIFF_SCHEDULE'];

function d4Employee($tenant, object $provider): User
{
    $person = Party::create(['type' => 'PERSON', 'display_name' => 'Staff '.Str::random(4), 'status' => 'ACTIVE']);
    DB::table('party_relationships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => null, 'from_party_id' => $person->id, 'to_party_id' => $provider->party_id,
        'type' => 'EMPLOYED_BY', 'status' => 'ACTIVE', 'details' => '{}', 'created_at' => now(), 'updated_at' => now()]);
    $u = makeAuthTestUser($tenant, D4_PERMS, 'PROVIDER_ADMIN');
    $u->update(['party_id' => $person->id]);

    return $u->refresh();
}

function d4Key(): array
{
    return test()->h + ['Idempotency-Key' => (string) Str::uuid()];
}

function d4Doc(string $code): ?object
{
    return DB::table('documents')->where('document_type_code', $code)->latest('created_at')->first();
}

beforeEach(function () {
    $root = storage_path('framework/testing/disks/d4-'.Str::random(10));
    Storage::set('local', Storage::createLocalDriver(['root' => $root]));
    $this->beforeApplicationDestroyed(fn () => \Illuminate\Support\Facades\File::deleteDirectory($root));

    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $this->f = $f;
    $this->tenant = $f['tenant'];
    $this->h = ['X-Tenant-ID' => $this->tenant->id];
    $this->artisan('opesinsure:seed-document-catalogue')->assertSuccessful();
    DocumentIssuanceProfile::create(['carrier_id' => $f['carrier']->id, 'issuance_mode' => 'OPES_GENERATED', 'opes_rendering_authorized' => true, 'authorization_reference' => 'AUTH-D4', 'default_language' => 'BILINGUAL']);
    $author = makeAuthTestUser($this->tenant, []);
    foreach (D4_CODES as $code) {
        $t = DocumentTemplate::create([
            'code' => DocumentTemplateService::lineage(['document_type_code' => $code, 'ownership' => 'PLATFORM', 'language' => 'BILINGUAL']), 'document_type_code' => $code,
            'ownership' => 'PLATFORM', 'language' => 'BILINGUAL', 'version' => 1, 'status' => 'PUBLISHED', 'title_en' => $code, 'title_fr' => $code,
            'content' => ['sections' => [['heading_en' => 'Document', 'heading_fr' => 'Document', 'body_en' => 'Reference {subject} — {document_number}', 'body_fr' => 'Référence {subject} — {document_number}']]],
            'content_hash' => 'x', 'effective_from' => now()->subYear()->toDateString(), 'created_by' => $author->id,
        ]);
        $t->update(['content_hash' => app(DocumentTemplateService::class)->hash($t)]);
    }

    $this->policy = Policy::create(['tenant_id' => $this->tenant->id, 'proposal_id' => $f['proposal']->id, 'carrier_id' => $f['carrier']->id, 'party_id' => $f['party']->id,
        'policy_number' => 'POL-'.Str::upper(Str::random(8)), 'status' => 'ACTIVE', 'coverage_starts_at' => '2026-01-01', 'coverage_ends_at' => '2026-12-31',
        'terms_snapshot' => ['line_code' => 'HEALTH'], 'version' => 1, 'currency' => 'XAF', 'premium_minor' => 1_000_000, 'issued_at' => now()]);
    $version = (string) Str::uuid();
    DB::table('policy_versions')->insert(['id' => $version, 'tenant_id' => $this->tenant->id, 'policy_id' => $this->policy->id, 'version_no' => 1, 'kind' => 'ISSUANCE',
        'valid_from' => '2026-01-01', 'recorded_at' => now()->subDay(), 'snapshot' => '{}', 'snapshot_hash' => str_repeat('0', 64), 'created_at' => now(), 'updated_at' => now()]);
    DB::table('policy_coverages')->insert(['id' => (string) Str::uuid(), 'policy_id' => $this->policy->id, 'policy_version_id' => $version, 'coverage_code' => 'OUTPATIENT',
        'limit_minor' => 5_000_000, 'deductible_minor' => 0, 'currency' => 'XAF', 'starts_at' => '2026-01-01', 'ends_at' => '2026-12-31', 'created_at' => now(), 'updated_at' => now()]);

    $net = app(ProviderNetworkService::class);
    $this->cons = $net->addMedicalService(['code' => 'CONS_D4', 'name' => 'GP consultation', 'category_code' => 'OUTPATIENT']);
    DB::table('health_benefit_rules')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'service_category_code' => 'OUTPATIENT', 'coverage_code' => 'OUTPATIENT',
        'benefit_code' => 'OP', 'created_at' => now(), 'updated_at' => now()]);
    $reg = app(ProviderRegistry::class);
    $this->clinic = $reg->register(['category' => 'HEALTH', 'name' => 'Clinique D4 '.Str::random(3), 'provider_type_code' => 'CLINIC'], null);
    foreach (['APPLICATION', 'UNDER_REVIEW', 'APPROVED', 'ACTIVE'] as $to) {
        $reg->transition($this->clinic->id, $to, null, null, null);
    }
    $this->main = $reg->addFacility($this->clinic->id, ['code' => 'MAIN', 'name' => 'Main']);
    $network = $net->createNetwork($this->tenant->id, ['code' => 'D4NET', 'name' => 'D4 network', 'network_type_code' => 'PREFERRED', 'category' => 'HEALTH', 'carrier_id' => $f['carrier']->id], null);
    $net->addMember($this->tenant->id, $network->id, ['provider_id' => $this->clinic->id, 'effective_from' => '2026-01-01'], null);
    DB::table('health_policy_networks')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'policy_id' => $this->policy->id, 'provider_network_id' => $network->id, 'created_at' => now(), 'updated_at' => now()]);
    $this->contract = $net->createContract($this->tenant->id, $network->id, ['provider_id' => $this->clinic->id, 'contract_number' => 'D4-001', 'effective_from' => '2026-01-01'], null);
    $this->tariff = $net->draftTariff($this->tenant->id, $this->contract->id, '2026-01-01', 'XAF',
        [['medical_service_id' => $this->cons->id, 'price_minor' => 20000, 'contracted_price_minor' => 15000, 'copay_minor' => 3000, 'insurer_share_percent' => 80]], (string) Str::uuid());
    $net->approveTariff($this->tenant->id, $this->tariff->id, (string) Str::uuid());

    $this->user = d4Employee($this->tenant, $this->clinic);
    Passport::actingAs($this->user, [], 'api');
});

it('D4 REQ-PRV-003: contract activation and tariff approval issue the numbered, verifiable provider contract (DOC-215) and tariff schedule (DOC-216)', function () {
    foreach (['PROVIDER_CONTRACT' => 'provider-contract:'.$this->contract->id, 'PROVIDER_TARIFF_SCHEDULE' => 'provider-tariff:'.$this->tariff->id] as $code => $key) {
        $d = d4Doc($code);
        expect($d)->not->toBeNull()
            ->and($d->provider_profile_id)->toBe($this->clinic->id)->and($d->subject_key)->toBe($key)->and($d->policy_id)->toBeNull()
            ->and($d->document_number)->not->toBeEmpty()->and($d->verification_code)->not->toBeEmpty()->and($d->verification_token_hash)->not->toBeEmpty()
            ->and($d->content_hash_sha256)->not->toBeEmpty()->and($d->status)->toBe('ISSUED')->and($d->issuer_type)->toBe('INSURER');
        expect(Storage::disk('local')->exists($d->storage_key))->toBeTrue()->and(hash('sha256', Storage::disk('local')->get($d->storage_key)))->toBe($d->sha256);
        $snapshot = json_decode((string) $d->issuance_snapshot, true);
        expect($snapshot['sources']['provider_profile_id'])->toBe($this->clinic->id)->and($snapshot['fields']['provider.name'])->not->toBeEmpty();
    }
    // Canonical spec security profile applied (spec ids by name: Provider Contract DOC-215 S4, Provider Tariff Schedule DOC-216 S3).
    expect(DB::table('documents')->where('document_type_code', 'PROVIDER_CONTRACT')->value('security_tier'))->toBe('S4')
        ->and(DB::table('documents')->where('document_type_code', 'PROVIDER_TARIFF_SCHEDULE')->value('security_tier'))->toBe('S3');

    // Idempotent: re-firing the trigger returns the same document; no second number.
    $again = app(ProviderDocumentService::class)->onContractActivated($this->tenant->id, $this->contract->id, null);
    expect($again[0]['document_id'])->toBe(d4Doc('PROVIDER_CONTRACT')->id)->and(DB::table('documents')->where('document_type_code', 'PROVIDER_CONTRACT')->count())->toBe(1);

    // Provider lists and downloads its own contract (not medical: any provider document viewer).
    $list = $this->getJson('/api/v1/provider-portal/documents/issued', $this->h)->assertOk()->json('data');
    expect(collect($list)->pluck('document_type_code')->all())->toContain('PROVIDER_CONTRACT', 'PROVIDER_TARIFF_SCHEDULE');
    $this->get('/api/v1/provider-portal/documents/'.d4Doc('PROVIDER_CONTRACT')->id.'/download', $this->h)->assertOk()->assertHeader('Content-Type', 'application/pdf');
});

it('D4 REQ-HLT-001: an eligibility check issues the eligibility confirmation (DOC-064), medical-restricted: clinical roles only for the provider', function () {
    $r = $this->postJson('/api/v1/provider-portal/eligibility/check', ['member_ref' => $this->f['party']->id, 'policy_id' => $this->policy->id, 'service_code' => 'CONS_D4',
        'search_method' => 'POLICY_NUMBER', 'service_date' => '2026-03-10'], d4Key())->assertOk()->json('data');
    $d = d4Doc('ELIGIBILITY_CONFIRMATION');
    expect($d)->not->toBeNull()->and($d->subject_key)->toBe('eligibility:'.$r['verification_reference'])->and($d->policy_id)->toBe($this->policy->id)
        ->and($d->security_level)->toBe('MEDICAL_RESTRICTED')->and($d->confidentiality_class)->toBe('MEDICAL_RESTRICTED')->and($d->generation_trigger)->toBe('ELIGIBILITY_CHECKED');

    // A non-clinical provider user neither lists nor downloads it.
    expect(collect($this->getJson('/api/v1/provider-portal/documents/issued', $this->h)->json('data'))->pluck('document_type_code')->all())->not->toContain('ELIGIBILITY_CONFIRMATION');
    $this->get('/api/v1/provider-portal/documents/'.$d->id.'/download', $this->h)->assertStatus(403);

    // A doctor (clinical role) of the same provider may.
    $doctor = d4Employee($this->tenant, $this->clinic);
    $this->postJson('/api/v1/provider-portal/users', ['user_id' => $doctor->id, 'provider_role' => 'DOCTOR', 'facility_scope' => 'ALL'], d4Key())->assertCreated();
    Passport::actingAs($doctor->refresh(), [], 'api');
    $this->get('/api/v1/provider-portal/documents/'.$d->id.'/download', $this->h)->assertOk();

    // Another provider never sees it (404, no existence leak).
    $other = app(ProviderRegistry::class)->register(['category' => 'HEALTH', 'name' => 'Autre '.Str::random(3), 'provider_type_code' => 'CLINIC'], null);
    foreach (['APPLICATION', 'UNDER_REVIEW', 'APPROVED', 'ACTIVE'] as $to) {
        app(ProviderRegistry::class)->transition($other->id, $to, null, null, null);
    }
    Passport::actingAs(d4Employee($this->tenant, $other), [], 'api');
    $this->get('/api/v1/provider-portal/documents/'.$d->id.'/download', $this->h)->assertStatus(404);
});

it('D4 REQ-HLT-002 REQ-HLT-003: preauth request (DOC-065), EOB on adjudication (DOC-072) and settlement statement on payment (DOC-198); insurer download by level; restricted public verification', function () {
    $pa = app(PreauthorizationService::class)->request($this->tenant->id, ['request_type' => 'OUTPATIENT', 'policy_id' => $this->policy->id, 'provider_id' => $this->clinic->id,
        'details' => ['consultation_date' => now()->toDateString(), 'diagnosis_code' => 'J06.9'], 'lines' => [['service_code' => 'CONS_D4', 'quantity' => 1]]], $this->user);
    $req = d4Doc('PREAUTHORIZATION_REQUEST');
    expect($req)->not->toBeNull()->and($req->subject_key)->toBe('preauth-request:'.$pa->id)->and($req->issuer_type)->toBe('PLATFORM')->and($req->provider_profile_id)->toBe($this->clinic->id);
    expect(json_encode(json_decode((string) $req->issuance_snapshot, true)))->not->toContain('J06.9'); // no clinical detail in the request document

    $claims = app(ProviderClaimService::class);
    $adj = makeAuthTestUser($this->tenant, []);
    $id = $this->postJson('/api/v1/provider-portal/claims', ['contract_id' => $this->contract->id, 'invoice_reference' => 'INV-D4', 'service_date' => '2026-03-10', 'policy_id' => $this->policy->id,
        'member_party_id' => $this->f['party']->id, 'facility_id' => $this->main->id, 'lines' => [['medical_service_id' => $this->cons->id, 'unit_price_minor' => 15000]]], d4Key())->assertCreated()->json('data.id');
    $this->postJson("/api/v1/provider-portal/claims/{$id}/submit", [], d4Key())->assertOk();
    $claims->startReview($this->tenant->id, $id, $adj->id);
    $claims->adjudicate($this->tenant->id, $id, [], null, $adj->id);
    $eob = d4Doc('EXPLANATION_OF_BENEFITS');
    expect($eob)->not->toBeNull()->and($eob->subject_key)->toBe('provider-claim:'.$id)->and($eob->generation_trigger)->toBe('PROVIDER_CLAIM_ADJUDICATED')
        ->and($eob->security_level)->toBe('MEDICAL_RESTRICTED');

    $claims->markPayable($this->tenant->id, $id, $adj->id);
    $batch = app(ProviderSettlementService::class)->createBatch($this->tenant->id, $this->clinic->id, 'XAF', null, $adj->id);
    expect(d4Doc('PROVIDER_SETTLEMENT_STATEMENT'))->toBeNull(); // statement only once paid
    app(ProviderSettlementService::class)->payBatch($this->tenant->id, $batch->id, 'VIR-D4', $adj->id);
    $st = d4Doc('PROVIDER_SETTLEMENT_STATEMENT');
    expect($st)->not->toBeNull()->and($st->subject_key)->toBe('provider-settlement:'.$batch->id)->and($st->document_stage)->toBe('FINANCE')
        ->and($st->security_level)->toBe('FINANCIAL_RESTRICTED')->and($st->security_tier)->toBe('S4');
    // Non-medical: the provider's finance/admin user downloads it without a clinical role.
    $this->get('/api/v1/provider-portal/documents/'.$st->id.'/download', $this->h)->assertOk();

    // Insurer: documents.read alone is not enough for a medical document; the level permission is.
    Passport::actingAs(makeAuthTestUser($this->tenant, ['documents.read']), [], 'api');
    $this->get('/api/v1/health/provider-documents/'.$eob->id.'/download', $this->h)->assertStatus(403);
    expect(collect($this->getJson('/api/v1/health/provider-documents', $this->h)->assertOk()->json('data'))->pluck('document_type_code')->all())->not->toContain('EXPLANATION_OF_BENEFITS');
    Passport::actingAs(makeAuthTestUser($this->tenant, ['documents.read', 'documents.medical.read']), [], 'api');
    $this->get('/api/v1/health/provider-documents/'.$eob->id.'/download', $this->h)->assertOk()->assertHeader('X-Document-Sha256', $eob->sha256);

    // Public verification: restricted disclosure — status and type only, never amounts, member or provider claim data.
    $v = app(PublicVerificationService::class)->lookup($eob->verification_code, null, 'API', 'test');
    $json = json_encode($v);
    expect($v['status'])->toBe('VALID')->and($json)->not->toContain('INV-D4')->and($json)->not->toContain((string) $this->f['party']->id)
        ->and($json)->not->toContain('9 600')->and($json)->not->toContain($eob->document_number);
});

it('D4: without an insurer issuance authorization nothing is numbered (AWAITING_CARRIER_DOCUMENT); an unknown provider trigger is refused', function () {
    DocumentIssuanceProfile::query()->update(['opes_rendering_authorized' => false]);
    $net = app(ProviderNetworkService::class);
    $network = DB::table('provider_networks')->where('code', 'D4NET')->first();
    $k = $net->createContract($this->tenant->id, $network->id, ['provider_id' => $this->clinic->id, 'contract_number' => 'D4-002', 'effective_from' => '2026-02-01'], null);
    expect(DB::table('documents')->where('subject_key', 'provider-contract:'.$k->id)->exists())->toBeFalse();
    $items = app(ProviderDocumentService::class)->onContractActivated($this->tenant->id, $k->id, null);
    expect($items[0]['state'])->toBe('AWAITING_CARRIER_DOCUMENT');

    expect(fn () => app(\App\Application\Documents\Engine\DocumentEngine::class)->issueProviderDocument('SOMETHING_ELSE', ['tenant_id' => $this->tenant->id, 'provider_id' => $this->clinic->id,
        'subject' => ['type' => 'X', 'key' => 'x', 'label' => 'x']]))->toThrow(\Illuminate\Validation\ValidationException::class);
});
