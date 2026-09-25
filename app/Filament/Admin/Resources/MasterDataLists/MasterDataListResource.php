<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MasterDataLists;

use App\Filament\Admin\Concerns\MasterDataAccess;
use App\Models\MasterData\MasterDataList;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;
use App\Filament\Admin\Resources\MasterDataValues\MasterDataValueResource;

/** Controlled lists: labels, hierarchy (parent list), "Other / Not listed" fallback and status. */
final class MasterDataListResource extends Resource
{
    use MasterDataAccess;

    protected static ?string $model = MasterDataList::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedQueueList;

    protected static ?string $navigationLabel = 'Lists';

    protected static ?int $navigationSort = 401;

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([Section::make('List')->columns(2)->schema([
            Forms\Components\TextInput::make('domain_code')->disabled(), Forms\Components\TextInput::make('code')->disabled(),
            Forms\Components\TextInput::make('label_en')->required(), Forms\Components\TextInput::make('label_fr')->required(),
            Forms\Components\Toggle::make('allow_other')->label('Offer "Other / Not listed" (review queue)'),
            Forms\Components\Select::make('status')->options(['ACTIVE' => 'Active', 'INACTIVE' => 'Inactive'])->required(),
            Forms\Components\TextInput::make('source_reference')->columnSpanFull(), Forms\Components\Textarea::make('note')->columnSpanFull(),
        ])]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('domain_code')->columns([
            Tables\Columns\TextColumn::make('domain_code')->label('Domain')->searchable()->sortable(),
            Tables\Columns\TextColumn::make('code')->searchable(), Tables\Columns\TextColumn::make('label_en')->label('EN'), Tables\Columns\TextColumn::make('label_fr')->label('FR')->toggleable(),
            Tables\Columns\TextColumn::make('parent_list_code')->label('Parent list')->placeholder('—'),
            Tables\Columns\TextColumn::make('values_count')->counts('values')->label('Values'),
            Tables\Columns\IconColumn::make('allow_other')->label('Other')->boolean(), Tables\Columns\IconColumn::make('structure_only')->label('Structure only')->boolean()->toggleable(),
            // Owner workflow data master status: PENDING_SOURCE lists show here with 0 values.
            Tables\Columns\TextColumn::make('workflow_status')->label('Owner status')->badge()->placeholder('—')
                ->state(fn ($record) => once(fn () => app(\App\Application\MasterData\WorkflowDataStatuses::class)->byList())[$record->domain_code.'.'.$record->code]['status'] ?? null)
                ->color(fn (?string $state) => match ($state) { 'PENDING_SOURCE', 'CONFIG_REQUIRED' => 'warning', 'UNVERIFIED', 'DEMO_ONLY' => 'danger', 'VERIFIED' => 'success', default => 'gray' }),
            Tables\Columns\TextColumn::make('source_type')->badge()->toggleable(), Tables\Columns\TextColumn::make('version')->toggleable(), Tables\Columns\TextColumn::make('status')->badge(),
        ])->filters([Tables\Filters\SelectFilter::make('domain_code')->label('Domain')->options(fn () => \App\Models\MasterData\MasterDataDomain::orderBy('code')->pluck('code', 'code')->all())->searchable()])
            ->recordActions([Actions\EditAction::make(), Actions\Action::make('values')->label('Values')->url(fn ($record) => MasterDataValueResource::getUrl('index', ['filters' => ['list_id' => ['value' => $record->id]]]))]);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMasterDataLists::route('/'),
            'edit' => Pages\EditMasterDataList::route('/{record}/edit'),
        ];
    }
}
