<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages;

use App\Application\Search\GlobalSearchService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Claim;
use App\Models\Document;
use App\Models\Policy;
use App\Models\Quote;
use App\Models\RiskAsset;
use App\Models\TenantCustomer;
use App\Models\User;
use BackedEnum;
use Filament\Facades\Filament;
use Filament\Pages\Page;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Throwable;

/**
 * SHR-001 Global Search / SHR-002 Advanced Search for the staff panels (admin, insurer, broker, …). Runs the SAME
 * GlobalSearchService as GET /api/v1/search — no search logic here — so RBAC (entity read permission) and data scope
 * (tenant always, then OWN / ASSIGNED / BRANCH / CARRIER / TENANT via DataScopeResolver) apply exactly as for the API.
 * Advanced = entity-type filter + per-type result count. The tenant is captured at mount and re-applied on every
 * Livewire round-trip; a hit links to the panel's own resource for that model when the panel has one.
 */
class GlobalSearchPage extends Page
{
    protected string $view = 'filament.shared.pages.global-search';

    protected static string|BackedEnum|null $navigationIcon = 'lucide-search';

    protected static ?int $navigationSort = 2;

    protected static ?string $slug = 'search';

    private const MODELS = [
        'customers' => TenantCustomer::class, 'policies' => Policy::class, 'claims' => Claim::class, 'quotes' => Quote::class,
        'documents' => Document::class, 'vehicles' => RiskAsset::class, 'risk_assets' => RiskAsset::class,
    ];

    /** Read permissions GlobalSearchService checks per type; any one opens the screen. */
    private const PERMISSIONS = ['customers.read', 'policies.read', 'claims.view', 'quotes.read', 'documents.read', 'risk_assets.read'];

    #[Locked]
    public ?string $tenantId = null;

    #[Url]
    public string $q = '';

    /** @var list<string> */
    #[Url]
    public array $types = [];

    public int $limit = 10;

    /** @var array<string, mixed>|null */
    public ?array $result = null;

    public ?string $error = null;

    public static function canAccess(): bool
    {
        $u = auth()->user();

        return $u instanceof User && collect(self::PERMISSIONS)->contains(fn ($p) => (bool) rescue(fn () => $u->hasPermission($p), false, false));
    }

    public static function getNavigationLabel(): string
    {
        return __('launch_customer.staff_search.title');
    }

    public function getTitle(): string
    {
        return __('launch_customer.staff_search.title');
    }

    public function getSubheading(): ?string
    {
        return __('launch_customer.staff_search.lede');
    }

    public function mount(): void
    {
        $this->tenantId = rescue(fn () => app(TenantContext::class)->id(), null, false);
        if (mb_strlen(trim($this->q)) >= 2) {
            $this->search();
        }
    }

    public function hydrate(): void
    {
        if ($this->tenantId !== null) {
            app(TenantContext::class)->set($this->tenantId);
        }
    }

    public function search(): void
    {
        $this->error = null;
        $this->result = null;
        $q = trim($this->q);
        if (mb_strlen($q) < 2 || mb_strlen($q) > 100) {
            $this->error = __('launch_customer.search.too_short');

            return;
        }
        if ($this->tenantId === null) {
            $this->result = ['results' => [], 'counts' => [], 'searched' => []];

            return;
        }
        $types = array_values(array_intersect(GlobalSearchService::TYPES, $this->types));
        try {
            $this->result = app(GlobalSearchService::class)->search(auth()->user(), $q, $types, max(1, min(20, $this->limit)));
        } catch (Throwable $e) {
            report($e);
            $this->error = __('launch_customer.search.failed');
        }
    }

    /** @return array<string, string> */
    public function typeOptions(): array
    {
        return collect(GlobalSearchService::TYPES)->mapWithKeys(fn ($t) => [$t => __('launch_customer.search.types.'.$t)])->all();
    }

    /** URL of the current panel's resource for the hit's model (view page when the panel has one), else null. */
    public function hitUrl(array $hit): ?string
    {
        $model = self::MODELS[$hit['type'] ?? ''] ?? null;
        $resource = $model ? rescue(fn () => Filament::getCurrentPanel()?->getModelResource($model), null, false) : null;
        if (! $resource) {
            return null;
        }

        return rescue(function () use ($resource, $hit) {
            foreach (['view', 'edit'] as $page) {
                if ($resource::hasPage($page)) { // the resource page authorises the record itself
                    return $resource::getUrl($page, ['record' => $hit['id']]);
                }
            }

            return $resource::hasPage('index') ? $resource::getUrl('index') : null;
        }, null, false);
    }
}
