<?php

declare(strict_types=1);

namespace App\Application\Integrations\Developer\Portal\Filament\Pages;

use BackedEnum;

/** DEV-010 Webhook Event Catalogue — active canonical events (restricted ones hidden) and this client's subscriptions. */
final class EventCataloguePage extends DeveloperPortalPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-radio-tower';

    protected static ?int $navigationSort = 7;

    protected static ?string $slug = 'webhooks/events';

    protected static string $screen = 'events';

    protected function cards(): array
    {
        $subs = $this->svc()->subscriptions($this->client());

        return ['events_available' => count($this->svc()->eventCatalogue($this->client())), 'webhook_subscriptions' => count($subs),
            'subscribed_endpoints' => implode(', ', array_unique(array_column($subs, 'endpoint_host')))];
    }

    protected function rows(): array
    {
        return $this->svc()->eventCatalogue($this->client());
    }
}
