<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\Underwriting;

use App\Application\Underwriting\Workbench\UnderwritingWorkbenchQuery;
use App\Application\WebExperiences\PortalAuthorization;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Admin\Resources\UnderwritingCases\UnderwritingCaseResource;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Livewire\Attributes\Locked;

/**
 * Q8 underwriting workbench dashboard (/admin and /insurer): UND-001 dashboard KPIs, UND-004 my assigned cases and
 * UND-020 performance & SLA. Read-only; opened by the case read permissions (underwriting.decide or
 * carrier.referrals.read, as UnderwritingCasePolicy). Tenant-scoped; own carrier only in a portal (PortalScope).
 */
final class UnderwritingDashboard extends Page
{
    protected static string|\BackedEnum|null $navigationIcon = 'lucide-gauge';

    protected static ?string $slug = 'underwriting-dashboard';

    protected static ?int $navigationSort = 50;

    protected string $view = 'filament.shared.pages.uw-dashboard';

    #[Locked]
    public ?string $tenantId = null;

    public static function canAccess(): bool
    {
        $u = auth()->user();

        return $u !== null && rescue(fn () => app(TenantContext::class)->id(), null, false) !== null
            && (PortalAuthorization::allowsRead($u, 'underwriting.decide') || PortalAuthorization::allowsRead($u, 'carrier.referrals.read'));
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Underwriting';
    }

    public static function getNavigationLabel(): string
    {
        return __('uw_workbench.dashboard');
    }

    public function getTitle(): string
    {
        return __('uw_workbench.dashboard');
    }

    public function mount(): void
    {
        $this->tenantId = rescue(fn () => app(TenantContext::class)->id(), null, false);
    }

    public function hydrate(): void
    {
        if ($this->tenantId !== null) {
            app(TenantContext::class)->set($this->tenantId);
        }
    }

    /** @return array{dashboard: array, performance: array, caseUrl: \Closure} */
    public function data(): array
    {
        $panel = Filament::getCurrentPanel()?->getId() ?? 'admin';

        return [
            'dashboard' => UnderwritingWorkbenchQuery::dashboard((string) $this->tenantId, auth()->user()),
            'performance' => UnderwritingWorkbenchQuery::performance((string) $this->tenantId),
            'caseUrl' => fn (string $id) => UnderwritingCaseResource::getUrl('view', ['record' => $id], panel: $panel),
            'listUrl' => UnderwritingCaseResource::getUrl('index', ['tab' => 'mine'], panel: $panel),
        ];
    }
}
