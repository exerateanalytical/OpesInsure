<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\MasterDataValues;

use App\Application\MasterData\MasterDataExportService;
use App\Application\MasterData\MasterDataMergeService;
use App\Filament\Admin\Concerns\MasterDataAccess;
use App\Filament\Admin\Concerns\ServiceValidation;
use App\Models\MasterData\MasterDataAlias;
use App\Models\MasterData\MasterDataList;
use App\Models\MasterData\MasterDataValue;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

/**
 * MDM-003 Domain values · MDM-004 Create value · MDM-005 Value details ·
 * MDM-007 Hierarchies (parent column/filter) · MDM-011 Merge records ·
 * MDM-014 Export · MDM-019 Translation management (untranslated filter,
 * inline French label). Values are deactivated, never deleted.
 */
final class MasterDataValueResource extends Resource
{
    use MasterDataAccess;

    protected static ?string $model = MasterDataValue::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedListBullet;

    protected static ?string $navigationLabel = 'Values';

    protected static ?string $modelLabel = 'master data value';

    protected static ?int $navigationSort = 402;

    public const SOURCE_TYPES = ['REGULATORY', 'GOVERNMENT', 'CIMA', 'INSURER', 'BROKER', 'MANUFACTURER', 'INDUSTRY', 'PLATFORM_NORMALIZED', 'MANUAL_VERIFIED', 'USER_SUBMITTED'];

    public static function listOptions(): array
    {
        return MasterDataList::orderBy('domain_code')->orderBy('code')->get()->mapWithKeys(fn ($l) => [$l->id => "{$l->domain_code}.{$l->code} — {$l->label_en}"])->all();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Value')->columns(2)->schema([
                Forms\Components\Select::make('list_id')->label('List')->options(fn () => self::listOptions())->searchable()->required()->live()->disabledOn('edit'),
                Forms\Components\TextInput::make('code')->required()->maxLength(128)->regex('/^[A-Z0-9_]+$/')->helperText('Canonical code, e.g. PROPERTY_WAREHOUSE (A–Z, 0–9, _).')->disabledOn('edit'),
                Forms\Components\TextInput::make('label_en')->label('Label (EN)')->required()->maxLength(255),
                Forms\Components\TextInput::make('label_fr')->label('Label (FR)')->required()->maxLength(255),
                Forms\Components\Textarea::make('description_en')->label('Description (EN)'),
                Forms\Components\Textarea::make('description_fr')->label('Description (FR)'),
                Forms\Components\Select::make('parent_code')->label('Parent (hierarchy)')->searchable()
                    ->options(function (Get $get) {
                        $list = MasterDataList::find($get('list_id'));
                        if (! $list?->parent_list_code) {
                            return [];
                        }
                        [$d, $l] = str_contains($list->parent_list_code, '.') ? explode('.', $list->parent_list_code, 2) : [$list->domain_code, $list->parent_list_code];

                        return MasterDataValue::where(['domain_code' => $d, 'list_code' => $l])->orderBy('label_en')->pluck('label_en', 'code')->all();
                    }),
                Forms\Components\TextInput::make('sort_order')->numeric()->default(1000),
                Forms\Components\Select::make('status')->options(['ACTIVE' => 'Active', 'INACTIVE' => 'Inactive'])->default('ACTIVE')->required(),
                Forms\Components\Toggle::make('is_common')->label('Common in Cameroon (ranked first)'),
            ]),
            Section::make('Provenance')->columns(2)->schema([
                Forms\Components\Select::make('source_type')->options(array_combine(self::SOURCE_TYPES, self::SOURCE_TYPES))->default('MANUAL_VERIFIED')->required(),
                Forms\Components\TextInput::make('source_reference')->maxLength(500),
                Forms\Components\DatePicker::make('effective_from'),
                Forms\Components\DatePicker::make('effective_until'),
                Forms\Components\DateTimePicker::make('verified_at'),
                Forms\Components\KeyValue::make('attributes')->columnSpanFull(),
            ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->defaultSort('sort_order')
            ->modifyQueryUsing(fn (Builder $query) => $query->whereNull('tenant_id'))
            ->columns([
                Tables\Columns\TextColumn::make('domain_code')->label('Domain')->sortable()->toggleable(),
                Tables\Columns\TextColumn::make('list_code')->label('List')->sortable(),
                Tables\Columns\TextColumn::make('code')->searchable()->copyable(),
                Tables\Columns\TextColumn::make('label_en')->label('EN')->searchable(),
                Tables\Columns\TextInputColumn::make('label_fr')->label('FR')->searchable()->rules(['required', 'max:255']),
                Tables\Columns\TextColumn::make('parent_code')->label('Parent')->placeholder('—')->toggleable(),
                Tables\Columns\TextColumn::make('status')->badge()->color(fn (string $state) => $state === 'ACTIVE' ? 'success' : 'gray'),
                Tables\Columns\TextColumn::make('source_type')->label('Source')->badge()->toggleable(),
                Tables\Columns\TextColumn::make('usage_count')->label('Uses')->sortable()->toggleable(),
                Tables\Columns\IconColumn::make('is_seeded')->label('Seeded')->boolean()->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('domain_code')->label('Domain')->options(fn () => MasterDataList::distinct()->orderBy('domain_code')->pluck('domain_code', 'domain_code')->all())->searchable(),
                Tables\Filters\SelectFilter::make('list_id')->label('List')->options(fn () => self::listOptions())->searchable(),
                Tables\Filters\SelectFilter::make('status')->options(['ACTIVE' => 'Active', 'INACTIVE' => 'Inactive']),
                Tables\Filters\SelectFilter::make('source_type')->options(array_combine(self::SOURCE_TYPES, self::SOURCE_TYPES)),
                Tables\Filters\Filter::make('untranslated')->label('Untranslated (FR = EN)')->query(fn (Builder $query) => $query->whereColumn('label_fr', 'label_en')->whereRaw("label_en ~ '[a-z]{4,}'")),
                // MDM-010 duplicate detection: active platform values whose normalized EN label repeats inside the list.
                Tables\Filters\Filter::make('possible_duplicates')->label('Possible duplicates')->query(fn (Builder $query) => $query->where('status', 'ACTIVE')->whereNull('tenant_id')
                    ->whereRaw("exists (select 1 from master_data_values d where d.list_id = master_data_values.list_id and d.id <> master_data_values.id and d.status = 'ACTIVE' and d.tenant_id is null and lower(trim(d.label_en)) = lower(trim(master_data_values.label_en)))")),
                Tables\Filters\Filter::make('has_parent')->label('Has a parent')->query(fn (Builder $query) => $query->whereNotNull('parent_code')),
            ])
            ->recordActions([
                Actions\EditAction::make(),
                Actions\Action::make('toggle')->label(fn (MasterDataValue $v) => $v->status === 'ACTIVE' ? 'Deactivate' : 'Reactivate')
                    ->icon(fn (MasterDataValue $v) => $v->status === 'ACTIVE' ? Heroicon::OutlinedNoSymbol : Heroicon::OutlinedCheck)
                    ->requiresConfirmation()->action(fn (MasterDataValue $v) => $v->update(['status' => $v->status === 'ACTIVE' ? 'INACTIVE' : 'ACTIVE'])),
                Actions\Action::make('alias')->label('Add alias')->icon(Heroicon::OutlinedTag)
                    ->schema([
                        Forms\Components\TextInput::make('alias')->required()->maxLength(255),
                        Forms\Components\Select::make('alias_type')->options(['ALIAS' => 'Alias', 'ABBREVIATION' => 'Abbreviation'])->default('ALIAS'),
                        Forms\Components\Select::make('locale')->options(['en' => 'English', 'fr' => 'French']),
                    ])
                    ->action(fn (MasterDataValue $v, array $data) => MasterDataAlias::create(['value_id' => $v->id] + $data)),
                Actions\Action::make('merge')->label('Request merge into…')->icon(Heroicon::OutlinedArrowsRightLeft)->color('warning')
                    ->visible(fn (MasterDataValue $v) => $v->status === 'ACTIVE' && $v->tenant_id === null)
                    ->modalDescription('Maker-checker (REQ-MDM-007): another admin approves under Master data → Merge requests. On approval this value becomes INACTIVE and redirects to the target; its code and labels become aliases of the target.')
                    ->schema(fn (MasterDataValue $v) => [Forms\Components\Select::make('into')->label('Keep this value')->required()->searchable()
                        ->options(MasterDataValue::where('list_id', $v->list_id)->where('id', '!=', $v->id)->where('status', 'ACTIVE')->whereNull('tenant_id')->orderBy('label_en')->pluck('label_en', 'id')->all()),
                        Forms\Components\Textarea::make('reason')->maxLength(500)])
                    ->action(function (MasterDataValue $v, array $data) {
                        $mr = ServiceValidation::run(fn () => app(MasterDataMergeService::class)->request($v, MasterDataValue::findOrFail($data['into']), auth()->user(), $data['reason'] ?? null));
                        if ($mr) {
                            Notification::make()->title($mr->status === 'MERGED' ? 'Merged' : 'Merge requested — waiting for a second admin')->success()->send();
                        }
                    }),
            ])
            ->headerActions([
                Actions\Action::make('export')->label('Export')->icon(Heroicon::OutlinedArrowDownTray)
                    ->schema([
                        Forms\Components\Select::make('domain')->options(fn () => MasterDataList::distinct()->orderBy('domain_code')->pluck('domain_code', 'domain_code')->all())->required()->searchable()->live(),
                        Forms\Components\Select::make('list')->options(fn (Get $get) => MasterDataList::where('domain_code', $get('domain'))->pluck('code', 'code')->all())->placeholder('All lists'),
                        Forms\Components\Select::make('format')->options(['csv' => 'CSV', 'xlsx' => 'Excel (XLSX)', 'json' => 'JSON'])->default('csv')->required(),
                    ])
                    ->action(fn (array $data) => response()->download(app(MasterDataExportService::class)->export($data['domain'], $data['list'] ?? null, $data['format']))->deleteFileAfterSend()),
            ])
            ->emptyStateHeading('No values')
            ->emptyStateDescription('Run php artisan opesinsure:seed-master-data or pick another filter.');
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListMasterDataValues::route('/'),
            'create' => Pages\CreateMasterDataValue::route('/create'),
            'edit' => Pages\EditMasterDataValue::route('/{record}/edit'),
        ];
    }
}
