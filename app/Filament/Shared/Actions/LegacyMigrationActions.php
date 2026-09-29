<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\Import\Legacy\LegacyMigrationPipeline;
use App\Domain\Tenancy\TenantContext;
use App\Models\Import\ImportBatch;
use App\Models\IntegrationClient;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;

/**
 * Legacy migration (batch 21, REQ-IMP-002). Same permission + service + validation as
 * App\Interfaces\Http\Controllers\Api\V1\Import\LegacyMigrationController (routes/api.php legacy-migrations):
 *   lmStage      POST legacy-migrations                 legacy_migration.manage   LegacyMigrationPipeline::stage
 *   lmMap        PUT  legacy-migrations/{b}/mapping     legacy_migration.manage   LegacyMigrationPipeline::map
 *   lmValidate   POST legacy-migrations/{b}/validate    legacy_migration.manage   LegacyMigrationPipeline::validate
 *   lmDryRun     POST legacy-migrations/{b}/dry-run     legacy_migration.manage   LegacyMigrationPipeline::dryRun
 *   lmReconcile  POST legacy-migrations/{b}/reconcile   legacy_migration.manage   LegacyMigrationPipeline::reconcile
 *   lmSubmit     POST legacy-migrations/{b}/submit      legacy_migration.manage   LegacyMigrationPipeline::submit
 *   lmRollback   POST legacy-migrations/{b}/rollback    legacy_migration.manage   LegacyMigrationPipeline::rollback
 *   lmApprove    POST legacy-migrations/{b}/approve     legacy_migration.approve  LegacyMigrationPipeline::approve
 *   lmReject     POST legacy-migrations/{b}/reject      legacy_migration.approve  LegacyMigrationPipeline::reject
 *   lmCommit     POST legacy-migrations/{b}/commit      legacy_migration.commit   LegacyMigrationPipeline::commit
 * Approval is maker-checker through ApprovalService (the maker cannot approve their own batch); refusals are shown.
 */
final class LegacyMigrationActions
{
    private const LANG = 'masterdata_actions';

    private const VALIDATED = ['VALIDATED', 'DRY_RUN_OK', 'DRY_RUN_FAILED', 'RECONCILED', 'UNRECONCILED'];

    public static function stage(): Action
    {
        $p = 'legacy_migration.manage';

        return WorkflowAction::make('lmStage', $p, self::LANG)->icon('lucide-upload')
            ->schema([
                Select::make('entity')->label(self::f('entity'))->required()->options(fn () => app(LegacyMigrationPipeline::class)->options()),
                Select::make('integration_client_id')->label(self::f('integration_client'))->required()->searchable()
                    ->options(fn () => IntegrationClient::where('status', 'ACTIVE')->orderBy('name')->limit(200)->pluck('name', 'id')->all()),
                FileUpload::make('file')->label(self::f('file'))->required()->storeFiles(false)->maxSize(20480)
                    ->acceptedFileTypes(['text/csv', 'text/plain', 'application/json', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']),
                KeyValue::make('mapping')->label(self::f('mapping'))->keyLabel(self::f('mapping_field'))->valueLabel(self::f('mapping_column')),
                TextInput::make('control_count')->label(self::f('control_count'))->integer()->minValue(0),
                TextInput::make('control_amount_minor')->label(self::f('control_amount_minor'))->integer(),
            ])
            ->action(function (Action $action, array $data) use ($p) {
                return WorkflowAction::run($action, $p, function () use ($data) {
                    $file = is_array($data['file']) ? reset($data['file']) : $data['file'];
                    abort_unless($file instanceof TemporaryUploadedFile, 422, __('masterdata_actions.lmStage.no_file'));
                    $totals = array_filter(['count' => $data['control_count'] ?? null, 'amount_minor' => $data['control_amount_minor'] ?? null], fn ($v) => $v !== null && $v !== '');
                    IntegrationClient::findOrFail($data['integration_client_id']);
                    // The upload may sit on a non-local disk: hand the pipeline a local copy (it hashes and reads a path).
                    $path = tempnam(sys_get_temp_dir(), 'legacy');
                    file_put_contents($path, $file->get());                    try {
                        return app(LegacyMigrationPipeline::class)->stage($data['entity'], $data['integration_client_id'], $path, $file->getClientOriginalName(),
                            auth()->user(), app(TenantContext::class)->id(), $data['mapping'] ?? [], $totals === [] ? null : $totals);
                    } finally {
                        @unlink($path);
                    }
                }, __('masterdata_actions.lmStage.done'));
            });
    }

    public static function map(): Action
    {
        $p = 'legacy_migration.manage';

        return WorkflowAction::make('lmMap', $p, self::LANG)->icon('lucide-columns-3')
            ->visible(fn (ImportBatch $record) => in_array($record->status, LegacyMigrationPipeline::OPEN, true))
            ->fillForm(fn (ImportBatch $record) => ['mapping' => $record->mapping ?? []])
            ->schema([KeyValue::make('mapping')->label(self::f('mapping'))->required()->keyLabel(self::f('mapping_field'))->valueLabel(self::f('mapping_column'))])
            ->action(fn (Action $action, ImportBatch $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(LegacyMigrationPipeline::class)->map(self::batch($record), (array) $data['mapping']), __('masterdata_actions.lmMap.done')));
    }

    public static function validate(): Action
    {
        $p = 'legacy_migration.manage';

        return WorkflowAction::make('lmValidate', $p, self::LANG)->icon('lucide-list-checks')->requiresConfirmation()
            ->visible(fn (ImportBatch $record) => in_array($record->status, LegacyMigrationPipeline::OPEN, true))
            ->action(fn (Action $action, ImportBatch $record) => WorkflowAction::run($action, $p,
                fn () => app(LegacyMigrationPipeline::class)->validate(self::batch($record)), __('masterdata_actions.lmValidate.done')));
    }

    public static function dryRun(): Action
    {
        $p = 'legacy_migration.manage';

        return WorkflowAction::make('lmDryRun', $p, self::LANG)->icon('lucide-flask-conical')->requiresConfirmation()
            ->visible(fn (ImportBatch $record) => in_array($record->status, self::VALIDATED, true))
            ->action(fn (Action $action, ImportBatch $record) => WorkflowAction::run($action, $p,
                fn () => app(LegacyMigrationPipeline::class)->dryRun(self::batch($record), auth()->user()), __('masterdata_actions.lmDryRun.done')));
    }

    public static function reconcile(): Action
    {
        $p = 'legacy_migration.manage';

        return WorkflowAction::make('lmReconcile', $p, self::LANG)->icon('lucide-scale')->requiresConfirmation()
            ->visible(fn (ImportBatch $record) => in_array($record->status, ['DRY_RUN_OK', 'RECONCILED', 'UNRECONCILED', 'COMMITTED'], true))
            ->action(fn (Action $action, ImportBatch $record) => WorkflowAction::run($action, $p,
                fn () => app(LegacyMigrationPipeline::class)->reconcile(self::batch($record)), __('masterdata_actions.lmReconcile.done')));
    }

    public static function submit(): Action
    {
        $p = 'legacy_migration.manage';

        return WorkflowAction::make('lmSubmit', $p, self::LANG)->icon('lucide-send')
            ->visible(fn (ImportBatch $record) => $record->status === 'RECONCILED')
            ->schema([Textarea::make('reason')->label(self::f('reason'))->maxLength(500)])
            ->action(fn (Action $action, ImportBatch $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(LegacyMigrationPipeline::class)->submit(self::batch($record), auth()->user(), filled($data['reason'] ?? null) ? $data['reason'] : null), __('masterdata_actions.lmSubmit.done')));
    }

    public static function approve(): Action
    {
        $p = 'legacy_migration.approve';

        return WorkflowAction::make('lmApprove', $p, self::LANG)->icon('lucide-check-check')->color('success')
            ->visible(fn (ImportBatch $record) => $record->status === 'PENDING_APPROVAL')
            ->schema([Textarea::make('note')->label(self::f('note'))->maxLength(500)])
            ->action(fn (Action $action, ImportBatch $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(LegacyMigrationPipeline::class)->approve(self::batch($record), auth()->user(), filled($data['note'] ?? null) ? $data['note'] : null), __('masterdata_actions.lmApprove.done')));
    }

    public static function reject(): Action
    {
        $p = 'legacy_migration.approve';

        return WorkflowAction::make('lmReject', $p, self::LANG)->icon('lucide-x-circle')->color('danger')
            ->visible(fn (ImportBatch $record) => $record->status === 'PENDING_APPROVAL')
            ->schema([Textarea::make('note')->label(self::f('note'))->required()->minLength(3)->maxLength(500)])
            ->action(fn (Action $action, ImportBatch $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(LegacyMigrationPipeline::class)->reject(self::batch($record), auth()->user(), $data['note']), __('masterdata_actions.lmReject.done')));
    }

    public static function commit(): Action
    {
        $p = 'legacy_migration.commit';

        return WorkflowAction::make('lmCommit', $p, self::LANG)->icon('lucide-database')->color('success')->requiresConfirmation()
            ->visible(fn (ImportBatch $record) => $record->status === 'APPROVED')
            ->action(fn (Action $action, ImportBatch $record) => WorkflowAction::run($action, $p,
                fn () => app(LegacyMigrationPipeline::class)->commit(self::batch($record), auth()->user()), __('masterdata_actions.lmCommit.done')));
    }

    public static function rollback(): Action
    {
        $p = 'legacy_migration.manage';

        return WorkflowAction::make('lmRollback', $p, self::LANG)->icon('lucide-undo-2')->color('danger')
            ->visible(fn (ImportBatch $record) => ! in_array($record->status, LegacyMigrationPipeline::FINAL, true))
            ->schema([Textarea::make('reason')->label(self::f('reason'))->required()->minLength(3)->maxLength(500)])
            ->action(fn (Action $action, ImportBatch $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(LegacyMigrationPipeline::class)->rollback(self::batch($record), auth()->user(), $data['reason']), __('masterdata_actions.lmRollback.done')));
    }

    /** Controller::find — the tenant's LEGACY batch only. */
    private static function batch(ImportBatch $record): ImportBatch
    {
        return ImportBatch::where(['tenant_id' => app(TenantContext::class)->id(), 'pipeline' => LegacyMigrationPipeline::PIPELINE])->whereKey($record->id)->firstOrFail();
    }

    private static function f(string $k): string
    {
        return __("masterdata_actions.fields.{$k}");
    }
}
