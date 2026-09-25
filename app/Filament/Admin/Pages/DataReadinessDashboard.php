<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages;

use App\Application\DataReadiness\DataReadinessRegistry;
use App\Application\DataReadiness\DataStatus;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/** Workflow Institutional Data Master v1 — Data Readiness registry: status, owner, source and what is missing per domain. */
final class DataReadinessDashboard extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static string|\UnitEnum|null $navigationGroup = 'Master data';

    protected static ?string $navigationLabel = 'Data readiness';

    protected static ?int $navigationSort = 398;

    protected static ?string $slug = 'data-readiness';

    protected string $view = 'filament.admin.pages.data-readiness';

    public ?string $statusFilter = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user !== null && $user->memberships()->where('status', 'ACTIVE')->whereIn('role_code', ['SYSTEM_ADMIN', 'PLATFORM_ADMIN', 'COMPLIANCE_ADMIN'])->exists();
    }

    public function getTitle(): string
    {
        return 'Data readiness';
    }

    protected function getViewData(): array
    {
        $registry = app(DataReadinessRegistry::class);
        $items = $registry->items();
        $shown = $this->statusFilter ? array_values(array_filter($items, fn ($i) => $i['status'] === $this->statusFilter)) : $items;

        return ['summary' => $registry->summary($items), 'items' => $shown, 'codes' => DataStatus::CODES, 'production' => DataStatus::PRODUCTION];
    }
}
