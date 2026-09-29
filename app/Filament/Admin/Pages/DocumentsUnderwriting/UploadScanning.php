<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\DocumentsUnderwriting;

use App\Application\Documents\Scanning\DocumentScanQueue;
use App\Application\Documents\Scanning\MalwareScannerHealth;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/**
 * Q1 "Upload scanning": malware scanner status (configured / reachable / version), counts per scan status, the held
 * queue (PENDING_SCAN / SCAN_UNAVAILABLE) with "rescan now", and the infected quarantine. Gated by documents.review or operations.console.view;
 * the tenant is the admin's current tenant (DocUwPage). Scanning itself: DocumentScanQueue / documents:rescan-pending.
 */
final class UploadScanning extends DocUwPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-shield-check';

    protected static ?int $navigationSort = 61;

    protected static ?string $slug = 'upload-scanning';

    /** Document reviewers, and operations staff who watch the scanner next to System health. */
    protected static ?array $permissions = ['documents.review', 'operations.console.view'];

    protected string $view = 'filament.admin.pages.upload-scanning';

    public static function getNavigationLabel(): string
    {
        return __('scan_queue.nav');
    }

    public function getTitle(): string
    {
        return __('scan_queue.title');
    }

    /** @return array{configured: bool, reachable: bool, endpoint: ?string, version: ?string, error: ?string} */
    public function scannerStatus(): array
    {
        return app(MalwareScannerHealth::class)->status();
    }

    /** @return array<string, int> */
    public function statusCounts(): array
    {
        return $this->tenantId === null ? [] : app(DocumentScanQueue::class)->counts($this->tenantId);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (array $filters): array {
                if ($this->tenantId === null) {
                    return [];
                }
                $infected = ($filters['queue']['value'] ?? 'held') === 'infected';

                return self::keyed(DB::table('documents as d')
                    ->leftJoin('document_scan_queue as q', 'q.document_id', '=', 'd.id')
                    ->leftJoin('parties as p', 'p.id', '=', 'd.party_id')
                    ->where('d.tenant_id', $this->tenantId)
                    ->when($infected, fn ($q) => $q->where('d.scan_status', DocumentScanQueue::INFECTED),
                        fn ($q) => $q->whereIn('d.scan_status', [...DocumentScanQueue::HELD, DocumentScanQueue::LEGACY_FAILED]))
                    ->orderByDesc($infected ? 'q.quarantined_at' : 'd.created_at')->limit(200)
                    ->get(['d.id', 'd.category', 'd.mime_type', 'd.scan_status', 'd.created_at', 'p.display_name as party', 'q.attempts', 'q.last_attempt_at',
                        'q.next_attempt_at', 'q.last_error', 'q.verdict', 'q.quarantined_at',
                        DB::raw("(select count(*) from document_pending_attachments pa where pa.document_id = d.id and pa.status = 'PENDING') as pending_attachments")]));
            })
            ->columns([
                TextColumn::make('id')->label(__('scan_queue.columns.document'))->formatStateUsing(fn ($state) => substr((string) $state, 0, 8)),
                TextColumn::make('category')->label(__('scan_queue.columns.category')),
                TextColumn::make('party')->label(__('scan_queue.columns.party')),
                TextColumn::make('scan_status')->label(__('scan_queue.columns.status'))->badge()
                    ->formatStateUsing(fn ($state) => __('scan_queue.status.'.$state))
                    ->color(fn ($state) => match ($state) { 'CLEAN' => 'success', 'INFECTED' => 'danger', default => 'warning' }),
                TextColumn::make('attempts')->label(__('scan_queue.columns.attempts'))->numeric(),
                TextColumn::make('last_attempt_at')->label(__('scan_queue.columns.last_attempt_at'))->dateTime(),
                TextColumn::make('next_attempt_at')->label(__('scan_queue.columns.next_attempt_at'))->dateTime(),
                TextColumn::make('last_error')->label(__('scan_queue.columns.last_error'))->wrap()->limit(120),
                TextColumn::make('pending_attachments')->label(__('scan_queue.columns.pending_attachments'))->numeric(),
                TextColumn::make('verdict')->label(__('scan_queue.columns.verdict'))->wrap(),
                TextColumn::make('quarantined_at')->label(__('scan_queue.columns.quarantined_at'))->dateTime(),
                TextColumn::make('created_at')->label(__('scan_queue.columns.created_at'))->dateTime(),
            ])
            ->filters([
                SelectFilter::make('queue')->label(__('scan_queue.tabs.held'))->default('held')
                    ->options(['held' => __('scan_queue.tabs.held'), 'infected' => __('scan_queue.tabs.infected')]),
            ])
            ->headerActions([
                Action::make('rescanAll')->label(__('scan_queue.actions.rescan_all'))->icon('lucide-refresh-cw')
                    ->visible(fn () => static::canAccess())
                    ->requiresConfirmation()
                    ->action(function () {
                        $s = app(DocumentScanQueue::class)->rescanPending(200, true);
                        $s['scanner']
                            ? Notification::make()->title(__('scan_queue.actions.rescan_all_done', $s))->success()->send()
                            : Notification::make()->title(__('scan_queue.actions.scanner_down'))->warning()->send();
                    }),
            ])
            ->recordActions([
                Action::make('rescan')->label(__('scan_queue.actions.rescan'))->icon('lucide-scan-search')
                    ->visible(fn (array $record) => static::canAccess() && $record['scan_status'] !== DocumentScanQueue::INFECTED)
                    ->action(function (array $record) {
                        abort_unless(DB::table('documents')->where('tenant_id', $this->tenantId)->where('id', $record['id'])->exists(), 404);
                        $status = (string) app(DocumentScanQueue::class)->rescanNow((string) $record['id']);
                        Notification::make()->title(__('scan_queue.actions.rescanned', ['status' => __('scan_queue.status.'.$status)]))
                            ->{$status === DocumentScanQueue::CLEAN ? 'success' : 'warning'}()->send();
                    }),
            ])
            ->emptyStateHeading(fn () => __('scan_queue.empty.held'));
    }
}
