<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\VehicleModels;

use App\Application\Vehicles\VehicleMasterAdminService;
use App\Filament\Admin\Concerns\ServiceValidation;
use App\Filament\Admin\Concerns\VehicleMasterAccess;
use App\Models\Vehicles\VehicleMake;
use App\Models\Vehicles\VehicleModel;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;

/** Vehicle models: add, alias, rename, mark historical, deactivate. Never deleted. */
final class VehicleModelResource extends Resource
{
    use VehicleMasterAccess;

    protected static ?string $model = VehicleModel::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Models';

    protected static ?string $modelLabel = 'vehicle model';

    protected static ?int $navigationSort = 302;

    public static function createAction(): Actions\Action
    {
        return Actions\Action::make('addModel')->label('Add model')->icon(Heroicon::OutlinedPlus)
            ->visible(fn () => self::canManageVehicleMaster())
            ->schema([
                Forms\Components\Select::make('make_id')->label('Make')->required()->searchable()->options(fn () => VehicleMake::where('active', true)->orderBy('name')->pluck('name', 'id')->all()),
                Forms\Components\TextInput::make('name')->required()->maxLength(120),
                Forms\Components\Select::make('segment')->options(['PASSENGER' => 'Passenger', 'COMMERCIAL' => 'Commercial', 'MIXED' => 'Mixed'])->helperText('Defaults to the make segment.'),
            ])
            ->action(function (array $data) {
                $make = VehicleMake::findOrFail($data['make_id']);
                if (ServiceValidation::run(fn () => app(VehicleMasterAdminService::class)->createModel($make, array_filter($data), auth()->user()))) {
                    Notification::make()->title('Model added')->success()->send();
                }
            });
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('code')
            ->modifyQueryUsing(fn ($query) => $query->with(['make', 'aliases']))
            ->columns([
                Tables\Columns\TextColumn::make('code')->searchable()->copyable(),
                Tables\Columns\TextColumn::make('make.name')->label('Make')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('name')->searchable()->sortable(),
                Tables\Columns\TextColumn::make('aliases_list')->label('Aliases')->state(fn (VehicleModel $m) => $m->aliases->pluck('alias')->join(', '))->wrap()->placeholder('-'),
                Tables\Columns\TextColumn::make('segment')->badge(),
                Tables\Columns\TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'HISTORICAL' ? 'gray' : 'success'),
                Tables\Columns\TextColumn::make('provenance')->badge()->color('gray')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('active')->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('make_id')->label('Make')->searchable()->options(fn () => VehicleMake::orderBy('name')->pluck('name', 'id')->all()),
                Tables\Filters\SelectFilter::make('status')->options(['ACTIVE' => 'Active', 'HISTORICAL' => 'Historical']),
                Tables\Filters\SelectFilter::make('provenance')->options(['MANUAL_VERIFIED' => 'Manually verified', 'CUSTOMER_SUBMITTED' => 'Customer submitted']),
                Tables\Filters\TernaryFilter::make('active'),
            ])
            ->recordActions([
                Actions\Action::make('rename')->label('Edit')->icon(Heroicon::OutlinedPencilSquare)
                    ->fillForm(fn (VehicleModel $m) => $m->only(['name', 'segment']))
                    ->schema([
                        Forms\Components\TextInput::make('name')->required()->maxLength(120),
                        Forms\Components\Select::make('segment')->required()->options(['PASSENGER' => 'Passenger', 'COMMERCIAL' => 'Commercial', 'MIXED' => 'Mixed']),
                    ])
                    ->action(fn (VehicleModel $m, array $data) => ServiceValidation::run(fn () => app(VehicleMasterAdminService::class)->updateModel($m, $data, auth()->user()))),
                Actions\Action::make('addAlias')->label('Add alias')->icon(Heroicon::OutlinedTag)
                    ->schema([Forms\Components\TextInput::make('alias')->required()->maxLength(120)])
                    ->action(function (VehicleModel $m, array $data) {
                        $ok = app(VehicleMasterAdminService::class)->addModelAlias($m, $data['alias'], auth()->user());
                        Notification::make()->title($ok ? 'Alias added' : 'Alias already used for this make')->{$ok ? 'success' : 'warning'}()->send();
                    }),
                Actions\Action::make('historical')->label(fn (VehicleModel $m) => $m->status === 'HISTORICAL' ? 'Mark current' : 'Mark historical')->requiresConfirmation()
                    ->action(fn (VehicleModel $m) => app(VehicleMasterAdminService::class)->updateModel($m, ['status' => $m->status === 'HISTORICAL' ? 'ACTIVE' : 'HISTORICAL'], auth()->user())),
                Actions\Action::make('toggleActive')->label(fn (VehicleModel $m) => $m->active ? 'Deactivate' : 'Reactivate')
                    ->color(fn (VehicleModel $m) => $m->active ? 'danger' : 'success')->requiresConfirmation()
                    ->action(fn (VehicleModel $m) => app(VehicleMasterAdminService::class)->updateModel($m, ['active' => ! $m->active], auth()->user())),
            ])
            ->emptyStateHeading('No vehicle models');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListVehicleModels::route('/')];
    }
}
