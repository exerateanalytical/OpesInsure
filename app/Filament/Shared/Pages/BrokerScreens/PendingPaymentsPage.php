<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\BrokerScreens;

use App\Filament\Admin\Resources\Proposals\ProposalResource;
use App\Filament\Shared\Actions\PaymentActions;
use App\Models\Proposal;
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\{Builder, Model};
use Illuminate\Support\Facades\DB;

/**
 * BRK-048 Pending Payments (WF-022): approved proposals of the book waiting for the premium (PAYMENT_PENDING, no
 * SUCCEEDED payment), with the last payment attempt's status. The broker sends the mobile-money request to the
 * customer (PaymentActions::requestPremium, PaymentInitiationService — same as the broker premium API) and opens the
 * receipt once paid.
 */
final class PendingPaymentsPage extends PaymentScreen
{
    protected static string $key = 'pending_payments';

    protected static ?string $slug = 'pending-payments';

    protected static string|BackedEnum|null $navigationIcon = 'lucide-hourglass';

    protected static ?int $navigationSort = 61;

    protected function query(): Builder
    {
        return $this->book(Proposal::class, 'proposals')->with('party')->where('proposals.status', 'PAYMENT_PENDING')
            ->whereNotIn('proposals.id', DB::table('payment_intents')->where('status', 'SUCCEEDED')->select('proposal_id'));
    }

    protected function defaultSort(): string
    {
        return 'updated_at';
    }

    protected function columns(): array
    {
        $last = fn (Proposal $p) => DB::table('payment_intents')->where('proposal_id', $p->id)->orderByDesc('created_at')->first();

        return [
            self::text('proposal_number', 'proposal')->searchable()->copyable(),
            self::text('party.display_name', 'customer')->searchable(),
            TextColumn::make('premium')->label(self::col('amount'))->alignEnd()->state(function (Proposal $record): string {
                $o = DB::table('quote_offers')->where('id', $record->quote_offer_id)->first(['total_minor', 'currency']);

                return $o === null ? '—' : \App\Application\WebExperiences\Money::display((int) $o->total_minor, $o->currency ?: 'XAF');
            }),
            TextColumn::make('last_attempt')->label(self::col('last_attempt'))->badge()
                ->state(fn (Proposal $record) => ($i = $last($record)) ? \App\Filament\Shared\Columns::humanise($i->status) : __('broker_screens_b.pending_payments.none')),
            self::date('decided_at', 'decided_at'),
        ];
    }

    protected function recordActions(): array
    {
        return [PaymentActions::requestPremium(), PaymentActions::premiumReceipt()];
    }

    protected function recordLink(Model $record): ?string
    {
        return self::viewUrl(ProposalResource::class, $record);
    }
}
