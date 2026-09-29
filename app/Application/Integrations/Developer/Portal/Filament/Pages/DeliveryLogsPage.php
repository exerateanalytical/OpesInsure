<?php

declare(strict_types=1);

namespace App\Application\Integrations\Developer\Portal\Filament\Pages;

use BackedEnum;

/** DEV-011 Webhook Delivery Logs — attempts for this client's subscriptions only (status filter). */
final class DeliveryLogsPage extends DeveloperPortalPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-send';

    protected static ?int $navigationSort = 8;

    protected static ?string $slug = 'webhooks/deliveries';

    protected static string $screen = 'deliveries';

    public ?string $status = null;

    public function extraView(): ?string
    {
        return 'developer-portal.filters';
    }

    /** @return array<string, list<string>> filter => options */
    public function filters(): array
    {
        return ['status' => ['DELIVERED', 'RETRY_SCHEDULED', 'DEAD_LETTERED']];
    }

    protected function rows(): array
    {
        $status = in_array($this->status, $this->filters()['status'], true) ? $this->status : null;

        return $this->svc()->deliveries($this->client(), $status);
    }
}
