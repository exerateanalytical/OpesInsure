<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Aml;

use App\Application\Compliance\Aml\Screening\Models\ScreeningHit;
use App\Filament\Shared\Actions\AmlActions;
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

/** Screening hits queue (GET aml/screening/hits, aml.screening.view); maker-checker disposition via AmlActions. */
final class AmlScreeningHits extends AmlPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-scan-search';

    protected static ?int $navigationSort = 40;

    protected static ?string $slug = 'aml/screening-hits';

    protected static string $permission = 'aml.screening.view';

    protected static string $screen = 'hits';

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => ScreeningHit::query()->where('tenant_id', $this->tenantId)->orderByDesc('created_at'))
            ->columns([
                TextColumn::make('party_name')->label(self::col('party'))->searchable(),
                TextColumn::make('matched_name')->label(self::col('matched_name'))->searchable(),
                TextColumn::make('list_type')->label(self::col('list_type'))->badge()
                    ->formatStateUsing(fn ($state) => AmlActions::codes([(string) $state], 'list_type')[(string) $state] ?? $state),
                TextColumn::make('entry_ref')->label(self::col('entry_ref')),
                TextColumn::make('score')->label(self::col('score'))->numeric(4),
                TextColumn::make('status')->label(self::col('status'))->badge()
                    ->formatStateUsing(fn ($state) => AmlActions::codes([(string) $state], 'hit_status')[(string) $state] ?? $state),
                TextColumn::make('disposition')->label(self::col('disposition'))
                    ->formatStateUsing(fn ($state) => $state ? (AmlActions::codes([(string) $state], 'disposition')[(string) $state] ?? $state) : null),
                TextColumn::make('created_at')->label(self::col('created_at'))->dateTime(),
            ])
            ->filters([SelectFilter::make('status')->label(self::col('status'))->options(AmlActions::codes(['OPEN', 'PROPOSED', 'DISPOSED'], 'hit_status'))])
            ->recordActions([AmlActions::hitPropose(), AmlActions::hitDecide()])
            ->emptyStateHeading(__('aml_actions.empty'));
    }
}
