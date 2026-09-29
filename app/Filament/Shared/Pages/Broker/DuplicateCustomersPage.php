<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\Broker;

use App\Filament\Shared\Actions\PartyActions;
use App\Filament\Shared\Pages\BrokerCustomersPage;
use App\Models\TenantCustomer;
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/**
 * BRK-017 Duplicate Customer Review: the caller's customers with an OPEN probable-match candidate
 * (GET party-match-candidates, parties.match.review). Actions = PartyActions stewardship (POST parties/{p}/match-scan,
 * POST party-match-candidates/{c}/dismiss, POST party-merges) with the API permissions; the merge itself stays a
 * maker-checker approval (parties.merge.approve), outside the portal.
 */
final class DuplicateCustomersPage extends BrokerScreen
{
    protected static ?string $slug = 'duplicate-customers';

    protected static string|BackedEnum|null $navigationIcon = 'lucide-copy';

    protected static ?int $navigationSort = 14;

    protected static array $permissions = ['parties.match.review'];

    protected static string $screen = 'duplicates';

    /**
     * OPEN candidates visible to the caller: BOTH parties must be customers of this tenant inside the caller's book
     * (BrokerCustomersPage::customers = tenant filter + BookScope), so neither the list nor the counts reveal matches
     * against parties outside the book or another tenant.
     */
    private function candidates()
    {
        $book = fn () => BrokerCustomersPage::customers($this->tenantId)->select('party_id')->toBase();

        return DB::table('entity_match_candidates')->where('status', 'OPEN')
            ->whereIn('party_a_id', $book())->whereIn('party_b_id', $book());
    }

    private function forParty(string $partyId)
    {
        return $this->candidates()->where(fn ($w) => $w->where('party_a_id', $partyId)->orWhere('party_b_id', $partyId));
    }

    public function table(Table $table): Table
    {
        return $table
            ->query(fn () => BrokerCustomersPage::customers($this->tenantId)->whereExists($this->candidates()->where(fn ($w) => $w->whereColumn('party_a_id', 'tenant_customers.party_id')->orWhereColumn('party_b_id', 'tenant_customers.party_id'))->select(DB::raw(1))))
            ->columns([
                self::col('customer_number')->searchable(),
                self::col('party.display_name', 'customer')->searchable(),
                TextColumn::make('candidates')->label(__('broker_screens_a.columns.candidates'))
                    ->state(fn (TenantCustomer $r) => $this->forParty((string) $r->party_id)->count()),
                TextColumn::make('best_score')->label(__('broker_screens_a.columns.best_score'))
                    ->state(fn (TenantCustomer $r) => $this->forParty((string) $r->party_id)->max('score')),
            ])
            ->recordActions([PartyActions::matchScan(), PartyActions::matchDismiss(), PartyActions::requestMerge()])
            ->emptyStateHeading(__('broker_screens_a.duplicates.empty'));
    }
}
