<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\BrokerScreens;

use App\Filament\Shared\Actions\PaymentActions;
use App\Models\PaymentIntentRecord;
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * BRK-049 Failed Payments (WF-085, WF-025): failed or expired premium payments of the book, with the provider's
 * failure reason (last attempt). Retry runs PaymentRetryService::retry (same as POST payments/{p}/retry, own-book
 * proposal); a new request to the customer is available from Pending Payments.
 */
final class FailedPaymentsPage extends PaymentScreen
{
    protected static string $key = 'failed_payments';

    protected static ?string $slug = 'failed-payments';

    protected static string|BackedEnum|null $navigationIcon = 'lucide-circle-x';

    protected static ?int $navigationSort = 62;

    protected function query(): Builder
    {
        return $this->payments(['FAILED', 'EXPIRED']);
    }

    protected function columns(): array
    {
        return [
            ...parent::columns(),
            TextColumn::make('failure')->label(self::col('reason'))->wrap()->state(function (PaymentIntentRecord $record): string {
                $a = DB::table('payment_attempts')->where('payment_intent_id', $record->id)->orderByDesc('created_at')->first();
                $snap = (array) ($record->provider_snapshot ?? []);

                return (string) ($a->failure_code ??$snap['reason'] ?? $snap['status_reason'] ?? '—');
            }),
        ];
    }

    protected function recordActions(): array
    {
        return [PaymentActions::retry()];
    }
}
