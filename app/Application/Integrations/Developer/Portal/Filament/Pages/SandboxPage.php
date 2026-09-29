<?php

declare(strict_types=1);

namespace App\Application\Integrations\Developer\Portal\Filament\Pages;

use BackedEnum;

/** DEV-007 Sandbox — environment info (base URL, token URL, sandbox limit, availability) and the sandbox keys. */
final class SandboxPage extends DeveloperPortalPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-flask-conical';

    protected static ?int $navigationSort = 5;

    protected static ?string $slug = 'sandbox';

    protected static string $screen = 'sandbox';

    protected function cards(): array
    {
        return $this->svc()->sandbox($this->client());
    }

    protected function rows(): array
    {
        return array_values(array_map(fn ($k) => (array) $k, array_filter($this->svc()->keys($this->client()), fn ($k) => $k->environment === 'sandbox')));
    }

    protected function columns(): array
    {
        return ['oauth_client_id', 'label', 'status', 'last_used_at', 'created_at'];
    }
}
