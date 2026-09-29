<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\Collections\CollectionService;
use App\Filament\Shared\Actions\RegulatoryCrmSupport as S;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Facades\DB;

/**
 * Collections worklist (REQ-REC-003). Rows are keyed by financial obligation id, as the API paths. Same service,
 * validation and permission as CollectionController; write-off stays maker-checker inside CollectionService.
 *   collectionsRun       POST collections/run                                   collections.manage             CollectionService::run
 *   collectionEscalate   POST collections/{o}/escalate                          collections.manage             CollectionService::escalate
 *   collectionPromise    POST collections/{o}/promises                          collections.manage             CollectionService::promise
 *   writeOffRequest      POST collections/{o}/write-off-requests                collections.manage             CollectionService::requestWriteOff
 *   writeOffApprove      POST collections/write-off-requests/{r}/approve        collections.write_off.approve  CollectionService::approveWriteOff
 *   writeOffReject       POST collections/write-off-requests/{r}/reject         collections.write_off.approve  CollectionService::rejectWriteOff
 */
final class CollectionActions
{
    public static function collectionsRun(): Action
    {
        $p = 'collections.manage';

        return WorkflowAction::make('collectionsRun', $p, S::L)->icon('lucide-refresh-cw')->requiresConfirmation()
            ->action(function (Action $action) use ($p) {
                WorkflowAction::run($action, $p, fn () => app(CollectionService::class)->run(S::tenant()), S::done('collectionsRun'));
            });
    }

    public static function collectionEscalate(): Action
    {
        $p = 'collections.manage';

        return WorkflowAction::make('collectionEscalate', $p, S::L)->icon('lucide-siren')->color('warning')
            ->visible(fn (mixed $record) => S::field($record, 'case_id') === null)
            ->schema([Textarea::make('reason')->label(S::f('reason'))->maxLength(1000)])
            ->action(fn (Action $action, mixed $record, array $data) => WorkflowAction::run($action, $p, function () use ($record, $data) {
                $d = S::check($data, ['reason' => 'nullable|string|max:1000']);

                return app(CollectionService::class)->escalate(S::tenant(), WorkflowAction::id($record), S::user(), $d['reason'] ?? 'Manual escalation');
            }, S::done('collectionEscalate')));
    }

    public static function collectionPromise(): Action
    {
        $p = 'collections.manage';

        return WorkflowAction::make('collectionPromise', $p, S::L)->icon('lucide-handshake')
            ->schema([
                TextInput::make('amount_minor')->label(S::f('amount_minor'))->integer()->minValue(1)->required(),
                DatePicker::make('promised_for')->label(S::f('promised_for'))->required()->minDate(today()),
                Textarea::make('notes')->label(S::f('notes'))->maxLength(2000),
            ])
            ->action(fn (Action $action, mixed $record, array $data) => WorkflowAction::run($action, $p, function () use ($record, $data) {
                $d = S::check($data, ['amount_minor' => 'required|integer|min:1', 'promised_for' => 'required|date', 'notes' => 'nullable|string|max:2000']);

                return app(CollectionService::class)->promise(S::tenant(), WorkflowAction::id($record), (int) $d['amount_minor'], $d['promised_for'], $d['notes'] ?? null, S::user());
            }, S::done('collectionPromise')));
    }

    public static function writeOffRequest(): Action
    {
        $p = 'collections.manage';

        return WorkflowAction::make('writeOffRequest', $p, S::L)->icon('lucide-file-x')->color('danger')
            ->visible(fn (mixed $record) => self::pendingRequest(WorkflowAction::id($record)) === null)
            ->schema([Textarea::make('reason')->label(S::f('reason'))->required()->maxLength(1000)])
            ->action(fn (Action $action, mixed $record, array $data) => WorkflowAction::run($action, $p, function () use ($record, $data) {
                $d = S::check($data, ['reason' => 'required|string|max:1000']);

                return app(CollectionService::class)->requestWriteOff(S::tenant(), WorkflowAction::id($record), $d['reason'], S::user());
            }, S::done('writeOffRequest')));
    }

    public static function writeOffApprove(): Action
    {
        $p = 'collections.write_off.approve';

        return WorkflowAction::make('writeOffApprove', $p, S::L)->icon('lucide-badge-check')->color('success')
            ->visible(fn (mixed $record) => self::pendingRequest(WorkflowAction::id($record)) !== null)
            ->schema([Textarea::make('note')->label(S::f('note'))->maxLength(1000)])
            ->action(fn (Action $action, mixed $record, array $data) => WorkflowAction::run($action, $p, function () use ($record, $data) {
                $d = S::check($data, ['note' => 'nullable|string|max:1000']);

                return app(CollectionService::class)->approveWriteOff(S::tenant(), self::pendingRequest(WorkflowAction::id($record)) ?? abort(404), S::user(), $d['note'] ?? null);
            }, S::done('writeOffApprove')));
    }

    public static function writeOffReject(): Action
    {
        $p = 'collections.write_off.approve';

        return WorkflowAction::make('writeOffReject', $p, S::L)->icon('lucide-circle-x')->color('danger')
            ->visible(fn (mixed $record) => self::pendingRequest(WorkflowAction::id($record)) !== null)
            ->schema([Textarea::make('note')->label(S::f('note'))->required()->maxLength(1000)])
            ->action(fn (Action $action, mixed $record, array $data) => WorkflowAction::run($action, $p, function () use ($record, $data) {
                $d = S::check($data, ['note' => 'required|string|max:1000']);

                return app(CollectionService::class)->rejectWriteOff(S::tenant(), self::pendingRequest(WorkflowAction::id($record)) ?? abort(404), S::user(), $d['note']);
            }, S::done('writeOffReject')));
    }

    /** The pending write-off request of an obligation in this tenant (at most one: the service refuses a second). */
    public static function pendingRequest(string $obligationId): ?string
    {
        return DB::table('collection_write_off_requests')->where(['tenant_id' => S::tenant(), 'financial_obligation_id' => $obligationId, 'status' => 'PENDING'])->value('id');
    }
}
