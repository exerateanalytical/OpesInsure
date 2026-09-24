<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CimaTerms;

use App\Application\Regulatory\RegulatoryVersioningService;
use App\Filament\Admin\Concerns\CimaRegulatoryAccess;
use App\Filament\Admin\Concerns\ServiceValidation;
use App\Models\Regulatory\RegulatoryTerm;
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

final class CimaTermResource extends Resource
{
    use CimaRegulatoryAccess;

    protected static ?string $model = RegulatoryTerm::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLanguage;

    protected static ?string $navigationLabel = 'Terminology (FR/EN)';

    protected static ?int $navigationSort = 206;

    protected static ?string $modelLabel = 'regulatory term';

    public static function table(Table $table): Table
    {
        return $table->defaultSort('code')->modifyQueryUsing(fn ($query) => $query->with('translations'))->columns([
                Tables\Columns\TextColumn::make('code')->searchable()->fontFamily('mono'),
                Tables\Columns\TextColumn::make('category')->badge()->sortable(),
                Tables\Columns\TextColumn::make('fr')->label('Français')->state(fn (RegulatoryTerm $r) => $r->label('fr'))->wrap(),
                Tables\Columns\TextColumn::make('en')->label('English')->state(fn (RegulatoryTerm $r) => $r->label('en'))->wrap(),
                Tables\Columns\TextColumn::make('source_article')->placeholder('-'),
                Tables\Columns\TextColumn::make('namespace')->toggleable(isToggledHiddenByDefault: true),
                Tables\Columns\TextColumn::make('regulatory_version')->label('Version')->toggleable(),
                Tables\Columns\TextColumn::make('effective_from')->date()->toggleable(),
                Tables\Columns\TextColumn::make('effective_until')->date()->placeholder('Open')->toggleable(),
            ])
            ->filters([
                Tables\Filters\SelectFilter::make('category')->options(fn () => RegulatoryTerm::query()->distinct()->orderBy('category')->pluck('category', 'category')->all()),
                Tables\Filters\SelectFilter::make('namespace')->options(['CIMA_TERM' => 'Terms', 'CIMA_PARTY_ROLE' => 'Party roles']),
            ])
            ->recordActions([Actions\ViewAction::make(), static::versionAction(['category', 'source_article', 'notes'], ['fr', 'en'])]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Semantic term')->columns(3)->schema([
                Infolists\Components\TextEntry::make('code')->copyable(), Infolists\Components\TextEntry::make('category')->badge(), Infolists\Components\TextEntry::make('source_article')->placeholder('-'),
                Infolists\Components\TextEntry::make('notes')->placeholder('-')->columnSpanFull()->helperText('Translate by semantic code, never word-for-word. Customers never see the code.'),
            ]),
            Section::make('EN/FR mapping')->schema([
                Infolists\Components\RepeatableEntry::make('translations')->hiddenLabel()->columns(3)->schema([
                    Infolists\Components\TextEntry::make('locale')->badge(), Infolists\Components\TextEntry::make('label'), Infolists\Components\TextEntry::make('context'),
                ]),
            ]),
            Section::make('Versioning')->columns(3)->schema([
                Infolists\Components\TextEntry::make('regulatory_version'), Infolists\Components\TextEntry::make('effective_from')->date(),
                Infolists\Components\TextEntry::make('effective_until')->date()->placeholder('Open'), Infolists\Components\TextEntry::make('source_reference')->columnSpanFull(),
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
            'index' => Pages\ListCimaTerms::route('/'),
            'view' => Pages\ViewCimaTerm::route('/{record}'),
        ];
    }
}
