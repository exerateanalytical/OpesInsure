<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\Broker;

use App\Application\WebExperiences\BrokerBookMetrics;
use App\Filament\Shared\Actions\{PartyActions, QuoteActions};
use App\Filament\Shared\Pages\BrokerCustomersPage;
use App\Models\TenantCustomer;
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/**
 * BRK-004 Customer Dashboard: KPIs of the caller's customer book (BrokerBookMetrics::customers) and the book itself
 * with policy / KYC / renewal state per customer. Same read as GET mobile/broker/clients (broker.portal.read); rows =
 * BrokerCustomersPage::customers (BookScope). Actions: quote, KYC open / submit (shared actions, API permissions).
 */
final class CustomerDashboardPage extends BrokerScreen
{
    protected static ?string $slug = 'customer-dashboard';

    protected static string|BackedEnum|null $navigationIcon = 'lucide-contact';

    protected static ?int $navigationSort = 1;

    protected static string $screen = 'customer_dashboard';

    protected static string $group = 'dashboards';

    public function getStats(): array
    {
        return $this->tenantId ? app(BrokerBookMetrics::class)->customers($this->tenantId) : [];
    }

    public function table(Table $table): Table
    {
        // R4: the per-customer figures are correlated sub-selects of the list query (one query per page, not three per row).
        $policies = fn () => DB::table('policies')->whereColumn('policies.tenant_id', 'tenant_customers.tenant_id')->whereColumn('policies.party_id', 'tenant_customers.party_id');

        return $table
            ->query(fn () => BrokerCustomersPage::customers($this->tenantId)->latest('created_at')->select('tenant_customers.*')
                ->selectSub($policies()->where('policies.status', 'ACTIVE')->selectRaw('count(*)'), 'r4_policies_active')
                ->selectSub($policies()->whereIn('policies.status', ['ACTIVE', 'EXPIRING'])->selectRaw('min(policies.coverage_ends_at)'), 'r4_renewal_due')
                ->selectSub(DB::table('kyc_submissions')->whereColumn('kyc_submissions.tenant_id', 'tenant_customers.tenant_id')->whereColumn('kyc_submissions.party_id', 'tenant_customers.party_id')
                    ->whereNull('superseded_by_submission_id')->orderByDesc('kyc_submissions.created_at')->limit(1)->select('kyc_submissions.status'), 'r4_kyc_status'))
            ->columns([
                self::col('customer_number')->searchable(),
                self::col('party.display_name', 'customer')->searchable(),
                TextColumn::make('policies_active')->label(__('broker_screens_a.columns.policies_active'))
                    ->state(fn (TenantCustomer $r) => (int) $r->getAttribute('r4_policies_active')),
                TextColumn::make('renewal_due')->label(__('broker_screens_a.columns.renewal_due'))->date()->placeholder('—')
                    ->state(fn (TenantCustomer $r) => $r->getAttribute('r4_renewal_due')),
                TextColumn::make('kyc_status')->label(__('broker_screens_a.columns.kyc_status'))->badge()->placeholder('—')
                    ->state(fn (TenantCustomer $r) => self::code('kyc_status', $r->getAttribute('r4_kyc_status'))),
                self::col('created_at', 'since')->date(),
            ])
            ->recordUrl(fn (TenantCustomer $r) => CustomerTimelinePage::getUrl(['customer' => $r->getKey()]))
            ->recordActions([QuoteActions::createForCustomer(), PartyActions::kycOpen(), PartyActions::kycSubmit()])
            ->emptyStateHeading(__('broker_screens_a.empty'));
    }
}
