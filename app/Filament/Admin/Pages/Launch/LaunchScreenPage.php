<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Launch;

use App\Domain\Tenancy\TenantContext;
use Filament\Pages\Page;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Livewire\Attributes\Locked;

/**
 * Launch 2026-10-02 (agent P10): base for the read-only staff screens that were specified in the enterprise screen
 * register but had no web screen (docs/LAUNCH_SCREEN_GAPS_2026-09-29.md). Same shape as OperationsDeskPage: opened by
 * the matching API route's GET permission, tenant captured at mount and re-applied on each Livewire round-trip.
 * Labels: resources/lang/{en,fr}/launch_screens.php.
 */
abstract class LaunchScreenPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.shared.pages.health-queue';

    /** @var list<string> any one of these opens the screen (the API's GET permission) */
    protected static array $permissions = [];

    /** launch_screens.nav.<key> */
    protected static string $screen = '';

    protected static string $group = 'Operations';

    #[Locked]
    public ?string $tenantId = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();
        if ($user === null || ! method_exists($user, 'hasPermission')) {
            return false;
        }
        foreach (static::$permissions as $p) {
            if ($user->hasPermission($p)) {
                return true;
            }
        }

        return false;
    }

    public static function getNavigationGroup(): ?string
    {
        return static::$group;
    }

    public static function getNavigationLabel(): string
    {
        return __('launch_screens.nav.'.static::$screen);
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
    }

    public function getSubheading(): ?string
    {
        return __('launch_screens.intro.'.static::$screen);
    }

    public function mount(): void
    {
        $this->tenantId = rescue(fn () => app(TenantContext::class)->id(), null, false);
    }

    public function hydrate(): void
    {
        if ($this->tenantId !== null) {
            app(TenantContext::class)->set($this->tenantId);
        }
    }

    protected static function col(string $key): string
    {
        return __('launch_screens.columns.'.$key);
    }

    /** @param iterable<object|array> $rows @return array<string, array<string, mixed>> keyed by id */
    protected static function keyed(iterable $rows, string $key = 'id'): array
    {
        $out = [];
        $i = 0;
        foreach ($rows as $r) {
            $r = (array) $r;
            $id = (string) ($r[$key] ?? $i);
            $out[$id] = ['__key' => $id, 'id' => $id] + $r;
            $i++;
        }

        return $out;
    }
}
