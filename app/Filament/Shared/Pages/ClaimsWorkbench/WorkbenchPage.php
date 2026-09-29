<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\ClaimsWorkbench;

use App\Application\WebExperiences\Money;
use App\Application\WebExperiences\PortalAuthorization;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Shared\Components\RecordInfolist;
use App\Models\User;
use Filament\Infolists\Components\TextEntry;
use Filament\Pages\Page;
use Livewire\Attributes\Locked;

/**
 * Q9 claims professional / adjuster workbench (/insurer/adjuster-workbench…). Gated by the API read permission of the
 * underlying data (claims.experts.work = GET adjuster/assignments), tenant captured at mount and re-applied on every
 * Livewire round-trip. Labels: resources/lang/{en,fr}/claims_workbench.php.
 */
abstract class WorkbenchPage extends Page
{
    protected const WORK = 'claims.experts.work';

    /** claims_workbench.nav.<key> */
    protected static string $screen = '';

    /** any one of these opens the page */
    protected static array $permissions = [self::WORK];

    #[Locked]
    public ?string $tenantId = null;

    public static function canAccess(): bool
    {
        $u = auth()->user();
        if (! $u instanceof User) {
            return false;
        }
        foreach (static::$permissions as $p) {
            if (PortalAuthorization::allowsRead($u, $p)) {
                return true;
            }
        }

        return false;
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Claims operations';
    }

    public static function getNavigationLabel(): string
    {
        return __('claims_workbench.nav.'.static::$screen);
    }

    public function getTitle(): string
    {
        return static::getNavigationLabel();
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

    protected static function may(string $permission): bool
    {
        $u = auth()->user();

        return $u instanceof User && PortalAuthorization::allowsRead($u, $permission);
    }

    protected static function t(string $key, array $replace = []): string
    {
        return __('claims_workbench.'.$key, $replace);
    }

    /** A read-only entry with an explicit state (the workbench pages have no Eloquent record). */
    protected static function entry(string $name, mixed $state, ?string $label = null): TextEntry
    {
        return TextEntry::make('wb_'.$name.'_'.substr(md5((string) json_encode([$name, $label])), 0, 6))
            ->label($label ?? self::t('fields.'.$name))->state($state)->placeholder('—');
    }

    protected static function badge(string $name, ?string $state): TextEntry
    {
        return self::entry($name, $state)->badge()->color(fn ($state) => RecordInfolist::color($state));
    }

    protected static function money(string $name, mixed $minor, ?string $currency = 'XAF'): TextEntry
    {
        return self::entry($name, $minor === null ? null : Money::format((int) $minor, $currency ?: 'XAF'));
    }

    protected static function date(string $name, mixed $at, bool $time = true): TextEntry
    {
        return self::entry($name, $at)->{$time ? 'dateTime' : 'date'}();
    }
}
