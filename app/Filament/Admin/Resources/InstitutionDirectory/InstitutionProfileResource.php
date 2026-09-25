<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\InstitutionDirectory;

use App\Application\Directory\InstitutionDirectoryService;
use App\Filament\Admin\Actions\LetterheadActions;
use App\Filament\Admin\Concerns\CimaRegulatoryAccess;
use App\Filament\Admin\Concerns\ServiceValidation;
use App\Models\Directory\InstitutionOffice;
use App\Models\Directory\InstitutionProfile;
use App\Models\Directory\InstitutionVerificationLabel;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/**
 * Official insurer directory (contacts, HQ, branches, verification status).
 * Edits go through InstitutionDirectoryService: audited, reason required,
 * sources kept, and the deploy seeder never overwrites an admin edit.
 */
final class InstitutionProfileResource extends Resource
{
    use CimaRegulatoryAccess;

    protected static ?string $model = InstitutionProfile::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?string $navigationLabel = 'Insurer directory';

    protected static ?int $navigationSort = 230;

    protected static ?string $modelLabel = 'insurer directory entry';

    public static function getNavigationGroup(): ?string
    {
        return 'Institutional directory';
    }

    /** @return array<string, string> */
    public static function statusOptions(): array
    {
        return collect(InstitutionVerificationLabel::map())->map(fn ($l) => $l['en'])->all();
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('directory_id')->modifyQueryUsing(fn ($query) => $query->with('carrier')->withCount('offices'))->columns([
            Tables\Columns\TextColumn::make('directory_id')->label('ID')->fontFamily('mono')->searchable(),
            Tables\Columns\TextColumn::make('carrier.trade_name')->label('Insurer')->wrap(),
            Tables\Columns\TextColumn::make('carrier.licence_branch')->label('Licence')->badge(),
            Tables\Columns\TextColumn::make('verification_status')->badge()->formatStateUsing(fn (?string $state) => $state ? (static::statusOptions()[$state] ?? $state) : null)->placeholder('-'),
            Tables\Columns\TextColumn::make('website')->placeholder('-')->limit(40),
            Tables\Columns\TextColumn::make('offices_count')->label('Offices'),
            Tables\Columns\TextColumn::make('admin_edited_at')->label('Admin edited')->dateTime()->placeholder('From directory file'),
        ])->filters([
            Tables\Filters\SelectFilter::make('verification_status')->options(fn () => static::statusOptions()),
        ])->recordActions([static::editAction(),
            LetterheadActions::edit('CARRIER', fn (InstitutionProfile $r) => $r->carrier_id),
            LetterheadActions::approve('CARRIER', fn (InstitutionProfile $r) => $r->carrier_id),
        ]);
    }

    public static function editAction(): Actions\Action
    {
        return Actions\Action::make('editDirectory')->label('Edit directory')->icon(Heroicon::OutlinedPencilSquare)
            ->visible(fn () => static::canAccessCima())
            ->fillForm(function (InstitutionProfile $record) {
                $hq = DB::table('party_addresses')->where('party_id', $record->carrier?->party_id)->where('type', 'HEAD_OFFICE')->first();

                return [
                    'verification_status' => $record->verification_status, 'website' => $record->website, 'po_box' => $record->po_box,
                    'phones' => $record->phones ?? [], 'emails' => $record->emails ?? [], 'hq_city' => $hq?->city, 'hq_address' => $hq?->line1,
                    'branches' => InstitutionOffice::where('carrier_id', $record->carrier_id)->orderBy('sort_order')->get()
                        ->map(fn ($o) => ['name' => $o->name, 'type' => $o->office_type, 'city' => $o->city, 'address' => $o->address, 'phone' => $o->phone])->all(),
                ];
            })
            ->schema([
                Forms\Components\Select::make('verification_status')->options(fn () => static::statusOptions()),
                Forms\Components\TextInput::make('website')->url()->maxLength(255),
                Forms\Components\TextInput::make('po_box')->label('PO box')->maxLength(64),
                Forms\Components\TagsInput::make('phones'),
                Forms\Components\TagsInput::make('emails'),
                Forms\Components\TextInput::make('hq_city')->label('Head-office city')->maxLength(120),
                Forms\Components\Textarea::make('hq_address')->label('Head-office address')->rows(2),
                Forms\Components\Repeater::make('branches')->schema([
                    Forms\Components\TextInput::make('name')->required()->maxLength(255),
                    Forms\Components\Select::make('type')->options(['HEAD_OFFICE' => 'Head office', 'DIRECT_BRANCH' => 'Direct branch'])->required(),
                    Forms\Components\TextInput::make('city')->maxLength(120),
                    Forms\Components\TextInput::make('phone')->maxLength(40),
                    Forms\Components\Textarea::make('address')->rows(2)->columnSpanFull(),
                ])->columns(2)->columnSpanFull()->defaultItems(0),
                Forms\Components\Textarea::make('reason')->label('Reason / evidence for the change')->required()->maxLength(1000),
            ])
            ->action(function (InstitutionProfile $record, array $data) {
                $reason = (string) $data['reason'];
                unset($data['reason']);
                $data['branches'] = array_values($data['branches'] ?? []);
                if (ServiceValidation::run(fn () => app(InstitutionDirectoryService::class)->update($record->carrier, $data, auth()->user(), $reason))) {
                    Notification::make()->title('Directory entry updated (audited)')->success()->send();
                }
            });
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListInstitutionProfiles::route('/')];
    }
}
