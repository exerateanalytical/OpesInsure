<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\Identity\CarrierScopeResolver;
use App\Application\Underwriting\UnderwritingCaseMachine;
use App\Application\Underwriting\UnderwritingDecisionService;
use App\Application\Underwriting\UnderwritingService;
use App\Domain\Tenancy\TenantContext;
use App\Models\UnderwritingCase;
use App\Models\UnderwritingReferralTask;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\DB;

/**
 * Underwriter workspace actions on the underwriting case detail page (ViewUnderwritingCase). Same permission, validation
 * and service as the API (routes/api.php):
 *   uwAssign            POST underwriting/cases/{c}/assign               underwriting.assign  UnderwritingService::assign
 *   uwStartReview       POST underwriting/cases/{c}/start-review         underwriting.decide  UnderwritingService::startReview
 *   uwEvaluate          POST underwriting/cases/{c}/evaluate             underwriting.decide  UnderwritingDecisionService::evaluate
 *   uwReadyForDecision  POST underwriting/cases/{c}/ready-for-decision   underwriting.decide  UnderwritingService::readyForDecision
 *   uwResolveReferral   POST underwriting/referrals/{r}/resolve          underwriting.decide  UnderwritingService::resolveReferral
 * Cases are re-read tenant- and carrier-scoped (CarrierScopeResolver, as UnderwritingWorkspaceController). The decision
 * itself stays with ProposalActions::decide (a human records the outcome; the service still refuses open referrals).
 */
final class UnderwritingCaseActions
{
    private const LANG = 'doc_uw_actions';

    public static function group(): ActionGroup
    {
        return ActionGroup::make([self::assign(), self::startReview(), self::evaluate(), self::readyForDecision(), self::resolveReferral(), self::decide(), self::counterOffer()])
            ->label(__('doc_uw_actions.uw_group'))->icon('lucide-zap')->button()
            // D4 lifted 2026-09-29: shown in /insurer too; each action is gated by its API permission + own carrier's case
            // (WorkflowAction -> PortalScope::isOwnRecord, docs/spec/PORTAL_WRITE_RULES.md).
            ;
    }

    /** POST underwriting/cases/{c}/decision  underwriting.decide  UnderwritingService::decide (refuses open referrals / closed cases). */
    public static function decide(): Action
    {
        $p = 'underwriting.decide';

        return WorkflowAction::make('uwDecide', $p, 'insurer_portal_uw')->icon('lucide-scale')->requiresConfirmation()
            ->visible(fn (UnderwritingCase $record) => in_array($record->status, UnderwritingCaseMachine::TRANSITIONS['decide'], true))
            ->schema(ProposalActions::decisionSchema())
            ->action(fn (Action $action, UnderwritingCase $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(UnderwritingService::class)->decide(self::case($record), ProposalActions::decisionPayload($data), auth()->user()),
                __('insurer_portal_uw.uwDecide.done')));
    }

    /** Counter-offer: the same decision route with COUNTEROFFERED (as ProposalActions::counterOffer). */
    public static function counterOffer(): Action
    {
        $p = 'underwriting.decide';

        return WorkflowAction::make('uwCounterOffer', $p, 'insurer_portal_uw')->icon('lucide-arrow-left-right')->requiresConfirmation()
            ->visible(fn (UnderwritingCase $record) => in_array($record->status, UnderwritingCaseMachine::TRANSITIONS['decide'], true))
            ->schema([
                \Filament\Forms\Components\TextInput::make('reason_code')->label(__('workflow_actions.fields.reason_code'))->required()->maxLength(64),
                Textarea::make('notes')->label(__('workflow_actions.fields.counter_terms'))->required()->minLength(20)->maxLength(4000),
                \Filament\Forms\Components\KeyValue::make('conditions')->label(__('workflow_actions.fields.conditions')),
            ])
            ->action(fn (Action $action, UnderwritingCase $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(UnderwritingService::class)->decide(self::case($record), ProposalActions::decisionPayload(['decision' => 'COUNTEROFFERED'] + $data), auth()->user()),
                __('insurer_portal_uw.uwCounterOffer.done')));
    }

    public static function assign(): Action
    {
        $p = 'underwriting.assign';

        return WorkflowAction::make('uwAssign', $p, self::LANG)->icon('lucide-user-check')
            ->visible(fn (UnderwritingCase $record) => in_array($record->status, UnderwritingCaseMachine::TRANSITIONS['assign'], true))
            ->schema([
                Select::make('assignee_id')->label(__('doc_uw_actions.fields.assignee'))->required()->searchable()
                    ->options(fn () => self::members()),
            ])
            ->action(fn (Action $action, UnderwritingCase $record, array $data) => WorkflowAction::run($action, $p, function () use ($record, $data) {
                $assignee = User::whereKey($data['assignee_id'])->whereIn('id', array_keys(self::members()))->firstOrFail();

                return app(UnderwritingService::class)->assign(self::case($record), $assignee, auth()->user());
            }, __('doc_uw_actions.uwAssign.done')));
    }

    public static function startReview(): Action
    {
        $p = 'underwriting.decide';

        return WorkflowAction::make('uwStartReview', $p, self::LANG)->icon('lucide-play')->requiresConfirmation()
            ->visible(fn (UnderwritingCase $record) => in_array($record->status, UnderwritingCaseMachine::TRANSITIONS['start_review'], true))
            ->action(fn (Action $action, UnderwritingCase $record) => WorkflowAction::run($action, $p,
                fn () => app(UnderwritingService::class)->startReview(self::case($record), auth()->user()), __('doc_uw_actions.uwStartReview.done')));
    }

    public static function evaluate(): Action
    {
        $p = 'underwriting.decide';

        return WorkflowAction::make('uwEvaluate', $p, self::LANG)->icon('lucide-calculator')->requiresConfirmation()
            ->visible(fn (UnderwritingCase $record) => in_array($record->status, UnderwritingCaseMachine::TRANSITIONS['evaluate'], true))
            ->action(function (Action $action, UnderwritingCase $record) use ($p) {
                $summary = WorkflowAction::run($action, $p,
                    fn () => app(UnderwritingDecisionService::class)->evaluate(self::case($record), auth()->user()), __('doc_uw_actions.uwEvaluate.done'));
                if (is_array($summary)) {
                    Notification::make()->info()->title(__('doc_uw_actions.uwEvaluate.result', [
                        'recommendation' => $summary['recommendation'] ?? '-', 'score' => $summary['risk_score'] ?? '-', 'band' => $summary['risk_band'] ?? '-',
                    ]))->body(implode("\n", array_map('strval', (array) ($summary['reasons'] ?? []))))->send();
                }
            });
    }

    public static function readyForDecision(): Action
    {
        $p = 'underwriting.decide';

        return WorkflowAction::make('uwReadyForDecision', $p, self::LANG)->icon('lucide-flag')
            ->visible(fn (UnderwritingCase $record) => in_array($record->status, UnderwritingCaseMachine::TRANSITIONS['ready_for_decision'], true))
            ->schema([Textarea::make('note')->label(__('doc_uw_actions.fields.note'))->maxLength(4000)])
            ->action(fn (Action $action, UnderwritingCase $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(UnderwritingService::class)->readyForDecision(self::case($record), auth()->user(), filled($data['note'] ?? null) ? $data['note'] : null),
                __('doc_uw_actions.uwReadyForDecision.done')));
    }

    public static function resolveReferral(): Action
    {
        $p = 'underwriting.decide';

        return WorkflowAction::make('uwResolveReferral', $p, self::LANG)->icon('lucide-check-check')
            ->visible(fn (UnderwritingCase $record) => $record->referrals()->where('status', 'OPEN')->exists())
            ->schema(fn (UnderwritingCase $record) => [
                Select::make('referral_id')->label(__('doc_uw_actions.fields.referral'))->required()
                    ->options(fn () => $record->referrals()->where('status', 'OPEN')->get()->mapWithKeys(fn (UnderwritingReferralTask $t) => [$t->id => trim($t->reason_code.' '.($t->severity ? "({$t->severity})" : ''))])->all()),
                Textarea::make('notes')->label(__('doc_uw_actions.fields.resolution_notes'))->required()->minLength(20)->maxLength(4000),
            ])
            ->action(fn (Action $action, UnderwritingCase $record, array $data) => WorkflowAction::run($action, $p, function () use ($record, $data) {
                $task = UnderwritingReferralTask::where('underwriting_case_id', self::case($record)->id)->findOrFail($data['referral_id']);

                return app(UnderwritingService::class)->resolveReferral($task, $data['notes'], auth()->user());
            }, __('doc_uw_actions.uwResolveReferral.done')));
    }

    /** Tenant- and carrier-scoped re-read (a case outside the caller's carrier is a 404). */
    private static function case(UnderwritingCase $record): UnderwritingCase
    {
        $tenant = app(TenantContext::class)->id();
        $carrier = app(CarrierScopeResolver::class)->carrierIdFor(auth()->user(), $tenant);

        return UnderwritingCase::query()->where('tenant_id', $tenant)->when($carrier !== null, fn ($q) => $q->where('carrier_id', $carrier))->findOrFail($record->id);
    }

    /** @return array<string, string> active staff members of the current tenant */
    private static function members(): array
    {
        return DB::table('tenant_memberships')->join('users', 'users.id', '=', 'tenant_memberships.user_id')
            ->where('tenant_memberships.tenant_id', app(TenantContext::class)->id())->where('tenant_memberships.status', 'ACTIVE')
            ->where('tenant_memberships.role_code', '<>', 'CUSTOMER')->orderBy('users.full_name')->pluck('users.full_name', 'users.id')->all();
    }
}
