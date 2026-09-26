<?php

declare(strict_types=1);

namespace App\Application\Providers\Workspace\Filament\Pages;

use BackedEnum;
use Filament\Support\Icons\Heroicon;

/** Provider Portal screen "notifications" (Gap-Free spec ui_screen_register). */
final class NotificationsPage extends ProviderWorkspacePage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBell;

    protected static ?int $navigationSort = 14;

    protected static ?string $slug = 'notifications';

    protected static string $permission = 'provider.dashboard.view';

    protected static string $screen = 'notifications';

    protected function rows(): array
    {
        return \Illuminate\Support\Facades\DB::table('outbox_messages')->whereRaw("payload->>'provider_id' = ?", [$this->scope()->providerId])->orderByDesc('occurred_at')->limit(200)->get(['event_name', 'aggregate_type', 'occurred_at'])->map(fn ($r) => (array) $r)->all();
    }
}
