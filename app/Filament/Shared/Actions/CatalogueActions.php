<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\Catalogue\CatalogueService;
use App\Application\Catalogue\ExclusionLegalTextService;
use App\Application\Catalogue\Governance\GovernanceCutover;
use App\Application\Catalogue\Governance\Models\ProductTestCase;
use App\Application\Catalogue\IndemnityCalculator;
use App\Application\Catalogue\ProductConfigurationService;
use App\Application\Catalogue\ProductModelService;
use App\Application\Catalogue\Sandbox\ProductSandbox;
use App\Application\Identity\CarrierScopeResolver;
use App\Domain\Tenancy\TenantContext;
use App\Models\Carrier;
use App\Models\Catalogue\CarrierProduct;
use App\Models\Catalogue\CoverageDeductible;
use App\Models\Catalogue\CoverageLimit;
use App\Models\Catalogue\ExclusionLegalText;
use App\Models\Catalogue\ProductFamily;
use App\Models\Catalogue\ProductPlan;
use App\Models\CoverageDefinition;
use App\Models\ExclusionDefinition;
use App\Models\InsuranceLine;
use App\Models\InsuranceProduct;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use App\Interfaces\Http\Errors\ApiProblemException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Product catalogue staff actions (UI coverage batch 18). Same permission, validation and service call as the API:
 *   catCreateLine          POST catalogue/lines                               catalogue.manage   CatalogueService::createLine
 *   catCreateCoverage      POST catalogue/lines/{line}/coverages              catalogue.manage   CatalogueService::createCoverage
 *   catCreateExclusion     POST catalogue/lines/{line}/exclusions             catalogue.manage   CatalogueService::createExclusion
 *   catCreateProduct       POST catalogue/products                            catalogue.manage   CatalogueService::createProduct
 *   catSubmitProduct       POST catalogue/products/{p}/submit                 catalogue.manage   CatalogueService::submit (legacy direct path only)
 *   catPublishProduct      POST catalogue/products/{p}/publish                catalogue.publish  CatalogueService::publish (legacy direct path only)
 *   catCreateFamily        POST catalogue/families                            catalogue.manage   ProductModelService::createFamily
 *   catCreateCarrierProduct POST catalogue/carrier-products                   catalogue.manage   ProductModelService::createCarrierProduct
 *   catUpdateCarrierProduct PATCH catalogue/carrier-products/{p}              catalogue.manage   ProductModelService::updateCarrierProduct
 *   catNewVersion          POST catalogue/carrier-products/{p}/versions       catalogue.manage   ProductModelService::newVersion
 *   catTransition          POST catalogue/versions/{v}/{action}               catalogue.publish  ProductModelService::approve|suspend|reinstate|retire
 *   catAddPlan             POST catalogue/versions/{v}/plans                  catalogue.manage   ProductConfigurationService::addPlan
 *   catSyncPlanCoverages   PUT  catalogue/plans/{plan}/coverages              catalogue.manage   ProductConfigurationService::syncPlanCoverages
 *   catConfigureCoverage   PUT  catalogue/versions/{v}/coverages/{c}          catalogue.manage   ProductConfigurationService::configureCoverage
 *   catAddLimit            POST catalogue/versions/{v}/limits                 catalogue.manage   ProductConfigurationService::addLimit
 *   catAddDeductible       POST catalogue/versions/{v}/deductibles            catalogue.manage   ProductConfigurationService::addDeductible
 *   catRemoveTerm          DELETE catalogue/{kind}/{id}                       catalogue.manage   ProductConfigurationService::removeTerm
 *   catAttachExclusion     POST catalogue/versions/{v}/exclusions             catalogue.manage   ProductConfigurationService::attachExclusion
 *   catIndemnityPreview    POST catalogue/versions/{v}/indemnity-preview      catalogue.view     IndemnityCalculator::compute
 *   catDeleteTestCase      DELETE catalogue/test-cases/{case}                 catalogue.test     ProductSandbox::removeCase
 *   catDraftLegalText      POST catalogue/exclusions/{e}/legal-texts          catalogue.manage   ExclusionLegalTextService::draft
 *   catApproveLegalText    POST catalogue/legal-texts/{t}/approve             catalogue.publish  ExclusionLegalTextService::approve
 * Insurer users are confined to their own carrier exactly like ProductModelController::assertCarrier. Governance is
 * untouched: the direct submit/publish path stays refused for GOVERNED versions (GovernanceCutover), and maker-checker
 * / CIMA publication guards are enforced by the services.
 */
final class CatalogueActions
{
    private const LANG = 'catalogue_actions';

    // ── Product version (ViewInsuranceProduct) ─────────────────────────
    public static function versionGroup(): ActionGroup
    {
        return ActionGroup::make([
            self::submitProduct(), self::publishProduct(), self::transition(), self::addPlan(), self::syncPlanCoverages(), self::configureCoverage(),
            self::addLimit(), self::addDeductible(), self::removeTerm(), self::attachExclusion(), self::indemnityPreview(), self::deleteTestCase(),
        ])->label(__('catalogue_actions.group'))->icon('lucide-zap')->button();
    }

    public static function submitProduct(): Action
    {
        $p = 'catalogue.manage';

        return WorkflowAction::make('catSubmitProduct', $p, self::LANG)->icon('lucide-send')
            ->visible(fn (InsuranceProduct $record) => $record->status === 'DRAFT' && GovernanceCutover::isLegacy($record))
            ->schema([Textarea::make('notes')->label(__('catalogue_actions.fields.notes'))->required()->minLength(10)->maxLength(2000)])
            ->action(fn (Action $action, InsuranceProduct $record, array $data) => WorkflowAction::run($action, $p, function () use ($record, $data) {
                self::assertCarrier($record->carrier_id);

                return app(CatalogueService::class)->submit($record->refresh(), auth()->user(), $data['notes']);
            }, __('catalogue_actions.catSubmitProduct.done')));
    }

    public static function publishProduct(): Action
    {
        $p = 'catalogue.publish';

        return WorkflowAction::make('catPublishProduct', $p, self::LANG)->icon('lucide-rocket')->color('success')
            ->visible(fn (InsuranceProduct $record) => in_array($record->status, ['IN_REVIEW', 'APPROVED'], true) && GovernanceCutover::isLegacy($record))
            ->schema([Textarea::make('reason')->label(__('catalogue_actions.fields.reason'))->required()->minLength(20)->maxLength(2000)])
            ->action(fn (Action $action, InsuranceProduct $record, array $data) => WorkflowAction::run($action, $p, function () use ($record, $data) {
                self::assertCarrier($record->carrier_id);

                return app(CatalogueService::class)->publish($record->refresh(), auth()->user(), $data['reason']);
            }, __('catalogue_actions.catPublishProduct.done')));
    }

    public static function transition(): Action
    {
        $p = 'catalogue.publish';

        return WorkflowAction::make('catTransition', $p, self::LANG)->icon('lucide-git-branch')
            ->visible(fn (InsuranceProduct $record) => in_array($record->status, ['IN_REVIEW', 'ACTIVE', 'SUSPENDED', 'APPROVED'], true))
            ->schema([
                Select::make('transition')->label(__('catalogue_actions.fields.transition'))->required()->native(false)
                    ->options(self::codes(['approve', 'suspend', 'reinstate', 'retire'], 'transition')),
                Textarea::make('reason')->label(__('catalogue_actions.fields.reason'))->required()->minLength(10)->maxLength(2000),
            ])
            ->action(fn (Action $action, InsuranceProduct $record, array $data) => WorkflowAction::run($action, $p, function () use ($record, $data) {
                self::assertCarrier($record->carrier_id);
                $svc = app(ProductModelService::class);
                $v = $record->refresh();

                return match ($data['transition']) {
                    'approve' => $svc->approve($v, auth()->user(), $data['reason']),
                    'suspend' => $svc->suspend($v, auth()->user(), $data['reason']),
                    'reinstate' => $svc->reinstate($v, auth()->user(), $data['reason']),
                    'retire' => $svc->retire($v, auth()->user(), $data['reason']),
                };
            }, __('catalogue_actions.catTransition.done')));
    }

    public static function addPlan(): Action
    {
        $p = 'catalogue.manage';

        return WorkflowAction::make('catAddPlan', $p, self::LANG)->icon('lucide-layers')
            ->schema(fn (InsuranceProduct $record) => [
                TextInput::make('code')->label(__('catalogue_actions.fields.code'))->required()->maxLength(32),
                ...self::bilingual('name', 160, true),
                Select::make('tier')->label(__('catalogue_actions.fields.tier'))->native(false)->options(array_combine(ProductPlan::TIERS, ProductPlan::TIERS)),
                TextInput::make('pricing_reference')->label(__('catalogue_actions.fields.pricing_reference'))->maxLength(120),
                Toggle::make('is_default')->label(__('catalogue_actions.fields.is_default')),
                TextInput::make('display_order')->label(__('catalogue_actions.fields.display_order'))->integer()->minValue(0),
                self::coverageRepeater($record),
            ])
            ->action(function (Action $action, InsuranceProduct $record, array $data) use ($p) {
                return WorkflowAction::run($action, $p, function () use ($record, $data) {
                    self::assertCarrier($record->carrier_id);
                    $d = Validator::validate(self::clean($data), ['code' => ['required', 'string', 'max:32', Rule::unique('product_plans')->where('insurance_product_id', $record->id)],
                        'name' => 'required|array', 'name.en' => 'required|string|max:160', 'name.fr' => 'required|string|max:160', 'tier' => ['nullable', Rule::in(ProductPlan::TIERS)],
                        'pricing_reference' => 'nullable|string|max:120', 'is_default' => 'nullable|boolean', 'display_order' => 'nullable|integer|min:0',
                        'coverages' => 'nullable|array', 'coverages.*.coverage_definition_id' => 'required|uuid|distinct', 'coverages.*.inclusion' => ['nullable', Rule::in(ProductConfigurationService::INCLUSIONS)]]);

                    return app(ProductConfigurationService::class)->addPlan($record, array_filter($d, fn ($x) => $x !== null), auth()->user());
                }, __('catalogue_actions.catAddPlan.done'));
            });
    }

    public static function syncPlanCoverages(): Action
    {
        $p = 'catalogue.manage';

        return WorkflowAction::make('catSyncPlanCoverages', $p, self::LANG)->icon('lucide-list-checks')
            ->visible(fn (InsuranceProduct $record) => ProductPlan::where('insurance_product_id', $record->id)->exists())
            ->schema(fn (InsuranceProduct $record) => [
                Select::make('plan_id')->label(__('catalogue_actions.fields.plan'))->required()->native(false)->options(self::plans($record)),
                self::coverageRepeater($record)->required()->minItems(1),
            ])
            ->action(function (Action $action, InsuranceProduct $record, array $data) use ($p) {
                return WorkflowAction::run($action, $p, function () use ($record, $data) {
                    self::assertCarrier($record->carrier_id);
                    $plan = ProductPlan::where('insurance_product_id', $record->id)->findOrFail($data['plan_id']);
                    $d = Validator::validate(['coverages' => self::clean($data['coverages'] ?? [])], ['coverages' => 'required|array|min:1',
                        'coverages.*.coverage_definition_id' => 'required|uuid|distinct', 'coverages.*.inclusion' => ['nullable', Rule::in(ProductConfigurationService::INCLUSIONS)]]);

                    return app(ProductConfigurationService::class)->syncPlanCoverages($plan, $d['coverages']);
                }, __('catalogue_actions.catSyncPlanCoverages.done'));
            });
    }

    public static function configureCoverage(): Action
    {
        $p = 'catalogue.manage';

        return WorkflowAction::make('catConfigureCoverage', $p, self::LANG)->icon('lucide-shield-plus')
            ->schema(fn (InsuranceProduct $record) => [
                Select::make('coverage_definition_id')->label(__('catalogue_actions.fields.coverage'))->required()->native(false)->options(self::coverages($record)),
                Select::make('inclusion')->label(__('catalogue_actions.fields.inclusion'))->native(false)->options(array_combine(ProductConfigurationService::INCLUSIONS, ProductConfigurationService::INCLUSIONS)),
                TextInput::make('waiting_period_days')->label(__('catalogue_actions.fields.waiting_period_days'))->integer()->minValue(0)->maxValue(3650),
                TextInput::make('territory')->label(__('catalogue_actions.fields.territory'))->maxLength(64),
                TextInput::make('coverage_period')->label(__('catalogue_actions.fields.coverage_period'))->maxLength(32),
                TextInput::make('display_order')->label(__('catalogue_actions.fields.display_order'))->integer()->minValue(0),
            ])
            ->action(function (Action $action, InsuranceProduct $record, array $data) use ($p) {
                return WorkflowAction::run($action, $p, function () use ($record, $data) {
                    self::assertCarrier($record->carrier_id);
                    $terms = Validator::validate(self::clean(array_diff_key($data, ['coverage_definition_id' => 1])), ['inclusion' => ['nullable', Rule::in(ProductConfigurationService::INCLUSIONS)],
                        'waiting_period_days' => 'nullable|integer|min:0|max:3650', 'territory' => 'nullable|string|max:64', 'coverage_period' => 'nullable|string|max:32', 'display_order' => 'nullable|integer|min:0']);

                    return app(ProductConfigurationService::class)->configureCoverage($record, CoverageDefinition::findOrFail($data['coverage_definition_id']), array_filter($terms, fn ($x) => $x !== null), auth()->user());
                }, __('catalogue_actions.catConfigureCoverage.done'));
            });
    }

    public static function addLimit(): Action
    {
        $p = 'catalogue.manage';

        return WorkflowAction::make('catAddLimit', $p, self::LANG)->icon('lucide-gauge')
            ->schema(fn (InsuranceProduct $record) => [
                Select::make('coverage_definition_id')->label(__('catalogue_actions.fields.coverage'))->required()->native(false)->options(self::coverages($record)),
                Select::make('product_plan_id')->label(__('catalogue_actions.fields.plan'))->native(false)->options(self::plans($record)),
                Select::make('limit_type')->label(__('catalogue_actions.fields.limit_type'))->required()->native(false)->options(array_combine(CoverageLimit::TYPES, CoverageLimit::TYPES)),
                TextInput::make('amount_minor')->label(__('catalogue_actions.fields.amount_minor'))->integer()->minValue(0),
                TextInput::make('percentage_bp')->label(__('catalogue_actions.fields.percentage_bp'))->integer()->minValue(0)->maxValue(10000),
                TextInput::make('currency')->label(__('catalogue_actions.fields.currency'))->length(3),
                Textarea::make('notes')->label(__('catalogue_actions.fields.notes'))->maxLength(500),
            ])
            ->action(function (Action $action, InsuranceProduct $record, array $data) use ($p) {
                return WorkflowAction::run($action, $p, function () use ($record, $data) {
                    self::assertCarrier($record->carrier_id);
                    $d = Validator::validate(self::clean($data), ['coverage_definition_id' => 'required|uuid', 'product_plan_id' => 'nullable|uuid', 'limit_type' => ['required', Rule::in(CoverageLimit::TYPES)],
                        'amount_minor' => 'nullable|integer|min:0', 'percentage_bp' => 'nullable|integer|min:0|max:10000', 'currency' => 'nullable|string|size:3', 'notes' => 'nullable|string|max:500']);

                    return app(ProductConfigurationService::class)->addLimit($record, array_filter($d, fn ($x) => $x !== null), auth()->user());
                }, __('catalogue_actions.catAddLimit.done'));
            });
    }

    public static function addDeductible(): Action
    {
        $p = 'catalogue.manage';

        return WorkflowAction::make('catAddDeductible', $p, self::LANG)->icon('lucide-scissors')
            ->schema(fn (InsuranceProduct $record) => [
                Select::make('coverage_definition_id')->label(__('catalogue_actions.fields.coverage'))->required()->native(false)->options(self::coverages($record)),
                Select::make('product_plan_id')->label(__('catalogue_actions.fields.plan'))->native(false)->options(self::plans($record)),
                Select::make('deductible_type')->label(__('catalogue_actions.fields.deductible_type'))->required()->native(false)->options(array_combine(CoverageDeductible::TYPES, CoverageDeductible::TYPES)),
                TextInput::make('amount_minor')->label(__('catalogue_actions.fields.amount_minor'))->integer()->minValue(0),
                TextInput::make('percentage_bp')->label(__('catalogue_actions.fields.percentage_bp'))->integer()->minValue(0)->maxValue(10000),
                Select::make('percentage_basis')->label(__('catalogue_actions.fields.percentage_basis'))->native(false)->options(['LOSS' => 'LOSS', 'SUM_INSURED' => 'SUM_INSURED']),
                TextInput::make('days')->label(__('catalogue_actions.fields.days'))->integer()->minValue(0)->maxValue(3650),
                TextInput::make('minimum_minor')->label(__('catalogue_actions.fields.minimum_minor'))->integer()->minValue(0),
                TextInput::make('maximum_minor')->label(__('catalogue_actions.fields.maximum_minor'))->integer()->minValue(0),
                TextInput::make('currency')->label(__('catalogue_actions.fields.currency'))->length(3),
                Textarea::make('notes')->label(__('catalogue_actions.fields.notes'))->maxLength(500),
            ])
            ->action(function (Action $action, InsuranceProduct $record, array $data) use ($p) {
                return WorkflowAction::run($action, $p, function () use ($record, $data) {
                    self::assertCarrier($record->carrier_id);
                    $d = Validator::validate(self::clean($data), ['coverage_definition_id' => 'required|uuid', 'product_plan_id' => 'nullable|uuid', 'deductible_type' => ['required', Rule::in(CoverageDeductible::TYPES)],
                        'amount_minor' => 'nullable|integer|min:0', 'percentage_bp' => 'nullable|integer|min:0|max:10000', 'percentage_basis' => 'nullable|in:LOSS,SUM_INSURED',
                        'days' => 'nullable|integer|min:0|max:3650', 'minimum_minor' => 'nullable|integer|min:0', 'maximum_minor' => 'nullable|integer|min:0|gte:minimum_minor',
                        'currency' => 'nullable|string|size:3', 'notes' => 'nullable|string|max:500']);

                    return app(ProductConfigurationService::class)->addDeductible($record, array_filter($d, fn ($x) => $x !== null), auth()->user());
                }, __('catalogue_actions.catAddDeductible.done'));
            });
    }

    public static function removeTerm(): Action
    {
        $p = 'catalogue.manage';

        return WorkflowAction::make('catRemoveTerm', $p, self::LANG)->icon('lucide-trash-2')->color('danger')
            ->visible(fn (InsuranceProduct $record) => self::terms($record) !== [])
            ->schema(fn (InsuranceProduct $record) => [
                Select::make('term')->label(__('catalogue_actions.fields.term'))->required()->native(false)->options(self::terms($record)),
            ])
            ->action(function (Action $action, InsuranceProduct $record, array $data) use ($p) {
                return WorkflowAction::run($action, $p, function () use ($record, $data) {
                    self::assertCarrier($record->carrier_id);
                    [$kind, $id] = explode(':', (string) $data['term'], 2);
                    $term = ($kind === 'limits' ? CoverageLimit::query() : CoverageDeductible::query())->where('insurance_product_id', $record->id)->findOrFail($id);
                    app(ProductConfigurationService::class)->removeTerm($term, auth()->user());

                    return true;
                }, __('catalogue_actions.catRemoveTerm.done'));
            });
    }

    public static function attachExclusion(): Action
    {
        $p = 'catalogue.manage';

        return WorkflowAction::make('catAttachExclusion', $p, self::LANG)->icon('lucide-ban')
            ->schema(fn (InsuranceProduct $record) => [
                Select::make('exclusion_definition_id')->label(__('catalogue_actions.fields.exclusion'))->required()->native(false)
                    ->options(fn () => ExclusionDefinition::whereHas('line', fn ($q) => $q->where('code', $record->line_code))->get()->mapWithKeys(fn ($e) => [$e->id => $e->code.' — '.($e->name['en'] ?? '')])->all()),
                Select::make('level')->label(__('catalogue_actions.fields.level'))->required()->native(false)->options(array_combine(ProductConfigurationService::EXCLUSION_LEVELS, ProductConfigurationService::EXCLUSION_LEVELS)),
                Select::make('product_plan_id')->label(__('catalogue_actions.fields.plan'))->native(false)->options(self::plans($record)),
                Select::make('coverage_definition_id')->label(__('catalogue_actions.fields.coverage'))->native(false)->options(self::coverages($record)),
                KeyValue::make('condition')->label(__('catalogue_actions.fields.condition')),
            ])
            ->action(function (Action $action, InsuranceProduct $record, array $data) use ($p) {
                return WorkflowAction::run($action, $p, function () use ($record, $data) {
                    self::assertCarrier($record->carrier_id);
                    $d = Validator::validate(self::clean($data), ['exclusion_definition_id' => 'required|uuid', 'level' => ['required', Rule::in(ProductConfigurationService::EXCLUSION_LEVELS)],
                        'product_plan_id' => 'nullable|uuid', 'coverage_definition_id' => 'nullable|uuid', 'condition' => 'nullable|array', 'configuration' => 'nullable|array']);

                    return app(ProductConfigurationService::class)->attachExclusion($record, ExclusionDefinition::findOrFail($d['exclusion_definition_id']), $d, auth()->user());
                }, __('catalogue_actions.catAttachExclusion.done'));
            });
    }

    public static function indemnityPreview(): Action
    {
        $p = 'catalogue.view';

        return WorkflowAction::make('catIndemnityPreview', $p, self::LANG)->icon('lucide-calculator')
            ->schema(fn (InsuranceProduct $record) => [
                Select::make('coverage_definition_id')->label(__('catalogue_actions.fields.coverage'))->required()->native(false)->options(self::coverages($record)),
                TextInput::make('loss_minor')->label(__('catalogue_actions.fields.loss_minor'))->required()->integer()->minValue(0),
                TextInput::make('sum_insured_minor')->label(__('catalogue_actions.fields.sum_insured_minor'))->integer()->minValue(0),
                TextInput::make('consumed_minor')->label(__('catalogue_actions.fields.consumed_minor'))->integer()->minValue(0),
                Select::make('plan_id')->label(__('catalogue_actions.fields.plan'))->native(false)->options(self::plans($record)),
            ])
            ->action(function (Action $action, InsuranceProduct $record, array $data) use ($p) {
                $out = WorkflowAction::run($action, $p, function () use ($record, $data) {
                    self::assertCarrier($record->carrier_id);
                    $d = Validator::validate(self::clean($data), ['coverage_definition_id' => 'required|uuid', 'loss_minor' => 'required|integer|min:0', 'sum_insured_minor' => 'nullable|integer|min:0',
                        'consumed_minor' => 'nullable|integer|min:0', 'plan_id' => 'nullable|uuid']);

                    return app(IndemnityCalculator::class)->compute($record, $d['coverage_definition_id'], $d);
                }, __('catalogue_actions.catIndemnityPreview.done'));
                Notification::make()->info()->title(__('catalogue_actions.catIndemnityPreview.result'))
                    ->body(json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))->persistent()->send();
            });
    }

    public static function deleteTestCase(): Action
    {
        $p = 'catalogue.test';

        return WorkflowAction::make('catDeleteTestCase', $p, self::LANG)->icon('lucide-flask-conical-off')->color('danger')
            ->visible(fn (InsuranceProduct $record) => ProductTestCase::where('insurance_product_id', $record->id)->exists())
            ->schema(fn (InsuranceProduct $record) => [
                Select::make('case_id')->label(__('catalogue_actions.fields.test_case'))->required()->native(false)
                    ->options(ProductTestCase::where('insurance_product_id', $record->id)->orderBy('code')->get()->mapWithKeys(fn ($c) => [$c->id => $c->code.' — '.$c->name])->all()),
            ])
            ->action(fn (Action $action, InsuranceProduct $record, array $data) => WorkflowAction::run($action, $p, function () use ($record, $data) {
                self::assertCarrier($record->carrier_id);
                app(ProductSandbox::class)->removeCase(ProductTestCase::where('insurance_product_id', $record->id)->findOrFail($data['case_id']), auth()->user());

                return true;
            }, __('catalogue_actions.catDeleteTestCase.done')));
    }

    // ── Product versions list (ListInsuranceProducts) ──────────────────
    public static function createProduct(): Action
    {
        $p = 'catalogue.manage';

        return WorkflowAction::make('catCreateProduct', $p, self::LANG)->icon('lucide-plus')
            ->schema([
                Select::make('carrier_id')->label(__('catalogue_actions.fields.carrier'))->required()->searchable()->options(fn () => self::carriers()),
                Select::make('line_code')->label(__('catalogue_actions.fields.line'))->required()->native(false)->live()->options(fn () => self::lines()),
                TextInput::make('code')->label(__('catalogue_actions.fields.code'))->required()->maxLength(64),
                TextInput::make('name')->label(__('catalogue_actions.fields.name'))->required()->maxLength(160),
                DatePicker::make('effective_from')->label(__('catalogue_actions.fields.effective_from'))->required(),
                DatePicker::make('effective_until')->label(__('catalogue_actions.fields.effective_until'))->afterOrEqual('effective_from'),
                Select::make('coverage_ids')->label(__('catalogue_actions.fields.coverages'))->required()->multiple()
                    ->options(fn ($get) => CoverageDefinition::whereHas('line', fn ($q) => $q->where('code', $get('line_code')))->where('status', 'ACTIVE')->get()->mapWithKeys(fn ($c) => [$c->id => $c->code])->all()),
                Select::make('exclusion_ids')->label(__('catalogue_actions.fields.exclusions'))->multiple()
                    ->options(fn ($get) => ExclusionDefinition::whereHas('line', fn ($q) => $q->where('code', $get('line_code')))->get()->mapWithKeys(fn ($e) => [$e->id => $e->code])->all()),
                KeyValue::make('eligibility_rules')->label(__('catalogue_actions.fields.eligibility_rules'))->required(),
                TextInput::make('regulatory_reference')->label(__('catalogue_actions.fields.regulatory_reference'))->maxLength(120),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, function () use ($data) {
                $d = Validator::validate(self::clean($data), ['carrier_id' => 'required|uuid|exists:carriers,id', 'line_code' => 'required|string|max:32|exists:insurance_lines,code', 'code' => 'required|string|max:64',
                    'name' => 'required|string|max:160', 'effective_from' => 'required|date', 'effective_until' => 'nullable|date|after_or_equal:effective_from', 'coverage_ids' => 'required|array|min:1',
                    'coverage_ids.*' => 'uuid|distinct', 'exclusion_ids' => 'nullable|array', 'exclusion_ids.*' => 'uuid|distinct', 'eligibility_rules' => 'required|array', 'regulatory_reference' => 'nullable|string|max:120']);
                self::assertCarrier($d['carrier_id']);

                return app(CatalogueService::class)->createProduct($d, auth()->user());
            }, __('catalogue_actions.catCreateProduct.done')));
    }

    // ── Families & carrier products (CatalogueCarrierProducts page) ────
    public static function createFamily(): Action
    {
        $p = 'catalogue.manage';

        return WorkflowAction::make('catCreateFamily', $p, self::LANG)->icon('lucide-folder-tree')
            ->schema([
                TextInput::make('code')->label(__('catalogue_actions.fields.code'))->required()->maxLength(64),
                Select::make('class_code')->label(__('catalogue_actions.fields.class_code'))->required()->native(false)->options(array_combine(ProductFamily::CLASS_CODES, ProductFamily::CLASS_CODES)),
                Select::make('line_code')->label(__('catalogue_actions.fields.line'))->native(false)->options(fn () => self::lines()),
                TextInput::make('default_branch_code')->label(__('catalogue_actions.fields.default_branch_code'))->maxLength(64),
                ...self::bilingual('name', 160, true),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, function () use ($data) {
                $d = Validator::validate(self::clean($data), ['code' => 'required|string|max:64|unique:product_families,code', 'class_code' => ['required', Rule::in(ProductFamily::CLASS_CODES)],
                    'line_code' => 'nullable|string|exists:insurance_lines,code', 'default_branch_code' => 'nullable|string|max:64', 'name' => 'required|array',
                    'name.en' => 'required|string|max:160', 'name.fr' => 'required|string|max:160']);

                return app(ProductModelService::class)->createFamily(array_filter($d, fn ($v) => $v !== null), auth()->user());
            }, __('catalogue_actions.catCreateFamily.done')));
    }

    public static function createCarrierProduct(): Action
    {
        $p = 'catalogue.manage';

        return WorkflowAction::make('catCreateCarrierProduct', $p, self::LANG)->icon('lucide-package-plus')
            ->schema([
                Select::make('carrier_id')->label(__('catalogue_actions.fields.carrier'))->required()->searchable()->options(fn () => self::carriers()),
                TextInput::make('code')->label(__('catalogue_actions.fields.code'))->required()->maxLength(64),
                Select::make('line_code')->label(__('catalogue_actions.fields.line'))->required()->native(false)->options(fn () => self::lines()),
                ...self::carrierProductFields(true),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, function () use ($data) {
                $d = Validator::validate(self::clean($data), self::carrierProductRules() + ['carrier_id' => 'required|uuid|exists:carriers,id',
                    'code' => ['required', 'string', 'max:64', Rule::unique('carrier_products')->where('carrier_id', $data['carrier_id'] ?? null)],
                    'line_code' => 'required|string|exists:insurance_lines,code']);
                self::assertCarrier($d['carrier_id']);

                return app(ProductModelService::class)->createCarrierProduct(array_filter($d, fn ($v) => $v !== null), auth()->user());
            }, __('catalogue_actions.catCreateCarrierProduct.done')));
    }

    public static function updateCarrierProduct(): Action
    {
        $p = 'catalogue.manage';

        return WorkflowAction::make('catUpdateCarrierProduct', $p, self::LANG)->icon('lucide-pencil')
            ->fillForm(fn (CarrierProduct $record) => $record->only(['product_family_id', 'name', 'description', 'customer_type', 'currency', 'market', 'status']))
            ->schema([
                ...self::carrierProductFields(false),
                Select::make('status')->label(__('catalogue_actions.fields.status'))->native(false)->options(self::codes(['ACTIVE', 'SUSPENDED', 'RETIRED'], 'status')),
            ])
            ->action(fn (Action $action, CarrierProduct $record, array $data) => WorkflowAction::run($action, $p, function () use ($record, $data) {
                self::assertCarrier($record->carrier_id);
                $rules = collect(self::carrierProductRules())->map(fn ($rule) => is_string($rule) ? str_replace('required|', 'sometimes|', $rule) : $rule)->all();
                $d = Validator::validate(self::clean($data), $rules + ['status' => 'sometimes|in:ACTIVE,SUSPENDED,RETIRED']);

                return app(ProductModelService::class)->updateCarrierProduct($record, $d, auth()->user());
            }, __('catalogue_actions.catUpdateCarrierProduct.done')));
    }

    public static function newVersion(): Action
    {
        $p = 'catalogue.manage';

        return WorkflowAction::make('catNewVersion', $p, self::LANG)->icon('lucide-copy-plus')
            ->schema(fn (CarrierProduct $record) => [
                DatePicker::make('effective_from')->label(__('catalogue_actions.fields.effective_from'))->required(),
                DatePicker::make('effective_until')->label(__('catalogue_actions.fields.effective_until'))->afterOrEqual('effective_from'),
                TextInput::make('name')->label(__('catalogue_actions.fields.name'))->maxLength(160),
                DatePicker::make('sales_start')->label(__('catalogue_actions.fields.sales_start')),
                DatePicker::make('sales_end')->label(__('catalogue_actions.fields.sales_end'))->afterOrEqual('sales_start'),
                Toggle::make('new_business_allowed')->label(__('catalogue_actions.fields.new_business_allowed'))->default(true),
                Toggle::make('renewal_allowed')->label(__('catalogue_actions.fields.renewal_allowed'))->default(true),
                TextInput::make('regulatory_reference')->label(__('catalogue_actions.fields.regulatory_reference'))->maxLength(120),
                Select::make('base_version_id')->label(__('catalogue_actions.fields.base_version'))->native(false)
                    ->options(InsuranceProduct::where('carrier_product_id', $record->id)->orderByDesc('version')->get()->mapWithKeys(fn ($v) => [$v->id => 'v'.$v->version.' — '.$v->status])->all()),
            ])
            ->action(fn (Action $action, CarrierProduct $record, array $data) => WorkflowAction::run($action, $p, function () use ($record, $data) {
                self::assertCarrier($record->carrier_id);
                $d = Validator::validate(self::clean($data), ['effective_from' => 'required|date', 'effective_until' => 'nullable|date|after_or_equal:effective_from', 'name' => 'nullable|string|max:160',
                    'sales_start' => 'nullable|date', 'sales_end' => 'nullable|date|after_or_equal:sales_start', 'new_business_allowed' => 'nullable|boolean',
                    'renewal_allowed' => 'nullable|boolean', 'regulatory_reference' => 'nullable|string|max:120', 'base_version_id' => 'nullable|uuid']);

                return app(ProductModelService::class)->newVersion($record, array_filter($d, fn ($v) => $v !== null), auth()->user());
            }, __('catalogue_actions.catNewVersion.done')));
    }

    // ── Lines, coverages, exclusions (InsuranceLine pages) ─────────────
    public static function createLine(): Action
    {
        $p = 'catalogue.manage';

        return WorkflowAction::make('catCreateLine', $p, self::LANG)->icon('lucide-plus')
            ->schema([
                TextInput::make('code')->label(__('catalogue_actions.fields.code'))->required()->maxLength(32),
                ...self::bilingual('name', 160, true),
                ...self::bilingual('description', 5000, false),
                KeyValue::make('risk_schema')->label(__('catalogue_actions.fields.risk_schema'))->required(),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, function () use ($data) {
                $d = Validator::validate(self::clean($data), ['code' => 'required|string|max:32|unique:insurance_lines,code', 'name' => 'required|array', 'description' => 'nullable|array', 'risk_schema' => 'required|array']);

                return app(CatalogueService::class)->createLine($d, auth()->user());
            }, __('catalogue_actions.catCreateLine.done')));
    }

    public static function createCoverage(): Action
    {
        $p = 'catalogue.manage';

        return WorkflowAction::make('catCreateCoverage', $p, self::LANG)->icon('lucide-shield-plus')
            ->schema([
                TextInput::make('code')->label(__('catalogue_actions.fields.code'))->required()->maxLength(64),
                ...self::bilingual('name', 160, true),
                ...self::bilingual('description', 5000, false),
                TextInput::make('limit_type')->label(__('catalogue_actions.fields.limit_type'))->required()->maxLength(32),
                Toggle::make('mandatory')->label(__('catalogue_actions.fields.mandatory'))->default(false),
            ])
            ->action(fn (Action $action, InsuranceLine $record, array $data) => WorkflowAction::run($action, $p, function () use ($record, $data) {
                $d = Validator::validate(self::clean($data), ['code' => 'required|string|max:64', 'name' => 'required|array', 'description' => 'nullable|array', 'limit_type' => 'required|string|max:32', 'mandatory' => 'required|boolean']);

                return app(CatalogueService::class)->createCoverage($record, $d, auth()->user());
            }, __('catalogue_actions.catCreateCoverage.done')));
    }

    public static function createExclusion(): Action
    {
        $p = 'catalogue.manage';

        return WorkflowAction::make('catCreateExclusion', $p, self::LANG)->icon('lucide-ban')
            ->schema([
                TextInput::make('code')->label(__('catalogue_actions.fields.code'))->required()->maxLength(64),
                ...self::bilingual('name', 160, true),
                ...self::bilingual('description', 5000, false),
            ])
            ->action(fn (Action $action, InsuranceLine $record, array $data) => WorkflowAction::run($action, $p, function () use ($record, $data) {
                $d = Validator::validate(self::clean($data), ['code' => 'required|string|max:64', 'name' => 'required|array', 'description' => 'nullable|array']);

                return app(CatalogueService::class)->createExclusion($record, $d, auth()->user());
            }, __('catalogue_actions.catCreateExclusion.done')));
    }

    // ── Exclusion legal texts (ExclusionDefinition view) ────────────────
    public static function draftLegalText(): Action
    {
        $p = 'catalogue.manage';

        return WorkflowAction::make('catDraftLegalText', $p, self::LANG)->icon('lucide-scale')
            ->schema([
                Textarea::make('text.en')->label(__('catalogue_actions.fields.text_en'))->required()->maxLength(20000),
                Textarea::make('text.fr')->label(__('catalogue_actions.fields.text_fr'))->required()->maxLength(20000),
                TextInput::make('legal_reference')->label(__('catalogue_actions.fields.legal_reference'))->maxLength(255),
                DatePicker::make('effective_from')->label(__('catalogue_actions.fields.effective_from'))->required(),
                DatePicker::make('effective_until')->label(__('catalogue_actions.fields.effective_until'))->afterOrEqual('effective_from'),
            ])
            ->action(fn (Action $action, ExclusionDefinition $record, array $data) => WorkflowAction::run($action, $p, function () use ($record, $data) {
                $d = Validator::validate(self::clean($data), ['text' => 'required|array', 'text.en' => 'required|string|max:20000', 'text.fr' => 'required|string|max:20000',
                    'legal_reference' => 'nullable|string|max:255', 'effective_from' => 'required|date', 'effective_until' => 'nullable|date|after_or_equal:effective_from']);

                return app(ExclusionLegalTextService::class)->draft($record, $d, auth()->user());
            }, __('catalogue_actions.catDraftLegalText.done')));
    }

    public static function approveLegalText(): Action
    {
        $p = 'catalogue.publish';

        return WorkflowAction::make('catApproveLegalText', $p, self::LANG)->icon('lucide-badge-check')->color('success')
            ->visible(fn (ExclusionDefinition $record) => ExclusionLegalText::where('exclusion_definition_id', $record->id)->where('status', 'DRAFT')->exists())
            ->schema(fn (ExclusionDefinition $record) => [
                Select::make('text_id')->label(__('catalogue_actions.fields.legal_text'))->required()->native(false)
                    ->options(ExclusionLegalText::where('exclusion_definition_id', $record->id)->where('status', 'DRAFT')->orderBy('version')->get()
                        ->mapWithKeys(fn ($t) => [$t->id => 'v'.$t->version.' — '.$t->effective_from?->toDateString()])->all()),
            ])
            ->action(fn (Action $action, ExclusionDefinition $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(ExclusionLegalTextService::class)->approve(ExclusionLegalText::where('exclusion_definition_id', $record->id)->findOrFail($data['text_id']), auth()->user()),
                __('catalogue_actions.catApproveLegalText.done')));
    }

    // ── helpers ────────────────────────────────────────────────────────
    /** Insurer users only manage their own carrier's products (ProductModelController::assertCarrier). */
    public static function carrierScope(): ?string
    {
        return rescue(fn () => app(CarrierScopeResolver::class)->carrierIdFor(auth()->user(), app(TenantContext::class)->id()), null, false);
    }

    private static function assertCarrier(?string $carrierId): void
    {
        $scoped = self::carrierScope();
        if ($scoped !== null && $scoped !== $carrierId) {
            throw new ApiProblemException('FORBIDDEN', 403, 'This product belongs to another insurer.');
        }
    }

    /** @return array<string, string> */
    private static function carriers(): array
    {
        return Carrier::with('party')->when(self::carrierScope(), fn ($q, $c) => $q->whereKey($c))->orderBy('cima_code')->get()
            ->mapWithKeys(fn ($c) => [$c->id => trim(($c->party?->display_name ?? '').' ('.$c->cima_code.')')])->all();
    }

    /** @return array<string, string> */
    private static function lines(): array
    {
        return InsuranceLine::where('status', 'ACTIVE')->orderBy('code')->pluck('code', 'code')->all();
    }

    /** @return array<string, string> coverage definitions of the version's line */
    private static function coverages(InsuranceProduct $v): array
    {
        return CoverageDefinition::whereHas('line', fn ($q) => $q->where('code', $v->line_code))->orderBy('code')->get()
            ->mapWithKeys(fn ($c) => [$c->id => $c->code.' — '.($c->name['en'] ?? '')])->all();
    }

    /** @return array<string, string> */
    private static function plans(InsuranceProduct $v): array
    {
        return ProductPlan::where('insurance_product_id', $v->id)->orderBy('code')->pluck('code', 'id')->all();
    }

    /** @return array<string, string> "limits:{id}" / "deductibles:{id}" */
    private static function terms(InsuranceProduct $v): array
    {
        $out = [];
        foreach (CoverageLimit::where('insurance_product_id', $v->id)->get() as $l) {
            $out['limits:'.$l->id] = __('catalogue_actions.fields.limit').' — '.$l->limit_type.($l->amount_minor !== null ? ' '.$l->amount_minor : '');
        }
        foreach (CoverageDeductible::where('insurance_product_id', $v->id)->get() as $d) {
            $out['deductibles:'.$d->id] = __('catalogue_actions.fields.deductible').' — '.$d->deductible_type.($d->amount_minor !== null ? ' '.$d->amount_minor : '');
        }

        return $out;
    }

    private static function coverageRepeater(InsuranceProduct $v): Repeater
    {
        return Repeater::make('coverages')->label(__('catalogue_actions.fields.coverages'))->defaultItems(0)->columns(2)->schema([
            Select::make('coverage_definition_id')->label(__('catalogue_actions.fields.coverage'))->required()->native(false)->options(self::coverages($v)),
            Select::make('inclusion')->label(__('catalogue_actions.fields.inclusion'))->native(false)->options(array_combine(ProductConfigurationService::INCLUSIONS, ProductConfigurationService::INCLUSIONS)),
        ]);
    }

    /** @return list<TextInput|Textarea> "{field}.en" / "{field}.fr" */
    private static function bilingual(string $field, int $max, bool $required): array
    {
        $make = fn (string $l) => ($max > 200 ? Textarea::make("{$field}.{$l}") : TextInput::make("{$field}.{$l}"))
            ->label(__("catalogue_actions.fields.{$field}_{$l}"))->required($required)->maxLength($max);

        return [$make('en'), $make('fr')];
    }

    private static function carrierProductFields(bool $required): array
    {
        return [
            Select::make('product_family_id')->label(__('catalogue_actions.fields.family'))->native(false)->options(fn () => ProductFamily::orderBy('code')->pluck('code', 'id')->all()),
            ...self::bilingual('name', 160, $required),
            ...self::bilingual('description', 5000, false),
            Select::make('customer_type')->label(__('catalogue_actions.fields.customer_type'))->native(false)->options(array_combine(CarrierProduct::CUSTOMER_TYPES, CarrierProduct::CUSTOMER_TYPES)),
            TextInput::make('currency')->label(__('catalogue_actions.fields.currency'))->length(3),
            TextInput::make('market')->label(__('catalogue_actions.fields.market'))->maxLength(32),
        ];
    }

    private static function carrierProductRules(): array
    {
        return ['product_family_id' => 'nullable|uuid|exists:product_families,id', 'name' => 'required|array', 'name.en' => 'required|string|max:160',
            'name.fr' => 'required|string|max:160', 'description' => 'nullable|array', 'description.en' => 'nullable|string|max:5000', 'description.fr' => 'nullable|string|max:5000',
            'customer_type' => ['nullable', Rule::in(CarrierProduct::CUSTOMER_TYPES)], 'currency' => 'nullable|string|size:3', 'market' => 'nullable|string|max:32'];
    }

    /** Form state → API-shaped input: blank strings become null, empty bilingual blocks are dropped. */
    public static function clean(array $data): array
    {
        $out = [];
        foreach ($data as $k => $v) {
            if (is_array($v)) {
                $v = array_is_list($v) ? array_values(array_map(fn ($x) => is_array($x) ? self::clean($x) : $x, $v)) : self::clean($v);
                if (in_array($k, ['description'], true) && array_filter($v, fn ($x) => $x !== null) === []) {
                    continue; // an API client would simply omit it
                }
            } elseif ($v === '') {
                $v = null;
            }
            $out[$k] = $v;
        }

        return $out;
    }

    /** @param array<int, string> $values */
    public static function codes(array $values, string $group): array
    {
        return collect($values)->mapWithKeys(fn ($v) => [$v => WorkflowAction::optional("catalogue_actions.codes.{$group}.{$v}") ?? $v])->all();
    }
}
