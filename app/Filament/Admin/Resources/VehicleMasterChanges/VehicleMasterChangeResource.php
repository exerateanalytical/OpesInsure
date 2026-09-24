<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\VehicleMasterChanges;

use App\Filament\Admin\Concerns\VehicleMasterAccess;
use App\Models\Vehicles\VehicleMasterChange;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;

/** Read-only change history of the vehicle master (seeding, admin edits, merges, reviews). */
final class VehicleMasterChangeResource extends Resource
{
    use VehicleMasterAccess;

    protected static ?string $model = VehicleMasterChange::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $navigationLabel = 'Change history';

    protected static ?int $navigationSort = 305;

    public static function table(Table $table): Table
    {
        $json = fn ($state) => $state ? json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

        return $table
            ->defaultSort('occurred_at', 'desc')
            ->modifyQueryUsing(fn ($query) => $query->with('actor'))
            ->columns([
                Tables\Columns\TextColumn::make('occurred_at')->dateTime()->sortable(),
                Tables\Columns\TextColumn::make('entity_type')->badge(),
                Tables\Columns\TextColumn::make('action')->badge()->color(fn (string $state) => match ($state) {
                    'MERGED' => 'warning', 'REJECTED' => 'danger', 'SEEDED', 'SEED_REFRESHED' => 'gray', default => 'success'
                }),
                Tables\Columns\TextColumn::make('before')->formatStateUsing($json)->wrap()->limit(120)->placeholder('-'),
                Tables\Columns\TextColumn::make('after')->formatStateUsing($json)->wrap()->limit(160)->placeholder('-'),
                Tables\Columns\TextColumn::make('actor.full_name')->label('By')->placeholder('system'),
                Tables\Columns\TextColumn::make('reason')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('entity_type')->options(['vehicle_make' => 'Make', 'vehicle_model' => 'Model', 'vehicle_master_review' => 'Review', 'vehicle_reference_value' => 'Reference value']),
                Tables\Filters\SelectFilter::make('action')->options(['SEEDED' => 'Seeded', 'SEED_REFRESHED' => 'Seed refreshed', 'CREATED' => 'Created', 'UPDATED' => 'Updated', 'ALIAS_ADDED' => 'Alias added', 'MERGED' => 'Merged', 'APPROVED_NEW' => 'Approved new', 'REJECTED' => 'Rejected']),
                Tables\Filters\Filter::make('admin_only')->label('Admin changes only')->query(fn ($query) => $query->whereNotNull('actor_id')),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListVehicleMasterChanges::route('/')];
    }
}
