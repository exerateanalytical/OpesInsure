<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\Broker;

use App\Application\WebExperiences\{PortalAuthorization, PortalScope};
use App\Filament\Shared\Actions\CrmLeadActions;
use App\Models\{Partner, User};
use BackedEnum;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/**
 * BRK-018 Customer Portfolio Transfer: move the book (customer attributions) between partners of the brokerage.
 *  - history = GET crm/portfolio-transfers (attribution.read), portal tenant, and for a book-scoped caller only the
 *    transfers from / to the caller's own partner (PortalScope::brokerPartnerId);
 *  - transfer = CrmLeadActions::portfolioTransfer (POST crm/portfolio-transfers/preview + POST crm/portfolio-transfers,
 *    attribution.transfer, preview hash guard).
 * The screen opens for either permission; the history is listed only to attribution.read holders.
 */
final class PortfolioTransferPage extends BrokerScreen
{
    protected static ?string $slug = 'portfolio-transfers';

    protected static string|BackedEnum|null $navigationIcon = 'lucide-arrow-left-right';

    protected static ?int $navigationSort = 15;

    protected static array $permissions = ['attribution.read', 'attribution.transfer'];

    protected static string $screen = 'portfolio_transfer';

    private function rows(): array
    {
        $user = auth()->user();
        if (! $this->tenantId || ! $user instanceof User || ! PortalAuthorization::allowsRead($user, 'attribution.read')) {
            return [];
        }
        $partner = PortalScope::brokerPartnerId();
        $name = fn (?string $id) => $id ? (Partner::with('party')->find($id)?->party?->display_name ?? $id) : null;

        return DB::table('portfolio_transfers')->where('tenant_id', $this->tenantId)
            ->when($partner !== null, fn ($q) => $q->where(fn ($w) => $w->where('from_partner_id', $partner)->orWhere('to_partner_id', $partner)))
            ->orderByDesc('executed_at')->limit(200)->get()
            ->map(fn ($t) => ['id' => $t->id, 'from' => $name($t->from_partner_id), 'to' => $name($t->to_partner_id), 'customer_count' => $t->customer_count,
                'policy_count' => $t->policy_count, 'reason_code' => $t->reason_code, 'status' => $t->status, 'executed_at' => $t->executed_at])->all();
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => self::keyed($this->rows()))
            ->columns([
                self::col('executed_at')->dateTime(), self::col('from', 'from_partner'), self::col('to', 'to_partner'),
                self::col('customer_count', 'customers'), self::col('policy_count', 'policies'),
                self::col('reason_code', 'reason'), self::col('status')->badge(),
            ])
            ->headerActions([CrmLeadActions::portfolioTransfer()])
            ->emptyStateHeading(__('broker_screens_a.empty'));
    }
}
