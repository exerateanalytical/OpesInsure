<?php

declare(strict_types=1);

namespace App\Application\Integrations\Developer\Portal\Filament\Pages;

use BackedEnum;

/** DEV-013 API Usage & Rate Limits — 30-day metering per day / environment and the per-minute limits. */
final class UsagePage extends DeveloperPortalPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-gauge';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'usage';

    protected static string $screen = 'usage';

    protected function cards(): array
    {
        $u = $this->svc()->usage($this->client());

        return $u['limits'] + $u['totals'];
    }

    protected function rows(): array
    {
        return $this->svc()->usage($this->client())['days'];
    }
}
