<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\Compliance\RegulatoryReportingService;
use App\Application\Regulatory\Inspection\InspectionWorkspaceService;
use App\Application\Regulatory\Returns\RegulatoryReturnGenerator;
use App\Application\Regulatory\Rules\RegulatoryRuleService;
use App\Filament\Shared\Actions\RegulatoryCrmSupport as S;
use App\Models\RegulatoryReportDefinition;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Regulatory returns, regulatory change rules and inspections (REQ-RPT-001/002/006). Each action calls the SAME
 * service method, with the SAME validation and permission, as RegulatoryReturnsController:
 *   returnDefine          POST regulatory/return-definitions                   regulatory.returns.define        RegulatoryReportingService::define
 *   returnApprove         POST regulatory/return-definitions/{d}/approve       regulatory.returns.approve       RegulatoryReportingService::approveDefinition (maker ≠ checker)
 *   returnGenerate        POST regulatory/return-definitions/{d}/runs          trust.regulatory-reports.prepare RegulatoryReturnGenerator::generate
 *   ruleDraft             POST regulatory/rules                                regulatory.rules.draft           RegulatoryRuleService::draft
 *   ruleReview            POST regulatory/rules/{r}/review                     regulatory.rules.review          RegulatoryRuleService::review (author ≠ reviewer)
 *   ruleApprove           POST regulatory/rules/{r}/approve                    regulatory.rules.approve         RegulatoryRuleService::approve (impact hash, reviewer ≠ approver)
 *   ruleActivate          POST regulatory/rules/{r}/activate                   regulatory.rules.approve         RegulatoryRuleService::activate
 *   inspectionOpen        POST regulatory/inspections                          regulatory.inspections.manage    InspectionWorkspaceService::open
 *   inspectionApprove     POST regulatory/inspections/{i}/approve              regulatory.inspections.approve   InspectionWorkspaceService::approve (requester ≠ approver)
 *   inspectionClose       POST regulatory/inspections/{i}/close                regulatory.inspections.manage    InspectionWorkspaceService::close
 */
final class RegulatoryReturnActions
{
    public static function returnDefine(): Action
    {
        $p = 'regulatory.returns.define';

        return WorkflowAction::make('returnDefine', $p, S::L)->icon('lucide-file-plus')
            ->schema([
                TextInput::make('code')->label(S::f('code'))->required()->maxLength(80),
                TextInput::make('version')->label(S::f('version'))->integer()->minValue(1)->default(1)->required(),
                TextInput::make('jurisdiction')->label(S::f('jurisdiction'))->default('CM')->required()->maxLength(8),
                TextInput::make('report_type')->label(S::f('report_type'))->required()->maxLength(64),
                TextInput::make('regime')->label(S::f('regime'))->maxLength(16),
                Textarea::make('description')->label(S::f('description'))->maxLength(2000),
                Select::make('regulatory_category_kind')->label(S::f('regulatory_category_kind'))->options(S::opts(['ART_411_CATEGORY', 'ART_557_MEASURE'])),
                TextInput::make('regulatory_category_code')->label(S::f('regulatory_category_code'))->maxLength(64),
                S::jsonField('schema'),
                DatePicker::make('effective_from')->label(S::f('effective_from'))->required(),
                DatePicker::make('effective_until')->label(S::f('effective_until')),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, function () use ($data) {
                $data['schema'] = S::json($data, 'schema');
                $d = S::check($data, [
                    'code' => 'required|string|max:80', 'version' => 'required|integer|min:1', 'jurisdiction' => 'required|string|max:8',
                    'report_type' => 'required|string|max:64', 'regime' => 'nullable|string|max:16', 'description' => 'nullable|string|max:2000',
                    'regulatory_category_kind' => 'nullable|required_with:regulatory_category_code|string|in:ART_411_CATEGORY,ART_557_MEASURE',
                    'regulatory_category_code' => 'nullable|string|max:64', 'schema' => 'required|array',
                    'effective_from' => 'required|date', 'effective_until' => 'nullable|date|after_or_equal:effective_from',
                ]);
                app(RegulatoryReturnGenerator::class)->validateSchema($d['schema']);
                if (isset($d['regulatory_category_code']) && ! DB::table('regulatory_reporting_categories')
                    ->where(['kind' => $d['regulatory_category_kind'], 'code' => $d['regulatory_category_code']])->exists()) {
                    throw ValidationException::withMessages(['regulatory_category_code' => 'Unknown regulatory reporting category for this kind.']);
                }

                return app(RegulatoryReportingService::class)->define($d, S::user());
            }, S::done('returnDefine')));
    }

    public static function returnApprove(): Action
    {
        $p = 'regulatory.returns.approve';

        return WorkflowAction::make('returnApprove', $p, S::L)->icon('lucide-badge-check')->color('success')->requiresConfirmation()
            ->visible(fn (mixed $record) => S::field($record, 'status') === 'DRAFT')
            ->action(fn (Action $action, mixed $record) => WorkflowAction::run($action, $p,
                fn () => app(RegulatoryReportingService::class)->approveDefinition(RegulatoryReportDefinition::findOrFail(WorkflowAction::id($record)), S::user()), S::done('returnApprove')));
    }

    public static function returnGenerate(): Action
    {
        $p = 'trust.regulatory-reports.prepare';

        return WorkflowAction::make('returnGenerate', $p, S::L)->icon('lucide-play')
            ->visible(fn (mixed $record) => S::field($record, 'status') === 'ACTIVE')
            ->schema([
                TextInput::make('period_key')->label(S::f('period_key'))->required()->maxLength(40),
                DatePicker::make('period_from')->label(S::f('period_from'))->required(),
                DatePicker::make('period_to')->label(S::f('period_to'))->required(),
                S::idempotency(),
            ])
            ->action(fn (Action $action, mixed $record, array $data) => WorkflowAction::run($action, $p, function () use ($record, $data) {
                $d = S::check($data, ['period_key' => 'required|string|max:40', 'period_from' => 'required|date', 'period_to' => 'required|date|after_or_equal:period_from',
                    'idempotency_key' => 'required|string|max:100']);

                return app(RegulatoryReturnGenerator::class)->generate(S::tenant(), RegulatoryReportDefinition::findOrFail(WorkflowAction::id($record)), $d, S::user());
            }, S::done('returnGenerate')));
    }

    public static function ruleDraft(): Action
    {
        $p = 'regulatory.rules.draft';

        return WorkflowAction::make('ruleDraft', $p, S::L)->icon('lucide-file-pen')
            ->schema([
                TextInput::make('jurisdiction')->label(S::f('jurisdiction'))->maxLength(8),
                TextInput::make('code')->label(S::f('code'))->required()->maxLength(80),
                TextInput::make('title')->label(S::f('title'))->required()->maxLength(255),
                TextInput::make('rule_type')->label(S::f('rule_type'))->required()->maxLength(48),
                TextInput::make('legal_reference')->label(S::f('legal_reference'))->maxLength(255),
                TextInput::make('reference_set_code')->label(S::f('reference_set_code'))->maxLength(80),
                S::jsonField('scope', false),
                S::jsonField('content', false),
                DatePicker::make('effective_from')->label(S::f('effective_from'))->required(),
                DatePicker::make('effective_until')->label(S::f('effective_until')),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, function () use ($data) {
                $data['scope'] = S::json($data, 'scope', false);
                $data['content'] = S::json($data, 'content', false);
                $d = S::check($data, [
                    'jurisdiction' => 'nullable|string|max:8', 'code' => 'required|string|max:80', 'title' => 'required|string|max:255',
                    'rule_type' => 'required|string|max:48', 'legal_reference' => 'nullable|string|max:255', 'reference_set_code' => 'nullable|string|max:80',
                    'scope' => 'nullable|array', 'content' => 'nullable|array',
                    'effective_from' => 'required|date', 'effective_until' => 'nullable|date|after_or_equal:effective_from',
                ]);

                return app(RegulatoryRuleService::class)->draft($d, S::user());
            }, S::done('ruleDraft')));
    }

    public static function ruleReview(): Action
    {
        $p = 'regulatory.rules.review';

        return WorkflowAction::make('ruleReview', $p, S::L)->icon('lucide-search-check')
            ->visible(fn (mixed $record) => S::field($record, 'status') === 'DRAFT')
            ->schema([Textarea::make('notes')->label(S::f('notes'))->maxLength(2000)])
            ->action(fn (Action $action, mixed $record, array $data) => WorkflowAction::run($action, $p, function () use ($record, $data) {
                $d = S::check($data, ['notes' => 'nullable|string|max:2000']);

                return app(RegulatoryRuleService::class)->review(WorkflowAction::id($record), S::user(), $d['notes'] ?? null);
            }, S::done('ruleReview')));
    }

    /** The approver confirms the impact analysed now (GET regulatory/rules/{r}/impact); the service refuses a stale hash. */
    public static function ruleApprove(): Action
    {
        $p = 'regulatory.rules.approve';

        return WorkflowAction::make('ruleApprove', $p, S::L)->icon('lucide-badge-check')->color('success')
            ->visible(fn (mixed $record) => S::field($record, 'status') === 'REVIEWED')
            ->fillForm(fn (mixed $record) => ['impact_hash' => self::impactHash(WorkflowAction::id($record))])
            ->schema([TextInput::make('impact_hash')->label(S::f('impact_hash'))->required()->length(64)->readOnly()])
            ->action(fn (Action $action, mixed $record, array $data) => WorkflowAction::run($action, $p, function () use ($record, $data) {
                $d = S::check($data, ['impact_hash' => 'required|string|size:64']);

                return app(RegulatoryRuleService::class)->approve(WorkflowAction::id($record), S::user(), $d['impact_hash']);
            }, S::done('ruleApprove')));
    }

    public static function ruleActivate(): Action
    {
        $p = 'regulatory.rules.approve';

        return WorkflowAction::make('ruleActivate', $p, S::L)->icon('lucide-power')->color('success')->requiresConfirmation()
            ->visible(fn (mixed $record) => S::field($record, 'status') === 'APPROVED')
            ->action(fn (Action $action, mixed $record) => WorkflowAction::run($action, $p,
                fn () => app(RegulatoryRuleService::class)->activate(WorkflowAction::id($record), S::user()), S::done('ruleActivate')));
    }

    public static function inspectionOpen(): Action
    {
        $p = 'regulatory.inspections.manage';
        $resources = array_keys(InspectionWorkspaceService::RESOURCES);

        return WorkflowAction::make('inspectionOpen', $p, S::L)->icon('lucide-scan-search')
            ->schema([
                S::member('inspector_user_id')->required(),
                TextInput::make('authority')->label(S::f('authority'))->required()->maxLength(120),
                TextInput::make('reference')->label(S::f('reference'))->maxLength(120),
                Textarea::make('justification')->label(S::f('justification'))->required()->maxLength(2000),
                CheckboxList::make('resources')->label(S::f('resources'))->options(S::opts($resources, 'resource'))->required(),
                DateTimePicker::make('starts_at')->label(S::f('starts_at'))->required(),
                DateTimePicker::make('expires_at')->label(S::f('expires_at'))->required(),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, function () use ($data, $resources) {
                $d = S::check($data, [
                    'inspector_user_id' => 'required|uuid', 'authority' => 'required|string|max:120', 'reference' => 'nullable|string|max:120',
                    'justification' => 'required|string|max:2000', 'resources' => 'required|array|min:1', 'resources.*' => ['string', 'in:'.implode(',', $resources)],
                    'starts_at' => 'required|date', 'expires_at' => 'required|date|after:starts_at',
                ]);
                $inspector = User::findOrFail($d['inspector_user_id']);
                abort_unless(DB::table('tenant_memberships')->where(['tenant_id' => S::tenant(), 'user_id' => $inspector->id, 'status' => 'ACTIVE'])->exists(), 422, 'Inspector is not a member of this tenant.');
                if ($inspector->id === S::user()->id) {
                    throw ValidationException::withMessages(['inspector_user_id' => __('wave9.maker_checker')]);
                }

                return app(InspectionWorkspaceService::class)->open(S::tenant(), $inspector, $d, S::user());
            }, S::done('inspectionOpen')));
    }

    public static function inspectionApprove(): Action
    {
        $p = 'regulatory.inspections.approve';

        return WorkflowAction::make('inspectionApprove', $p, S::L)->icon('lucide-badge-check')->color('success')->requiresConfirmation()
            ->visible(fn (mixed $record) => S::field($record, 'status') === 'REQUESTED')
            ->action(fn (Action $action, mixed $record) => WorkflowAction::run($action, $p,
                fn () => app(InspectionWorkspaceService::class)->approve(S::tenant(), WorkflowAction::id($record), S::user()), S::done('inspectionApprove')));
    }

    public static function inspectionClose(): Action
    {
        $p = 'regulatory.inspections.manage';

        return WorkflowAction::make('inspectionClose', $p, S::L)->icon('lucide-lock')->color('danger')
            ->visible(fn (mixed $record) => S::field($record, 'status') !== 'CLOSED')
            ->schema([Textarea::make('reason')->label(S::f('reason'))->required()->maxLength(2000)])
            ->action(fn (Action $action, mixed $record, array $data) => WorkflowAction::run($action, $p, function () use ($record, $data) {
                $d = S::check($data, ['reason' => 'required|string|max:2000']);

                return app(InspectionWorkspaceService::class)->close(S::tenant(), WorkflowAction::id($record), $d['reason'], S::user());
            }, S::done('inspectionClose')));
    }

    /** Same hash as GET regulatory/rules/{rule}/impact. */
    public static function impactHash(string $ruleId): ?string
    {
        return rescue(function () use ($ruleId) {
            $rules = app(RegulatoryRuleService::class);

            return hash('sha256', json_encode($rules->analyse($rules->find($ruleId)), JSON_THROW_ON_ERROR));
        }, null, false);
    }
}
