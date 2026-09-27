<?php

namespace App\Filament\Admin\Pages;

use App\Application\Integrations\IntegrationHealthService;
use BackedEnum;
use Filament\Pages\Page;

final class IntegrationHealth extends Page
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-signal';
    protected static string|\UnitEnum|null $navigationGroup = 'Integrations';
    protected static ?string $navigationLabel = 'Health';
    protected static ?int $navigationSort = 89;
    protected string $view = 'filament.admin.pages.integration-health';

    public function getTitle(): string
    {
        return 'Integration health';
    }

    protected function getViewData(): array
    {
        return ['summary' => app(IntegrationHealthService::class)->summary()];
    }
}
