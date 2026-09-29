<?php

declare(strict_types=1);

/**
 * UI coverage batches 16/17 (provider-portal): insurer-staff provider registry, benefit/eligibility desk, provider-side
 * pre-authorization and provider-claim actions (ProviderPortalActions) and the provider panel department form. Every
 * action calls the API's service under the API's permission; refusals are shown to the user.
 */

use App\Application\Health\ProviderClaims\ProviderClaimService;
use App\Application\Providers\Portal\ProviderScope;
use App\Application\Providers\ProviderNetworkService;
use App\Application\Providers\ProviderRegistry;
use App\Application\Providers\Workspace\Filament\Pages\FacilitiesPage;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Admin\Pages\HealthBenefitSchedules;
use App\Filament\Admin\Pages\HealthPreauthorizationQueue;
use App\Filament\Admin\Pages\HealthProviderClaimQueue;
use App\Filament\Admin\Pages\HealthProviderRegister;
use App\Models\Party;
use App\Models\Policy;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

require_once __DIR__.'/../Concerns/wave_auth_helpers.php';
require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $this->f = $f;
    $this->tenant = $f['tenant'];
    $this->policy = Policy::create(['tenant_id' => $this->tenant->id, 'proposal_id' => $f['proposal']->id, 'carrier_id' => $f['carrier']->id, 'party_id' => $f['party']->id,
        'policy_number' => 'POL-'.Str::upper(Str::random(8)), 'status' => 'ACTIVE', 'coverage_starts_at' => '2026-01-01', 'coverage_ends_at' => '2026-12-31',
        'terms_snapshot' => ['line_code' => 'HEALTH'], 'version' => 1, 'currency' => 'XAF', 'premium_minor' => 1_000_000, 'issued_at' => now()]);
    $net = app(ProviderNetworkService::class);
    $this->cons = $net->addMedicalService(['code' => 'CONS_PPA', 'name' => 'GP consultation', 'category_code' => 'OUTPATIENT']);
    $reg = app(ProviderRegistry::class);
    $this->clinic = $reg->register(['category' => 'HEALTH', 'name' => 'Clinique PPA', 'provider_type_code' => 'CLINIC'], null);
    foreach (['APPLICATION', 'UNDER_REVIEW', 'APPROVED', 'ACTIVE'] as $to) {
        $reg->transition($this->clinic->id, $to, null, null, null);
    }
    $this->main = $reg->addFacility($this->clinic->id, ['code' => 'MAIN', 'name' => 'Main']);
    $network = $net->createNetwork($this->tenant->id, ['code' => 'PPANET', 'name' => 'PPA network', 'network_type_code' => 'PREFERRED', 'category' => 'HEALTH'], null);
    $net->addMember($this->tenant->id, $network->id, ['provider_id' => $this->clinic->id, 'effective_from' => '2026-01-01'], null);
    $this->contract = $net->createContract($this->tenant->id, $network->id, ['provider_id' => $this->clinic->id, 'contract_number' => 'PPA-001', 'effective_from' => '2026-01-01'], null);
    $t = $net->draftTariff($this->tenant->id, $this->contract->id, '2026-01-01', 'XAF',
        [['medical_service_id' => $this->cons->id, 'price_minor' => 20000, 'contracted_price_minor' => 15000, 'copay_minor' => 3000, 'insurer_share_percent' => 80]], (string) Str::uuid());
    $net->approveTariff($this->tenant->id, $t->id, (string) Str::uuid());
});

function ppaStaff(object $t, array $perms): User
{
    $u = makeAuthTestUser($t->tenant, $perms);
    test()->actingAs($u, 'web');
    app(TenantContext::class)->set($t->tenant->id);
    Filament::setCurrentPanel(Filament::getPanel('admin'));

    return $u;
}

function ppaProviderUser(object $t, array $perms): User
{
    $person = Party::create(['type' => 'PERSON', 'display_name' => 'Staff '.Str::random(4), 'status' => 'ACTIVE']);
    DB::table('party_relationships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => null, 'from_party_id' => $person->id, 'to_party_id' => $t->clinic->party_id,
        'type' => 'EMPLOYED_BY', 'status' => 'ACTIVE', 'details' => '{}', 'created_at' => now(), 'updated_at' => now()]);
    $u = makeAuthTestUser($t->tenant, $perms, 'PROVIDER_ADMIN');
    $u->update(['party_id' => $person->id]);
    test()->actingAs($u->refresh(), 'web');
    app(TenantContext::class)->set($t->tenant->id);
    app()->instance(ProviderScope::class.'@panel', new ProviderScope($t->clinic->id, [$t->clinic->id], $t->clinic->party_id, null));

    return $u;
}

it('provider registry: staff with providers.manage register a provider, add facility, service, code mapping, relationship and a medical service; credentialing needs providers.credential', function () {
    ppaStaff($this, ['providers.view', 'providers.manage']);
    $page = Livewire::test(HealthProviderRegister::class)->assertOk()->assertSee('Clinique PPA')
        ->assertTableActionVisible('providerAddFacility', $this->clinic->id)->assertTableActionHidden('providerCredential', $this->clinic->id);

    $page->callTableAction('providerRegister', data: ['category' => 'HEALTH', 'name' => 'Hôpital Neuf', 'provider_type_code' => 'HOSPITAL'])->assertHasNoTableActionErrors();
    $new = DB::table('provider_profiles')->join('parties', 'parties.id', '=', 'provider_profiles.party_id')->where('parties.display_name', 'Hôpital Neuf')->select('provider_profiles.*')->first();
    expect($new)->not->toBeNull()->and($new->credentialing_status)->toBe('PROSPECT');

    $page->callTableAction('medicalServiceAdd', data: ['code' => 'LAB_PPA', 'name' => 'Blood test', 'category_code' => 'LAB'])->assertHasNoTableActionErrors();
    $lab = DB::table('medical_services')->where('code', 'LAB_PPA')->first();
    expect($lab)->not->toBeNull();

    Livewire::test(HealthProviderRegister::class)->callTableAction('providerAddFacility', $this->clinic->id, ['code' => 'ANNEX', 'name' => 'Annex'])->assertHasNoTableActionErrors();
    expect(DB::table('provider_facilities')->where(['provider_profile_id' => $this->clinic->id, 'code' => 'ANNEX'])->exists())->toBeTrue();

    Livewire::test(HealthProviderRegister::class)->callTableAction('providerAddFacilityService', $this->clinic->id, ['facility_id' => $this->main->id, 'medical_service_id' => $lab->id])
        ->assertHasNoTableActionErrors();
    expect(DB::table('provider_facility_services')->where(['provider_facility_id' => $this->main->id, 'medical_service_id' => $lab->id])->exists())->toBeTrue();

    Livewire::test(HealthProviderRegister::class)->callTableAction('providerMapCode', $this->clinic->id, ['provider_code' => 'CLIN-LAB-1', 'medical_service_id' => $lab->id])
        ->assertHasNoTableActionErrors();
    expect(DB::table('provider_service_code_mappings')->where('provider_code', 'CLIN-LAB-1')->exists())->toBeTrue();

    Livewire::test(HealthProviderRegister::class)->callTableAction('providerRelate', $this->clinic->id, ['to_party_id' => $this->f['carrier']->party_id ?? $new->party_id, 'type' => 'NETWORK_PROVIDER_FOR'])
        ->assertHasNoTableActionErrors();
    expect(DB::table('party_relationships')->where(['from_party_id' => $this->clinic->party_id, 'type' => 'NETWORK_PROVIDER_FOR'])->exists())->toBeTrue();

    ppaStaff($this, ['providers.view', 'providers.credential']);
    Livewire::test(HealthProviderRegister::class)->assertTableActionHidden('providerAddFacility', $new->id)
        ->callTableAction('providerCredential', $new->id, ['to' => 'APPLICATION'])->assertHasNoTableActionErrors();
    expect(DB::table('provider_profiles')->where('id', $new->id)->value('credentialing_status'))->toBe('APPLICATION');
});

it('provider registry: hidden without a provider permission; a service refusal is shown and nothing changes', function () {
    ppaStaff($this, ['claims.view']);
    expect(HealthProviderRegister::canAccess())->toBeFalse()->and(HealthBenefitSchedules::canAccess())->toBeFalse();
    $this->get('/admin/health/providers')->assertForbidden();

    ppaStaff($this, ['providers.view', 'providers.manage']);
    // Duplicate facility code: ProviderRegistry refuses; the user sees a danger notification.
    Livewire::test(HealthProviderRegister::class)->callTableAction('providerAddFacility', $this->clinic->id, ['code' => 'MAIN', 'name' => 'Main again'])
        ->assertNotified(__('workflow_actions.failed'));
    expect(DB::table('provider_facilities')->where('provider_profile_id', $this->clinic->id)->count())->toBe(1);
});

it('benefit desk: a schedule line is created through BenefitSchedule; eligibility check runs through EligibilityService', function () {
    $product = DB::table('insurance_products')->value('id');
    ppaStaff($this, ['health.benefits.view', 'health.benefits.manage', 'health.eligibility.check']);
    Livewire::test(HealthBenefitSchedules::class)->assertOk()->assertTableActionVisible('eligibilityCheck')->assertTableActionHidden('eligibilityScan')
        ->callTableAction('benefitScheduleCreate', data: ['insurance_product_id' => $product, 'benefit_code' => 'OP_PPA', 'effective_from' => '2026-01-01', 'currency' => 'XAF', 'period_limit_minor' => 500000])
        ->assertHasNoTableActionErrors();
    expect(DB::table('health_benefit_schedules')->where(['benefit_code' => 'OP_PPA', 'tenant_id' => $this->tenant->id])->exists())->toBeTrue();

    $before = DB::table('health_eligibility_checks')->count();
    Livewire::test(HealthBenefitSchedules::class)->callTableAction('eligibilityCheck', data: ['member_ref' => 'UNKNOWN-MEMBER', 'service_code' => 'CONS_PPA']);
    expect(DB::table('health_eligibility_checks')->count())->toBe($before + 1);
});

it('pre-authorization queue: staff request on behalf of a provider, answer a query, admit, extend and discharge through PreauthorizationService', function () {
    ppaStaff($this, ['health.preauth.view', 'health.preauth.request']);
    Livewire::test(HealthPreauthorizationQueue::class)->callTableAction('preauthRequest', data: ['request_type' => 'ADMISSION', 'policy_id' => $this->policy->id,
        'member_ref' => $this->f['party']->id, 'provider_id' => $this->clinic->id,
        'details' => ['admission_date' => '2026-03-10', 'expected_discharge_date' => '2026-03-12', 'diagnosis_code' => 'A09', 'admission_reason' => 'Dehydration'],
        'lines' => [['service_code' => 'CONS_PPA', 'quantity' => 1, 'unit_price_minor' => 15000]]])->assertHasNoTableActionErrors();
    $pa = DB::table('health_preauthorizations')->where('provider_profile_id', $this->clinic->id)->first();
    expect($pa)->not->toBeNull()->and($pa->status)->toBe('REQUESTED')->and($pa->request_type)->toBe('ADMISSION');

    DB::table('health_preauthorizations')->where('id', $pa->id)->update(['status' => 'INFO_REQUESTED']);
    Livewire::test(HealthPreauthorizationQueue::class)->assertTableActionHidden('preauthAdmit', $pa->id)
        ->callTableAction('preauthProvideInfo', $pa->id, ['answer' => 'Lab results attached'])->assertHasNoTableActionErrors();
    expect(DB::table('health_preauthorizations')->where('id', $pa->id)->value('status'))->toBe('REQUESTED');

    DB::table('health_preauthorizations')->where('id', $pa->id)->update(['status' => 'APPROVED', 'gop_valid_until' => '2026-03-12']);
    Livewire::test(HealthPreauthorizationQueue::class)->callTableAction('preauthAdmit', $pa->id, ['admitted_on' => '2026-03-10'])->assertHasNoTableActionErrors();
    expect(DB::table('health_preauthorizations')->where('id', $pa->id)->value('status'))->toBe('ADMITTED');

    Livewire::test(HealthPreauthorizationQueue::class)->callTableAction('preauthRequestExtension', $pa->id, ['requested_until' => '2026-03-15', 'reason' => 'Complications'])
        ->assertHasNoTableActionErrors();
    expect(DB::table('health_preauthorization_extensions')->where('health_preauthorization_id', $pa->id)->count())->toBe(1);

    // A pending extension blocks the discharge: the service refusal is shown, the stay stays ADMITTED.
    Livewire::test(HealthPreauthorizationQueue::class)->callTableAction('preauthDischarge', $pa->id, ['discharged_on' => '2026-03-12'])
        ->assertNotified(__('workflow_actions.failed'));
    expect(DB::table('health_preauthorizations')->where('id', $pa->id)->value('status'))->toBe('ADMITTED');
    DB::table('health_preauthorization_extensions')->where('health_preauthorization_id', $pa->id)->update(['status' => 'DECLINED']);
    Livewire::test(HealthPreauthorizationQueue::class)->callTableAction('preauthDischarge', $pa->id, ['discharged_on' => '2026-03-12'])->assertHasNoTableActionErrors();
    expect(DB::table('health_preauthorizations')->where('id', $pa->id)->value('status'))->toBe('DISCHARGED');

    // Review-only staff do not see the provider-side actions.
    ppaStaff($this, ['health.preauth.view']);
    Livewire::test(HealthPreauthorizationQueue::class)->assertTableActionHidden('preauthRequest');
});

it('provider-claim queue: staff capture and submit a provider claim, then dispute the decision through ProviderClaimService', function () {
    ppaStaff($this, ['health.provider_claims.view', 'health.provider_claims.capture', 'health.provider_claims.dispute']);
    Livewire::test(HealthProviderClaimQueue::class)->callTableAction('providerClaimCreate', data: ['provider_id' => $this->clinic->id, 'contract_id' => $this->contract->id,
        'invoice_reference' => 'PPA-INV-1', 'service_date' => '2026-03-10', 'policy_id' => $this->policy->id,
        'lines' => [['medical_service_id' => $this->cons->id, 'provider_code' => null, 'quantity' => 1, 'unit_price_minor' => 15000]]])->assertHasNoTableActionErrors();
    $claim = DB::table('health_provider_claims')->where('invoice_reference', 'PPA-INV-1')->first();
    expect($claim)->not->toBeNull()->and($claim->status)->toBe('DRAFT');

    Livewire::test(HealthProviderClaimQueue::class)->callTableAction('providerClaimSubmit', $claim->id)->assertHasNoTableActionErrors();
    expect(DB::table('health_provider_claims')->where('id', $claim->id)->value('status'))->toBe('SUBMITTED');

    DB::table('health_provider_claims')->where('id', $claim->id)->update(['status' => 'REJECTED']);
    Livewire::test(HealthProviderClaimQueue::class)->callTableAction('providerClaimDispute', $claim->id, ['reason' => 'Service was contracted'])->assertHasNoTableActionErrors();
    expect(DB::table('health_provider_claims')->where('id', $claim->id)->value('status'))->toBe('DISPUTED');

    ppaStaff($this, ['health.provider_claims.view']);
    Livewire::test(HealthProviderClaimQueue::class)->assertTableActionHidden('providerClaimCreate');
});

it('provider panel: a facility manager adds a department and a service unit through ProviderAccess::addDepartment; a duplicate is refused visibly; others get no form', function () {
    ppaProviderUser($this, ['provider_portal.profile.view', 'provider.settings.manage']);
    $p = Livewire::test(FacilitiesPage::class)->assertSee(__('provider_portal_actions.department.heading'))
        ->set('department_facility', $this->main->id)->set('department_code', 'cardio')->set('department_name', 'Cardiology')->call('addDepartment')->assertSet('state', 'SUCCESS');
    $dept = DB::table('provider_departments')->where(['provider_facility_id' => $this->main->id, 'code' => 'CARDIO'])->first();
    expect($dept)->not->toBeNull()->and($dept->level)->toBe('DEPARTMENT');

    $p->set('department_level', 'SERVICE_UNIT')->set('parent_department_id', $dept->id)->set('department_code', 'ECHO')->set('department_name', 'Echo lab')
        ->call('addDepartment')->assertSet('state', 'SUCCESS');
    expect(DB::table('provider_departments')->where(['code' => 'ECHO', 'parent_department_id' => $dept->id])->exists())->toBeTrue();

    Livewire::test(FacilitiesPage::class)->set('department_facility', $this->main->id)->set('department_code', 'CARDIO')->set('department_name', 'Again')
        ->call('addDepartment')->assertSet('state', 'VALIDATION_FAILED')->assertSee('already exists');
    expect(DB::table('provider_departments')->where('code', 'CARDIO')->count())->toBe(1);

    ppaProviderUser($this, ['provider_portal.profile.view']);
    Livewire::test(FacilitiesPage::class)->assertDontSee(__('provider_portal_actions.department.heading'))->call('addDepartment')->assertForbidden();
});
