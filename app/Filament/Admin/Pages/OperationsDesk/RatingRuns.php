<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\OperationsDesk;

use App\Filament\Shared\Actions\RatingActions;
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/** REQ-RAT-005 rating snapshots — rating runs of this tenant (rating.runs.view, as GET rating/runs/{run}); reproduce via RatingService::reproduce. */
final class RatingRuns extends OperationsDeskPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-history';

    protected static ?int $navigationSort = 36;

    protected static ?string $slug = 'rating/runs';

    protected static array $permissions = ['rating.runs.view'];

    protected static string $screen = 'rating_runs';

    protected static string $group = 'Products & pricing';

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => $this->tenantId === null ? [] : self::keyed(DB::table('rating_runs as r')->leftJoin('quotes as q', 'q.id', '=', 'r.quote_id')
                ->where('r.tenant_id', $this->tenantId)->orderByDesc('r.created_at')->limit(200)
                ->get(['r.id', 'r.status', 'r.created_at', 'r.reference_at', 'r.output_hash', 'q.quote_number'])))
            ->columns([
                TextColumn::make('created_at')->label(self::col('created_at'))->dateTime(),
                TextColumn::make('quote_number')->label(self::col('quote')),
                TextColumn::make('status')->label(self::col('status'))->badge(),
                TextColumn::make('reference_at')->label(self::col('reference_at'))->dateTime(),
                TextColumn::make('output_hash')->label(self::col('output_hash'))->limit(16),
            ])
            ->recordActions([RatingActions::ratingRunReproduce()])
            ->emptyStateHeading(__('operations_actions.empty'));
    }
}
