<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Catalogue;

use App\Application\Rules\Models\QuestionSet;
use App\Filament\Shared\Actions\ReferenceConfigActions;
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Question sets per line / product version (GET question-sets, rules.view); maker-checker via ReferenceConfigActions. */
final class QuestionSets extends CatalogueConfigPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-list-checks';

    protected static ?int $navigationSort = 36;

    protected static ?string $slug = 'catalogue/question-sets';

    protected static array $permissions = ['rules.view'];

    protected static string $screen = 'question_sets';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => QuestionSet::query()->withCount('questions')->orderBy('line_code')->orderBy('stage')->orderByDesc('version'))
            ->columns([
                TextColumn::make('line_code')->label(self::col('line'))->badge()->searchable(),
                TextColumn::make('stage')->label(self::col('stage')),
                TextColumn::make('scope_type')->label(self::col('scope')),
                TextColumn::make('version')->label(self::col('version')),
                TextColumn::make('questions_count')->label(self::col('questions')),
                TextColumn::make('effective_from')->label(self::col('effective_from'))->date(),
                TextColumn::make('status')->label(self::col('status'))->badge(),
            ])
            ->filters([SelectFilter::make('status')->label(self::col('status'))->options(array_combine($s = ['DRAFT', 'IN_REVIEW', 'APPROVED', 'REJECTED'], $s))])
            ->headerActions([ReferenceConfigActions::qsCreate()])
            ->recordActions([ReferenceConfigActions::qsSubmit(), ReferenceConfigActions::qsApprove(), ReferenceConfigActions::qsReject()])
            ->emptyStateHeading(__('catalogue_actions.empty'));
    }
}
