<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Aml;

use App\Application\Compliance\Aml\Screening\Models\ScreeningListSource;
use App\Application\Compliance\Aml\Screening\Models\ScreeningListVersion;
use App\Filament\Shared\Actions\AmlActions;
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/**
 * Screening lists and their imported versions (GET aml/screening/lists, list-versions/{v}; aml.screening.view).
 * Create a list source, import a version (maker), approve / reject it (checker) via AmlActions.
 */
final class AmlScreeningLists extends AmlPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-list-checks';

    protected static ?int $navigationSort = 41;

    protected static ?string $slug = 'aml/screening-lists';

    protected static string $permission = 'aml.screening.view';

    protected static string $screen = 'lists';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => ScreeningListVersion::query()->where('tenant_id', $this->tenantId)->orderByDesc('created_at'))
            ->columns([
                TextColumn::make('source_id')->label(self::col('source'))
                    ->formatStateUsing(fn ($state) => ($s = ScreeningListSource::where('tenant_id', $this->tenantId)->find($state)) ? "{$s->code} — {$s->name}" : $state),
                TextColumn::make('version')->label(self::col('version')),
                TextColumn::make('status')->label(self::col('status'))->badge()
                    ->formatStateUsing(fn ($state) => AmlActions::codes([(string) $state], 'version_status')[(string) $state] ?? $state),
                TextColumn::make('format')->label(self::col('format')),
                TextColumn::make('entry_count')->label(self::col('entry_count'))->numeric(),
                TextColumn::make('source_reference')->label(self::col('source_reference')),
                TextColumn::make('created_at')->label(self::col('created_at'))->dateTime(),
                TextColumn::make('activated_at')->label(self::col('activated_at'))->dateTime(),
            ])
            ->filters([SelectFilter::make('status')->label(self::col('status'))
                ->options(AmlActions::codes(['PENDING_APPROVAL', 'ACTIVE', 'SUPERSEDED', 'REJECTED'], 'version_status'))])
            ->headerActions([AmlActions::listCreate(), AmlActions::listImport()])
            ->recordActions([AmlActions::listVersionDecide()])
            ->emptyStateHeading(__('aml_actions.empty'));
    }
}
