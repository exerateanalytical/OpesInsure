<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\VehicleReferenceValues;

use App\Application\Vehicles\VehicleMasterAdminService;
use App\Application\Vehicles\VehicleReferenceLabels;
use App\Filament\Admin\Concerns\VehicleMasterAccess;
use App\Models\Vehicles\VehicleReferenceValue;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;

/** EN/FR labels, order and activation of the vehicle enumerations (codes are canonical). */
final class VehicleReferenceValueResource extends Resource
{
    use VehicleMasterAccess;

    protected static ?string $model = VehicleReferenceValue::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLanguage;

    protected static ?string $navigationLabel = 'Reference values';

    protected static ?int $navigationSort = 304;

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort(fn ($query) => $query->orderBy('group')->orderBy('sort_order'))
            ->columns([
                Tables\Columns\TextColumn::make('group')->badge(),
                Tables\Columns\TextColumn::make('code')->searchable(),
                Tables\Columns\TextColumn::make('label_en')->label('English')->searchable(),
                Tables\Columns\TextColumn::make('label_fr')->label('Français')->searchable(),
                Tables\Columns\TextColumn::make('sort_order')->label('Order'),
                Tables\Columns\IconColumn::make('active')->boolean(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('group')->options(array_combine(array_values(VehicleReferenceLabels::GROUPS), array_values(VehicleReferenceLabels::GROUPS))),
            ])
            ->recordActions([
                Actions\Action::make('editLabels')->label('Edit')->icon(Heroicon::OutlinedPencilSquare)
                    ->fillForm(fn (VehicleReferenceValue $v) => $v->only(['label_en', 'label_fr', 'sort_order', 'active']))
                    ->schema([
                        Forms\Components\TextInput::make('label_en')->required()->maxLength(120),
                        Forms\Components\TextInput::make('label_fr')->required()->maxLength(120),
                        Forms\Components\TextInput::make('sort_order')->numeric()->minValue(0),
                        Forms\Components\Toggle::make('active'),
                    ])
                    ->action(fn (VehicleReferenceValue $v, array $data) => app(VehicleMasterAdminService::class)->updateReferenceValue($v, $data, auth()->user())),
            ]);
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListVehicleReferenceValues::route('/')];
    }
}
