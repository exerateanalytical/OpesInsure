<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MasterDataImports;

use App\Application\Approvals\ApprovalService;
use App\Application\Import\ImportPipeline;
use App\Application\Import\ImportTargetRegistry;
use App\Filament\Admin\Concerns\MasterDataAccess;
use App\Filament\Admin\Concerns\ServiceValidation;
use App\Filament\Admin\Resources\MasterDataValues\MasterDataValueResource;
use App\Models\ApprovalRequest;
use App\Models\Import\ImportBatch;
use App\Models\MasterData\MasterDataList;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Storage;

/**
 * MDM-013 Import — REQ-IMP-001 generic pipeline screen: upload CSV/XLSX/JSON → map columns → validate → detect
 * duplicates → preview → submit → approve (another admin, ApprovalService maker-checker) → import → audit.
 * Targets: master-data values, vehicle generations, vehicle variants (more register in ImportTargetRegistry).
 * Existing records are never overwritten; batches are never deleted.
 */
final class MasterDataImportResource extends Resource
{
    use MasterDataAccess;

    protected static ?string $model = ImportBatch::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static ?string $navigationLabel = 'Import';

    protected static ?string $modelLabel = 'import batch';

    protected static ?int $navigationSort = 408;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit($record): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        $pipeline = fn () => app(ImportPipeline::class);

        return $table->defaultSort('created_at', 'desc')->columns([
            Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
            Tables\Columns\TextColumn::make('target')->badge()->formatStateUsing(fn (string $state, $record) => $state === 'master_data_values'
                ? 'master data · '.($record->target_params['domain'] ?? '').'.'.($record->target_params['list'] ?? '') : str_replace('_', ' ', $state)),
            Tables\Columns\TextColumn::make('filename')->wrap(),
            Tables\Columns\TextColumn::make('format')->badge(),
            Tables\Columns\TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) {
                'IMPORTED' => 'success', 'FAILED', 'REJECTED' => 'danger', 'CANCELLED' => 'gray', 'PENDING_APPROVAL' => 'info', default => 'warning' }),
            Tables\Columns\TextColumn::make('preview')->label('Preview')->state(fn ($record) => ($record->report['needs_mapping'] ?? [])
                ? 'map: '.implode(', ', $record->report['needs_mapping'])
                : sprintf('%d new · %d duplicates · %d errors', $record->report['valid'] ?? 0, count($record->report['duplicates'] ?? []), count($record->report['errors'] ?? [])))->wrap(),
            Tables\Columns\TextColumn::make('issues')->label('Issues')->state(fn ($record) => collect($record->report['errors'] ?? [])->map(fn ($e) => "row {$e['row']}: {$e['error']}")
                ->merge(collect($record->report['duplicates'] ?? [])->map(fn ($d) => "row {$d['row']}: {$d['code']} ≈ {$d['matches']}"))->take(5)->join('; '))->wrap()->placeholder('—'),
            Tables\Columns\TextColumn::make('imported_count')->label('Imported')->toggleable(),
        ])->filters([
            Tables\Filters\SelectFilter::make('target')->options(fn () => app(ImportTargetRegistry::class)->options()),
            Tables\Filters\SelectFilter::make('status')->options(array_combine($s = ['UPLOADED', 'VALIDATED', 'FAILED', 'PENDING_APPROVAL', 'IMPORTED', 'REJECTED', 'CANCELLED'], $s)),
        ])->headerActions([
            Actions\Action::make('upload')->label('Upload file')->icon(Heroicon::OutlinedArrowUpTray)->schema([
                Forms\Components\Select::make('target')->required()->live()->default('master_data_values')->options(fn () => app(ImportTargetRegistry::class)->options()),
                Forms\Components\Select::make('list_id')->label('Target list')->searchable()->options(fn () => MasterDataValueResource::listOptions())
                    ->visible(fn (Get $get) => $get('target') === 'master_data_values')->required(fn (Get $get) => $get('target') === 'master_data_values'),
                Forms\Components\FileUpload::make('file')->required()->disk('local')->directory('imports')->preserveFilenames()
                    ->acceptedFileTypes(['text/csv', 'text/plain', 'application/json', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']),
                Forms\Components\KeyValue::make('mapping')->label('Column mapping (field → column in your file)')->keyLabel('Field')->valueLabel('Column in file')
                    ->helperText(fn (Get $get) => 'Fields: '.implode(', ', array_keys(app(ImportTargetRegistry::class)->get($get('target') ?: 'master_data_values')->fields())).'. Columns with the same name map automatically.'),
            ])->action(function (array $data) {
                $params = [];
                if ($data['target'] === 'master_data_values') {
                    $list = MasterDataList::findOrFail($data['list_id']);
                    $params = ['domain' => $list->domain_code, 'list' => $list->code];
                }
                ServiceValidation::run(fn () => app(ImportPipeline::class)->upload($data['target'], $params, Storage::disk('local')->path($data['file']),
                    basename($data['file']), auth()->user(), array_filter($data['mapping'] ?? [])));
            }),
        ])->recordActions([
            Actions\Action::make('map')->label('Map columns')->icon(Heroicon::OutlinedArrowsRightLeft)->visible(fn ($record) => in_array($record->status, ImportPipeline::OPEN, true))
                ->fillForm(fn ($record) => ['mapping' => $record->mapping ?? []])
                ->schema(fn ($record) => [Forms\Components\KeyValue::make('mapping')->keyLabel('Field')->valueLabel('Column in file')
                    ->helperText('File columns: '.implode(', ', $record->source_columns ?? []).' · Fields: '.implode(', ', array_keys(app(ImportTargetRegistry::class)->get($record->target)->fields())))])
                ->action(fn ($record, array $data) => ServiceValidation::run(fn () => $pipeline()->map($record, array_filter($data['mapping'] ?? [])))),
            Actions\Action::make('submit')->label('Submit for approval')->color('primary')->icon(Heroicon::OutlinedPaperAirplane)->visible(fn ($record) => $record->status === 'VALIDATED')
                ->schema([Forms\Components\Textarea::make('reason')->maxLength(500)])
                ->action(fn ($record, array $data) => ServiceValidation::run(fn () => $pipeline()->submit($record, auth()->user(), $data['reason'] ?? null))),
            Actions\Action::make('approve')->label('Approve & import')->color('success')->icon(Heroicon::OutlinedCheck)->requiresConfirmation()
                ->visible(fn ($record) => $record->status === 'PENDING_APPROVAL' && self::canDecide($record))
                ->schema([Forms\Components\Textarea::make('note')->maxLength(500)])
                ->action(fn ($record, array $data) => ServiceValidation::run(fn () => $pipeline()->approve($record, auth()->user(), $data['note'] ?? null))),
            Actions\Action::make('reject')->label('Reject')->color('danger')->icon(Heroicon::OutlinedXMark)
                ->visible(fn ($record) => $record->status === 'PENDING_APPROVAL' && self::canDecide($record))
                ->schema([Forms\Components\Textarea::make('note')->required()->minLength(3)->maxLength(500)])
                ->action(fn ($record, array $data) => ServiceValidation::run(fn () => $pipeline()->reject($record, auth()->user(), $data['note']))),
            Actions\Action::make('cancel')->label('Cancel')->color('gray')->requiresConfirmation()
                ->visible(fn ($record) => in_array($record->status, [...ImportPipeline::OPEN, 'PENDING_APPROVAL'], true) && $record->created_by === auth()->id())
                ->action(fn ($record) => ServiceValidation::run(fn () => $pipeline()->cancel($record, auth()->user()))),
        ]);
    }

    private static function canDecide(ImportBatch $batch): bool
    {
        $req = $batch->approval_request_id ? ApprovalRequest::find($batch->approval_request_id) : null;

        return $req !== null && auth()->user() !== null && app(ApprovalService::class)->canDecide($req, auth()->user());
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMasterDataImports::route('/'),
        ];
    }
}
