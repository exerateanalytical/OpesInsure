<?php

declare(strict_types=1);

namespace App\Filament\Admin\Resources\CimaCompulsoryInsurance;

use App\Application\Regulatory\RegulatoryVersioningService;
use App\Filament\Admin\Concerns\CimaRegulatoryAccess;
use App\Filament\Admin\Concerns\ServiceValidation;
use App\Models\Regulatory\CompulsoryInsuranceRule;
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

final class CimaCompulsoryInsuranceResource extends Resource
{
    use CimaRegulatoryAccess;

    protected static ?string $model = CompulsoryInsuranceRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldExclamation;

    protected static ?string $navigationLabel = 'Compulsory insurance';

    protected static ?int $navigationSort = 210;

    protected static ?string $modelLabel = 'compulsory insurance rule';

    protected static ?string $slug = 'cima-compulsory-insurance';

    public static function table(Table $table): Table
    {
        return $table->columns([
                Tables\Columns\TextColumn::make('code')->fontFamily('mono'),
                Tables\Columns\TextColumn::make('branch_code')->label('CIMA branch'),
                Tables\Columns\TextColumn::make('basis')->wrap(),
                Tables\Columns\TextColumn::make('jurisdiction')->badge(),
                Tables\Columns\TextColumn::make('legal_reference')->placeholder('-'),
                Tables\Columns\TextColumn::make('regulatory_version')->label('Version')->toggleable(),
                Tables\Columns\TextColumn::make('effective_from')->date()->toggleable(),
                Tables\Columns\TextColumn::make('effective_until')->date()->placeholder('Open')->toggleable(),
            ])
            ->recordActions([static::versionAction(['basis', 'jurisdiction', 'legal_reference'])]);
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
            'index' => Pages\ListCimaCompulsoryInsurance::route('/'),
        ];
    }
}
