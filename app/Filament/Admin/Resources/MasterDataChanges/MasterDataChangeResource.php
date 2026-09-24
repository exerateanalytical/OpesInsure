<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MasterDataChanges;

use App\Filament\Admin\Concerns\MasterDataAccess;
use App\Models\MasterData\MasterDataChange;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;

/** MDM-017 Version history · MDM-018 Audit: every seed, edit, deactivation, merge and review decision. */
final class MasterDataChangeResource extends Resource
{
    use MasterDataAccess;

    protected static ?string $model = MasterDataChange::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $navigationLabel = 'History & audit';

    protected static ?int $navigationSort = 407;

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
        return $table->defaultSort('created_at', 'desc')->columns([
            Tables\Columns\TextColumn::make('created_at')->dateTime()->sortable(),
            Tables\Columns\TextColumn::make('domain_code')->label('Domain')->searchable(), Tables\Columns\TextColumn::make('entity_type')->badge(),
            Tables\Columns\TextColumn::make('action')->badge(), Tables\Columns\TextColumn::make('source')->badge(),
            Tables\Columns\TextColumn::make('after')->label('Change')->state(fn ($record) => json_encode($record->after, JSON_UNESCAPED_UNICODE))->limit(80)->wrap(),
            Tables\Columns\TextColumn::make('actor_id')->label('Actor')->state(fn ($record) => $record->actor_id ? \App\Models\User::find($record->actor_id)?->full_name : 'system'),
        ])->filters([
            Tables\Filters\SelectFilter::make('domain_code')->label('Domain')->options(fn () => \App\Models\MasterData\MasterDataDomain::orderBy('code')->pluck('code', 'code')->all()),
            Tables\Filters\SelectFilter::make('action')->options(fn () => \App\Models\MasterData\MasterDataChange::distinct()->pluck('action', 'action')->all()),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMasterDataChanges::route('/'),
        ];
    }
}
