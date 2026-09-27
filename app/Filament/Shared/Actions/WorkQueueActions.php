<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\Cases\CaseService;
use App\Application\Cases\Models\WorkCase;
use App\Application\Cases\Models\WorkQueue;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;

/**
 * Work queue actions. Same as POST queues/{q}/next (cases.manage → CaseService::pullNext: active queue members only,
 * highest priority / earliest due first, SKIP LOCKED) and POST cases/{c}/assign (cases.assign → CaseService::assign).
 */
final class WorkQueueActions
{
    /** Queue record action: claim the next unassigned case from this queue. */
    public static function claimNext(): Action
    {
        $p = 'cases.manage';

        return WorkflowAction::make('queueClaimNext', $p)->icon('heroicon-o-hand-raised')->requiresConfirmation()
            ->visible(fn (WorkQueue $record) => (bool) $record->active)
            ->action(function (Action $action, WorkQueue $record) use ($p) {
                $case = WorkflowAction::run($action, $p, fn () => app(CaseService::class)->pullNext($record, auth()->user()) ?? false);
                if ($case === false) {
                    Notification::make()->info()->title(__('workflow_actions.queueClaimNext.empty'))->send();
                }

                return $case;
            });
    }

    /** Case record action: assign to a user and/or a queue. */
    public static function assign(): Action
    {
        $p = 'cases.assign';
        $tenant = fn () => app(TenantContext::class)->id();

        return WorkflowAction::make('caseAssign', $p)->icon('heroicon-o-user-plus')
            ->schema([
                Select::make('owner_user_id')->label(__('workflow_actions.fields.assignee'))->searchable()->requiredWithout('queue_id')
                    ->options(fn () => User::where('status', 'ACTIVE')->whereHas('memberships', fn ($q) => $q->where('tenant_id', $tenant())->where('status', 'ACTIVE'))->pluck('full_name', 'id')),
                Select::make('queue_id')->label(__('workflow_actions.fields.queue'))
                    ->options(fn () => WorkQueue::where('tenant_id', $tenant())->where('active', true)->pluck('name', 'id')),
                Textarea::make('reason')->label(__('workflow_actions.fields.reason'))->maxLength(500),
            ])
            ->action(fn (Action $action, WorkCase $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(CaseService::class)->assign($record, $data['owner_user_id'] ?? null, $data['queue_id'] ?? null, auth()->user(), $data['reason'] ?? null)));
    }
}
