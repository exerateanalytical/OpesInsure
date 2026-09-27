<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\Policies\Cancellation\CancellationService;
use App\Application\Policies\Cancellation\PolicyCancellation;
use App\Application\Policies\PolicyServicingService;
use App\Models\Policy;
use App\Models\PolicyTransaction;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\HtmlString;

/**
 * Policy endorsement and cancellation actions (mount on the policy detail page). Same services / permissions as:
 *   endorse             POST policies/{p}/transactions (type ENDORSEMENT)      (route has no permission gate)  PolicyServicingService::request
 *   decideService       POST policies/{p}/transactions/{t}/approve|reject      policies.service.approve        PolicyServicingService::approve|reject
 *   requestCancellation POST policies/{p}/cancellations (+ /preview)           policies.cancellation.request   CancellationService::quote|request
 *   reviewCancellation  POST policy-cancellations/{c}/review                   policies.cancellation.review    CancellationService::review
 *   decideCancellation  POST policy-cancellations/{c}/approve|reject           policies.cancellation.approve   CancellationService::approve|reject
 */
final class PolicyActions
{
    public static function group(): ActionGroup
    {
        return ActionGroup::make([self::endorse(), self::decideService(), self::requestCancellation(), self::reviewCancellation(), self::decideCancellation()])
            ->label(__('workflow_actions.policy_group'))->icon('heroicon-o-bolt')->button();
    }

    public static function endorse(): Action
    {
        return WorkflowAction::make('policyEndorse', null)->icon('heroicon-o-pencil-square')
            ->visible(fn (Policy $record) => $record->status === 'ACTIVE')
            ->schema([
                TextInput::make('endorsement_type')->label(__('workflow_actions.fields.endorsement_type'))->maxLength(32),
                DateTimePicker::make('effective_at')->label(__('workflow_actions.fields.effective_at'))->required(),
                KeyValue::make('requested_changes')->label(__('workflow_actions.fields.requested_changes')),
                TextInput::make('premium_delta_minor')->label(__('workflow_actions.fields.premium_delta_minor'))->integer()->required()->default(0),
                TextInput::make('reason_code')->label(__('workflow_actions.fields.reason_code'))->required()->maxLength(64),
                Textarea::make('notes')->label(__('workflow_actions.fields.notes'))->maxLength(2000),
            ])
            ->requiresConfirmation()
            ->action(fn (Action $action, Policy $record, array $data) => WorkflowAction::run($action, null, fn () => app(PolicyServicingService::class)->request($record, array_filter([
                'type' => 'ENDORSEMENT', 'effective_at' => $data['effective_at'], 'requested_changes' => $data['requested_changes'] ?? [],
                'premium_delta_minor' => (int) $data['premium_delta_minor'], 'reason_code' => $data['reason_code'], 'notes' => $data['notes'] ?? null,
                'endorsement_type' => filled($data['endorsement_type'] ?? null) ? $data['endorsement_type'] : null,
            ], fn ($v) => $v !== null), auth()->user())));
    }

    public static function decideService(): Action
    {
        $p = 'policies.service.approve';
        $pending = fn (Policy $r) => PolicyTransaction::where('policy_id', $r->id)->where('status', 'PENDING_APPROVAL');

        return WorkflowAction::make('policyDecideService', $p)->icon('heroicon-o-check-badge')->requiresConfirmation()
            ->visible(fn (Policy $record) => $pending($record)->exists())
            ->schema([
                Select::make('transaction_id')->label(__('workflow_actions.fields.pending_transaction'))->required()
                    ->options(fn (Policy $record) => $pending($record)->get()->mapWithKeys(fn ($t) => [$t->id => $t->type.' · '.$t->reason_code.' · '.number_format((int) $t->premium_delta_minor)])),
                Select::make('outcome')->label(__('workflow_actions.fields.outcome'))->options(['APPROVE' => __('workflow_actions.accept'), 'REJECT' => __('workflow_actions.reject')])->required()->live(),
                Textarea::make('reason')->label(__('workflow_actions.fields.reason'))->minLength(20)->maxLength(2000)->required(fn (callable $get) => $get('outcome') === 'REJECT'),
            ])
            ->action(function (Action $action, Policy $record, array $data) use ($p) {
                $t = PolicyTransaction::where(['id' => $data['transaction_id'], 'policy_id' => $record->id])->firstOrFail();
                $s = app(PolicyServicingService::class);

                return WorkflowAction::run($action, $p, fn () => $data['outcome'] === 'APPROVE' ? $s->approve($t, auth()->user()) : $s->reject($t, (string) $data['reason'], auth()->user()));
            });
    }

    public static function requestCancellation(): Action
    {
        $p = 'policies.cancellation.request';

        return WorkflowAction::make('policyRequestCancellation', $p)->icon('heroicon-o-x-circle')->color('danger')
            ->visible(fn (Policy $record) => $record->status === 'ACTIVE')
            ->schema([
                DateTimePicker::make('effective_at')->label(__('workflow_actions.fields.effective_at'))->required()->live(),
                Select::make('initiated_by')->label(__('workflow_actions.fields.initiated_by'))->options(WorkflowAction::options(CancellationService::INITIATORS, 'initiator'))->required()->live(),
                Placeholder::make('preview')->label(__('workflow_actions.fields.refund_preview'))
                    ->content(function (Policy $record, callable $get) {
                        if (! $get('effective_at') || ! $get('initiated_by')) {
                            return '—';
                        }
                        $q = rescue(fn () => app(CancellationService::class)->quote($record, (string) $get('effective_at'), (string) $get('initiated_by')), null, false);

                        return $q === null ? '—' : new HtmlString(collect($q)->filter(fn ($v) => is_scalar($v))->map(fn ($v, $k) => e($k).': <b>'.e((string) $v).'</b>')->implode('<br>'));
                    }),
                TextInput::make('reason_code')->label(__('workflow_actions.fields.reason_code'))->required()->maxLength(64),
                Textarea::make('notes')->label(__('workflow_actions.fields.notes'))->maxLength(2000),
            ])
            ->requiresConfirmation()
            ->action(fn (Action $action, Policy $record, array $data) => WorkflowAction::run($action, $p, fn () => app(CancellationService::class)->request($record, [
                'effective_at' => $data['effective_at'], 'reason_code' => $data['reason_code'], 'initiated_by' => $data['initiated_by'], 'notes' => $data['notes'] ?? null,
            ], auth()->user())));
    }

    public static function reviewCancellation(): Action
    {
        $p = 'policies.cancellation.review';

        return WorkflowAction::make('policyReviewCancellation', $p)->icon('heroicon-o-eye')
            ->visible(fn (Policy $record) => self::cancellation($record, ['REQUESTED']) !== null)
            ->schema([Textarea::make('note')->label(__('workflow_actions.fields.note'))->maxLength(2000)])
            ->action(fn (Action $action, Policy $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(CancellationService::class)->review(self::cancellation($record, ['REQUESTED']) ?? abort(404), auth()->user(), $data['note'] ?? null)));
    }

    public static function decideCancellation(): Action
    {
        $p = 'policies.cancellation.approve';
        $open = ['REQUESTED', 'UNDER_REVIEW'];

        return WorkflowAction::make('policyDecideCancellation', $p)->icon('heroicon-o-check-badge')->requiresConfirmation()
            ->visible(fn (Policy $record) => self::cancellation($record, $open) !== null)
            ->schema([
                Select::make('outcome')->label(__('workflow_actions.fields.outcome'))->options(['APPROVE' => __('workflow_actions.accept'), 'REJECT' => __('workflow_actions.reject')])->required()->live(),
                Textarea::make('note')->label(__('workflow_actions.fields.note'))->maxLength(2000)->required(fn (callable $get) => $get('outcome') === 'REJECT'),
            ])
            ->action(function (Action $action, Policy $record, array $data) use ($p, $open) {
                $case = self::cancellation($record, $open) ?? abort(404);
                $s = app(CancellationService::class);

                return WorkflowAction::run($action, $p, fn () => $data['outcome'] === 'APPROVE' ? $s->approve($case, auth()->user(), $data['note'] ?? null) : $s->reject($case, auth()->user(), (string) $data['note']));
            });
    }

    /** @param  list<string>  $statuses */
    private static function cancellation(Policy $policy, array $statuses): ?PolicyCancellation
    {
        return PolicyCancellation::where('policy_id', $policy->id)->where('tenant_id', $policy->tenant_id)->whereIn('status', $statuses)->latest('created_at')->first();
    }
}
