<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\BrokerOnboarding;

use App\Application\Approvals\ApprovalService;
use App\Application\Import\ImportPipeline;
use App\Application\Partners\BulkOnboarding\BrokerOnboardingService;
use App\Filament\Admin\Concerns\ServiceValidation;
use App\Models\ApprovalRequest;
use App\Models\Import\ImportBatch;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\HtmlString;

/**
 * S9 — bulk broker onboarding (/admin, platform tenant, tenant.manage + identity.invite). Download the template, upload
 * the filled CSV/XLSX, read the per-row preview, submit; another admin approves and every valid row becomes a BROKER
 * tenant + partner + licence + branches + BROKER_ADMIN invitation. Runs on ImportPipeline (batch = import_batches row).
 */
final class BrokerOnboardingResource extends \App\Filament\Shared\LocalizedResource
{
    protected static ?string $model = ImportBatch::class;

    protected static string|BackedEnum|null $navigationIcon = 'lucide-building-2';

    protected static ?string $slug = 'broker-onboarding';

    protected static ?int $navigationSort = 12;

    public static function getNavigationLabel(): string
    {
        return __('bulk_onboarding.nav');
    }

    public static function getNavigationGroup(): ?string
    {
        return __('bulk_onboarding.group');
    }

    public static function getModelLabel(): string
    {
        return __('bulk_onboarding.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('bulk_onboarding.models');
    }

    public static function canViewAny(): bool
    {
        return app(BrokerOnboardingService::class)->allows(auth()->user());
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function canDelete($record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return app(BrokerOnboardingService::class)->query();
    }

    private static function tr(string $key, array $r = []): string
    {
        return __('bulk_onboarding.'.$key, $r);
    }

    public static function table(Table $table): Table
    {
        $pipeline = fn () => app(ImportPipeline::class);
        $service = fn () => app(BrokerOnboardingService::class);
        $csv = fn (string $content, string $name) => response()->streamDownload(function () use ($content) {
            echo $content;
        }, $name, ['Content-Type' => 'text/csv; charset=UTF-8']);

        return $table->defaultSort('created_at', 'desc')->columns([
            \App\Filament\Shared\Columns::date('created_at')->label(self::tr('columns.uploaded'))->sortable(),
            Tables\Columns\TextColumn::make('filename')->label(self::tr('columns.file'))->wrap(),
            \App\Filament\Shared\Columns::status('status')->label(self::tr('columns.status')),
            Tables\Columns\TextColumn::make('summary')->label(self::tr('columns.summary'))->state(fn (ImportBatch $b) => self::tr('summary', [
                'rows' => count($b->raw_rows ?? []), 'new' => $b->report['valid'] ?? 0, 'duplicates' => count($b->report['duplicates'] ?? []),
                'errors' => count($b->report['errors'] ?? []), 'created' => count($b->result['created'] ?? []), 'failed' => count($b->result['failed'] ?? []),
            ]))->wrap(),
            Tables\Columns\TextColumn::make('maker')->label(self::tr('columns.maker'))->state(fn (ImportBatch $b) => \App\Models\User::find($b->created_by)?->full_name)->placeholder('—'),
            Tables\Columns\TextColumn::make('checker')->label(self::tr('columns.checker'))->state(fn (ImportBatch $b) => $b->approved_by ? \App\Models\User::find($b->approved_by)?->full_name : null)->placeholder('—'),
        ])->headerActions([
            Actions\Action::make('templateCsv')->label(self::tr('actions.template_csv'))->icon('lucide-file-down')->color('gray')
                ->action(fn () => $csv($service()->templateCsv(), 'broker-onboarding-template.csv')),
            Actions\Action::make('templateXlsx')->label(self::tr('actions.template_xlsx'))->icon('lucide-file-spreadsheet')->color('gray')
                ->action(fn () => response()->download($service()->templateXlsx(), 'broker-onboarding-template.xlsx')->deleteFileAfterSend()),
            Actions\Action::make('upload')->label(self::tr('actions.upload'))->icon('lucide-upload')
                ->schema([
                    Forms\Components\FileUpload::make('file')->label(self::tr('fields.file'))->required()->disk('local')->directory('imports/brokers')->preserveFilenames()
                        ->acceptedFileTypes(['text/csv', 'text/plain', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'])
                        ->helperText(self::tr('help.upload')),
                ])
                ->action(function (array $data) use ($service) {
                    $batch = ServiceValidation::run(fn () => $service()->upload(Storage::disk('local')->path($data['file']), basename($data['file']), auth()->user()));
                    if ($batch) {
                        Notification::make()->success()->title(self::tr('notify.uploaded'))->body(self::tr('summary', [
                            'rows' => count($batch->raw_rows ?? []), 'new' => $batch->report['valid'] ?? 0, 'duplicates' => count($batch->report['duplicates'] ?? []),
                            'errors' => count($batch->report['errors'] ?? []), 'created' => 0, 'failed' => 0]))->send();
                    }
                }),
        ])->recordActions([
            Actions\Action::make('preview')->label(self::tr('actions.preview'))->icon('lucide-list-checks')->color('gray')
                ->modalHeading(fn (ImportBatch $b) => $b->filename)->modalSubmitAction(false)->modalWidth('7xl')
                ->modalContent(fn (ImportBatch $b) => self::rowsTable($service()->rows($b))),
            Actions\Action::make('report')->label(self::tr('actions.report'))->icon('lucide-download')->color('gray')
                ->action(fn (ImportBatch $b) => $csv($service()->reportCsv($b), 'broker-onboarding-'.substr($b->id, 0, 8).'.csv')),
            Actions\Action::make('submit')->label(self::tr('actions.submit'))->icon('lucide-send')->color('primary')
                ->visible(fn (ImportBatch $b) => $b->status === 'VALIDATED' && ($b->report['valid'] ?? 0) > 0)
                ->schema([Forms\Components\Textarea::make('reason')->label(self::tr('fields.reason'))->maxLength(500)])
                ->action(fn (ImportBatch $b, array $data) => ServiceValidation::run(fn () => $pipeline()->submit($b, auth()->user(), $data['reason'] ?? null))),
            Actions\Action::make('approve')->label(self::tr('actions.approve'))->icon('lucide-check')->color('success')->requiresConfirmation()
                ->modalDescription(fn (ImportBatch $b) => self::tr('help.approve', ['n' => $b->report['valid'] ?? 0]))
                ->visible(fn (ImportBatch $b) => $b->status === 'PENDING_APPROVAL' && self::canDecide($b))
                ->schema([Forms\Components\Textarea::make('note')->label(self::tr('fields.note'))->maxLength(500)])
                ->action(function (ImportBatch $b, array $data) use ($pipeline) {
                    $done = ServiceValidation::run(fn () => $pipeline()->approve($b, auth()->user(), $data['note'] ?? null));
                    if ($done) {
                        Notification::make()->success()->title(self::tr('notify.imported', ['created' => count($done->result['created'] ?? []), 'failed' => count($done->result['failed'] ?? [])]))->send();
                    }
                }),
            Actions\Action::make('reject')->label(self::tr('actions.reject'))->icon('lucide-x')->color('danger')
                ->visible(fn (ImportBatch $b) => $b->status === 'PENDING_APPROVAL' && self::canDecide($b))
                ->schema([Forms\Components\Textarea::make('note')->label(self::tr('fields.note'))->required()->minLength(3)->maxLength(500)])
                ->action(fn (ImportBatch $b, array $data) => ServiceValidation::run(fn () => $pipeline()->reject($b, auth()->user(), $data['note']))),
            Actions\Action::make('cancel')->label(self::tr('actions.cancel'))->color('gray')->requiresConfirmation()
                ->visible(fn (ImportBatch $b) => in_array($b->status, [...ImportPipeline::OPEN, 'PENDING_APPROVAL'], true) && $b->created_by === auth()->id())
                ->action(fn (ImportBatch $b) => ServiceValidation::run(fn () => $pipeline()->cancel($b, auth()->user()))),
        ])->emptyStateHeading(self::tr('empty.heading'))->emptyStateDescription(self::tr('empty.description'))->emptyStateIcon('lucide-building-2');
    }

    private static function canDecide(ImportBatch $b): bool
    {
        $req = $b->approval_request_id ? ApprovalRequest::find($b->approval_request_id) : null;

        return $req !== null && auth()->user() !== null && $b->created_by !== auth()->id() && app(ApprovalService::class)->canDecide($req, auth()->user());
    }

    /** @param list<array<string, mixed>> $rows */
    private static function rowsTable(array $rows): HtmlString
    {
        $tone = ['NEW' => '#0369a1', 'CREATED' => '#15803d', 'DUPLICATE' => '#a16207', 'SKIPPED_DUPLICATE' => '#a16207', 'ERROR' => '#b91c1c', 'SKIPPED_ERROR' => '#b91c1c', 'FAILED' => '#b91c1c'];
        $head = collect(['row', 'legal_name', 'niu', 'licence_number', 'admin', 'status', 'reason', 'delivery'])
            ->map(fn ($c) => '<th style="text-align:left;padding:4px 8px">'.e(self::tr('preview.'.$c)).'</th>')->implode('');
        $body = collect($rows)->map(fn ($r) => '<tr style="border-top:1px solid rgba(127,127,127,.25)">'
            .'<td style="padding:4px 8px">'.e((string) $r['row']).'</td><td style="padding:4px 8px">'.e((string) $r['legal_name']).'</td>'
            .'<td style="padding:4px 8px">'.e((string) $r['niu']).'</td><td style="padding:4px 8px">'.e((string) $r['licence_number']).'</td>'
            .'<td style="padding:4px 8px">'.e((string) $r['admin']).'</td>'
            .'<td style="padding:4px 8px;font-weight:600;color:'.($tone[$r['status']] ?? 'inherit').'">'.e(self::tr('status.'.strtolower($r['status']))).'</td>'
            .'<td style="padding:4px 8px">'.e((string) $r['reason']).'</td><td style="padding:4px 8px">'.e((string) $r['delivery']).'</td></tr>')->implode('');

        return new HtmlString('<div style="overflow-x:auto"><table style="width:100%;font-size:13px;border-collapse:collapse"><thead><tr>'.$head.'</tr></thead><tbody>'.$body.'</tbody></table></div>');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListBrokerOnboardings::route('/')];
    }
}
