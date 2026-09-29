<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\Broker;

use App\Filament\Shared\Pages\BrokerCustomersPage;
use App\Models\TenantCustomer;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Tables\Table;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Url;

/**
 * BRK-016 Customer Activity Timeline: the dated business events of the caller's customers (quotes, proposals,
 * payments, policies, claims, KYC), newest first — for one customer (?customer=) or the whole book. Read =
 * broker.portal.read (GET mobile/broker/clients/{c}); customers = BrokerCustomersPage::customers (BookScope), so a
 * customer outside the book is refused (404) and its events are never read.
 */
final class CustomerTimelinePage extends BrokerScreen
{
    protected static ?string $slug = 'customer-timeline';

    protected static string|BackedEnum|null $navigationIcon = 'lucide-history';

    protected static ?int $navigationSort = 13;

    protected static string $screen = 'customer_timeline';

    #[Url]
    public ?string $customer = null;

    public function mount(): void
    {
        parent::mount();
        if ($this->customer !== null) {
            abort_unless($this->selected() !== null, 404);
        }
    }

    private function selected(): ?TenantCustomer
    {
        return $this->customer && \Illuminate\Support\Str::isUuid($this->customer)
            ? BrokerCustomersPage::customers($this->tenantId)->whereKey($this->customer)->first() : null;
    }

    public function getSubheading(): ?string
    {
        return ($c = $this->selected()) ? $c->party?->display_name : parent::getSubheading();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('chooseCustomer')->label(__('broker_screens_a.customer_timeline.choose'))->icon('lucide-user-search')
                ->schema([Select::make('customer')->label(__('broker_screens_a.columns.customer'))->required()->searchable()
                    ->getSearchResultsUsing(fn (string $search) => BrokerCustomersPage::customers($this->tenantId)
                        ->whereHas('party', fn ($q) => $q->where('display_name', 'ilike', "%{$search}%"))->limit(20)->get()
                        ->mapWithKeys(fn (TenantCustomer $c) => [$c->id => $c->party?->display_name ?? $c->id])->all())
                    // R6 2026-09-29: a searchable Select needs a label resolver, or submitting it throws (Filament validates the option).
                    ->getOptionLabelUsing(fn ($value) => BrokerCustomersPage::customers($this->tenantId)->whereKey($value)->first()?->party?->display_name)])
                ->action(fn (array $data) => $this->redirect(self::getUrl(['customer' => $data['customer']]))),
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    private function events(): Collection
    {
        if ($this->tenantId === null) {
            return collect();
        }
        $c = $this->selected();
        $parties = $c ? DB::query()->selectRaw('?::uuid as party_id', [$c->party_id]) : BrokerCustomersPage::customers($this->tenantId)->toBase()->select('party_id');
        $t = $this->tenantId;
        $name = fn (string $table) => DB::table($table)->where("{$table}.tenant_id", $t)->whereIn("{$table}.party_id", $parties)->leftJoin('parties', 'parties.id', '=', "{$table}.party_id");
        $row = fn (string $type, $at, ?string $ref, ?string $status, ?string $who, ?int $amount = null) => ['type' => $type, 'at' => $at, 'reference' => $ref, 'status' => $status, 'customer' => $who,
            'amount' => $amount !== null ? \App\Application\WebExperiences\Money::format($amount, 'XAF') : null];

        return collect()
            ->concat($name('quotes')->latest('quotes.created_at')->limit(100)->get(['quotes.id', 'quotes.quote_number', 'quotes.lifecycle_state', 'quotes.created_at', 'parties.display_name'])
                ->map(fn ($r) => $row('quote', $r->created_at, $r->quote_number, $r->lifecycle_state, $r->display_name)))
            ->concat($name('proposals')->latest('proposals.created_at')->limit(100)->get(['proposals.status', 'proposals.created_at', 'proposals.id', 'parties.display_name'])
                ->map(fn ($r) => $row('proposal', $r->created_at, substr((string) $r->id, 0, 8), $r->status, $r->display_name)))
            ->concat($name('policies')->whereNotNull('policies.issued_at')->latest('policies.issued_at')->limit(100)->get(['policies.policy_number', 'policies.status', 'policies.issued_at', 'policies.premium_minor', 'parties.display_name'])
                ->map(fn ($r) => $row('policy', $r->issued_at, $r->policy_number, $r->status, $r->display_name, (int) $r->premium_minor)))
            ->concat(DB::table('claims')->join('policies', 'policies.id', '=', 'claims.policy_id')->leftJoin('parties', 'parties.id', '=', 'policies.party_id')
                ->where('claims.tenant_id', $t)->whereIn('policies.party_id', $parties)->latest('claims.created_at')->limit(100)
                ->get(['claims.claim_number', 'claims.status', 'claims.created_at', 'parties.display_name'])
                ->map(fn ($r) => $row('claim', $r->created_at, $r->claim_number, $r->status, $r->display_name)))
            ->concat(DB::table('payment_intents')->join('proposals', 'proposals.id', '=', 'payment_intents.proposal_id')->leftJoin('parties', 'parties.id', '=', 'proposals.party_id')
                ->where('payment_intents.tenant_id', $t)->whereIn('proposals.party_id', $parties)->latest('payment_intents.created_at')->limit(100)
                ->get(['payment_intents.id', 'payment_intents.status', 'payment_intents.amount_minor', 'payment_intents.created_at', 'parties.display_name'])
                ->map(fn ($r) => $row('payment', $r->created_at, substr((string) $r->id, 0, 8), $r->status, $r->display_name, (int) $r->amount_minor)))
            ->concat($name('kyc_submissions')->latest('kyc_submissions.created_at')->limit(100)->get(['kyc_submissions.status', 'kyc_submissions.kyc_level', 'kyc_submissions.created_at', 'parties.display_name'])
                ->map(fn ($r) => $row('kyc', $r->created_at, $r->kyc_level, $r->status, $r->display_name)))
            ->sortByDesc(fn ($e) => (string) $e['at'])->take(200)->values()
            ->map(fn ($e, $i) => $e + ['id' => (string) $i]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (): array => self::keyed($this->events()))
            ->columns([
                self::col('at', 'date')->dateTime(),
                self::col('type', 'event')->badge()->formatStateUsing(fn (?string $state) => __('broker_screens_a.customer_timeline.types.'.$state)),
                self::col('reference'), self::col('customer'),
                self::col('status')->badge()->formatStateUsing(fn (?string $state) => self::code('status', $state)),
                self::col('amount'),
            ])
            ->emptyStateHeading(__('broker_screens_a.empty'));
    }
}
