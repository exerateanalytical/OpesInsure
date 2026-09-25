<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\VehicleVariants;

use App\Application\Vehicles\VehicleMasterAdminService;
use App\Filament\Admin\Concerns\ServiceValidation;
use App\Filament\Admin\Concerns\VehicleMasterAccess;
use App\Filament\Admin\Resources\VehicleGenerations\VehicleGenerationResource;
use App\Models\Vehicles\VehicleGeneration;
use App\Models\Vehicles\VehicleModel;
use App\Models\Vehicles\VehicleReferenceValue;
use App\Models\Vehicles\VehicleVariant;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * CUST-007: variants (trim / engine / body) of a model, optionally under a
 * generation. Starts empty; admins or imports fill it. Attributes are
 * restricted to the vehicle reference codes. Never deleted.
 */
final class VehicleVariantResource extends Resource
{
    use VehicleMasterAccess;

    protected static ?string $model = VehicleVariant::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?string $navigationLabel = 'Variants';

    protected static ?string $modelLabel = 'vehicle variant';

    protected static ?int $navigationSort = 307;

    /** @return array<string, string> */
    private static function codes(string $group): array
    {
        return VehicleReferenceValue::where(['group' => $group, 'active' => true])->orderBy('sort_order')->pluck('label_en', 'code')->all();
    }

    /** @return array<int, Forms\Components\Field> */
    private static function attributeFields(): array
    {
        return [
            Forms\Components\TextInput::make('name')->required()->maxLength(160),
            Forms\Components\Select::make('body_type')->options(fn () => self::codes('body_type')),
            Forms\Components\Select::make('powertrain')->options(fn () => self::codes('powertrain')),
            Forms\Components\Select::make('hybrid_subtype')->options(fn () => self::codes('hybrid_subtype')),
            Forms\Components\Select::make('transmission')->options(fn () => self::codes('transmission')),
            Forms\Components\Select::make('drive_type')->options(fn () => self::codes('drive_type')),
            Forms\Components\TextInput::make('engine_capacity_cc')->label('Engine capacity (cc)')->numeric()->minValue(1),
            Forms\Components\TextInput::make('year_from')->numeric(),
            Forms\Components\TextInput::make('year_to')->numeric(),
        ];
    }

    public static function createAction(): Actions\Action
    {
        return Actions\Action::make('addVariant')->label('Add variant')->icon(Heroicon::OutlinedPlus)
            ->visible(fn () => self::canManageVehicleMaster())
            ->schema([
                Forms\Components\Select::make('model_id')->label('Model')->required()->searchable()->live()->options(fn () => VehicleGenerationResource::modelOptions()),
                Forms\Components\Select::make('generation_id')->label('Generation (optional)')
                    ->options(fn (Get $get) => $get('model_id') ? VehicleGeneration::where('model_id', $get('model_id'))->where('active', true)->orderBy('name')->pluck('name', 'id')->all() : []),
                ...self::attributeFields(),
            ])
            ->action(function (array $data) {
                $model = VehicleModel::findOrFail($data['model_id']);
                $generation = ! empty($data['generation_id']) ? VehicleGeneration::findOrFail($data['generation_id']) : null;
                if (ServiceValidation::run(fn () => app(VehicleMasterAdminService::class)->createVariant($model, $generation, $data, auth()->user()))) {
                    Notification::make()->title('Variant added')->success()->send();
                }
            });
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('code')
            ->modifyQueryUsing(fn ($query) => $query->with(['model.make', 'generation']))
            ->columns([
                Tables\Columns\TextColumn::make('code')->searchable()->copyable(),
                Tables\Columns\TextColumn::make('model.make.name')->label('Make'),
                Tables\Columns\TextColumn::make('model.name')->label('Model')->searchable(),
                Tables\Columns\TextColumn::make('generation.name')->label('Generation')->placeholder('-'),
                Tables\Columns\TextColumn::make('name')->searchable(),
                Tables\Columns\TextColumn::make('body_type')->badge()->placeholder('-'),
                Tables\Columns\TextColumn::make('powertrain')->badge()->placeholder('-'),
                Tables\Columns\TextColumn::make('engine_capacity_cc')->label('cc')->placeholder('-'),
                Tables\Columns\TextColumn::make('provenance')->badge()->color('gray')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\IconColumn::make('active')->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('model_id')->label('Model')->searchable()->options(fn () => VehicleGenerationResource::modelOptions()),
                Tables\Filters\TernaryFilter::make('active'),
            ])
            ->recordActions([
                Actions\Action::make('edit')->label('Edit')->icon(Heroicon::OutlinedPencilSquare)
                    ->fillForm(fn (VehicleVariant $v) => $v->only(['name', 'body_type', 'powertrain', 'hybrid_subtype', 'transmission', 'drive_type', 'engine_capacity_cc', 'year_from', 'year_to']))
                    ->schema(self::attributeFields())
                    ->action(fn (VehicleVariant $v, array $data) => ServiceValidation::run(fn () => app(VehicleMasterAdminService::class)->updateVariant($v, $data, auth()->user()))),
                Actions\Action::make('toggleActive')->label(fn (VehicleVariant $v) => $v->active ? 'Deactivate' : 'Reactivate')
                    ->color(fn (VehicleVariant $v) => $v->active ? 'danger' : 'success')->requiresConfirmation()
                    ->action(fn (VehicleVariant $v) => app(VehicleMasterAdminService::class)->updateVariant($v, ['active' => ! $v->active], auth()->user())),
            ])
            ->emptyStateHeading('No variants yet')
            ->emptyStateDescription('Variants are added by admins or imports only; none are pre-filled.');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListVehicleVariants::route('/')];
    }
}
