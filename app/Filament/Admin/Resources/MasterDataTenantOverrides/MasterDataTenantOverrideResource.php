<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MasterDataTenantOverrides;

use App\Application\MasterData\MasterDataOverrideService;
use App\Filament\Admin\Concerns\MasterDataAccess;
use App\Filament\Admin\Concerns\ServiceValidation;
use App\Filament\Admin\Resources\CarrierMasterDataMappings\CarrierMasterDataMappingResource;
use App\Models\MasterData\MasterDataTenantOverride;
use App\Models\MasterData\MasterDataValue;
use App\Models\Tenant;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;

/**
 * REQ-MDM-006 Tenant overrides: an organization may hide a platform value, add its own alias, or map its internal
 * code — never change the value's meaning. Private tenant values are listed under Values (tenant column).
 * Writes go through MasterDataOverrideService (change log + catalog_version bump).
 */
final class MasterDataTenantOverrideResource extends Resource
{
    use MasterDataAccess;

    protected static ?string $model = MasterDataTenantOverride::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static ?string $navigationLabel = 'Tenant overrides';

    protected static ?string $modelLabel = 'tenant override';

    protected static ?int $navigationSort = 404;

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
        return $table->modifyQueryUsing(fn ($query) => $query->with('value'))->defaultSort('created_at', 'desc')->columns([
            Tables\Columns\TextColumn::make('tenant_id')->label('Organization')->state(fn ($record) => Tenant::find($record->tenant_id)?->legal_name ?? $record->tenant_id),
            Tables\Columns\TextColumn::make('value.list_code')->label('List')->description(fn ($record) => $record->value?->domain_code),
            Tables\Columns\TextColumn::make('value.label_en')->label('Value')->description(fn ($record) => $record->value?->code),
            Tables\Columns\TextColumn::make('action')->badge()->color(fn (string $state) => $state === 'HIDE' ? 'danger' : 'info'),
            Tables\Columns\TextColumn::make('alias')->placeholder('—'),
            Tables\Columns\TextColumn::make('internal_code')->placeholder('—'),
        ])->filters([
            Tables\Filters\SelectFilter::make('action')->options(array_combine(MasterDataOverrideService::ACTIONS, MasterDataOverrideService::ACTIONS)),
            Tables\Filters\SelectFilter::make('tenant_id')->label('Organization')->searchable()->options(fn () => Tenant::orderBy('legal_name')->pluck('legal_name', 'id')->all()),
        ])->headerActions([
            Actions\Action::make('add')->label('Add override')->icon(Heroicon::OutlinedPlus)->schema([
                Forms\Components\Select::make('tenant_id')->label('Organization')->required()->searchable()->options(fn () => Tenant::orderBy('legal_name')->pluck('legal_name', 'id')->all()),
                CarrierMasterDataMappingResource::valueSelect(),
                Forms\Components\Select::make('action')->required()->live()->options(['HIDE' => 'Hide for this organization', 'ALIAS' => 'Add an alias', 'INTERNAL_CODE' => 'Map an internal code']),
                Forms\Components\TextInput::make('text')->label(fn (Get $get) => $get('action') === 'ALIAS' ? 'Alias' : 'Internal code')->maxLength(128)
                    ->visible(fn (Get $get) => in_array($get('action'), ['ALIAS', 'INTERNAL_CODE'], true))->required(fn (Get $get) => in_array($get('action'), ['ALIAS', 'INTERNAL_CODE'], true)),
            ])->action(fn (array $data) => ServiceValidation::run(fn () => app(MasterDataOverrideService::class)
                ->setOverride($data['tenant_id'], MasterDataValue::findOrFail($data['value_id']), $data['action'], $data['text'] ?? null, auth()->id()))),
        ])->recordActions([
            Actions\Action::make('remove')->label('Remove')->color('danger')->requiresConfirmation()
                ->modalDescription('The organization sees the platform value as-is again. Logged in the version history.')
                ->action(fn ($record) => ServiceValidation::run(fn () => app(MasterDataOverrideService::class)->removeOverride($record, auth()->id()))),
        ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMasterDataTenantOverrides::route('/'),
        ];
    }
}
