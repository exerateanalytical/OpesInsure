<?php

declare(strict_types=1);

namespace App\Application\Integrations\Developer\Portal\Filament\Pages;

use App\Application\Integrations\Developer\Portal\ApiChangelogService;
use BackedEnum;

/** DEV-014 API Versions & Changelog — partner-facing changes recorded by `api:changelog` from OpenAPI diffs. */
final class ChangelogPage extends DeveloperPortalPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-history';

    protected static ?int $navigationSort = 11;

    protected static ?string $slug = 'changelog';

    protected static string $screen = 'changelog';

    protected function cards(): array
    {
        $rows = app(ApiChangelogService::class)->entries();

        return ['api_version' => $this->svc()->openApi()['info']['version'] ?? 'v1', 'changes' => count($rows),
            'breaking_changes' => count(array_filter($rows, fn ($r) => (bool) $r['breaking']))];
    }

    protected function rows(): array
    {
        return app(ApiChangelogService::class)->entries();
    }
}
