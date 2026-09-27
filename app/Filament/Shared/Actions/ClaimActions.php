<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\Claims\Assessment\ClaimAssessmentService;
use App\Application\Claims\Assessment\Models\ClaimAssessment;
use App\Application\Claims\ClaimLifecycleService;
use App\Application\Claims\ClaimReferenceCodes;
use App\Application\Claims\Closure\ClaimClosureChecklist;
use App\Application\Claims\Closure\ClaimClosureService;
use App\Application\Claims\Decisions\ClaimDecisionService;
use App\Application\Claims\Fnol\FnolService;
use App\Application\Claims\Reserves\ClaimReserveService;
use App\Application\Claims\Settlement\ClaimSettlementService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Claim;
use App\Models\ClaimDecision;
use App\Models\ClaimReserveChange;
use App\Models\Policy;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Wizard\Step;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Claim lifecycle actions for the web (mount on the claim list / claim detail page). Each one calls the service the
 * matching API route calls, behind the same permission:
 *   register        POST claims/fnol                              claims.create               FnolService::submit
 *   assign          POST claims/{id}/assign                       claims.assign               ClaimLifecycleService::assign
 *   assess          POST claims/{claim}/assessments               claims.assessment.record    ClaimAssessmentService::record
 *   reviewAssessment POST claims/assessments/{a}/accept|reject    claims.assessment.review    ClaimAssessmentService::accept|reject
 *   reserve         POST claims/{id}/reserves                     claims.reserve.request      ClaimReserveService::request
 *   approveReserve  POST claims/{id}/reserves/{r}/approve         claims.reserve.approve      ClaimReserveService::approve
 *   decide          POST claims/{claim}/decision-proposals        claims.decision.propose     ClaimDecisionService::propose
 *   approveDecision POST …/decision-proposals/{d}/approve|return  claims.decision.approve     ClaimDecisionService::approve|returnToMaker
 *   settle          POST claims/{claim}/settlements               claims.settlement.calculate ClaimSettlementService::calculate
 *   offerSettlement POST claim-settlements/{s}/offer              claims.settlement.offer     ClaimSettlementService::offer
 *   close           POST claims/{claim}/close                     claims.close                ClaimClosureService::close
 *   requestReopen   POST claims/{claim}/reopen-requests           claims.reopen.request       ClaimClosureService::requestReopen
 *   decideReopen    POST claims/reopen-requests/{r}/approve|reject claims.reopen.approve      ClaimClosureService::approveReopen|rejectReopen
 * Staff withdrawal on the claimant's behalf is `close` with reason WITHDRAWN (the claimant's own withdrawal is the
 * mobile route, MobileClaimService::withdraw, which is limited to the claimant).
 */
final class ClaimActions
{
    /** All record actions, grouped, for a claim detail page header. */
    public static function group(): ActionGroup
    {
        return ActionGroup::make([
            self::assign(), self::assess(), self::reviewAssessment(), self::reserve(), self::approveReserve(),
            self::decide(), self::approveDecision(), self::settle(), self::offerSettlement(),
            self::close(), self::requestReopen(), self::decideReopen(),
        ])->label(__('workflow_actions.claim_group'))->icon('heroicon-o-bolt')->button();
    }

    /** List-page header action (no record): FNOL registration wizard. */
    public static function register(): Action
    {
        $p = 'claims.create';

        return WorkflowAction::make('claimRegister', $p)->icon('heroicon-o-plus')
            ->steps([
                Step::make(__('workflow_actions.claimRegister.step_policy'))->schema([
                    Select::make('policy_id')->label(__('workflow_actions.fields.policy'))->required()->searchable()->live()
                        ->options(fn () => Policy::where('tenant_id', self::tenant())->whereIn('status', ['ACTIVE', 'EXPIRING', 'SUSPENDED', 'CANCELLATION_PENDING'])->limit(200)->pluck('policy_number', 'id'))
                        ->afterStateUpdated(fn ($state, callable $set) => $set('claimant_party_id', Policy::find($state)?->party_id)),
                    Select::make('claimant_party_id')->label(__('workflow_actions.fields.claimant'))->required()
                        ->options(fn (callable $get) => ($pol = Policy::find($get('policy_id'))) ? [$pol->party_id => $pol->party?->display_name ?? $pol->party_id] : []),
                ]),
                Step::make(__('workflow_actions.claimRegister.step_loss'))->schema([
                    DateTimePicker::make('loss_occurred_at')->label(__('workflow_actions.fields.loss_occurred_at'))->required()->maxDate(now()),
                    TextInput::make('loss_location')->label(__('workflow_actions.fields.loss_location'))->maxLength(255),
                    Textarea::make('description')->label(__('workflow_actions.fields.loss_description'))->required()->maxLength(5000),
                    TextInput::make('estimated_loss_minor')->label(__('workflow_actions.fields.estimated_loss_minor'))->integer()->minValue(0),
                    Select::make('priority')->label(__('workflow_actions.fields.priority'))->options(WorkflowAction::options(['LOW', 'NORMAL', 'HIGH', 'CRITICAL']))->default('NORMAL')->required(),
                    Select::make('channel')->label(__('workflow_actions.fields.channel'))->options(WorkflowAction::options(['BACK_OFFICE', 'PHONE', 'EMAIL', 'BRANCH', 'WEB']))->default('BACK_OFFICE')->required(),
                ]),
            ])
            ->action(function (Action $action, array $data) use ($p) {
                $channel = $data['channel'] ?? 'BACK_OFFICE';
                $payload = [
                    'policy_id' => $data['policy_id'], 'claimant_party_id' => $data['claimant_party_id'], 'loss_occurred_at' => $data['loss_occurred_at'],
                    'loss_details' => ['description' => $data['description']], 'loss_location' => $data['loss_location'] ?? null,
                    'estimated_loss_minor' => filled($data['estimated_loss_minor'] ?? null) ? (int) $data['estimated_loss_minor'] : null,
                    'priority' => $data['priority'] ?? 'NORMAL', 'idempotency_key' => (string) Str::uuid(),
                ];

                return WorkflowAction::run($action, $p, fn () => app(FnolService::class)->submit(self::tenant(), $payload, auth()->user(), ['channel' => $channel, 'role' => 'STAFF']));
            });
    }

    public static function assign(): Action
    {
        $p = 'claims.assign';

        return WorkflowAction::make('claimAssign', $p)->icon('heroicon-o-user-plus')
            ->schema([
                Select::make('assignee_id')->label(__('workflow_actions.fields.assignee'))->required()->searchable()
                    ->options(fn () => User::where('status', 'ACTIVE')->whereHas('memberships', fn ($q) => $q->where('tenant_id', self::tenant())->where('status', 'ACTIVE'))->pluck('full_name', 'id')),
                TextInput::make('reason_code')->label(__('workflow_actions.fields.reason_code'))->required()->maxLength(64),
            ])
            ->action(fn (Action $action, Claim $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(ClaimLifecycleService::class)->assign($record, User::findOrFail($data['assignee_id']), $data['reason_code'], auth()->user())));
    }

    public static function assess(): Action
    {
        $p = 'claims.assessment.record';

        return WorkflowAction::make('claimAssess', $p)->icon('heroicon-o-clipboard-document-check')
            ->schema([
                Repeater::make('heads')->label(__('workflow_actions.fields.heads'))->required()->minItems(1)->maxItems(50)->defaultItems(1)->schema([
                    Select::make('head_code')->label(__('workflow_actions.fields.head'))->options(WorkflowAction::options(ClaimReferenceCodes::RESERVE_TYPES))->required(),
                    TextInput::make('claimed_minor')->label(__('workflow_actions.fields.claimed_minor'))->integer()->minValue(0),
                    TextInput::make('recommended_minor')->label(__('workflow_actions.fields.recommended_minor'))->integer()->minValue(0)->required(),
                    TextInput::make('note')->label(__('workflow_actions.fields.note'))->maxLength(1000),
                ])->columns(4),
                Textarea::make('rationale')->label(__('workflow_actions.fields.rationale'))->required()->minLength(10)->maxLength(10000),
            ])
            ->action(fn (Action $action, Claim $record, array $data) => WorkflowAction::run($action, $p, fn () => app(ClaimAssessmentService::class)->record($record, [
                'heads' => array_map(fn ($h) => ['head_code' => $h['head_code'], 'recommended_minor' => (int) $h['recommended_minor'],
                    'claimed_minor' => filled($h['claimed_minor'] ?? null) ? (int) $h['claimed_minor'] : null, 'note' => $h['note'] ?? null], array_values($data['heads'])),
                'rationale' => $data['rationale'],
            ], auth()->user())));
    }

    public static function reviewAssessment(): Action
    {
        $p = 'claims.assessment.review';

        return WorkflowAction::make('claimReviewAssessment', $p)->icon('heroicon-o-check-circle')
            ->visible(fn (Claim $record) => ClaimAssessment::where('claim_id', $record->id)->where('status', 'SUBMITTED')->exists())
            ->schema([
                Select::make('assessment_id')->label(__('workflow_actions.fields.assessment'))->required()
                    ->options(fn (Claim $record) => ClaimAssessment::where('claim_id', $record->id)->where('status', 'SUBMITTED')->get()->mapWithKeys(fn ($a) => [$a->id => ($a->reference ?? Str::limit($a->id, 8, '')).' · '.number_format((int) $a->recommended_total_minor)])),
                Select::make('outcome')->label(__('workflow_actions.fields.outcome'))->options(['ACCEPT' => __('workflow_actions.accept'), 'REJECT' => __('workflow_actions.reject')])->required()->live(),
                Textarea::make('note')->label(__('workflow_actions.fields.note'))->maxLength(2000)->required(fn (callable $get) => $get('outcome') === 'REJECT'),
            ])
            ->action(function (Action $action, Claim $record, array $data) use ($p) {
                $a = ClaimAssessment::where('claim_id', $record->id)->findOrFail($data['assessment_id']);
                $s = app(ClaimAssessmentService::class);

                return WorkflowAction::run($action, $p, fn () => $data['outcome'] === 'ACCEPT' ? $s->accept($a, auth()->user(), $data['note'] ?? null) : $s->reject($a, auth()->user(), (string) $data['note']));
            });
    }

    public static function reserve(): Action
    {
        $p = 'claims.reserve.request';

        return WorkflowAction::make('claimReserve', $p)->icon('heroicon-o-banknotes')
            ->schema([
                TextInput::make('amount_minor')->label(__('workflow_actions.fields.amount_minor'))->integer()->minValue(0)->required(),
                Select::make('reserve_head')->label(__('workflow_actions.fields.reserve_head'))->options(WorkflowAction::options(ClaimReserveService::HEADS)),
                Select::make('reserve_type')->label(__('workflow_actions.fields.reserve_type'))->options(WorkflowAction::options(ClaimReferenceCodes::RESERVE_TYPES)),
                Select::make('reason_code')->label(__('workflow_actions.fields.reason_code'))->options(WorkflowAction::options(ClaimReserveService::REASON_CODES))->required(),
                Toggle::make('final')->label(__('workflow_actions.fields.final_reserve')),
                Textarea::make('notes')->label(__('workflow_actions.fields.notes'))->maxLength(2000),
            ])
            ->action(fn (Action $action, Claim $record, array $data) => WorkflowAction::run($action, $p, fn () => app(ClaimReserveService::class)->request($record,
                array_filter(['amount_minor' => (int) $data['amount_minor'], 'reason_code' => $data['reason_code'], 'notes' => $data['notes'] ?? null,
                    'reserve_type' => $data['reserve_type'] ?? null, 'reserve_head' => $data['reserve_head'] ?? null, 'final' => ! empty($data['final'])], fn ($v) => $v !== null && $v !== false),
                auth()->user())));
    }

    public static function approveReserve(): Action
    {
        $p = 'claims.reserve.approve';

        return WorkflowAction::make('claimApproveReserve', $p)->icon('heroicon-o-check-badge')->color('success')
            ->visible(fn (Claim $record) => ClaimReserveChange::where(['claim_id' => $record->id, 'status' => 'PENDING_APPROVAL'])->exists())
            ->schema([
                Select::make('reserve_id')->label(__('workflow_actions.fields.pending_reserve'))->required()
                    ->options(fn (Claim $record) => ClaimReserveChange::where(['claim_id' => $record->id, 'status' => 'PENDING_APPROVAL'])->get()
                        ->mapWithKeys(fn ($r) => [$r->id => number_format((int) $r->requested_amount_minor).' '.$r->currency.' · '.$r->reason_code])),
            ])
            ->action(fn (Action $action, Claim $record, array $data) => WorkflowAction::run($action, $p, fn () => app(ClaimReserveService::class)->approve(
                ClaimReserveChange::where(['id' => $data['reserve_id'], 'claim_id' => $record->id])->firstOrFail(), auth()->user())));
    }

    public static function decide(): Action
    {
        $p = 'claims.decision.propose';

        return WorkflowAction::make('claimDecide', $p)->icon('heroicon-o-scale')
            ->schema([
                Select::make('decision')->label(__('workflow_actions.fields.decision'))->options(WorkflowAction::options(['APPROVE', 'PARTIAL', 'DECLINE'], 'decision'))->required()->live(),
                Select::make('reason_codes')->label(__('workflow_actions.fields.reason_codes'))->multiple()->required()->maxItems(10)
                    ->options(fn (callable $get) => DB::table('claim_decision_reason_codes')->where('status', 'ACTIVE')->whereIn('applies_to', [$get('decision') ?? 'ANY', 'ANY'])->orderBy('code')->pluck('label', 'code')->all()),
                Repeater::make('heads')->label(__('workflow_actions.fields.heads'))->maxItems(10)->defaultItems(0)->schema([
                    Select::make('head')->label(__('workflow_actions.fields.head'))->options(WorkflowAction::options(ClaimDecisionService::HEADS))->required(),
                    TextInput::make('amount_minor')->label(__('workflow_actions.fields.amount_minor'))->integer()->minValue(0)->required(),
                ])->columns(2),
                Textarea::make('rationale')->label(__('workflow_actions.fields.rationale'))->required()->minLength(20)->maxLength(5000),
            ])
            ->requiresConfirmation()
            ->action(fn (Action $action, Claim $record, array $data) => WorkflowAction::run($action, $p, fn () => app(ClaimDecisionService::class)->propose($record, [
                'decision' => $data['decision'], 'reason_codes' => array_values($data['reason_codes']), 'rationale' => $data['rationale'],
                'heads' => array_map(fn ($h) => ['head' => $h['head'], 'amount_minor' => (int) $h['amount_minor']], array_values($data['heads'] ?? [])),
            ], auth()->user())));
    }

    public static function approveDecision(): Action
    {
        $p = 'claims.decision.approve';
        $pending = fn (Claim $c) => ClaimDecision::where('claim_id', $c->id)->whereIn('status', ['PENDING_APPROVAL', 'REFERRED']);

        return WorkflowAction::make('claimApproveDecision', $p)->icon('heroicon-o-check-badge')->color('success')
            ->visible(fn (Claim $record) => $pending($record)->exists())
            ->schema([
                Select::make('decision_id')->label(__('workflow_actions.fields.pending_decision'))->required()
                    ->options(fn (Claim $record) => $pending($record)->get()->mapWithKeys(fn ($d) => [$d->id => $d->decision.' · '.number_format((int) $d->approved_amount_minor).' '.$d->currency.' · '.$d->status])),
                Select::make('outcome')->label(__('workflow_actions.fields.outcome'))->options(['APPROVE' => __('workflow_actions.accept'), 'RETURN' => __('workflow_actions.return_to_maker')])->required()->live(),
                Textarea::make('reason')->label(__('workflow_actions.fields.reason'))->minLength(5)->maxLength(2000)->required(fn (callable $get) => $get('outcome') === 'RETURN'),
            ])
            ->requiresConfirmation()
            ->action(function (Action $action, Claim $record, array $data) use ($p) {
                $d = ClaimDecision::where(['id' => $data['decision_id'], 'claim_id' => $record->id])->firstOrFail();
                $s = app(ClaimDecisionService::class);

                return WorkflowAction::run($action, $p, fn () => $data['outcome'] === 'APPROVE' ? $s->approve($d, auth()->user()) : $s->returnToMaker($d, (string) $data['reason'], auth()->user()));
            });
    }

    public static function settle(): Action
    {
        $p = 'claims.settlement.calculate';

        return WorkflowAction::make('claimSettle', $p)->icon('heroicon-o-calculator')
            ->schema([
                TextInput::make('covered_minor')->label(__('workflow_actions.fields.covered_minor'))->integer()->minValue(0)->required(),
                TextInput::make('excluded_minor')->label(__('workflow_actions.fields.excluded_minor'))->integer()->minValue(0),
                TextInput::make('deductible_minor')->label(__('workflow_actions.fields.deductible_minor'))->integer()->minValue(0),
                TextInput::make('coverage_code')->label(__('workflow_actions.fields.coverage_code'))->maxLength(64),
                Repeater::make('adjustments')->label(__('workflow_actions.fields.adjustments'))->maxItems(50)->defaultItems(0)->schema([
                    TextInput::make('code')->label(__('workflow_actions.fields.code'))->required()->maxLength(64),
                    TextInput::make('amount_minor')->label(__('workflow_actions.fields.amount_minor'))->integer()->required(),
                    TextInput::make('reason')->label(__('workflow_actions.fields.reason'))->maxLength(1000),
                ])->columns(3),
            ])
            ->action(fn (Action $action, Claim $record, array $data) => WorkflowAction::run($action, $p, fn () => app(ClaimSettlementService::class)->calculate($record, array_filter([
                'covered_minor' => (int) $data['covered_minor'],
                'excluded_minor' => filled($data['excluded_minor'] ?? null) ? (int) $data['excluded_minor'] : null,
                'deductible_minor' => filled($data['deductible_minor'] ?? null) ? (int) $data['deductible_minor'] : null,
                'coverage_code' => $data['coverage_code'] ?? null,
                'adjustments' => array_map(fn ($a) => ['code' => $a['code'], 'amount_minor' => (int) $a['amount_minor'], 'reason' => $a['reason'] ?? null], array_values($data['adjustments'] ?? [])) ?: null,
            ], fn ($v) => $v !== null), auth()->user())));
    }

    public static function offerSettlement(): Action
    {
        $p = 'claims.settlement.offer';
        $calculated = fn (Claim $c) => DB::table('claim_settlements')->where(['claim_id' => $c->id, 'status' => 'CALCULATED']);

        return WorkflowAction::make('claimOfferSettlement', $p)->icon('heroicon-o-paper-airplane')->requiresConfirmation()
            ->visible(fn (Claim $record) => $calculated($record)->exists())
            ->schema([
                Select::make('settlement_id')->label(__('workflow_actions.fields.settlement'))->required()
                    ->options(fn (Claim $record) => $calculated($record)->get()->mapWithKeys(fn ($s) => [$s->id => $s->reference.' · '.number_format((int) $s->amount_minor).' '.$s->currency])->all()),
            ])
            ->action(fn (Action $action, Claim $record, array $data) => WorkflowAction::run($action, $p, fn () => app(ClaimSettlementService::class)->offer(
                (string) $calculated($record)->where('id', $data['settlement_id'])->value('id') ?: abort(404), auth()->user())));
    }

    public static function close(): Action
    {
        $p = 'claims.close';

        return WorkflowAction::make('claimClose', $p)->icon('heroicon-o-lock-closed')->color('danger')->requiresConfirmation()
            ->visible(fn (Claim $record) => ! in_array($record->status, ['CLOSED', 'DRAFT'], true))
            ->schema([
                Select::make('reason_code')->label(__('workflow_actions.fields.closure_reason'))->required()
                    ->options(WorkflowAction::options(array_values(array_diff(ClaimClosureChecklist::REASONS, ['AUTO_INACTIVE_SETTLED'])), 'closure')),
                Textarea::make('summary')->label(__('workflow_actions.fields.summary'))->maxLength(5000),
            ])
            ->action(fn (Action $action, Claim $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(ClaimClosureService::class)->close($record, $data['reason_code'], $data['summary'] ?? null, auth()->user())));
    }

    public static function requestReopen(): Action
    {
        $p = 'claims.reopen.request';

        return WorkflowAction::make('claimRequestReopen', $p)->icon('heroicon-o-lock-open')
            ->visible(fn (Claim $record) => $record->status === 'CLOSED')
            ->schema([
                Select::make('reason_code')->label(__('workflow_actions.fields.reason_code'))->options(WorkflowAction::options(ClaimClosureService::REOPEN_REASONS, 'reopen'))->required(),
                Textarea::make('justification')->label(__('workflow_actions.fields.justification'))->required()->maxLength(5000),
                TextInput::make('restore_reserve_minor')->label(__('workflow_actions.fields.restore_reserve_minor'))->integer()->minValue(0)->default(0),
            ])
            ->action(fn (Action $action, Claim $record, array $data) => WorkflowAction::run($action, $p, fn () => app(ClaimClosureService::class)->requestReopen(
                $record, $data['reason_code'], $data['justification'], (int) ($data['restore_reserve_minor'] ?? 0), auth()->user())));
    }

    public static function decideReopen(): Action
    {
        $p = 'claims.reopen.approve';
        $pending = fn (Claim $c) => DB::table('claim_reopen_requests')->where(['claim_id' => $c->id, 'status' => 'PENDING_APPROVAL']);

        return WorkflowAction::make('claimDecideReopen', $p)->icon('heroicon-o-arrow-path')->requiresConfirmation()
            ->visible(fn (Claim $record) => $pending($record)->exists())
            ->schema([
                Select::make('request_id')->label(__('workflow_actions.fields.reopen_request'))->required()
                    ->options(fn (Claim $record) => $pending($record)->get()->mapWithKeys(fn ($r) => [$r->id => $r->reason_code.' · '.Str::limit((string) $r->justification, 60)])->all()),
                Select::make('outcome')->label(__('workflow_actions.fields.outcome'))->options(['APPROVE' => __('workflow_actions.accept'), 'REJECT' => __('workflow_actions.reject')])->required()->live(),
                Textarea::make('note')->label(__('workflow_actions.fields.note'))->maxLength(2000)->required(fn (callable $get) => $get('outcome') === 'REJECT'),
            ])
            ->action(function (Action $action, Claim $record, array $data) use ($p, $pending) {
                $id = (string) $pending($record)->where('id', $data['request_id'])->value('id');
                $s = app(ClaimClosureService::class);

                return WorkflowAction::run($action, $p, fn () => $data['outcome'] === 'APPROVE' ? $s->approveReopen($id, auth()->user(), $data['note'] ?? null) : $s->rejectReopen($id, auth()->user(), (string) $data['note']));
            });
    }

    private static function tenant(): string
    {
        return app(TenantContext::class)->id();
    }
}
