<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Catalogue;

use App\Filament\Shared\Actions\ReferenceConfigActions;
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/** Versioned institutional datasets — public holidays, hazard zones (GET reference-datasets, reference_datasets.view). */
final class ReferenceDatasets extends CatalogueConfigPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-database';

    protected static string|\UnitEnum|null $navigationGroup = 'Master data';

    protected static ?int $navigationSort = 60;

    protected static ?string $slug = 'reference-datasets';

    protected static array $permissions = ['reference_datasets.view'];

    protected static string $screen = 'reference_datasets';

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => DB::table('reference_datasets')->orderBy('kind')->orderBy('code')->orderByDesc('version')->get()
                ->mapWithKeys(fn ($r) => [$r->id => ['__key' => $r->id] + (array) $r])->all())
            ->columns([
                TextColumn::make('kind')->label(self::col('kind'))->badge(),
                TextColumn::make('jurisdiction')->label(self::col('jurisdiction')),
                TextColumn::make('code')->label(self::col('code')),
                TextColumn::make('version')->label(self::col('version')),
                TextColumn::make('effective_from')->label(self::col('effective_from'))->date(),
                TextColumn::make('verification_status')->label(self::col('verification'))->badge(),
                TextColumn::make('status')->label(self::col('status'))->badge(),
            ])
            ->headerActions([ReferenceConfigActions::rdDraft(), ReferenceConfigActions::rdGenerateHolidays()])
            ->recordActions([ReferenceConfigActions::rdActivate(), ReferenceConfigActions::rdRetire()])
            ->emptyStateHeading(__('catalogue_actions.empty'));
    }
}
