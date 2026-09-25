<?php

declare(strict_types=1);

/**
 * Workflow Institutional Data Master v1 (database/data/workflow_institutional_data_master_2026.json):
 * REQ-DRM-001 data-status vocabulary + mapping, REQ-DRM-002 production-use guard, REQ-DRM-003 Data Readiness registry,
 * REQ-DRM-004 reconciliation (capabilities, agreements, authority, KYC, case families/priorities, notifications,
 * complaints, documents, document requirement rules).
 */

use App\Application\Capabilities\CapabilityCatalogue;
use App\Application\Cases\CaseTypeCatalogue;
use App\Application\Cases\Models\CaseType;
use App\Application\DataReadiness\DataReadinessRegistry;
use App\Application\DataReadiness\DataStatus;
use App\Application\DataReadiness\NonProductionDataException;
use App\Application\DataReadiness\ProductionUseGuard;
use App\Application\DataReadiness\WorkflowDataMaster;
use App\Application\Documents\Engine\DocumentRegister;
use App\Application\Kyc\KycRequirementService;
use App\Application\Kyc\Risk\CustomerRiskRatingService;
use App\Application\Kyc\Screening\ScreeningMode;
use App\Application\Notifications\NotificationVocabulary;
use App\Application\Rating\ChargeTableService;
use App\Models\Tenant;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

require_once __DIR__.'/../Concerns/wave_auth_helpers.php';
require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::create(['id' => (string) Str::uuid(), 'type' => 'PLATFORM', 'slug' => 'drm-'.Str::random(6), 'legal_name' => 'DRM', 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en', 'settings' => []]);
});

it('REQ-DRM-001: the seven status codes are the vocabulary and existing stored statuses map to them without renaming', function () {
    $file = app(WorkflowDataMaster::class)->dataset()['status_codes'];
    expect(DataStatus::CODES)->toBe($file)
        ->and(DataStatus::normalize('DEMO_UNVERIFIED'))->toBe('DEMO_ONLY')
        ->and(DataStatus::normalize('DEMO'))->toBe('DEMO_ONLY')
        ->and(DataStatus::normalize('PENDING_VERIFICATION'))->toBe('UNVERIFIED')
        ->and(DataStatus::normalize('UNVERIFIED'))->toBe('UNVERIFIED')
        ->and(DataStatus::normalize('OWNER_CONFIRMED'))->toBe('VERIFIED')
        ->and(DataStatus::normalize('VERIFIED_RULE'))->toBe('VERIFIED')
        ->and(DataStatus::normalize('LEGACY_ACTIVE_AUTHORIZATION_PENDING_VERIFICATION'))->toBe('UNVERIFIED')
        ->and(DataStatus::normalize('SOMETHING_NEW'))->toBe('UNVERIFIED') // fail closed
        ->and(DataStatus::isProduction('PLATFORM_NORMALIZED'))->toBeTrue()
        ->and(DataStatus::isProduction('CONFIG_REQUIRED'))->toBeFalse()
        ->and(DataStatus::mapping()['DEMO_ONLY'])->toContain('DEMO_UNVERIFIED');
});

it('REQ-DRM-002: the production-use guard refuses non-production values on a real production host only (demo ON = labelled)', function () {
    expect(fn () => ProductionUseGuard::assertUsable('sla_targets', 'CONFIG_REQUIRED', 'x'))->not->toThrow(NonProductionDataException::class)
        ->and(ProductionUseGuard::inspect('kyc', 'PLATFORM_DEFAULT_UNVERIFIED')['production_usable'])->toBeFalse();

    config(['data_readiness.enforce' => true]);
    foreach (['PENDING_SOURCE', 'CONFIG_REQUIRED', 'UNVERIFIED', 'DEMO_ONLY', 'DEMO_UNVERIFIED', 'PENDING_VERIFICATION'] as $s) {
        expect(fn () => ProductionUseGuard::assertUsable('regulatory_reports', $s, 'r'))->toThrow(NonProductionDataException::class);
    }
    expect(fn () => ProductionUseGuard::assertUsable('rating', 'VERIFIED'))->not->toThrow(NonProductionDataException::class)
        ->and(ProductionUseGuard::usableRows([['s' => 'VERIFIED'], ['s' => 'DEMO']], 's'))->toHaveCount(1)
        // the charge-table guard now delegates to the central guard
        ->and(fn () => ChargeTableService::assertUsable('tax_levy_versions', (object) ['id' => 'x', 'data_status' => 'DEMO_UNVERIFIED']))->toThrow(DomainException::class, 'cannot be used in production')
        ->and(fn () => ChargeTableService::assertUsable('tax_levy_versions', (object) ['id' => 'y', 'data_status' => 'OWNER_CONFIRMED']))->not->toThrow(DomainException::class);

    config(['data_readiness.enforce' => null]);
    app()->detectEnvironment(fn () => 'production');
    try {
        config(['demo.enabled' => true]);
        expect(ProductionUseGuard::enforced())->toBeFalse();
        config(['demo.enabled' => false]);
        expect(ProductionUseGuard::enforced())->toBeTrue();
    } finally {
        app()->detectEnvironment(fn () => 'testing');
    }
});

it('REQ-DRM-003: the registry lists every data-master domain with status, owner, source and what is missing', function () {
    $registry = app(DataReadinessRegistry::class);
    $items = collect($registry->items());
    $domains = app(WorkflowDataMaster::class)->domains();

    expect($items->pluck('domain')->unique()->sort()->values()->all())->toBe(collect($domains)->sort()->values()->all());
    $items->each(fn ($i) => expect(DataStatus::CODES)->toContain($i['status']));
    expect($items->every(fn ($i) => $i['owner'] !== 'Unassigned'))->toBeTrue();

    $get = fn ($d, $item) => $items->first(fn ($i) => $i['domain'] === $d && $i['item'] === $item);
    expect($get('regulatory', 'insurer_authorizations')['status'])->toBe('PENDING_SOURCE')
        ->and($get('regulatory', 'taxes_levies_fees')['status'])->toBe('PENDING_SOURCE')
        ->and($get('regulatory', 'taxes_levies_fees')['production_usable'])->toBeFalse()
        ->and($get('accounting', 'chart_of_accounts')['status'])->toBe('CONFIG_REQUIRED')
        ->and($get('ict_controls', 'cima_010_24_control_taxonomy')['status'])->toBe('PENDING_SOURCE')
        ->and($get('fraud_controls', 'decision_rule')['status'])->toBe('VERIFIED')
        ->and($get('case_management', 'case_families')['status'])->toBe('VERIFIED')
        ->and($get('broker_insurer_agreements', 'required_fields')['missing'])->toBe([])
        ->and($get('kyc_aml', 'pep_provider')['status'])->toBe('CONFIG_REQUIRED')
        ->and($get('regulatory', 'taxes_levies_fees')['source'])->toContain('tax_levy_versions');

    // Computed from real data: an OWNER_CONFIRMED tax table moves taxes out of PENDING_SOURCE.
    DB::table('tax_levy_versions')->insert(['id' => (string) Str::uuid(), 'jurisdiction' => 'CM', 'line_code' => 'MOTOR', 'version' => 1, 'effective_from' => '2026-01-01',
        'status' => 'APPROVED', 'rules' => '{}', 'rules_hash' => str_repeat('a', 64), 'data_status' => 'OWNER_CONFIRMED', 'verification_status' => 'VERIFIED',
        'legal_basis' => 'test basis', 'source_reference' => 'test source', 'verification_requested_by' => makeAuthTestUser($this->tenant, [])->id, 'verified_by' => makeAuthTestUser($this->tenant, [])->id, 'verified_at' => now(),
        'created_at' => now(), 'updated_at' => now()]);
    $row = collect($registry->domain('regulatory'))->firstWhere('item', 'taxes_levies_fees');
    expect($row['status'])->toBe('VERIFIED')->and($row['declared_status'])->toBe('PENDING_SOURCE');

    // Other domain owners can plug computed checks.
    DataReadinessRegistry::extend('aviation', fn () => [app(DataReadinessRegistry::class)->row('aviation', 'airport_master', 'PENDING_SOURCE', 'VERIFIED', [], 'test')]);
    expect(collect($registry->domain('aviation'))->firstWhere('item', 'airport_master')['status'])->toBe('VERIFIED');
});

it('REQ-DRM-003: GET /v1/data-readiness needs data_readiness.view; the admin page renders for platform admins', function () {
    $h = ['X-Tenant-ID' => $this->tenant->id];
    Passport::actingAs(makeAuthTestUser($this->tenant, ['cases.view']), [], 'api');
    $this->getJson('/api/v1/data-readiness', $h)->assertForbidden();

    Passport::actingAs(makeAuthTestUser($this->tenant, ['data_readiness.view']), [], 'api');
    $r = $this->getJson('/api/v1/data-readiness?status=PENDING_SOURCE', $h)->assertOk();
    expect(collect($r->json('data'))->pluck('status')->unique()->all())->toBe(['PENDING_SOURCE'])
        ->and($r->json('meta.by_status.PENDING_SOURCE'))->toBeGreaterThan(0);
    expect($this->getJson('/api/v1/data-readiness?domain=documents', $h)->assertOk()->json('data.0.domain'))->toBe('documents');
    expect($this->getJson('/api/v1/data-readiness/vocabulary', $h)->assertOk()->json('data.codes'))->toBe(DataStatus::CODES);

    $admin = makeMobileTenantStaffUser($this->tenant, '+237670009901', 'PLATFORM_ADMIN');
    $this->actingAs($admin, 'web')->get('/admin/data-readiness')->assertOk()->assertSee('Data readiness')->assertSee('insurer_authorizations')->assertSee('PENDING_SOURCE');
    expect($this->actingAs(makeMobileTenantStaffUser($this->tenant, '+237670009902', 'CLAIMS_OFFICER'), 'web')->get('/admin/data-readiness')->status())->not->toBe(200);
});

it('REQ-DRM-004: capabilities, authority types, KYC dimensions / screening modes / levels are reconciled with the owner lists', function () {
    $m = app(WorkflowDataMaster::class);
    foreach ($m->get('organization_capabilities.capabilities') as $c) {
        expect(CapabilityCatalogue::resolve($c))->not->toBe([]);
    }
    expect(CapabilityCatalogue::resolve('API'))->toBe(['API_INTEGRATION'])->and(CapabilityCatalogue::resolve('ENDORSEMENT'))->toBe(['ENDORSEMENT']);

    $active = DB::table('authority_types')->where('status', 'ACTIVE')->pluck('code')->all();
    expect(array_diff($m->get('authority.authority_types'), $active))->toBe([]);

    expect(array_keys(CustomerRiskRatingService::DIMENSIONS))->toBe($m->get('kyc_aml.risk_dimensions'))
        ->and(ScreeningMode::MODES)->toBe($m->get('kyc_aml.screening_modes'))
        ->and(ScreeningMode::configurationStatus('EXTERNAL_PROVIDER'))->toBe('CONFIG_REQUIRED')
        ->and(ScreeningMode::configurationStatus('MANUAL'))->toBe('PLATFORM_NORMALIZED')
        ->and(ScreeningMode::isAutomated('EXTERNAL_PROVIDER'))->toBeFalse()
        ->and(KycRequirementService::normalizeLevel('BASIC'))->toBe('SIMPLIFIED');
});

it('REQ-DRM-004: owner case families replace the reconstructed ones (remapped, not deleted); CRITICAL priority accepted', function () {
    $owner = app(WorkflowDataMaster::class)->get('case_management.case_families');
    expect(DB::table('case_families')->where('active', true)->orderBy('sort_order')->pluck('code')->all())->toBe($owner)
        ->and(DB::table('case_families')->where('code', 'RECOVERY_LEGAL')->value('active'))->toBeFalse() // kept as history
        ->and(CaseType::whereNotIn('family_code', $owner)->count())->toBe(0)
        ->and(CaseType::where('code', 'CLAIM_INVESTIGATION')->value('family_code'))->toBe('FRAUD_REVIEW')
        ->and(CaseType::where('code', 'PROVIDER_DISPUTE')->value('family_code'))->toBe('PROVIDER')
        ->and(CaseTypeCatalogue::PRIORITIES)->toEqualCanonicalizing(app(WorkflowDataMaster::class)->get('case_management.priorities'));

    $type = CaseType::where('code', 'CONFIG_GAP')->where('status', 'EFFECTIVE')->firstOrFail();
    $id = (string) Str::uuid();
    DB::table('cases')->insert(['id' => $id, 'tenant_id' => $this->tenant->id, 'case_number' => 'DRM-1', 'case_type_id' => $type->id, 'case_type_code' => 'CONFIG_GAP',
        'title' => 't', 'status' => 'OPEN', 'priority' => 'CRITICAL', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    expect(DB::table('cases')->where('id', $id)->value('priority'))->toBe('CRITICAL');
    expect(fn () => DB::table('cases')->where('id', $id)->update(['priority' => 'PANIC']))->toThrow(QueryException::class);
});

it('REQ-DRM-004: agreements carry the owner rights, settlement terms and source document', function () {
    foreach (['can_issue_documents', 'can_service_policies', 'can_assist_claims'] as $c) {
        expect(\Illuminate\Support\Facades\Schema::hasColumn('carrier_broker_agreement_products', $c))->toBeTrue();
    }
    expect(\Illuminate\Support\Facades\Schema::hasColumns('carrier_broker_agreements', ['settlement_terms', 'source_document', 'territories']))->toBeTrue()
        ->and(\App\Application\CarrierOperations\Agreements\CarrierBrokerAgreementService::ACTIONS)->toHaveKeys(['issue_documents', 'service_policy', 'assist_claims']);
});

it('REQ-DRM-004: notification, complaint, document and requirement vocabularies map onto the existing lists', function () {
    $m = app(WorkflowDataMaster::class);
    expect(NotificationVocabulary::CHANNELS)->toBe($m->get('notifications.channels'))
        ->and(array_diff($m->get('notifications.delivery_statuses'), NotificationVocabulary::DELIVERY_STATUSES))->toBe([])
        ->and(NotificationVocabulary::deliveryStatus('DEAD_LETTERED'))->toBe('FAILED')
        ->and(array_keys(CaseTypeCatalogue::COMPLAINT_SEVERITY_MAP))->toBe($m->get('complaints.severity_levels'))
        ->and(DocumentRegister::SEAL_TYPES)->toBe($m->get('documents.seal_types'));
    foreach ($m->get('documents.confidentiality_classes') as $c) {
        expect(DocumentRegister::SECURITY_LEVELS)->toContain(DocumentRegister::CONFIDENTIALITY_CLASS_MAP[$c]);
    }
    expect(array_diff($m->get('document_requirement_rules.requirement_types'), \App\Application\DocumentCatalogue\ProductDocumentRequirementService::REQUIREMENTS))->toBe([])
        ->and(array_keys(\App\Application\DocumentCatalogue\ProductDocumentRequirementService::LIFECYCLE_STAGE_MAP))->toBe($m->get('document_requirement_rules.lifecycle_stages'));

    // READ / BOUNCED are storable delivery statuses now.
    $sql = DB::selectOne("SELECT pg_get_constraintdef(oid) AS d FROM pg_constraint WHERE conname = 'notification_status_check'");
    expect($sql->d)->toContain('BOUNCED')->toContain('READ');
});
