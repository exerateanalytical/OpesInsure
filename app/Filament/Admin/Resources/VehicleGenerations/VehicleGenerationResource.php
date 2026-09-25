<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\VehicleGenerations;

use App\Application\Vehicles\VehicleMasterAdminService;
use App\Filament\Admin\Concerns\ServiceValidation;
use App\Filament\Admin\Concerns\VehicleMasterAccess;
use App\Models\Vehicles\VehicleGeneration;
use App\Models\Vehicles\VehicleModel;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * CUST-007: model generations. The table starts empty on purpose: rows come
 * only from admins (here), imports, or approved reviews; nothing is inferred.
 * Edit / deactivate only, never deleted.
 */
final class VehicleGenerationResource extends Resource
{
    use VehicleMasterAccess;

    protected static ?string $model = VehicleGeneration::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static ?string $navigationLabel = 'Generations';

    protected static ?string $modelLabel = 'vehicle generation';

    protected static ?int $navigationSort = 306;

    /** @return array<string, string> "Make Model" keyed by model id */
    public static function modelOptions(): array
    {
        return VehicleModel::query()->join('vehicle_makes', 'vehicle_makes.id', '=', 'vehicle_models.make_id')
            ->where('vehicle_models.active', true)->orderBy('vehicle_makes.name')->orderBy('vehicle_models.name')
            ->get(['vehicle_models.id', 'vehicle_models.name', 'vehicle_makes.name as make_name'])
            ->mapWithKeys(fn ($m) => [$m->id => $m->make_name.' '.$m->name])->all();
    }

    public static function createAction(): Actions\Action
    {
        return Actions\Action::make('addGeneration')->label('Add generation')->icon(Heroicon::OutlinedPlus)
            ->visible(fn () => self::canManageVehicleMaster())
            ->schema([
                Forms\Components\Select::make('model_id')->label('Model')->required()->searchable()->options(fn () => self::modelOptions()),
                Forms\Components\TextInput::make('name')->required()->maxLength(120)->helperText('As the manufacturer names it, e.g. a generation code or years.'),
                Forms\Components\TextInput::make('year_from')->numeric()->minValue(1950),
                Forms\Components\TextInput::make('year_to')->numeric()->minValue(1950)->helperText('Leave empty if still in production.'),
            ])
            ->action(function (array $data) {
                $model = VehicleModel::findOrFail($data['model_id']);
                if (ServiceValidation::run(fn () => app(VehicleMasterAdminService::class)->createGeneration($model, $data, auth()->user()))) {
                    Notification::make()->title('Generation added')->success()->send();
                }
            });
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('code')
            ->modifyQueryUsing(fn ($query) => $query->with('model.make')->withCount('variants'))
            ->columns([
                Tables\Columns\TextColumn::make('code')->searchable()->copyable(),
                Tables\Columns\TextColumn::make('model.make.name')->label('Make'),
                Tables\Columns\TextColumn::make('model.name')->label('Model')->searchable(),
                Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\TextColumn::make('year_from')->label('From')->placeholder('-'),
                Tables\Columns\TextColumn::make('year_to')->label('To')->placeholder('-'),
                Tables\Columns\TextColumn::make('variants_count')->label('Variants'),
                Tables\Columns\TextColumn::make('provenance')->badge()->color('gray')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('active')->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('model_id')->label('Model')->searchable()->options(fn () => self::modelOptions()),
                Tables\Filters\TernaryFilter::make('active'),
            ])
            ->recordActions([
                Actions\Action::make('edit')->label('Edit')->icon(Heroicon::OutlinedPencilSquare)
                    ->fillForm(fn (VehicleGeneration $g) => $g->only(['name', 'year_from', 'year_to']))
                    ->schema([
                        Forms\Components\TextInput::make('name')->required()->maxLength(120),
                        Forms\Components\TextInput::make('year_from')->numeric(),
                        Forms\Components\TextInput::make('year_to')->numeric(),
                    ])
                    ->action(fn (VehicleGeneration $g, array $data) => ServiceValidation::run(fn () => app(VehicleMasterAdminService::class)->updateGeneration($g, $data, auth()->user()))),
                Actions\Action::make('toggleActive')->label(fn (VehicleGeneration $g) => $g->active ? 'Deactivate' : 'Reactivate')
                    ->color(fn (VehicleGeneration $g) => $g->active ? 'danger' : 'success')->requiresConfirmation()
                    ->action(fn (VehicleGeneration $g) => app(VehicleMasterAdminService::class)->updateGeneration($g, ['active' => ! $g->active], auth()->user())),
            ])
            ->emptyStateHeading('No generations yet')
            ->emptyStateDescription('Generations are added by admins or imports only; none are pre-filled.');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListVehicleGenerations::route('/')];
    }
}
