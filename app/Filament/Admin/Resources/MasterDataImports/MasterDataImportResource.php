<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MasterDataImports;

use App\Filament\Admin\Concerns\MasterDataAccess;
use App\Models\MasterData\MasterDataImport;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;

/** MDM-013 Import: upload CSV/XLSX/JSON → map columns → validate → detect duplicates → preview → approve → import → audit. Existing codes are never overwritten. */
final class MasterDataImportResource extends Resource
{
    use MasterDataAccess;

    protected static ?string $model = MasterDataImport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowUpTray;

    protected static ?string $navigationLabel = 'Import';

    protected static ?int $navigationSort = 408;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('created_at', 'desc')->columns([
            Tables\Columns\TextColumn::make('created_at')->dateTime(), Tables\Columns\TextColumn::make('filename'),
            Tables\Columns\TextColumn::make('list')->state(fn ($record) => "{$record->domain_code}.{$record->list_code}"), Tables\Columns\TextColumn::make('format')->badge(),
            Tables\Columns\TextColumn::make('status')->badge()->color(fn (string $state) => match ($state) { 'IMPORTED' => 'success', 'FAILED' => 'danger', default => 'warning' }),
            Tables\Columns\TextColumn::make('preview')->label('Preview')->state(fn ($record) => sprintf('%d new · %d duplicates · %d errors', $record->report['valid'] ?? 0, count($record->report['duplicates'] ?? []), count($record->report['errors'] ?? [])))->wrap(),
            Tables\Columns\TextColumn::make('issues')->label('Issues')->state(fn ($record) => collect($record->report['errors'] ?? [])->map(fn ($e) => "row {$e['row']}: {$e['error']}")->merge(collect($record->report['duplicates'] ?? [])->map(fn ($d) => "row {$d['row']}: {$d['code']} ≈ {$d['matches']}"))->take(5)->join('; '))->wrap()->placeholder('—'),
        ])->headerActions([
            Actions\Action::make('upload')->label('Upload file')->icon(Heroicon::OutlinedArrowUpTray)->schema([
                Forms\Components\Select::make('list_id')->label('Target list')->required()->searchable()->options(fn () => \App\Filament\Admin\Resources\MasterDataValues\MasterDataValueResource::listOptions()),
                Forms\Components\FileUpload::make('file')->required()->disk('local')->directory('master-data-imports')->preserveFilenames()
                    ->acceptedFileTypes(['text/csv', 'text/plain', 'application/json', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']),
                Forms\Components\KeyValue::make('mapping')->label('Column mapping (field → your column)')->keyLabel('Field (code, label_en, label_fr, parent_code, aliases …)')->valueLabel('Column in file'),
            ])->action(function (array $data) {
                $list = \App\Models\MasterData\MasterDataList::findOrFail($data['list_id']);
                $path = \Illuminate\Support\Facades\Storage::disk('local')->path($data['file']);
                \App\Filament\Admin\Concerns\ServiceValidation::run(fn () => app(\App\Application\MasterData\MasterDataImportService::class)->upload($list->domain_code, $list->code, $path, basename($data['file']), auth()->id(), array_filter($data['mapping'] ?? [])));
            }),
        ])->recordActions([
            Actions\Action::make('import')->label('Approve & import')->color('success')->requiresConfirmation()->visible(fn ($record) => $record->status === 'VALIDATED')
                ->action(fn ($record) => \App\Filament\Admin\Concerns\ServiceValidation::run(fn () => app(\App\Application\MasterData\MasterDataImportService::class)->import($record, auth()->id()))),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMasterDataImports::route('/'),
        ];
    }
}
