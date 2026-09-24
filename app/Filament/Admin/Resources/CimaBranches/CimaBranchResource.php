<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CimaBranches;

use App\Application\Regulatory\RegulatoryVersioningService;
use App\Filament\Admin\Concerns\CimaRegulatoryAccess;
use App\Filament\Admin\Concerns\ServiceValidation;
use App\Models\Regulatory\RegulatoryBranch;
use BackedEnum;
use Filament\Actions;
use Filament\Forms;
use Filament\Infolists;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables;
use Filament\Tables\Table;

final class CimaBranchResource extends Resource
{
    use CimaRegulatoryAccess;

    protected static ?string $model = RegulatoryBranch::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static ?string $navigationLabel = 'Branch register (Art. 328)';

    protected static ?int $navigationSort = 201;

    protected static ?string $modelLabel = 'CIMA branch';

    public static function table(Table $table): Table
    {
        return $table->defaultSort('number')->columns([
                Tables\Columns\TextColumn::make('number')->label('No.')->sortable(),
                Tables\Columns\TextColumn::make('code')->searchable()->fontFamily('mono'),
                Tables\Columns\TextColumn::make('label_fr')->label('Libellé (FR)')->searchable()->wrap(),
                Tables\Columns\TextColumn::make('label_en')->label('Label (EN)')->searchable()->wrap(),
                Tables\Columns\TextColumn::make('business_family')->label('Family')->badge(),
                Tables\Columns\IconColumn::make('reserved')->boolean(),
                Tables\Columns\IconColumn::make('accessory_allowed')->label('Accessory OK')->boolean(),
                Tables\Columns\IconColumn::make('complementary_covers_allowed')->label('Complementary')->boolean(),
                Tables\Columns\IconColumn::make('is_compulsory')->label('Compulsory')->boolean(),
                Tables\Columns\TextColumn::make('regulatory_version')->label('Version')->toggleable(),
                Tables\Columns\TextColumn::make('effective_from')->date()->toggleable(),
                Tables\Columns\TextColumn::make('effective_until')->date()->placeholder('Open')->toggleable(),
            ])
            ->filters([Tables\Filters\SelectFilter::make('business_family')->options(['IARD' => 'IARD', 'LIFE' => 'Life']), Tables\Filters\TernaryFilter::make('reserved')])
            ->recordActions([Actions\ViewAction::make(), static::versionAction(['label_fr', 'label_en', 'compulsory_basis', 'legal_reference'])]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('CIMA branch')->columns(3)->schema([
                Infolists\Components\TextEntry::make('number'), Infolists\Components\TextEntry::make('code')->copyable(), Infolists\Components\TextEntry::make('business_family'),
                Infolists\Components\TextEntry::make('label_fr')->label('Libellé (FR)'), Infolists\Components\TextEntry::make('label_en')->label('Label (EN)'), Infolists\Components\TextEntry::make('legal_reference'),
                Infolists\Components\IconEntry::make('reserved')->boolean(), Infolists\Components\IconEntry::make('accessory_allowed')->boolean()->helperText('Article 328-1: branches 14 and 15 are never accessory.'),
                Infolists\Components\IconEntry::make('complementary_covers_allowed')->boolean(),
                Infolists\Components\IconEntry::make('is_compulsory')->boolean(), Infolists\Components\TextEntry::make('compulsory_basis')->placeholder('-')->columnSpan(2),
            ]),
            Section::make('Subdivisions (Article 328)')->schema([
                Infolists\Components\TextEntry::make('subdivisions')->hiddenLabel()->state(fn ($record) => \App\Models\Regulatory\RegulatoryBranchSubclass::where('branch_code', $record->code)->pluck('label_fr')->join(', ') ?: (\App\Models\Regulatory\RegulatoryRegime::where('code', 'CIMA')->first()?->metadata['branch_subclasses_note'] ?? 'Not entered yet.')),
            ]),
            Section::make('Versioning and authorized insurers')->columns(4)->schema([
                Infolists\Components\TextEntry::make('regulatory_version'), Infolists\Components\TextEntry::make('effective_from')->date(),
                Infolists\Components\TextEntry::make('effective_until')->date()->placeholder('Open'), Infolists\Components\TextEntry::make('status')->badge(),
                Infolists\Components\TextEntry::make('source_reference')->columnSpanFull(),
                Infolists\Components\TextEntry::make('insurers')->label('Authorized insurers')->columnSpanFull()->state(fn ($record) => \App\Models\Regulatory\InsurerAuthorizedBranch::where('branch_code', $record->code)->where('status', 'ACTIVE')->whereHas('authorization', fn ($a) => $a->where('status', 'ACTIVE'))->with('authorization.carrier')->get()->map(fn ($b) => ($b->authorization->carrier?->legal_name ?? $b->authorization->carrier?->cima_code).($b->authorization->is_demo ? ' (DEMO)' : ''))->unique()->join(', ') ?: 'None recorded'),
            ]),
        ]);
    }

    /** "New effective version" - the only way to change a regulatory row. */
    public static function versionAction(array $fields, array $labelFields = []): Actions\Action
    {
        return Actions\Action::make('newVersion')->label('New effective version')->icon(Heroicon::OutlinedDocumentDuplicate)
            ->visible(fn () => static::canAccessCima())
            ->fillForm(fn ($record) => collect($fields)->mapWithKeys(fn ($f) => [$f => $record->{$f}])->all() + ['effective_from' => now()->addDay()->toDateString()])
            ->schema(array_merge(
                array_map(fn ($f) => Forms\Components\TextInput::make($f)->label(str($f)->headline()->toString()), $fields),
                array_map(fn ($l) => Forms\Components\TextInput::make('label_'.$l)->label('Preferred label ('.strtoupper($l).')'), $labelFields),
                [
                    Forms\Components\TextInput::make('regulatory_version')->required()->maxLength(32),
                    Forms\Components\DatePicker::make('effective_from')->required(),
                    Forms\Components\TextInput::make('source_reference')->label('Source reference (legal text / circular)')->required()->maxLength(500),
                ]))
            ->action(function ($record, array $data) use ($fields, $labelFields) {
                $labels = collect($labelFields)->mapWithKeys(fn ($l) => [$l => $data['label_'.$l] ?? null])->filter()->all();
                $new = ServiceValidation::run(fn () => app(RegulatoryVersioningService::class)->supersede($record, collect($data)->only($fields)->all(), $data['regulatory_version'], $data['effective_from'], $data['source_reference'], auth()->user(), $labels));
                if ($new) {
                    Notification::make()->title('New effective version recorded')->success()->send();
                }
            });
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCimaBranches::route('/'),
            'view' => Pages\ViewCimaBranch::route('/{record}'),
        ];
    }
}
