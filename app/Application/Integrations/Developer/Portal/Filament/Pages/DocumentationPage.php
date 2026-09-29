<?php

declare(strict_types=1);

namespace App\Application\Integrations\Developer\Portal\Filament\Pages;

use BackedEnum;

/** DEV-002 API Documentation — partner operations of the generated OpenAPI document, auth model, spec download. */
final class DocumentationPage extends DeveloperPortalPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-book-open';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'docs';

    protected static string $screen = 'docs';

    protected function cards(): array
    {
        $doc = $this->svc()->openApi();
        $base = rtrim((string) config('app.url'), '/');

        return ['api_version' => $doc['info']['version'] ?? 'v1', 'openapi_url' => url('/api/v1/developer/openapi.json'), 'token_url' => $base.'/oauth/token',
            'grant_type' => 'client_credentials', 'on_behalf_of_header' => 'X-OpesInsure-On-Behalf-Of'];
    }

    protected function rows(): array
    {
        return $this->svc()->operations();
    }

    protected function columns(): array
    {
        return ['method', 'path', 'tag', 'scopes', 'parameters', 'body_fields'];
    }
}
