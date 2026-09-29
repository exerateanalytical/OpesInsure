<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Launch;

use App\Application\Identity\Rbac\PlatformAuthority;
use App\Application\Operations\Launch\LaunchPreflight;
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * Launch 2026-10-02 (S7): the launch:preflight checks on screen. Platform tenant only (operations.console.view).
 * The queue is not probed here (no blocking wait in a page render): it shows the last worker heartbeat recorded
 * by `php artisan launch:preflight`. No test e-mail is sent from here. Labels: resources/lang/{en,fr}/launch_preflight.php.
 */
final class LaunchReadiness extends LaunchScreenPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-rocket';

    protected static ?int $navigationSort = 79;

    protected static ?string $slug = 'operations/launch-readiness';

    protected static array $permissions = ['operations.console.view'];

    public static function canAccess(): bool
    {
        return parent::canAccess() && (bool) rescue(fn () => app(PlatformAuthority::class)->isPlatformTenant(), false, false);
    }

    public static function getNavigationLabel(): string
    {
        return __('launch_preflight.nav');
    }

    public function getSubheading(): ?string
    {
        return __('launch_preflight.intro');
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(function (): array {
                $rows = [];
                foreach (app(LaunchPreflight::class)->run(['probe_queue' => false]) as $r) {
                    $rows[$r['key']] = ['__key' => $r['key'], 'id' => $r['key'], 'check' => __('launch_preflight.checks.'.$r['key']), 'status' => $r['status'], 'detail' => $r['detail'], 'fix' => $r['fix'] ?? ''];
                }

                return $rows;
            })
            ->columns([
                TextColumn::make('check')->label(__('launch_preflight.columns.check')),
                TextColumn::make('status')->label(__('launch_preflight.columns.status'))->badge()
                    ->formatStateUsing(fn (string $state): string => __('launch_preflight.status.'.$state))
                    ->color(fn (string $state): string => match ($state) { 'PASS' => 'success', 'FAIL' => 'danger', default => 'warning' }),
                TextColumn::make('detail')->label(__('launch_preflight.columns.detail'))->wrap(),
                TextColumn::make('fix')->label(__('launch_preflight.columns.fix'))->wrap(),
            ])
            ->paginated(false)
            ->emptyStateHeading(__('launch_preflight.empty'));
    }
}
