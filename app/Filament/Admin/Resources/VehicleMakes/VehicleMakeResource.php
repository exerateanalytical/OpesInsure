<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\VehicleMakes;

use App\Application\Vehicles\VehicleMasterAdminService;
use App\Filament\Admin\Concerns\ServiceValidation;
use App\Filament\Admin\Concerns\VehicleMasterAccess;
use App\Models\Vehicles\VehicleMake;
use App\Models\Vehicles\VehicleReferenceValue;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Forms\Components\Field;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * Vehicle makes: edit priority/status/segment/UI ranks, add aliases, merge a
 * duplicate make into the canonical one, deactivate. Changes are recorded in
 * vehicle_master_changes and survive reseeding (admin_modified_at).
 */
final class VehicleMakeResource extends Resource
{
    use VehicleMasterAccess;

    protected static ?string $model = VehicleMake::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTruck;

    protected static ?string $navigationLabel = 'Makes';

    protected static ?string $modelLabel = 'vehicle make';

    protected static ?int $navigationSort = 301;

    /** @return array<string, string> */
    public static function codes(string $group): array
    {
        return VehicleReferenceValue::where('group', $group)->orderBy('sort_order')->pluck('label_en', 'code')->all();
    }

    /** @return array<int, Field> */
    private static function makeFields(): array
    {
        return [
            Forms\Components\TextInput::make('name')->required()->maxLength(120),
            Forms\Components\TextInput::make('country_of_origin')->label('Country of origin (ISO-2)')->length(2),
            Forms\Components\Select::make('segment')->required()->options(['PASSENGER' => 'Passenger', 'COMMERCIAL' => 'Commercial', 'MIXED' => 'Mixed']),
            Forms\Components\Select::make('market_priority')->required()->options(['HIGH' => 'High', 'NORMAL' => 'Normal']),
            Forms\Components\Select::make('cameroon_status')->required()->options(fn () => self::codes('cameroon_status')),
            Forms\Components\TextInput::make('ui_rank_cameroon')->label('Cameroon list rank (UX only)')->numeric()->minValue(1),
            Forms\Components\TextInput::make('ui_rank_chinese')->label('Chinese list rank (UX only)')->numeric()->minValue(1),
        ];
    }

    public static function createAction(): Actions\Action
    {
        return Actions\Action::make('addMake')->label('Add make')->icon(Heroicon::OutlinedPlus)
            ->visible(fn () => self::canManageVehicleMaster())
            ->schema([Forms\Components\TextInput::make('code')->helperText('Leave blank to derive from the name (e.g. LAND_ROVER).')->maxLength(80), ...self::makeFields()])
            ->action(function (array $data) {
                if (ServiceValidation::run(fn () => app(VehicleMasterAdminService::class)->createMake(array_filter($data, fn ($v) => $v !== null && $v !== ''), auth()->user()))) {
                    Notification::make()->title('Make added')->success()->send();
                }
            });
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('name')
            ->modifyQueryUsing(fn ($query) => $query->withCount('models')->with('aliases'))
            ->columns([
                Tables\Columns\TextColumn::make('code')->searchable()->copyable(),
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('aliases_list')->label('Aliases')->state(fn (VehicleMake $m) => $m->aliases->pluck('alias')->join(', '))->wrap()->placeholder('-'),
                Tables\Columns\TextColumn::make('country_of_origin')->label('Origin'),
                Tables\Columns\TextColumn::make('segment')->badge(),
                Tables\Columns\TextColumn::make('market_priority')->label('Priority')->badge()->color(fn (string $state) => $state === 'HIGH' ? 'success' : 'gray'),
                Tables\Columns\TextColumn::make('cameroon_status')->label('Cameroon status')->badge(),
                Tables\Columns\TextColumn::make('ui_rank_cameroon')->label('CM rank')->sortable()->placeholder('-'),
                Tables\Columns\TextColumn::make('models_count')->label('Models')->sortable(),
                Tables\Columns\TextColumn::make('provenance')->badge()->color('gray')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('active')->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('segment')->options(['PASSENGER' => 'Passenger', 'COMMERCIAL' => 'Commercial', 'MIXED' => 'Mixed']),
                Tables\Filters\SelectFilter::make('cameroon_status')->options(fn () => self::codes('cameroon_status')),
                Tables\Filters\SelectFilter::make('country_of_origin')->label('Origin')->options(fn () => VehicleMake::query()->distinct()->orderBy('country_of_origin')->pluck('country_of_origin', 'country_of_origin')->filter()->all()),
                Tables\Filters\TernaryFilter::make('active'),
            ])
            ->recordActions([
                Actions\Action::make('editMake')->label('Edit')->icon(Heroicon::OutlinedPencilSquare)
                    ->fillForm(fn (VehicleMake $m) => $m->only(['name', 'country_of_origin', 'segment', 'market_priority', 'cameroon_status', 'ui_rank_cameroon', 'ui_rank_chinese']))
                    ->schema(self::makeFields())
                    ->action(fn (VehicleMake $m, array $data) => ServiceValidation::run(fn () => app(VehicleMasterAdminService::class)->updateMake($m, $data, auth()->user()))),
                Actions\Action::make('addAlias')->label('Add alias')->icon(Heroicon::OutlinedTag)
                    ->schema([Forms\Components\TextInput::make('alias')->required()->maxLength(120)])
                    ->action(function (VehicleMake $m, array $data) {
                        $ok = app(VehicleMasterAdminService::class)->addMakeAlias($m, $data['alias'], auth()->user());
                        Notification::make()->title($ok ? 'Alias added' : 'Alias already used by a make')->{$ok ? 'success' : 'warning'}()->send();
                    }),
                Actions\Action::make('merge')->label('Merge into…')->icon(Heroicon::OutlinedArrowsRightLeft)->color('warning')
                    ->visible(fn (VehicleMake $m) => $m->active)
                    ->schema([Forms\Components\Select::make('target_id')->label('Canonical make')->required()->searchable()
                        ->options(fn (VehicleMake $record) => VehicleMake::where('active', true)->where('id', '!=', $record->id)->orderBy('name')->pluck('name', 'id')->all())])
                    ->requiresConfirmation()
                    ->modalDescription('Models, aliases and vehicle records move to the canonical make; this make becomes an alias and is deactivated (never deleted).')
                    ->action(fn (VehicleMake $m, array $data) => ServiceValidation::run(fn () => app(VehicleMasterAdminService::class)->mergeMake($m, VehicleMake::findOrFail($data['target_id']), auth()->user()))),
                Actions\Action::make('toggleActive')->label(fn (VehicleMake $m) => $m->active ? 'Deactivate' : 'Reactivate')
                    ->color(fn (VehicleMake $m) => $m->active ? 'danger' : 'success')->requiresConfirmation()
                    ->visible(fn (VehicleMake $m) => $m->merged_into_id === null)
                    ->action(fn (VehicleMake $m) => app(VehicleMasterAdminService::class)->updateMake($m, ['active' => ! $m->active], auth()->user())),
            ])
            ->emptyStateHeading('No vehicle makes')
            ->emptyStateDescription('Run php artisan opesinsure:seed-vehicles to load the Cameroon vehicle master.');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListVehicleMakes::route('/')];
    }
}
