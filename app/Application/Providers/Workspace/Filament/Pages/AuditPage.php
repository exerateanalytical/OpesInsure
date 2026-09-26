<?php

declare(strict_types=1);

namespace App\Application\Providers\Workspace\Filament\Pages;

use BackedEnum;
use Filament\Support\Icons\Heroicon;

/** Provider Portal screen "audit_log" (Gap-Free spec ui_screen_register). */
final class AuditPage extends ProviderWorkspacePage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    protected static ?int $navigationSort = 19;

    protected static ?string $slug = 'audit';

    protected static string $permission = 'provider.audit.view';

    protected static string $screen = 'audit_log';

    protected function rows(): array
    {
        return array_map(fn ($r) => (array) $r, $this->ws()->auditLog($this->user(), $this->scope(), []));
    }
}
