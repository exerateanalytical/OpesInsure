<?php

declare(strict_types=1);

namespace App\Filament\Shared\Widgets\Insurer;

use App\Application\WebExperiences\InsurerDashboards;
use App\Application\WebExperiences\Money;
use App\Filament\Shared\Pages\Insurer\InsurerDashboardPage;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\Concerns\InteractsWithPageFilters;
use Filament\Widgets\TableWidget;

/** Ranked breakdown table of an /insurer dashboard: intermediaries (CAR-006) or products (CAR-007), own carrier only. */
final class InsurerTableWidget extends TableWidget
{
    use InteractsWithPageFilters;

    protected static bool $isLazy = false;

    /** brokers | products */
    public string $breakdown = 'brokers';

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        return InsurerDashboardPage::mayView('operations');
    }

    /** @return array<string, array<string, mixed>> */
    public function rows(): array
    {
        if (! InsurerDashboardPage::mayView($this->breakdown)) {
            return [];
        }
        $svc = app(InsurerDashboards::class);
        $ccy = $svc->currency();
        $rows = $this->breakdown === 'products'
            ? array_map(fn ($r) => $r + ['premium' => Money::format($r['premium_minor'], $ccy), 'incurred' => Money::format($r['incurred_minor'], $ccy), 'ratio' => InsurerDashboards::percent($r['loss_ratio'])],
                $svc->productPerformance($this->pageFilters['period'] ?? null))
            : array_map(fn ($r) => $r + ['premium' => Money::format((int) $r['premium_minor'], $ccy)], $svc->intermediaries());
        $out = [];
        foreach (array_values($rows) as $i => $r) {
            $out['r'.$i] = array_map(fn ($v) => is_array($v) ? json_encode($v) : $v, $r) + ['__key' => 'r'.$i];
        }

        return $out;
    }

    public function table(Table $table): Table
    {
        $c = fn (string $name) => TextColumn::make($name)->label(__('insurer_screens.columns.'.$name))->placeholder('—');
        $columns = $this->breakdown === 'products'
            ? [$c('product')->weight('medium'), $c('version'), $c('line')->badge(), $c('status')->badge(), $c('policies')->numeric(), $c('premium'), $c('claims')->numeric(), $c('incurred'), $c('ratio')]
            : [$c('name')->weight('medium'), $c('type')->badge(), $c('licence_number'), $c('agreement_number'), $c('agreement_status')->badge(), $c('policies')->numeric(), $c('premium')];

        return $table->heading(__('insurer_screens.tables.'.$this->breakdown))
            ->records(fn (): array => $this->rows())
            ->columns($columns)
            ->paginated(false)
            ->emptyStateHeading(__('insurer_screens.empty'));
    }
}
