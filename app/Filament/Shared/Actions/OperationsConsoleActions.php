<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\Cases\Models\WorkQueue;
use App\Application\Operations\QueueConsoleService;
use App\Application\OperationsTaxonomy\OperationsCatalogue;
use App\Application\OperationsTaxonomy\OperationsLabels;
use App\Application\OperationsTaxonomy\OperationsTaxonomyService;
use App\Domain\Tenancy\TenantContext;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;

/**
 * Operations console actions (UI batch 24). Same permission + service + validation as the API:
 *   failedJobRetry   POST operations/failed-jobs/{uuid}/retry     operations.jobs.manage   QueueConsoleService::retry
 *   failedJobForget  POST operations/failed-jobs/{uuid}/forget    operations.jobs.manage   QueueConsoleService::forget
 *   queueSetType     PUT  operations/queues/{queue}/queue-type    operations.taxonomy.manage  OperationsTaxonomyService::setQueueType
 *   templateApprove  POST operations/notification-templates/{t}/approve  operations.notification_templates.approve
 *                                                                  OperationsTaxonomyService::approvePlatformTemplate (author cannot approve)
 */
final class OperationsConsoleActions
{
    private const L = 'operations_actions';

    public static function failedJobRetry(): Action
    {
        $p = 'operations.jobs.manage';

        return WorkflowAction::make('failedJobRetry', $p, self::L)->icon('lucide-rotate-cw')->requiresConfirmation()
            ->action(fn (Action $action, mixed $record) => WorkflowAction::run($action, $p,
                fn () => app(QueueConsoleService::class)->retry(WorkflowAction::id($record)), __(self::L.'.failedJobRetry.done')));
    }

    public static function failedJobForget(): Action
    {
        $p = 'operations.jobs.manage';

        return WorkflowAction::make('failedJobForget', $p, self::L)->icon('lucide-trash')->color('danger')
            ->schema([Textarea::make('reason')->label(__(self::L.'.fields.reason'))->required()->minLength(5)->maxLength(1000)])
            ->action(fn (Action $action, mixed $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(QueueConsoleService::class)->forget(WorkflowAction::id($record), $data['reason']), __(self::L.'.failedJobForget.done')));
    }

    public static function queueSetType(): Action
    {
        $p = 'operations.taxonomy.manage';

        return WorkflowAction::make('queueSetType', $p, self::L)->icon('lucide-tag')
            ->fillForm(fn (WorkQueue $record) => ['queue_type' => $record->queue_type])
            ->schema([Select::make('queue_type')->label(__(self::L.'.fields.queue_type'))->required()
                ->options(fn () => collect(OperationsCatalogue::list('queue_types'))->mapWithKeys(fn ($c) => [$c => app()->getLocale() === 'fr' ? OperationsLabels::fr($c) : OperationsLabels::en($c)])->all())])
            ->action(fn (Action $action, WorkQueue $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(OperationsTaxonomyService::class)->setQueueType(app(TenantContext::class)->id(), $record->id, $data['queue_type']), __(self::L.'.queueSetType.done')));
    }

    public static function templateApprove(): Action
    {
        $p = 'operations.notification_templates.approve';

        return WorkflowAction::make('templateApprove', $p, self::L)->icon('lucide-check')->requiresConfirmation()
            ->visible(fn (mixed $record) => ($record['status'] ?? null) === 'DRAFT' && ($record['tenant_id'] ?? null) === null)
            ->action(fn (Action $action, mixed $record) => WorkflowAction::run($action, $p,
                fn () => app(OperationsTaxonomyService::class)->approvePlatformTemplate(WorkflowAction::id($record), auth()->user()), __(self::L.'.templateApprove.done')));
    }
}
