<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\Approvals\ApprovalService;
use App\Models\ApprovalRequest;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;

/**
 * Approval request decisions (WF-081 / REQ-RBAC-005). Same as POST approvals/{a}/approve|reject (approvals.decide) and
 * POST approvals/{a}/cancel (approvals.inbox.view) → ApprovalService (maker-checker, SoD, matrix, audit).
 * Self-approval is shown disabled with an explanation; ApprovalService still refuses it server-side.
 */
final class ApprovalActions
{
    /** @return list<Action> */
    public static function all(): array
    {
        return [self::approve(), self::reject(), self::withdraw()];
    }

    public static function approve(): Action
    {
        $p = 'approvals.decide';

        return self::decision(WorkflowAction::make('approvalApprove', $p)->icon('heroicon-o-check')->color('success')->requiresConfirmation()
            ->schema([Textarea::make('note')->label(__('workflow_actions.fields.note'))->maxLength(2000)])
            ->action(fn (Action $action, ApprovalRequest $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(ApprovalService::class)->approve($record, auth()->user(), $data['note'] ?? null))));
    }

    public static function reject(): Action
    {
        $p = 'approvals.decide';

        return self::decision(WorkflowAction::make('approvalReject', $p)->icon('heroicon-o-x-mark')->color('danger')
            ->schema([Textarea::make('note')->label(__('workflow_actions.fields.reason'))->required()->maxLength(2000)])
            ->action(fn (Action $action, ApprovalRequest $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(ApprovalService::class)->reject($record, auth()->user(), $data['note']))));
    }

    public static function withdraw(): Action
    {
        $p = 'approvals.inbox.view';

        return WorkflowAction::make('approvalWithdraw', $p)->icon('heroicon-o-arrow-uturn-left')->color('gray')->requiresConfirmation()
            ->visible(fn (ApprovalRequest $record) => $record->status === 'PENDING' && $record->requested_by === auth()->id())
            ->schema([Textarea::make('reason')->label(__('workflow_actions.fields.reason'))->required()->maxLength(2000)])
            ->action(fn (Action $action, ApprovalRequest $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(ApprovalService::class)->cancel($record, auth()->user(), $data['reason'])));
    }

    private static function decision(Action $action): Action
    {
        $svc = fn (): ApprovalService => app(ApprovalService::class);

        return $action
            ->visible(fn (ApprovalRequest $record) => $record->status === 'PENDING' && ($record->requested_by === auth()->id() || $svc()->canDecide($record, auth()->user())))
            ->disabled(fn (ApprovalRequest $record) => $record->requested_by === auth()->id())
            ->tooltip(fn (ApprovalRequest $record) => $record->requested_by === auth()->id() ? __('web_experience.approval.self_approval_disabled') : null);
    }
}
