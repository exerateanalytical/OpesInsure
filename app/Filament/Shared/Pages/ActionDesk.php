<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages;

use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Filament\Pages\Page;
use Livewire\Attributes\Locked;

/**
 * A desk: one staff screen grouping the long-tail workflow actions of an area (header action groups). Each action
 * carries its own API permission; the desk is reachable when the user holds at least one of them. The tenant is
 * captured at mount and restored on every Livewire round-trip (panel tenant middleware is not persistent).
 */
abstract class ActionDesk extends Page
{
    protected string $view = 'filament.shared.pages.action-desk';

    #[Locked]
    public ?string $tenantId = null;

    /** @return list<string> */
    abstract protected static function deskPermissions(): array;

    abstract protected static function deskKey(): string;

    public static function canAccess(): bool
    {
        $u = auth()->user();
        if (! $u instanceof User || rescue(fn () => app(TenantContext::class)->id(), null, false) === null) {
            return false;
        }
        foreach (static::deskPermissions() as $p) {
            if ($u->hasPermission($p)) {
                return true;
            }
        }

        return false;
    }

    public static function getNavigationLabel(): string
    {
        return __('misc_actions.desks.'.static::deskKey().'.title');
    }

    public function getTitle(): string
    {
        return __('misc_actions.desks.'.static::deskKey().'.title');
    }

    public function getSubheading(): ?string
    {
        return __('misc_actions.desks.'.static::deskKey().'.lede');
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
}
