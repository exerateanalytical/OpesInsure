<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Launch;

use App\Application\Operations\SystemHealthService;
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/** OPS-001..005 System / infrastructure / database / cache health — GET operations/health (operations.console.view) via SystemHealthService::summary. */
final class SystemHealth extends LaunchScreenPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-activity';

    protected static ?int $navigationSort = 80;

    protected static ?string $slug = 'operations/system-health';

    protected static array $permissions = ['operations.console.view'];

    protected static string $screen = 'system_health';

    public function table(Table $table): Table
    {
        return $table
            ->records(function (): array {
                $rows = [];
                foreach (app(SystemHealthService::class)->summary()['checks'] as $name => $check) {
                    $details = collect($check)->except(['status'])->map(fn ($v, $k) => $k.': '.(is_scalar($v) || $v === null ? var_export($v, true) : json_encode($v)))->implode(' · ');
                    $rows[$name] = ['__key' => $name, 'id' => $name, 'check' => __('launch_screens.checks.'.$name), 'status' => $check['status'] ?? 'UNKNOWN', 'details' => $details];
                }

                return $rows;
            })
            ->columns([
                TextColumn::make('check')->label(self::col('check')),
                TextColumn::make('status')->label(self::col('status'))->badge()
                    ->color(fn (string $state): string => match ($state) { 'OK' => 'success', 'DOWN' => 'danger', default => 'warning' }),
                TextColumn::make('details')->label(self::col('details'))->wrap(),
            ])
            ->paginated(false)
            ->emptyStateHeading(__('launch_screens.empty'));
    }
}
