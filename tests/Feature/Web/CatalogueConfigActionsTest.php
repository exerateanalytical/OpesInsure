<?php

declare(strict_types=1);

/**
 * UI coverage batches 18 (catalogue) and 28 (question sets, reference datasets, regulatory reference sets, configuration
 * inheritance, marketplace publications): CatalogueActions / ReferenceConfigActions call the same services, with the
 * same permissions, as the API routes.
 */

use App\Application\Catalogue\ProductConfigurationService;
use App\Application\Catalogue\ProductModelService;
use App\Application\Catalogue\Sandbox\ProductSandbox;
use App\Application\Rules\Models\QuestionSet;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Admin\Pages\Catalogue\CatalogueCarrierProducts;
use App\Filament\Admin\Pages\Catalogue\ConfigurationOverrides;
use App\Filament\Admin\Pages\Catalogue\MarketplacePublications;
use App\Filament\Admin\Pages\Catalogue\QuestionSets;
use App\Filament\Admin\Pages\Catalogue\ReferenceDatasets;
use App\Filament\Admin\Pages\Catalogue\RegulatoryReferenceSets;
use App\Filament\Admin\Resources\ExclusionDefinitions\Pages\ViewExclusionDefinition;
use App\Filament\Admin\Resources\InsuranceLines\Pages\ListInsuranceLines;
use App\Filament\Admin\Resources\InsuranceLines\Pages\ViewInsuranceLine;
use App\Filament\Admin\Resources\InsuranceProducts\Pages\ViewInsuranceProduct;
use App\Models\Carrier;
use App\Models\Catalogue\CarrierProduct;
use App\Models\Catalogue\CoverageLimit;
use App\Models\Catalogue\ExclusionLegalText;
use App\Models\Catalogue\ProductPlan;
use App\Models\CoverageDefinition;
use App\Models\ExclusionDefinition;
use App\Models\InsuranceLine;
use App\Models\InsuranceProduct;
use App\Models\MarketplacePublication;
use App\Models\Party;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

require_once __DIR__.'/../Concerns/wave_auth_helpers.php';
require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

uses(RefreshDatabase::class);

function catUiAs(User $u): void
{
    test()->actingAs($u);
    app(TenantContext::class)->set(test()->tenant->id);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
}

beforeEach(function () {
    $this->tenant = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999))['tenant'];
    $this->maker = makeAuthTestUser($this->tenant, ['catalogue.view', 'catalogue.manage', 'catalogue.test', 'rules.view', 'rules.manage',
        'reference_datasets.view', 'reference_datasets.manage', 'configuration.regulatory.manage', 'configuration.changes.manage',
        'marketplace.publications.view', 'marketplace.publications.create'], 'PLATFORM_ADMIN');
    $this->checker = makeAuthTestUser($this->tenant, ['catalogue.view', 'catalogue.publish', 'rules.view', 'rules.approve', 'reference_datasets.view',
        'reference_datasets.approve', 'configuration.regulatory.approve', 'marketplace.publications.view', 'marketplace.publications.approve'], 'PLATFORM_ADMIN');
    $this->nobody = makeAuthTestUser($this->tenant, ['claims.view'], 'PLATFORM_ADMIN');

    $this->line = InsuranceLine::firstOrCreate(['code' => 'CUI_MOTOR'], ['name' => ['en' => 'Motor', 'fr' => 'Auto'], 'status' => 'ACTIVE', 'risk_schema' => []]);
    $this->rc = CoverageDefinition::firstOrCreate(['insurance_line_id' => $this->line->id, 'code' => 'RC'], ['name' => ['en' => 'RC', 'fr' => 'RC'], 'limit_type' => 'FIXED_AMOUNT', 'mandatory' => true, 'status' => 'ACTIVE']);
    $this->carrier = Carrier::create(['party_id' => Party::create(['type' => 'ORGANIZATION', 'display_name' => 'CUI Assurances', 'status' => 'ACTIVE'])->id, 'cima_code' => 'CUI-'.Str::random(6), 'status' => 'ACTIVE', 'capabilities' => []]);
    $svc = app(ProductModelService::class);
    $this->cp = $svc->createCarrierProduct(['carrier_id' => $this->carrier->id, 'code' => 'CUI_'.Str::random(4), 'line_code' => 'CUI_MOTOR',
        'name' => ['en' => 'Auto Plus', 'fr' => 'Auto Plus'], 'customer_type' => 'INDIVIDUAL'], $this->maker);
    $this->v = $svc->newVersion($this->cp, ['effective_from' => '2026-01-01', 'regulatory_reference' => 'CUI-REF'], $this->maker);
});

it('shows the catalogue and configuration screens and actions only with the API permissions', function () {
    catUiAs($this->nobody);
    foreach ([CatalogueCarrierProducts::class, QuestionSets::class, ReferenceDatasets::class, RegulatoryReferenceSets::class, ConfigurationOverrides::class, MarketplacePublications::class] as $page) {
        expect($page::canAccess())->toBeFalse();
    }

    catUiAs($this->maker);
    foreach ([CatalogueCarrierProducts::class, QuestionSets::class, ReferenceDatasets::class, RegulatoryReferenceSets::class, ConfigurationOverrides::class, MarketplacePublications::class] as $page) {
        expect($page::canAccess())->toBeTrue();
        Livewire::test($page)->assertOk();
    }
    Livewire::test(ViewInsuranceProduct::class, ['record' => $this->v->id])->assertOk()
        ->assertActionVisible('catAddPlan')->assertActionVisible('catAddLimit')->assertActionHidden('catTransition')
        ->assertActionHidden('catSubmitProduct'); // governed version: direct submit path is not offered

    catUiAs($this->checker);
    Livewire::test(ViewInsuranceProduct::class, ['record' => $this->v->id])->assertOk()
        ->assertActionHidden('catAddPlan')->assertActionVisible('catIndemnityPreview');
});

it('manages lines, coverages, exclusions and maker-checker legal texts through CatalogueService and ExclusionLegalTextService', function () {
    catUiAs($this->maker);
    Livewire::test(ListInsuranceLines::class)->callAction('catCreateLine', ['code' => 'CUI_HOME', 'name' => ['en' => 'Home', 'fr' => 'Habitation'], 'risk_schema' => ['type' => 'object']])
        ->assertHasNoActionErrors()->assertNotified(__('catalogue_actions.catCreateLine.done'));
    $home = InsuranceLine::where('code', 'CUI_HOME')->firstOrFail();

    Livewire::test(ViewInsuranceLine::class, ['record' => $home->id])
        ->callAction('catCreateCoverage', ['code' => 'FIRE', 'name' => ['en' => 'Fire', 'fr' => 'Incendie'], 'limit_type' => 'FIXED_AMOUNT', 'mandatory' => true])
        ->assertNotified(__('catalogue_actions.catCreateCoverage.done'))
        ->callAction('catCreateExclusion', ['code' => 'WAR', 'name' => ['en' => 'War', 'fr' => 'Guerre']])
        ->assertNotified(__('catalogue_actions.catCreateExclusion.done'));
    expect(CoverageDefinition::where('insurance_line_id', $home->id)->where('code', 'FIRE')->value('mandatory'))->toBeTrue();
    $war = ExclusionDefinition::where('insurance_line_id', $home->id)->where('code', 'WAR')->firstOrFail();

    Livewire::test(ViewExclusionDefinition::class, ['record' => $war->id])
        ->callAction('catDraftLegalText', ['text' => ['en' => 'War is excluded.', 'fr' => 'La guerre est exclue.'], 'effective_from' => '2026-01-01'])
        ->assertNotified(__('catalogue_actions.catDraftLegalText.done'));
    $text = ExclusionLegalText::where('exclusion_definition_id', $war->id)->firstOrFail();

    // The maker also holding catalogue.publish is still refused by the service (maker-checker): failure notification, no change.
    $makerPublisher = makeAuthTestUser($this->tenant, ['catalogue.view', 'catalogue.manage', 'catalogue.publish'], 'PLATFORM_ADMIN');
    $text->forceFill(['created_by' => $makerPublisher->id])->save();
    catUiAs($makerPublisher);
    Livewire::test(ViewExclusionDefinition::class, ['record' => $war->id])->callAction('catApproveLegalText', ['text_id' => $text->id])
        ->assertNotified(__('workflow_actions.failed'));
    expect($text->refresh()->status)->toBe('DRAFT');

    catUiAs($this->checker);
    Livewire::test(ViewExclusionDefinition::class, ['record' => $war->id])->callAction('catApproveLegalText', ['text_id' => $text->id])
        ->assertNotified(__('catalogue_actions.catApproveLegalText.done'));
    expect($text->refresh()->status)->toBe('APPROVED');
});

it('builds families, carrier products and versions through ProductModelService', function () {
    catUiAs($this->maker);
    Livewire::test(CatalogueCarrierProducts::class)
        ->callAction(TestAction::make('catCreateFamily')->table(), ['code' => 'CUI_FAM', 'class_code' => 'MOTOR', 'line_code' => 'CUI_MOTOR', 'name' => ['en' => 'Private', 'fr' => 'Particulier']])
        ->assertNotified(__('catalogue_actions.catCreateFamily.done'))
        ->callAction(TestAction::make('catCreateCarrierProduct')->table(), ['carrier_id' => $this->carrier->id, 'code' => 'CUI_NEW', 'line_code' => 'CUI_MOTOR', 'name' => ['en' => 'New', 'fr' => 'Nouveau']])
        ->assertNotified(__('catalogue_actions.catCreateCarrierProduct.done'));
    $cp = CarrierProduct::where('code', 'CUI_NEW')->firstOrFail();

    Livewire::test(CatalogueCarrierProducts::class)
        ->callAction(TestAction::make('catUpdateCarrierProduct')->table($cp), ['name' => ['en' => 'Renamed', 'fr' => 'Renommé'], 'market' => 'CM'])
        ->assertNotified(__('catalogue_actions.catUpdateCarrierProduct.done'))
        ->callAction(TestAction::make('catNewVersion')->table($cp), ['effective_from' => '2026-02-01'])
        ->assertNotified(__('catalogue_actions.catNewVersion.done'));
    expect($cp->refresh()->name['en'])->toBe('Renamed')
        ->and(InsuranceProduct::where('carrier_product_id', $cp->id)->where('status', 'DRAFT')->count())->toBe(1);

    // A second draft is refused by the service.
    Livewire::test(CatalogueCarrierProducts::class)->callAction(TestAction::make('catNewVersion')->table($cp), ['effective_from' => '2026-03-01'])
        ->assertNotified(__('workflow_actions.failed'));
});

it('configures a product version (plans, coverage terms, limits, deductibles, exclusions, test cases) through ProductConfigurationService', function () {
    catUiAs($this->maker);
    $excl = ExclusionDefinition::create(['insurance_line_id' => $this->line->id, 'code' => 'RACE', 'name' => ['en' => 'Racing', 'fr' => 'Course'], 'status' => 'ACTIVE']);
    $page = fn () => Livewire::test(ViewInsuranceProduct::class, ['record' => $this->v->id]);

    $page()->callAction('catConfigureCoverage', ['coverage_definition_id' => $this->rc->id, 'inclusion' => 'MANDATORY', 'territory' => 'CEMAC'])
        ->assertNotified(__('catalogue_actions.catConfigureCoverage.done'));
    $page()->callAction('catAddPlan', ['code' => 'BASIC', 'name' => ['en' => 'Basic', 'fr' => 'Basique'], 'coverages' => [['coverage_definition_id' => $this->rc->id, 'inclusion' => 'MANDATORY']]])
        ->assertNotified(__('catalogue_actions.catAddPlan.done'));
    $plan = ProductPlan::where('insurance_product_id', $this->v->id)->where('code', 'BASIC')->firstOrFail();
    $page()->callAction('catSyncPlanCoverages', ['plan_id' => $plan->id, 'coverages' => [['coverage_definition_id' => $this->rc->id, 'inclusion' => 'OPTIONAL']]])
        ->assertNotified(__('catalogue_actions.catSyncPlanCoverages.done'));
    $page()->callAction('catAddLimit', ['coverage_definition_id' => $this->rc->id, 'limit_type' => 'FIXED_AMOUNT', 'amount_minor' => 5000000, 'currency' => 'XAF'])
        ->assertNotified(__('catalogue_actions.catAddLimit.done'));
    $page()->callAction('catAddDeductible', ['coverage_definition_id' => $this->rc->id, 'deductible_type' => 'FIXED', 'amount_minor' => 50000, 'currency' => 'XAF'])
        ->assertNotified(__('catalogue_actions.catAddDeductible.done'));
    $page()->callAction('catAttachExclusion', ['exclusion_definition_id' => $excl->id, 'level' => 'PRODUCT'])
        ->assertNotified(__('catalogue_actions.catAttachExclusion.done'));
    $page()->callAction('catIndemnityPreview', ['coverage_definition_id' => $this->rc->id, 'loss_minor' => 1000000])
        ->assertNotified(__('catalogue_actions.catIndemnityPreview.done'));

    $limit = CoverageLimit::where('insurance_product_id', $this->v->id)->firstOrFail();
    $page()->callAction('catRemoveTerm', ['term' => 'limits:'.$limit->id])->assertNotified(__('catalogue_actions.catRemoveTerm.done'));
    expect(CoverageLimit::whereKey($limit->id)->exists())->toBeFalse()
        ->and(\App\Models\Catalogue\CoverageDeductible::where('insurance_product_id', $this->v->id)->exists())->toBeTrue();

    $case = app(ProductSandbox::class)->addCase($this->v, ['code' => 'OK', 'name' => 'Private car', 'facts' => ['usage' => 'PRIVATE']], $this->maker);
    $page()->callAction('catDeleteTestCase', ['case_id' => $case->id])->assertNotified(__('catalogue_actions.catDeleteTestCase.done'));
    expect(DB::table('product_test_cases')->where('id', $case->id)->exists())->toBeFalse();
});

it('drafts, submits and decides question sets through QuestionSetCatalogue', function () {
    catUiAs($this->maker);
    $schema = json_encode(['fields' => [['key' => 'usage', 'label' => 'Usage', 'type' => 'TEXT']]]);
    Livewire::test(QuestionSets::class)->callAction(TestAction::make('qsCreate')->table(), ['line_code' => 'CUI_MOTOR', 'stage' => 'QUOTE', 'effective_from' => '2026-01-01', 'schema' => $schema])
        ->assertNotified(__('catalogue_actions.qsCreate.done'));
    $set = QuestionSet::where('line_code', 'CUI_MOTOR')->where('status', 'DRAFT')->firstOrFail();

    Livewire::test(QuestionSets::class)->callAction(TestAction::make('qsCreate')->table(), ['line_code' => 'CUI_MOTOR', 'effective_from' => '2026-01-01', 'schema' => 'not json'])
        ->assertNotified(__('workflow_actions.failed'));

    Livewire::test(QuestionSets::class)->callAction(TestAction::make('qsSubmit')->table($set))->assertNotified(__('catalogue_actions.qsSubmit.done'));
    expect($set->refresh()->status)->not->toBe('DRAFT');
    if ($set->status === 'IN_REVIEW') {
        catUiAs($this->checker);
        Livewire::test(QuestionSets::class)->callAction(TestAction::make('qsReject')->table($set), ['note' => 'Missing labels'])
            ->assertNotified(__('catalogue_actions.qsReject.done'));
        expect($set->refresh()->status)->toBeIn(['REJECTED', 'IN_REVIEW']);
    }
});

it('runs reference datasets and regulatory reference sets maker-checker through their services', function () {
    catUiAs($this->maker);
    Livewire::test(ReferenceDatasets::class)->callAction(TestAction::make('rdDraft')->table(), ['kind' => 'PUBLIC_HOLIDAYS', 'jurisdiction' => 'CM', 'code' => 'CUI_HOLIDAYS',
        'source_name' => 'Decree', 'source_reference' => 'D-1', 'effective_from' => '2027-01-01', 'entries' => json_encode([['date' => '2027-01-01', 'label' => 'New Year']])])
        ->assertNotified(__('catalogue_actions.rdDraft.done'));
    $ds = DB::table('reference_datasets')->where('code', 'CUI_HOLIDAYS')->first();

    // Maker cannot activate (and lacks the permission): the action is hidden for them; the checker activates, then retires.
    catUiAs($this->checker);
    Livewire::test(ReferenceDatasets::class)->callAction(TestAction::make('rdActivate')->table($ds->id), ['verification_status' => 'VERIFIED'])
        ->assertNotified(__('catalogue_actions.rdActivate.done'));
    expect(DB::table('reference_datasets')->where('id', $ds->id)->value('status'))->toBe('ACTIVE');
    Livewire::test(ReferenceDatasets::class)->callAction(TestAction::make('rdRetire')->table($ds->id), ['reason' => 'Superseded by decree'])
        ->assertNotified(__('catalogue_actions.rdRetire.done'));
    expect(DB::table('reference_datasets')->where('id', $ds->id)->value('status'))->toBe('RETIRED');

    catUiAs($this->maker);
    Livewire::test(RegulatoryReferenceSets::class)->callAction(TestAction::make('rrsDraft')->table(), ['jurisdiction' => 'CM', 'code' => 'CUI_TAX', 'effective_from' => '2027-01-01',
        'entries' => json_encode(['VAT' => 19.25])])->assertNotified(__('catalogue_actions.rrsDraft.done'));
    $set = DB::table('regulatory_reference_sets')->where('code', 'CUI_TAX')->first();
    catUiAs($this->checker);
    Livewire::test(RegulatoryReferenceSets::class)->callAction(TestAction::make('rrsApprove')->table($set->id), ['reason' => 'Checked against the finance law text'])
        ->assertNotified(__('catalogue_actions.rrsApprove.done'));
    expect(DB::table('regulatory_reference_sets')->where('id', $set->id)->value('status'))->toBe('ACTIVE');
});

it('publishes to the marketplace with an independent approver through PortalWorkspaceService', function () {
    $this->v->forceFill(['status' => 'ACTIVE'])->save();
    catUiAs($this->maker);
    Livewire::test(MarketplacePublications::class)->callAction(TestAction::make('mpPublish')->table(), ['product_id' => $this->v->id, 'channels' => ['WEB']])
        ->assertNotified(__('catalogue_actions.mpPublish.done'));
    $pub = MarketplacePublication::where('product_id', $this->v->id)->firstOrFail();
    expect($pub->status)->toBe('DRAFT');

    catUiAs($this->checker);
    Livewire::test(MarketplacePublications::class)->callAction(TestAction::make('mpApprove')->table($pub))->assertNotified(__('catalogue_actions.mpApprove.done'));
    expect($pub->refresh()->status)->toBe('PUBLISHED');
});

it('drafts a configuration override through ConfigurationInheritance and shows its refusal of a less restrictive value', function () {
    catUiAs($this->maker);
    $insurer = (string) Str::uuid();
    Livewire::test(ConfigurationOverrides::class)->callAction(TestAction::make('cfgDraftOverride')->table('quote.max_discount_basis_points'),
        ['scope_level' => 'INSURER', 'scope_id' => $insurer, 'insurer_id' => $insurer, 'value' => '5000', 'reason' => 'Too generous'])
        ->assertNotified(__('workflow_actions.failed'));
    Livewire::test(ConfigurationOverrides::class)->callAction(TestAction::make('cfgDraftOverride')->table('quote.max_discount_basis_points'),
        ['scope_level' => 'INSURER', 'scope_id' => $insurer, 'insurer_id' => $insurer, 'value' => '1000', 'reason' => 'Insurer cap'])
        ->assertNotified(__('catalogue_actions.cfgDraftOverride.done'));
    expect(DB::table('configuration_change_sets')->count())->toBeGreaterThan(0);
});
