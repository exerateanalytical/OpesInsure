<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\Accumulation\CapacityService;
use App\Application\Accumulation\LargeLossNotifier;
use App\Application\Authority\AuthorityTypeCatalogue;
use App\Application\Capabilities\CapabilityCatalogue;
use App\Application\Capabilities\CapabilityPinner;
use App\Application\Cases\CaseTypeCatalogue;
use App\Application\Cases\CaseTypeService;
use App\Application\Cases\CaseVisibility;
use App\Application\Cases\Http\CaseAdminController;
use App\Application\Cases\Http\CaseConfigurationController;
use App\Application\Cases\Models\CaseType;
use App\Application\Claims\Types\ClaimTypeCatalogue;
use App\Application\Configuration\ConfigurationGovernanceService;
use App\Application\Health\Eligibility\HealthMemberService;
use App\Application\Policies\Special\LifeSurrenderService;
use App\Application\Rules\PremiumCover\Http\PremiumCoverController;
use App\Application\Rules\PremiumCover\PremiumCoverEvaluator;
use App\Application\Security\Http\SecurityCentreController;
use App\Application\Settings\FeatureFlags;
use App\Application\Tenancy\OrganizationStructureService;
use App\Domain\Rules\Expression\ExpressionValidator;
use App\Interfaces\Http\Controllers\Api\V1\Fraud\RiskAlertController;
use App\Interfaces\Http\Controllers\Api\V1\Notifications\NotificationController;
use App\Interfaces\Http\Controllers\Api\V1\Tenancy\BranchController;
use App\Interfaces\Http\Controllers\Api\V1\Tenancy\TenantController;
use App\Interfaces\Http\Errors\ApiProblemException;
use App\Models\ConfigurationChangeSet;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Illuminate\Validation\Rule;

/**
 * Configuration desk actions (catalogues, rules, templates, queues, organisation settings). Each one uses the SAME
 * permission and the SAME service (or, where the API route has its logic inline, the API controller itself through
 * ControllerCall) as the API route:
 *   authorityTypeAdd/Retire      POST authority-types[/{code}/retire]         authority.types.manage     AuthorityTypeCatalogue::add / retire
 *   caseTypeDraft/Approve        POST admin/case-types[/{type}/approve]        cases.admin                CaseTypeService::draft / approve
 *   claimTypeDraft               POST claim-types                              claims.types.manage        ClaimTypeCatalogue::draft
 *   claimTypeApprove             POST claim-types/{version}/approve            claims.types.approve       ClaimTypeCatalogue::approve (maker-checker in service)
 *   premiumCoverRuleDraft        POST premium-cover-rules                      premium_cover.rules.manage ExpressionValidator::validate + PremiumCoverController@store
 *   premiumCoverRuleApprove      POST premium-cover-rules/{id}/approve         premium_cover.rules.approve PremiumCoverController@approve (maker != checker)
 *   premiumCoverRuleRetire       POST premium-cover-rules/{id}/retire          premium_cover.rules.manage PremiumCoverController@retire
 *   premiumCoverEvaluate         POST premium-cover/evaluate                   premium_cover.rules.view   PremiumCoverEvaluator::evaluate
 *   fraudRuleCreate              POST fraud-rules                              fraud.rules.manage         RiskAlertController@createRule
 *   healthBenefitRuleAdd         POST health-benefit-rules                     health.benefits.manage     HealthMemberService::addBenefitRule
 *   notificationTemplateDraft    POST notification-templates                   communications.manage      NotificationController@template
 *   notificationTemplateApprove  POST notification-templates/{t}/approve       communications.approve     NotificationController@approveTemplate (maker != checker)
 *   communicationPreferenceSet   PUT  communications/preferences               communications.manage      NotificationController@preference
 *   queueCreate / queueMemberAdd POST admin/queues[/{queue}/members]           cases.admin                CaseAdminController@addQueue / addMember
 *   slaOverrideAdd/Retire        POST admin/sla-overrides[/{id}/retire]        cases.admin                CaseConfigurationController@addOverride / retireOverride
 *   lifeScaleCreate              POST life/surrender-scales                    life_surrender.scales.manage LifeSurrenderService::createScale
 *   lifeScaleActivate            POST life/surrender-scales/{scale}/activate   life_surrender.scales.approve LifeSurrenderService::activateScale
 *   configChangeDraft            POST configuration-changes                    configuration.changes.manage ConfigurationGovernanceService::draft
 *   configChangeSubmit/Publish   POST configuration-changes/{change}/{step}    configuration.changes.manage ConfigurationGovernanceService::submit / publish
 *   featureFlagSet               PUT  admin/feature-flags                      tenant.manage              FeatureFlags::set (non-platform: own tenant only)
 *   privacyPurposeUpdate         PATCH privacy/purposes/{code}                 privacy.purposes.manage    SecurityCentreController@updatePurpose
 *   largeLossThreshold           POST large-loss/threshold                     catastrophe.events.manage  LargeLossNotifier::configure
 *   capacityCheck                POST capacity/check                           accumulation.capacity.check CapacityService::check
 *   capabilityPin                POST capability-pins                          capability_profiles.manage CapabilityPinner::pinned / pin
 *   departmentCreate             POST organization/departments                 tenant.manage              OrganizationStructureService::createDepartment
 *   branchCreate                 POST branches                                 tenant.manage              BranchController@store
 *   tenantUpdate                 PATCH tenants/{tenant} (own tenant)           tenant.manage              TenantController@update
 */
final class MiscConfigActions
{
    public static function groups(): array
    {
        $g = fn (string $key, string $icon, array $actions) => ActionGroup::make($actions)->label(__(MiscSupport::L.'.groups.'.$key))->icon($icon)->button()->color('gray');

        return [
            $g('catalogues', 'lucide-library', [self::authorityTypeAdd(), self::authorityTypeRetire(), self::caseTypeDraft(), self::caseTypeApprove(), self::claimTypeDraft(), self::claimTypeApprove()]),
            $g('rules', 'lucide-scale', [self::premiumCoverRuleDraft(), self::premiumCoverRuleApprove(), self::premiumCoverRuleRetire(), self::premiumCoverEvaluate(), self::fraudRuleCreate(), self::healthBenefitRuleAdd(), self::lifeScaleCreate(), self::lifeScaleActivate()]),
            $g('communications', 'lucide-mail', [self::notificationTemplateDraft(), self::notificationTemplateApprove(), self::communicationPreferenceSet()]),
            $g('work', 'lucide-list-checks', [self::queueCreate(), self::queueMemberAdd(), self::slaOverrideAdd(), self::slaOverrideRetire()]),
            $g('governance', 'lucide-git-pull-request', [self::configChangeDraft(), self::configChangeSubmit(), self::configChangePublish(), self::featureFlagSet(), self::privacyPurposeUpdate()]),
            $g('risk', 'lucide-gauge', [self::largeLossThreshold(), self::capacityCheck(), self::capabilityPin()]),
            $g('organisation', 'lucide-building-2', [self::departmentCreate(), self::branchCreate(), self::tenantUpdate()]),
        ];
    }

    /** @return list<string> every permission used here (desk access = any of them) */
    public static function permissions(): array
    {
        return ['authority.types.manage', 'cases.admin', 'claims.types.manage', 'claims.types.approve', 'premium_cover.rules.manage', 'premium_cover.rules.approve',
            'premium_cover.rules.view', 'fraud.rules.manage', 'health.benefits.manage', 'communications.manage', 'communications.approve', 'life_surrender.scales.manage',
            'life_surrender.scales.approve', 'configuration.changes.manage', 'tenant.manage', 'privacy.purposes.manage', 'catastrophe.events.manage',
            'accumulation.capacity.check', 'capability_profiles.manage'];
    }

    private static function f(string $k): string
    {
        return MiscSupport::f($k);
    }

    private static function reason(int $min = 5): Textarea
    {
        return Textarea::make('reason')->label(self::f('reason'))->required()->minLength($min)->maxLength(2000);
    }

    private static function products(): array
    {
        return MiscSupport::rows('insurance_products', ['code', 'name', 'version']);
    }

    // ---- catalogues -------------------------------------------------------------------------------------------

    public static function authorityTypeAdd(): Action
    {
        return MiscSupport::op('authorityTypeAdd', 'authority.types.manage', 'lucide-plus', [
            TextInput::make('code')->label(self::f('code'))->required()->maxLength(32)->regex('/^[A-Z][A-Z0-9_]*$/'),
            TextInput::make('name')->label(self::f('name'))->required()->maxLength(120),
            Textarea::make('description')->label(self::f('description'))->maxLength(2000),
            Toggle::make('monetary')->label(self::f('monetary'))->default(true),
        ], fn (array $d) => app(AuthorityTypeCatalogue::class)->add(MiscSupport::validate($d, [
            'code' => 'required|string|max:32|regex:/^[A-Z][A-Z0-9_]*$/', 'name' => 'required|string|max:120',
            'description' => 'nullable|string|max:2000', 'monetary' => 'nullable|boolean',
        ]), MiscSupport::user()));
    }

    public static function authorityTypeRetire(): Action
    {
        return MiscSupport::op('authorityTypeRetire', 'authority.types.manage', 'lucide-archive', [
            Select::make('code')->label(self::f('authority_type'))->required()->searchable()
                ->options(fn () => MiscSupport::rows('authority_types', ['code', 'name'], fn ($q) => $q->where('status', 'ACTIVE'), 'code')),
            self::reason(),
        ], fn (array $d) => app(AuthorityTypeCatalogue::class)->retire($d['code'], MiscSupport::validate($d, ['reason' => 'required|string|min:5|max:2000'])['reason']));
    }

    public static function caseTypeDraft(): Action
    {
        return MiscSupport::op('caseTypeDraft', 'cases.admin', 'lucide-folder-plus', [
            TextInput::make('code')->label(self::f('code'))->required()->maxLength(48)->regex('/^[A-Z][A-Z0-9_]*$/'),
            TextInput::make('name')->label(self::f('name'))->maxLength(160),
            TextInput::make('family_code')->label(self::f('family_code'))->maxLength(48),
            Textarea::make('states')->label(self::f('states_json'))->required()->rows(4),
            Textarea::make('transitions')->label(self::f('transitions_json'))->required()->rows(4),
            Textarea::make('sla_policies')->label(self::f('sla_policies_json'))->rows(3),
            TagsInput::make('subtypes')->label(self::f('subtypes')),
            Select::make('default_confidentiality')->label(self::f('confidentiality'))->options(MiscSupport::options(CaseVisibility::LEVELS)),
            Toggle::make('regulated')->label(self::f('regulated')),
        ], function (array $d) {
            foreach (['states', 'transitions', 'sla_policies'] as $k) {
                $d[$k] = MiscSupport::json($d[$k] ?? null, $k);
            }
            $d = MiscSupport::validate(MiscSupport::filled($d) + ['regulated' => (bool) ($d['regulated'] ?? false)], [
                'code' => 'required|string|max:48|regex:/^[A-Z][A-Z0-9_]*$/', 'name' => 'nullable|string|max:160', 'family_code' => 'nullable|string|max:48',
                'states' => 'required|array|min:1', 'transitions' => 'required|array|min:1', 'sla_policies' => 'nullable|array', 'auto_tasks' => 'nullable|array',
                'subtypes' => 'nullable|array', 'subtypes.*' => 'string|max:48|regex:/^[A-Z][A-Z0-9_]*$/',
                'default_confidentiality' => ['nullable', Rule::in(CaseVisibility::LEVELS)], 'regulated' => 'nullable|boolean',
            ]);

            return app(CaseTypeService::class)->draft($d, MiscSupport::user());
        });
    }

    public static function caseTypeApprove(): Action
    {
        return MiscSupport::op('caseTypeApprove', 'cases.admin', 'lucide-badge-check', [
            Select::make('type')->label(self::f('case_type'))->required()->searchable()
                ->options(fn () => MiscSupport::rows('case_types', ['code', 'version', 'status'], fn ($q) => $q->where('status', 'DRAFT'))),
        ], fn (array $d) => app(CaseTypeService::class)->approve(CaseType::findOrFail($d['type']), MiscSupport::user()));
    }

    public static function claimTypeDraft(): Action
    {
        return MiscSupport::op('claimTypeDraft', 'claims.types.manage', 'lucide-file-plus', [
            Select::make('code')->label(self::f('code'))->required()->searchable()->options(MiscSupport::options(array_keys(\App\Application\Claims\ClaimReferenceCodes::CLAIM_TYPES))),
            TextInput::make('line_code')->label(self::f('line_code'))->required()->maxLength(32),
            Select::make('product_id')->label(self::f('product'))->searchable()->options(fn () => self::products()),
            TextInput::make('labels.en')->label(self::f('label_en'))->maxLength(160),
            TextInput::make('labels.fr')->label(self::f('label_fr'))->maxLength(160),
            TagsInput::make('applicable_coverages')->label(self::f('applicable_coverages')),
            TagsInput::make('required_evidence_codes')->label(self::f('required_evidence_codes')),
            TextInput::make('reporting_deadline_days')->label(self::f('reporting_deadline_days'))->integer()->minValue(1)->maxValue(3650),
            TextInput::make('default_reserve_minor')->label(self::f('default_reserve_minor'))->integer()->minValue(0),
            TextInput::make('currency')->label(self::f('currency'))->length(3)->default('XAF'),
            DatePicker::make('effective_from')->label(self::f('effective_from')),
        ], function (array $d) {
            $d = MiscSupport::filled($d);
            if (isset($d['labels'])) {
                $d['labels'] = MiscSupport::filled($d['labels']);
                if ($d['labels'] === []) {
                    unset($d['labels']);
                }
            }
            $d = MiscSupport::validate($d, [
                'code' => 'required|string|max:64', 'line_code' => 'required|string|max:32', 'product_id' => 'nullable|uuid',
                'labels' => 'sometimes|array', 'labels.en' => 'required_with:labels|string|max:160', 'labels.fr' => 'required_with:labels|string|max:160',
                'applicable_coverages' => 'sometimes|array|max:50', 'applicable_coverages.*' => 'string|max:64',
                'reporting_deadline_days' => 'sometimes|nullable|integer|min:1|max:3650', 'required_evidence_codes' => 'sometimes|array|max:50',
                'required_evidence_codes.*' => 'string|max:64', 'default_reserve_minor' => 'sometimes|nullable|integer|min:0', 'currency' => 'sometimes|nullable|string|size:3',
                'effective_from' => 'sometimes|date',
            ]);

            return app(ClaimTypeCatalogue::class)->draft(MiscSupport::tenant(), $d, MiscSupport::user());
        });
    }

    public static function claimTypeApprove(): Action
    {
        return MiscSupport::op('claimTypeApprove', 'claims.types.approve', 'lucide-badge-check', [
            Select::make('version')->label(self::f('claim_type'))->required()->searchable()
                ->options(fn () => MiscSupport::rows('claim_type_versions', ['code', 'line_code', 'version', 'status'], fn ($q) => $q->where('status', 'DRAFT'))),
        ], fn (array $d) => app(ClaimTypeCatalogue::class)->approve(MiscSupport::tenant(), $d['version'], MiscSupport::user()));
    }

    // ---- rules ------------------------------------------------------------------------------------------------

    public static function premiumCoverRuleDraft(): Action
    {
        return MiscSupport::op('premiumCoverRuleDraft', 'premium_cover.rules.manage', 'lucide-file-plus', [
            TextInput::make('code')->label(self::f('code'))->required()->maxLength(64)->regex('/^[A-Z][A-Z0-9_]*$/'),
            TextInput::make('name')->label(self::f('name'))->required()->maxLength(160),
            Select::make('product_id')->label(self::f('product'))->searchable()->options(fn () => self::products()),
            TextInput::make('class_code')->label(self::f('class_code'))->maxLength(40),
            TextInput::make('jurisdiction')->label(self::f('jurisdiction'))->length(2),
            Select::make('premium_statuses')->label(self::f('premium_statuses'))->multiple()->options(MiscSupport::options(PremiumCoverEvaluator::PREMIUM_STATUSES)),
            Toggle::make('is_exception')->label(self::f('is_exception')),
            Textarea::make('activation_rule')->label(self::f('activation_rule_json'))->required()->rows(3)->default('true'),
            Select::make('outcome')->label(self::f('outcome'))->required()->options(MiscSupport::options(PremiumCoverEvaluator::OUTCOMES)),
            TextInput::make('grace_days')->label(self::f('grace_days'))->integer()->minValue(1)->maxValue(366),
            TextInput::make('lapse_after_days')->label(self::f('lapse_after_days'))->integer()->minValue(1)->maxValue(366),
            TextInput::make('priority')->label(self::f('priority'))->integer(),
            Textarea::make('legal_basis')->label(self::f('legal_basis'))->maxLength(2000),
            DatePicker::make('effective_from')->label(self::f('effective_from'))->required(),
            DatePicker::make('effective_until')->label(self::f('effective_until')),
        ], function (array $d) {
            $d['activation_rule'] = MiscSupport::json($d['activation_rule'] ?? null, 'activation_rule', false);
            // Same expression check the API runs before storing (a clear refusal before the insert).
            if (($errors = app(ExpressionValidator::class)->validate($d['activation_rule'])) !== []) {
                throw new ApiProblemException('ACTIVATION_RULE_INVALID', 422, 'The activation rule is not a valid rules expression: '.json_encode($errors));
            }
            $in = MiscSupport::filled($d) + ['activation_rule' => $d['activation_rule'], 'is_exception' => (bool) ($d['is_exception'] ?? false)];

            return ControllerCall::invoke(PremiumCoverController::class, 'store', $in);
        });
    }

    public static function premiumCoverRuleApprove(): Action
    {
        return MiscSupport::op('premiumCoverRuleApprove', 'premium_cover.rules.approve', 'lucide-badge-check', [
            Select::make('id')->label(self::f('premium_cover_rule'))->required()->searchable()
                ->options(fn () => MiscSupport::rows('premium_cover_rules', ['code', 'name', 'outcome'], fn ($q) => $q->where('status', 'DRAFT'))),
            Select::make('verification_status')->label(self::f('verification_status'))->options(MiscSupport::options(['UNVERIFIED', 'VERIFIED']))->default('UNVERIFIED'),
            Textarea::make('reason')->label(self::f('reason'))->maxLength(2000),
        ], fn (array $d) => ControllerCall::invoke(PremiumCoverController::class, 'approve', MiscSupport::filled(array_diff_key($d, ['id' => 1])), ['id' => $d['id']]));
    }

    public static function premiumCoverRuleRetire(): Action
    {
        return MiscSupport::op('premiumCoverRuleRetire', 'premium_cover.rules.manage', 'lucide-archive', [
            Select::make('id')->label(self::f('premium_cover_rule'))->required()->searchable()
                ->options(fn () => MiscSupport::rows('premium_cover_rules', ['code', 'name', 'status'], fn ($q) => $q->whereIn('status', ['DRAFT', 'ACTIVE']))),
            self::reason(),
        ], fn (array $d) => ControllerCall::invoke(PremiumCoverController::class, 'retire', ['reason' => $d['reason']], ['id' => $d['id']]));
    }

    public static function premiumCoverEvaluate(): Action
    {
        return MiscSupport::op('premiumCoverEvaluate', 'premium_cover.rules.view', 'lucide-calculator', [
            Select::make('product_id')->label(self::f('product'))->searchable()->options(fn () => self::products()),
            TextInput::make('class_code')->label(self::f('class_code'))->maxLength(40),
            TextInput::make('jurisdiction')->label(self::f('jurisdiction'))->length(2),
            Select::make('premium_status')->label(self::f('premium_status'))->required()->options(MiscSupport::options(PremiumCoverEvaluator::PREMIUM_STATUSES)),
            DatePicker::make('effective_date')->label(self::f('effective_date'))->required()->default(now()),
            Textarea::make('facts')->label(self::f('facts_json'))->rows(3),
        ], function (array $d) {
            $d['facts'] = MiscSupport::json($d['facts'] ?? null, 'facts');
            if (isset($d['effective_date'])) {
                $d['effective_date'] = substr((string) $d['effective_date'], 0, 10);
            }
            $d = MiscSupport::validate(MiscSupport::filled($d), [
                'product_id' => 'nullable|uuid', 'carrier_id' => 'nullable|uuid', 'class_code' => 'nullable|string|max:40', 'jurisdiction' => 'nullable|string|size:2',
                'premium_status' => ['required', Rule::in(PremiumCoverEvaluator::PREMIUM_STATUSES)], 'effective_date' => 'required|date_format:Y-m-d', 'facts' => 'nullable|array',
            ]);

            return app(PremiumCoverEvaluator::class)->evaluate($d);
        }, true);
    }

    public static function fraudRuleCreate(): Action
    {
        return MiscSupport::op('fraudRuleCreate', 'fraud.rules.manage', 'lucide-shield-plus', [
            TextInput::make('code')->label(self::f('code'))->required()->maxLength(80),
            Select::make('scope')->label(self::f('scope'))->required()->options(MiscSupport::options(['PAYMENT', 'QUOTE', 'POLICY', 'CLAIM', 'COMMISSION', 'IDENTITY', 'AML_TRANSACTION'])),
            TextInput::make('risk_points')->label(self::f('risk_points'))->required()->integer()->minValue(1)->maxValue(100),
            Textarea::make('conditions')->label(self::f('conditions_json'))->required()->rows(4),
            DatePicker::make('effective_from')->label(self::f('effective_from'))->required(),
            DatePicker::make('effective_until')->label(self::f('effective_until')),
        ], function (array $d) {
            $d['conditions'] = MiscSupport::json($d['conditions'] ?? null, 'conditions');

            return ControllerCall::invoke(RiskAlertController::class, 'createRule', MiscSupport::filled($d));
        });
    }

    public static function healthBenefitRuleAdd(): Action
    {
        return MiscSupport::op('healthBenefitRuleAdd', 'health.benefits.manage', 'lucide-heart-pulse', [
            Select::make('policy_id')->label(self::f('policy'))->searchable()->options(fn () => MiscSupport::rows('policies', ['policy_number', 'status'])),
            TextInput::make('medical_service_code')->label(self::f('medical_service_code'))->maxLength(64),
            TextInput::make('service_category_code')->label(self::f('service_category_code'))->maxLength(64),
            TextInput::make('coverage_code')->label(self::f('coverage_code'))->required()->maxLength(64),
            TextInput::make('benefit_code')->label(self::f('benefit_code'))->required()->maxLength(64),
            TextInput::make('waiting_period_days')->label(self::f('waiting_period_days'))->integer()->minValue(0)->maxValue(3650),
        ], fn (array $d) => app(HealthMemberService::class)->addBenefitRule(MiscSupport::tenant(), MiscSupport::validate(MiscSupport::filled($d), [
            'policy_id' => 'nullable|uuid', 'medical_service_code' => 'nullable|string|max:64', 'service_category_code' => 'nullable|string|max:64',
            'coverage_code' => 'required|string|max:64', 'benefit_code' => 'required|string|max:64', 'waiting_period_days' => 'nullable|integer|min:0|max:3650',
        ]), MiscSupport::user()->id));
    }

    public static function lifeScaleCreate(): Action
    {
        return MiscSupport::op('lifeScaleCreate', 'life_surrender.scales.manage', 'lucide-table', [
            Select::make('insurance_product_id')->label(self::f('product'))->required()->searchable()->options(fn () => self::products()),
            Select::make('basis')->label(self::f('basis'))->options(MiscSupport::options(LifeSurrenderService::BASES)),
            TextInput::make('min_years_in_force')->label(self::f('min_years_in_force'))->integer()->minValue(0)->maxValue(50),
            TagsInput::make('factors_bps')->label(self::f('factors_bps'))->required(),
            TextInput::make('surrender_charge_bps')->label(self::f('surrender_charge_bps'))->integer()->minValue(0)->maxValue(10000),
            TextInput::make('source_reference')->label(self::f('source_reference'))->maxLength(191),
        ], function (array $d) {
            $d['factors_bps'] = array_map(fn ($v) => is_numeric($v) ? (int) $v : $v, array_values($d['factors_bps'] ?? []));
            $d = MiscSupport::validate(MiscSupport::filled($d), [
                'insurance_product_id' => 'required|uuid', 'basis' => 'nullable|string|in:'.implode(',', LifeSurrenderService::BASES),
                'min_years_in_force' => 'nullable|integer|min:0|max:50', 'factors_bps' => 'required|array|min:1', 'factors_bps.*' => 'integer|min:0|max:10000',
                'surrender_charge_bps' => 'nullable|integer|min:0|max:10000', 'source_reference' => 'nullable|string|max:191',
            ]);

            return app(LifeSurrenderService::class)->createScale(MiscSupport::tenant(), $d, MiscSupport::user());
        });
    }

    public static function lifeScaleActivate(): Action
    {
        return MiscSupport::op('lifeScaleActivate', 'life_surrender.scales.approve', 'lucide-badge-check', [
            Select::make('scale')->label(self::f('surrender_scale'))->required()->searchable()
                ->options(fn () => MiscSupport::rows('life_surrender_scales', ['version', 'basis', 'status'], fn ($q) => $q->where('status', 'DRAFT'))),
        ], fn (array $d) => app(LifeSurrenderService::class)->activateScale(MiscSupport::tenant(), $d['scale'], MiscSupport::user()));
    }

    // ---- communications ---------------------------------------------------------------------------------------

    public static function notificationTemplateDraft(): Action
    {
        return MiscSupport::op('notificationTemplateDraft', 'communications.manage', 'lucide-file-plus', [
            TextInput::make('code')->label(self::f('code'))->required()->maxLength(80),
            Select::make('locale')->label(self::f('locale'))->required()->options(['en' => 'English', 'fr' => 'Français']),
            Select::make('purpose')->label(self::f('purpose'))->required()->options(MiscSupport::options(['TRANSACTIONAL', 'CLAIMS', 'RENEWALS', 'MARKETING', 'SECURITY'])),
            Select::make('channel')->label(self::f('channel'))->required()->options(MiscSupport::options(['SMS', 'EMAIL', 'PUSH', 'WHATSAPP'])),
            TextInput::make('subject')->label(self::f('subject'))->maxLength(200),
            Textarea::make('body')->label(self::f('body'))->required()->maxLength(10000)->rows(5),
            TagsInput::make('required_variables')->label(self::f('required_variables')),
        ], fn (array $d) => ControllerCall::invoke(NotificationController::class, 'template', MiscSupport::filled($d)));
    }

    public static function notificationTemplateApprove(): Action
    {
        return MiscSupport::op('notificationTemplateApprove', 'communications.approve', 'lucide-badge-check', [
            Select::make('template')->label(self::f('notification_template'))->required()->searchable()
                ->options(fn () => MiscSupport::rows('notification_templates', ['code', 'locale', 'channel', 'version'], fn ($q) => $q->where('status', 'DRAFT'))),
        ], fn (array $d) => ControllerCall::invoke(NotificationController::class, 'approveTemplate', [], ['template' => $d['template']]));
    }

    public static function communicationPreferenceSet(): Action
    {
        return MiscSupport::op('communicationPreferenceSet', 'communications.manage', 'lucide-bell-ring', [
            Select::make('party_id')->label(self::f('customer'))->required()->searchable()->options(fn () => MiscSupport::customers()),
            Select::make('purpose')->label(self::f('purpose'))->required()->options(MiscSupport::options(['TRANSACTIONAL', 'CLAIMS', 'RENEWALS', 'MARKETING', 'SECURITY'])),
            Select::make('channel')->label(self::f('channel'))->required()->options(MiscSupport::options(['SMS', 'EMAIL', 'PUSH', 'WHATSAPP'])),
            Toggle::make('enabled')->label(self::f('enabled'))->default(true),
        ], fn (array $d) => ControllerCall::invoke(NotificationController::class, 'preference', ['enabled' => (bool) ($d['enabled'] ?? false)] + $d, [], 'PUT'));
    }

    // ---- work queues & SLA ------------------------------------------------------------------------------------

    public static function queueCreate(): Action
    {
        return MiscSupport::op('queueCreate', 'cases.admin', 'lucide-list-plus', [
            TextInput::make('code')->label(self::f('code'))->required()->maxLength(64),
            TextInput::make('name')->label(self::f('name'))->required()->maxLength(160),
            TagsInput::make('case_type_codes')->label(self::f('case_type_codes')),
            Select::make('branch_id')->label(self::f('branch'))->options(fn () => MiscSupport::rows('tenant_branches', ['code', 'name'])),
            Select::make('routing_rule')->label(self::f('routing_rule'))->options(MiscSupport::options(['PULL', 'LEAST_LOADED', 'ROUND_ROBIN'])),
            Toggle::make('is_default')->label(self::f('is_default')),
        ], fn (array $d) => ControllerCall::invoke(CaseAdminController::class, 'addQueue', MiscSupport::filled($d) + ['is_default' => (bool) ($d['is_default'] ?? false)]));
    }

    public static function queueMemberAdd(): Action
    {
        return MiscSupport::op('queueMemberAdd', 'cases.admin', 'lucide-user-plus', [
            Select::make('queue')->label(self::f('queue'))->required()->searchable()->options(fn () => MiscSupport::rows('queues', ['code', 'name'])),
            Select::make('user_id')->label(self::f('user'))->required()->searchable()->options(fn () => MiscSupport::members()),
            TextInput::make('capacity')->label(self::f('capacity'))->integer()->minValue(1)->maxValue(1000),
            TagsInput::make('skills')->label(self::f('skills')),
            Toggle::make('active')->label(self::f('active'))->default(true),
        ], fn (array $d) => ControllerCall::invoke(CaseAdminController::class, 'addMember',
            MiscSupport::filled(array_diff_key($d, ['queue' => 1])) + ['active' => (bool) ($d['active'] ?? true)], ['queue' => $d['queue']]));
    }

    public static function slaOverrideAdd(): Action
    {
        return MiscSupport::op('slaOverrideAdd', 'cases.admin', 'lucide-timer', [
            Select::make('case_type_code')->label(self::f('case_type'))->required()->searchable()
                ->options(fn () => CaseType::query()->orderBy('code')->pluck('code', 'code')->all()),
            TextInput::make('metric')->label(self::f('metric'))->required()->maxLength(64)->default('RESOLUTION'),
            Select::make('branch_id')->label(self::f('branch'))->options(fn () => MiscSupport::rows('tenant_branches', ['code', 'name'])),
            TextInput::make('case_subtype')->label(self::f('case_subtype'))->maxLength(48),
            TextInput::make('target_business_days')->label(self::f('target_business_days'))->integer()->minValue(1)->maxValue(3650),
            TextInput::make('target_business_minutes')->label(self::f('target_business_minutes'))->integer()->minValue(1),
            TextInput::make('warn_at_pct')->label(self::f('warn_at_pct'))->integer()->minValue(1)->maxValue(99),
            Select::make('label')->label(self::f('deadline_label'))->options(MiscSupport::options(CaseTypeCatalogue::LABELS)),
            Textarea::make('legal_basis')->label(self::f('legal_basis'))->maxLength(2000),
            DatePicker::make('effective_from')->label(self::f('effective_from')),
            DatePicker::make('effective_until')->label(self::f('effective_until')),
        ], fn (array $d) => ControllerCall::invoke(CaseConfigurationController::class, 'addOverride', MiscSupport::filled($d)));
    }

    public static function slaOverrideRetire(): Action
    {
        return MiscSupport::op('slaOverrideRetire', 'cases.admin', 'lucide-archive', [
            Select::make('id')->label(self::f('sla_override'))->required()->searchable()
                ->options(fn () => MiscSupport::rows('sla_policy_overrides', ['case_type_code', 'metric', 'deadline_label'], fn ($q) => $q->where('status', 'ACTIVE'))),
            self::reason(),
        ], fn (array $d) => ControllerCall::invoke(CaseConfigurationController::class, 'retireOverride', ['reason' => $d['reason']], ['id' => $d['id']]));
    }

    // ---- governance -------------------------------------------------------------------------------------------

    public static function configChangeDraft(): Action
    {
        return MiscSupport::op('configChangeDraft', 'configuration.changes.manage', 'lucide-file-pen', [
            TextInput::make('config_type')->label(self::f('config_type'))->required()->maxLength(64),
            TextInput::make('config_key')->label(self::f('config_key'))->required()->maxLength(128),
            Textarea::make('proposed_value')->label(self::f('proposed_value_json'))->required()->rows(4),
            self::reason(),
            DatePicker::make('effective_from')->label(self::f('effective_from')),
        ], function (array $d) {
            $d['proposed_value'] = MiscSupport::json($d['proposed_value'] ?? null, 'proposed_value');
            $d = MiscSupport::validate(MiscSupport::filled($d), ['config_type' => 'required|string|max:64', 'config_key' => 'required|string|max:128',
                'proposed_value' => 'required|array', 'reason' => 'required|string|min:5', 'effective_from' => 'nullable|date']);

            return app(ConfigurationGovernanceService::class)->draft(MiscSupport::user(), $d['config_type'], $d['config_key'], $d['proposed_value'], $d['reason'], $d['effective_from'] ?? null);
        });
    }

    private static function changeSet(array $d): ConfigurationChangeSet
    {
        return ConfigurationChangeSet::where('tenant_id', MiscSupport::tenant())->findOrFail($d['change']);
    }

    private static function changePicker(array $statuses): Select
    {
        return Select::make('change')->label(self::f('configuration_change'))->required()->searchable()
            ->options(fn () => MiscSupport::rows('configuration_change_sets', ['config_type', 'config_key', 'status'], fn ($q) => $q->whereIn('status', $statuses)));
    }

    public static function configChangeSubmit(): Action
    {
        return MiscSupport::op('configChangeSubmit', 'configuration.changes.manage', 'lucide-send', [self::changePicker(['DRAFT'])],
            fn (array $d) => app(ConfigurationGovernanceService::class)->submit(self::changeSet($d), MiscSupport::user()));
    }

    public static function configChangePublish(): Action
    {
        return MiscSupport::op('configChangePublish', 'configuration.changes.manage', 'lucide-upload', [self::changePicker(['APPROVED'])],
            fn (array $d) => app(ConfigurationGovernanceService::class)->publish(self::changeSet($d), MiscSupport::user()));
    }

    public static function featureFlagSet(): Action
    {
        return MiscSupport::op('featureFlagSet', 'tenant.manage', 'lucide-flag', [
            TextInput::make('key')->label(self::f('flag_key'))->required()->maxLength(96),
            Toggle::make('enabled')->label(self::f('enabled')),
            TextInput::make('environment')->label(self::f('environment'))->maxLength(24),
            TextInput::make('country_code')->label(self::f('country_code'))->length(2),
            Select::make('tenant_id')->label(self::f('tenant'))->visible(fn () => MiscSupport::isPlatformTenant())
                ->options(fn () => MiscSupport::rows('tenants', ['legal_name', 'type'], null, 'id', false)),
            Select::make('branch_id')->label(self::f('branch'))->options(fn () => MiscSupport::rows('tenant_branches', ['code', 'name'])),
            TextInput::make('product_code')->label(self::f('product_code'))->maxLength(64),
            TextInput::make('description')->label(self::f('description'))->maxLength(500),
        ], function (array $d) {
            $d = MiscSupport::validate(MiscSupport::filled($d) + ['enabled' => (bool) ($d['enabled'] ?? false)], [
                'key' => 'required|string|max:96', 'enabled' => 'required|boolean', 'environment' => 'nullable|string|max:24', 'country_code' => 'nullable|string|size:2',
                'tenant_id' => 'nullable|uuid|exists:tenants,id', 'branch_id' => 'nullable|uuid', 'product_code' => 'nullable|string|max:64', 'description' => 'nullable|string|max:500',
            ]);
            // Same rule as FeatureFlagController::upsert: only the platform tenant may set flags for other tenants.
            if (! MiscSupport::isPlatformTenant()) {
                abort_if(isset($d['tenant_id']) && $d['tenant_id'] !== MiscSupport::tenant(), 403, 'Only the platform may set flags for other tenants.');
                $d['tenant_id'] = MiscSupport::tenant();
            }

            return app(FeatureFlags::class)->set($d, MiscSupport::user()->id);
        });
    }

    public static function privacyPurposeUpdate(): Action
    {
        return MiscSupport::op('privacyPurposeUpdate', 'privacy.purposes.manage', 'lucide-lock', [
            Select::make('code')->label(self::f('processing_purpose'))->required()->searchable()
                ->options(fn () => MiscSupport::rows('processing_purposes', ['code', 'name'], null, 'code', false)),
            TextInput::make('name')->label(self::f('name'))->maxLength(160),
            Textarea::make('description')->label(self::f('description'))->maxLength(2000),
            Select::make('lawful_basis')->label(self::f('lawful_basis'))
                ->options(MiscSupport::options(['CONSENT', 'CONTRACT', 'LEGAL_OBLIGATION', 'LEGITIMATE_INTEREST', 'VITAL_INTEREST', 'PUBLIC_TASK'])),
            TextInput::make('consent_purpose')->label(self::f('consent_purpose'))->maxLength(64),
            Select::make('basis_status')->label(self::f('basis_status'))->options(MiscSupport::options(['PLATFORM_PROVISIONAL', 'OWNER_CONFIRMED'])),
            Select::make('is_active')->label(self::f('active'))->options(['1' => __(MiscSupport::L.'.yes'), '0' => __(MiscSupport::L.'.no')]),
        ], function (array $d) {
            $in = MiscSupport::filled(array_diff_key($d, ['code' => 1]));
            if (isset($in['is_active'])) {
                $in['is_active'] = $in['is_active'] === '1';
            }

            return ControllerCall::invoke(SecurityCentreController::class, 'updatePurpose', $in, ['code' => $d['code']], 'PATCH');
        });
    }

    // ---- risk & capacity --------------------------------------------------------------------------------------

    public static function largeLossThreshold(): Action
    {
        return MiscSupport::op('largeLossThreshold', 'catastrophe.events.manage', 'lucide-siren', [
            TextInput::make('currency')->label(self::f('currency'))->required()->length(3)->default('XAF'),
            TextInput::make('threshold_minor')->label(self::f('threshold_minor'))->required()->integer()->minValue(1),
            Select::make('recipient_user_ids')->label(self::f('recipients'))->required()->multiple()->searchable()->options(fn () => MiscSupport::members()),
        ], function (array $d) {
            $d = MiscSupport::validate($d, ['currency' => 'required|string|size:3', 'threshold_minor' => 'required|integer|min:1',
                'recipient_user_ids' => 'required|array|min:1', 'recipient_user_ids.*' => 'uuid|exists:users,id']);

            return app(LargeLossNotifier::class)->configure(MiscSupport::tenant(), $d['currency'], (int) $d['threshold_minor'], array_values($d['recipient_user_ids']));
        });
    }

    public static function capacityCheck(): Action
    {
        return MiscSupport::op('capacityCheck', 'accumulation.capacity.check', 'lucide-gauge', [
            TextInput::make('peril_code')->label(self::f('peril_code'))->maxLength(32),
            TextInput::make('sum_insured_minor')->label(self::f('sum_insured_minor'))->required()->integer()->minValue(0),
            TextInput::make('currency')->label(self::f('currency'))->required()->length(3)->default('XAF'),
            TextInput::make('line_code')->label(self::f('line_code'))->maxLength(32),
            DatePicker::make('date')->label(self::f('date')),
        ], function (array $d) {
            $d = MiscSupport::validate(MiscSupport::filled($d), ['zone_id' => 'nullable|uuid', 'peril_code' => 'sometimes|string|max:32', 'sum_insured_minor' => 'required|integer|min:0',
                'currency' => 'required|string|size:3', 'line_code' => 'nullable|string|max:32', 'date' => 'nullable|date']);

            return app(CapacityService::class)->check(MiscSupport::tenant(), $d, MiscSupport::user()->id);
        }, true);
    }

    public static function capabilityPin(): Action
    {
        return MiscSupport::op('capabilityPin', 'capability_profiles.manage', 'lucide-pin', [
            Select::make('subject_type')->label(self::f('subject_type'))->required()->options(MiscSupport::options(CapabilityPinner::SUBJECT_TYPES)),
            TextInput::make('subject_id')->label(self::f('subject_id'))->required()->uuid(),
            Select::make('carrier_id')->label(self::f('carrier'))->required()->searchable()->options(fn () => MiscSupport::rows('carriers', ['cima_code', 'name'], null, 'id', false)),
            Select::make('capability')->label(self::f('capability'))->required()->options(MiscSupport::options(array_keys(CapabilityCatalogue::CAPABILITIES))),
            Select::make('product_id')->label(self::f('product'))->searchable()->options(fn () => self::products()),
        ], function (array $d) {
            $d = MiscSupport::validate(MiscSupport::filled($d), [
                'subject_type' => ['required', Rule::in(CapabilityPinner::SUBJECT_TYPES)], 'subject_id' => 'required|uuid', 'carrier_id' => 'required|uuid|exists:carriers,id',
                'capability' => ['required', Rule::in(array_keys(CapabilityCatalogue::CAPABILITIES))], 'product_id' => 'nullable|uuid|exists:insurance_products,id',
            ]);
            $pinner = app(CapabilityPinner::class);

            return $pinner->pinned($d['subject_type'], $d['subject_id'], $d['capability'])
                ?? $pinner->pin($d['subject_type'], $d['subject_id'], $d['carrier_id'], $d['capability'], $d['product_id'] ?? null, MiscSupport::user());
        });
    }

    // ---- organisation -----------------------------------------------------------------------------------------

    public static function departmentCreate(): Action
    {
        return MiscSupport::op('departmentCreate', 'tenant.manage', 'lucide-network', [
            TextInput::make('code')->label(self::f('code'))->required()->alphaDash()->maxLength(40),
            TextInput::make('name')->label(self::f('name'))->required()->maxLength(160),
            Select::make('branch_id')->label(self::f('branch'))->options(fn () => MiscSupport::rows('tenant_branches', ['code', 'name'])),
            Select::make('manager_user_id')->label(self::f('manager'))->searchable()->options(fn () => MiscSupport::members()),
            TextInput::make('queue_code')->label(self::f('queue_code'))->maxLength(64),
        ], fn (array $d) => app(OrganizationStructureService::class)->createDepartment(MiscSupport::tenant(), MiscSupport::validate(MiscSupport::filled($d), [
            'code' => 'required|alpha_dash|max:40', 'name' => 'required|string|max:160', 'branch_id' => 'nullable|uuid',
            'manager_user_id' => 'nullable|uuid|exists:users,id', 'queue_code' => 'nullable|string|max:64', 'limits' => 'sometimes|array',
        ])));
    }

    public static function branchCreate(): Action
    {
        return MiscSupport::op('branchCreate', 'tenant.manage', 'lucide-map-pin-plus', [
            TextInput::make('code')->label(self::f('code'))->required()->alphaDash()->maxLength(40),
            TextInput::make('name')->label(self::f('name'))->required()->maxLength(160),
            TextInput::make('phone_e164')->label(self::f('phone'))->maxLength(20),
            TextInput::make('email')->label(self::f('email'))->email(),
            TextInput::make('timezone')->label(self::f('timezone'))->placeholder('Africa/Douala'),
            Select::make('manager_user_id')->label(self::f('manager'))->searchable()->options(fn () => MiscSupport::members()),
        ], fn (array $d) => ControllerCall::invoke(BranchController::class, 'store', MiscSupport::filled($d)));
    }

    public static function tenantUpdate(): Action
    {
        return MiscSupport::op('tenantUpdate', 'tenant.manage', 'lucide-building', [
            TextInput::make('trade_name')->label(self::f('trade_name'))->maxLength(160),
            Select::make('primary_locale')->label(self::f('locale'))->options(['en' => 'English', 'fr' => 'Français']),
        ], fn (array $d) => ControllerCall::invoke(TenantController::class, 'update', MiscSupport::filled($d), ['tenant' => MiscSupport::tenant()], 'PATCH'));
    }
}
