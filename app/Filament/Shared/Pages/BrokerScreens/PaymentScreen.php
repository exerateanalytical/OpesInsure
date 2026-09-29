<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\BrokerScreens;

use App\Models\PaymentIntentRecord;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;

/** Premium payments of the caller's book (portal tenant + PortalScope::narrowTable('payment_intents'), i.e. own proposals). */
abstract class PaymentScreen extends BrokerScreen
{
    protected static ?string $group = 'Financial operations';

    /** @param list<string> $statuses */
    protected function payments(array $statuses = []): Builder
    {
        $q = $this->book(PaymentIntentRecord::class, 'payment_intents')->with('proposal.party');

        return $statuses === [] ? $q : $q->whereIn('payment_intents.status', $statuses);
    }

    protected function columns(): array
    {
        return [
            self::text('proposal.proposal_number', 'proposal')->searchable(),
            self::text('proposal.party.display_name', 'customer'),
            self::money('amount_minor', 'amount'),
            self::text('provider', 'provider'),
            self::text('provider_reference', 'provider_reference')->searchable()->copyable(),
            TextColumn::make('payer')->label(self::col('payer'))
                ->state(fn (PaymentIntentRecord $record) => self::mask((string) $record->payer_phone_e164)),
            self::status(),
            self::date('created_at', 'created_at'),
        ];
    }

    /** +2376XXXXX89 style: the payer's number is personal data, the last digits are enough to reconcile. */
    public static function mask(string $phone): string
    {
        return $phone === '' ? '—' : substr($phone, 0, 5).str_repeat('•', max(0, strlen($phone) - 7)).substr($phone, -2);
    }
}
