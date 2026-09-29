<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Launch;

use App\Application\Finance\ExceptionCentre\FinanceExceptionCentre;
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** FIN-006 Financial exceptions — GET finance/exception-centre (finance.exceptions.view) via FinanceExceptionCentre::summary (tenant-scoped). */
final class FinanceExceptions extends LaunchScreenPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-triangle-alert';

    protected static ?int $navigationSort = 66;

    protected static ?string $slug = 'finance/exception-centre';

    protected static array $permissions = ['finance.exceptions.view'];

    protected static string $screen = 'finance_exceptions';

    protected static string $group = 'Financial operations';

    public function table(Table $table): Table
    {
        return $table
            ->records(function (): array {
                if ($this->tenantId === null) {
                    return [];
                }
                $rows = [];
                foreach (app(FinanceExceptionCentre::class)->summary($this->tenantId)['sources'] as $source => $s) {
                    $rows[$source] = [
                        '__key' => $source, 'id' => $source,
                        'source' => __('launch_screens.sources.'.$source),
                        'count' => (int) ($s['count'] ?? 0),
                        'amounts' => collect($s['amounts'] ?? [])->map(fn ($minor, $cur) => $cur.' '.number_format($minor / 100, 2))->implode(' · ') ?: '—',
                        'breakdown' => collect($s['breakdown'] ?? [])->map(fn ($n, $k) => $k.': '.$n)->implode(' · ') ?: '—',
                        'available' => ($s['available'] ?? true) ? __('launch_screens.yes') : __('launch_screens.no'),
                    ];
                }

                return $rows;
            })
            ->columns([
                TextColumn::make('source')->label(self::col('source')),
                TextColumn::make('count')->label(self::col('count'))->badge()->color(fn (int $state): string => $state > 0 ? 'warning' : 'success'),
                TextColumn::make('amounts')->label(self::col('amounts'))->wrap(),
                TextColumn::make('breakdown')->label(self::col('breakdown'))->wrap(),
                TextColumn::make('available')->label(self::col('available')),
            ])
            ->paginated(false)
            ->emptyStateHeading(__('launch_screens.empty'));
    }
}
