<?php

declare(strict_types=1);

namespace App\Application\Integrations\Developer\Portal\Filament\Pages;

use BackedEnum;

/** DEV-003 API Products — the OAuth scope catalogue as products, with what this connection is granted. */
final class ProductsPage extends DeveloperPortalPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-package';

    protected static ?int $navigationSort = 3;

    protected static ?string $slug = 'products';

    protected static string $screen = 'products';

    protected function cards(): array
    {
        $p = $this->svc()->products($this->client());

        return ['products' => count(array_unique(array_column($p, 'product'))), 'scopes_granted' => count(array_filter(array_column($p, 'granted')))];
    }

    protected function rows(): array
    {
        return $this->svc()->products($this->client());
    }
}
