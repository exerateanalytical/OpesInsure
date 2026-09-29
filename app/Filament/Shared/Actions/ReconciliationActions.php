<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\Reconciliation\ManualMatchService;
use App\Application\Reconciliation\ReconciliationService;
use App\Domain\Tenancy\TenantContext;
use App\Models\ReconciliationImport;
use App\Models\ReconciliationItem;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Statement reconciliation actions (UI batch 26). Same permission + service + validation as ReconciliationController and
 * ReconciliationWorkspaceController:
 *   reconciliationImport    POST reconciliation/imports                    reconciliation.import   ReconciliationService::import
 *   reconciliationApprove   POST reconciliation/imports/{import}/approve   reconciliation.approve  ReconciliationService::approve (≠ uploader)
 *   reconciliationResolve   POST reconciliation/items/{item}/resolve       reconciliation.resolve  ReconciliationService::resolve (not MATCHED)
 *   manualMatchRequest      POST reconciliation/items/{item}/manual-matches reconciliation.resolve ManualMatchService::request
 *   manualMatchDecide       POST reconciliation/manual-matches/{m}/decide  reconciliation.approve  ManualMatchService::decide (≠ requester)
 * The statement file hash (duplicate-import guard) is the SHA-256 of the entered statement.
 */
final class ReconciliationActions
{
    private const L = 'operations_actions';

    public static function import(): Action
    {
        $p = 'reconciliation.import';

        return WorkflowAction::make('reconciliationImport', $p, self::L)->icon('lucide-upload')
            ->schema([
                Select::make('source_type')->label(__(self::L.'.fields.source_type'))->required()
                    ->options(['PAYMENT_PROVIDER' => __(self::L.'.codes.source_type.PAYMENT_PROVIDER'), 'CARRIER_BANK' => __(self::L.'.codes.source_type.CARRIER_BANK')]),
                TextInput::make('provider')->label(__(self::L.'.fields.provider'))->required()->maxLength(40),
                TextInput::make('statement_reference')->label(__(self::L.'.fields.statement_reference'))->required()->maxLength(120),
                DatePicker::make('period_start')->label(__(self::L.'.fields.period_start'))->required(),
                DatePicker::make('period_end')->label(__(self::L.'.fields.period_end'))->required()->afterOrEqual('period_start'),
                Select::make('currency')->label(__(self::L.'.fields.currency'))->required()->options(['XAF' => 'XAF'])->default('XAF'),
                Repeater::make('items')->label(__(self::L.'.fields.items'))->required()->minItems(1)->maxItems(5000)->columns(5)->schema([
                    TextInput::make('external_reference')->label(__(self::L.'.fields.external_reference'))->required()->maxLength(160),
                    DateTimePicker::make('transaction_at')->label(__(self::L.'.fields.transaction_at'))->required(),
                    TextInput::make('gross_minor')->label(__(self::L.'.fields.gross_minor'))->required()->integer()->minValue(0),
                    TextInput::make('fee_minor')->label(__(self::L.'.fields.fee_minor'))->required()->integer()->minValue(0)->default(0),
                    TextInput::make('net_minor')->label(__(self::L.'.fields.net_minor'))->required()->integer()->minValue(0),
                ]),
            ])
            ->action(function (Action $action, array $data) use ($p) {
                $d = [
                    'source_type' => $data['source_type'], 'provider' => $data['provider'], 'statement_reference' => $data['statement_reference'],
                    'period_start' => Carbon::parse($data['period_start'])->toDateString(), 'period_end' => Carbon::parse($data['period_end'])->toDateString(),
                    'currency' => $data['currency'],
                    'items' => array_values(array_map(fn (array $i) => [
                        'external_reference' => $i['external_reference'], 'transaction_at' => Carbon::parse($i['transaction_at'])->toIso8601String(),
                        'gross_minor' => (int) $i['gross_minor'], 'fee_minor' => (int) $i['fee_minor'], 'net_minor' => (int) $i['net_minor'],
                    ], $data['items'] ?? [])),
                ];
                $d['file_hash'] = hash('sha256', json_encode($d, JSON_UNESCAPED_SLASHES));

                return WorkflowAction::run($action, $p, fn () => app(ReconciliationService::class)->import(app(TenantContext::class)->id(), $d, auth()->user()),
                    __(self::L.'.reconciliationImport.done'));
            });
    }

    public static function approve(): Action
    {
        $p = 'reconciliation.approve';

        return WorkflowAction::make('reconciliationApprove', $p, self::L)->icon('lucide-badge-check')->requiresConfirmation()
            ->visible(fn (ReconciliationImport $record) => in_array($record->status, ['COMPLETED', 'COMPLETED_WITH_EXCEPTIONS'], true))
            ->action(fn (Action $action, ReconciliationImport $record) => WorkflowAction::run($action, $p,
                fn () => app(ReconciliationService::class)->approve(self::import_($record->id), auth()->user()), __(self::L.'.reconciliationApprove.done')));
    }

    public static function resolve(): Action
    {
        $p = 'reconciliation.resolve';

        return WorkflowAction::make('reconciliationResolve', $p, self::L)->icon('lucide-check-check')
            ->visible(fn (mixed $record) => ($record['status'] ?? null) === 'EXCEPTION')
            ->schema([
                Select::make('resolution')->label(__(self::L.'.fields.resolution'))->required()
                    ->options(['IGNORED' => __(self::L.'.codes.resolution.IGNORED'), 'ADJUSTMENT_REQUIRED' => __(self::L.'.codes.resolution.ADJUSTMENT_REQUIRED')]),
                Textarea::make('notes')->label(__(self::L.'.fields.notes'))->required()->minLength(20)->maxLength(2000),
            ])
            ->action(fn (Action $action, mixed $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(ReconciliationService::class)->resolve(self::item(WorkflowAction::id($record)),
                    ['resolution' => $data['resolution'], 'notes' => $data['notes']], auth()->user()), __(self::L.'.reconciliationResolve.done')));
    }

    public static function requestMatch(): Action
    {
        $p = 'reconciliation.resolve';

        return WorkflowAction::make('manualMatchRequest', $p, self::L)->icon('lucide-link')
            ->visible(fn (mixed $record) => ($record['status'] ?? null) === 'EXCEPTION' && ($record['pending_match_id'] ?? null) === null)
            ->schema(fn (mixed $record) => [
                Select::make('matched_id')->label(__(self::L.'.fields.payment'))->required()->searchable()
                    ->options(fn () => self::candidates(WorkflowAction::id($record))),
                Textarea::make('notes')->label(__(self::L.'.fields.notes'))->required()->minLength(20)->maxLength(2000),
            ])
            ->action(function (Action $action, mixed $record, array $data) use ($p) {
                return WorkflowAction::run($action, $p, function () use ($record, $data) {
                    $s = app(ManualMatchService::class);
                    $tenant = app(TenantContext::class)->id();

                    return $s->request($tenant, $s->item($tenant, WorkflowAction::id($record)), ['matched_id' => $data['matched_id'], 'notes' => $data['notes']], auth()->user());
                }, __(self::L.'.manualMatchRequest.done'));
            });
    }

    public static function decideMatch(): Action
    {
        $p = 'reconciliation.approve';

        return WorkflowAction::make('manualMatchDecide', $p, self::L)->icon('lucide-scale')
            ->visible(fn (mixed $record) => ($record['pending_match_id'] ?? null) !== null)
            ->schema([
                Select::make('decision')->label(__(self::L.'.fields.decision'))->required()
                    ->options(['APPROVE' => __(self::L.'.codes.decision.APPROVE'), 'REJECT' => __(self::L.'.codes.decision.REJECT')]),
                Textarea::make('note')->label(__(self::L.'.fields.note'))->required()->minLength(5)->maxLength(2000),
            ])
            ->action(fn (Action $action, mixed $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(ManualMatchService::class)->decide(app(TenantContext::class)->id(), (string) $record['pending_match_id'],
                    $data['decision'] === 'APPROVE', $data['note'], auth()->user()), __(self::L.'.manualMatchDecide.done')));
    }

    /** @return array<string, string> */
    private static function candidates(string $itemId): array
    {
        $s = app(ManualMatchService::class);
        $tenant = app(TenantContext::class)->id();

        return $s->candidates($tenant, $s->item($tenant, $itemId))
            ->mapWithKeys(fn ($p) => [$p->id => trim(($p->provider_reference ?? '—').' · '.number_format((int) $p->amount_minor, 0, '.', ' ').' '.$p->currency.' · '.$p->status)])->all();
    }

    /** Same tenant scope as ReconciliationController::scoped (own tenant or platform-level import). */
    private static function import_(string $id): ReconciliationImport
    {
        return ReconciliationImport::where(fn ($q) => $q->where('tenant_id', app(TenantContext::class)->id())->orWhereNull('tenant_id'))->findOrFail($id);
    }

    private static function item(string $id): ReconciliationItem
    {
        return ReconciliationItem::whereHas('import', fn ($q) => $q->where('tenant_id', app(TenantContext::class)->id()))->findOrFail($id);
    }

    /** Pending manual match per item (for the exceptions list). @param list<string> $itemIds @return array<string, string> */
    public static function pendingMatches(array $itemIds): array
    {
        return DB::table('reconciliation_manual_matches')->whereIn('reconciliation_item_id', $itemIds ?: ['00000000-0000-0000-0000-000000000000'])
            ->where('status', 'PENDING')->pluck('id', 'reconciliation_item_id')->all();
    }
}
