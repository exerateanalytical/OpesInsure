<?php

declare(strict_types=1);

namespace App\Application\Integrations\Developer\Portal\Filament\Pages;

use BackedEnum;

/** DEV-012 API Request Logs — this client's calls (route template, status, outcome, latency); no headers or bodies are ever kept. */
final class RequestLogsPage extends DeveloperPortalPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-scroll-text';

    protected static ?int $navigationSort = 9;

    protected static ?string $slug = 'logs';

    protected static string $screen = 'request_logs';

    public ?string $environment = null;

    public ?string $outcome = null;

    public function extraView(): ?string
    {
        return 'developer-portal.filters';
    }

    public function filters(): array
    {
        return ['environment' => ['sandbox', 'production'], 'outcome' => ['allowed', 'denied', 'rate_limited']];
    }

    protected function rows(): array
    {
        $f = $this->filters();

        return $this->svc()->requestLogs($this->client(), [
            'environment' => in_array($this->environment, $f['environment'], true) ? $this->environment : null,
            'outcome' => in_array($this->outcome, $f['outcome'], true) ? $this->outcome : null,
        ]);
    }
}
