<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages;

use App\Application\Partners\BookScope;
use App\Application\WebExperiences\{PortalAuthorization, PortalScope};
use App\Domain\Tenancy\TenantContext;
use App\Filament\Shared\Actions\{PartyActions, QuoteActions};
use App\Models\{TenantCustomer, User};
use BackedEnum;
use Filament\Pages\Page;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Attributes\Locked;

/**
 * Owner decision 2026-09-29 (portals writable, docs/spec/PORTAL_WRITE_RULES.md): the broker's customers in /broker,
 * the start of the sales journey (new customer -> quote -> proposal -> premium -> issued policy). Rows are the portal
 * tenant's customers inside the caller's book (BookScope::parties, the same rule PortalScope::narrowTable applies to
 * quotes / proposals / policies). Every write is a shared workflow action calling the API's service:
 *   new customer   PartyActions::registerBrokerClient   (POST mobile/broker/clients, crm.leads.manage)
 *   new quote      QuoteActions::createForCustomer      (POST quotes + POST quotes/{q}/rate, quotes.rate)
 *   KYC            PartyActions::kycOpen / kycSubmit    (POST kyc/parties/{p}/submissions ..., kyc.manage)
 */
final class BrokerCustomersPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected string $view = 'filament.shared.pages.health-queue';

    protected static ?string $slug = 'customers';

    protected static string|BackedEnum|null $navigationIcon = 'lucide-users';

    protected static ?int $navigationSort = 40;

    #[Locked]
    public ?string $tenantId = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return PortalScope::panel() === 'broker' && $user instanceof User && PortalAuthorization::allowsRead($user, 'broker.portal.read');
    }

    public static function getNavigationGroup(): ?string
    {
        return 'Sales workspace'; // same group key as QuoteResource / ProposalResource (labelled through lang/*/navigation.php)
    }

    public static function getNavigationLabel(): string
    {
        return __('broker_portal_sales.customers.nav');
    }

    public function getTitle(): string
    {
        return __('broker_portal_sales.customers.title');
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

    /** The caller's customers in the portal tenant (nothing without a tenant). */
    public static function customers(?string $tenantId): Builder
    {
        $q = TenantCustomer::query()->with('party')->where('tenant_id', $tenantId ?? '00000000-0000-0000-0000-000000000000');
        $user = auth()->user();
        $parties = $user instanceof User ? app(BookScope::class)->parties($user) : null;

        return $parties === null ? $q : $q->whereIn('party_id', $parties);
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => self::customers($this->tenantId)->latest('created_at'))
            ->columns([
                TextColumn::make('customer_number')->label(__('broker_portal_sales.customers.number'))->searchable()->copyable()->placeholder('—'),
                TextColumn::make('party.display_name')->label(__('broker_portal_sales.customers.name'))->searchable(),
                TextColumn::make('status')->label(__('broker_portal_sales.customers.status'))->badge(),
                TextColumn::make('created_at')->label(__('broker_portal_sales.customers.since'))->date(),
            ])
            ->headerActions([PartyActions::registerBrokerClient()])
            ->recordActions([QuoteActions::createForCustomer(), PartyActions::kycOpen(), PartyActions::kycSubmit()])
            ->emptyStateHeading(__('broker_portal_sales.customers.empty_t'))
            ->emptyStateDescription(__('broker_portal_sales.customers.empty_d'))
            ->emptyStateIcon('lucide-users');
    }
}
