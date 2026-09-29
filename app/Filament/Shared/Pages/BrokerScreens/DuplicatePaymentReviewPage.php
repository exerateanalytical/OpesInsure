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
 * BRK-051 Duplicate Payment Review (WF-086, WF-063): successful premium payments of the book for a proposal that was
 * paid more than once. The surplus is returned through a refund request (FinancialCaseService::requestRefund,
 * refund.request — the same service as POST payments/{p}/refunds); the refund is then approved in the refund queue.
 */
final class DuplicatePaymentReviewPage extends PaymentScreen
{
    protected static string $key = 'duplicate_payments';

    protected static ?string $slug = 'duplicate-payment-review';

    protected static string|BackedEnum|null $navigationIcon = 'lucide-copy';

    protected static ?int $navigationSort = 63;

    protected function query(): Builder
    {
        $dup = DB::table('payment_intents')->where('status', 'SUCCEEDED')->whereNotNull('proposal_id')
            ->groupBy('proposal_id')->havingRaw('count(*) > 1')->select('proposal_id');

        return $this->payments(['SUCCEEDED', 'REFUND_PENDING', 'REFUNDED'])->whereIn('payment_intents.proposal_id', $dup);
    }

    protected function columns(): array
    {
        return [
            ...parent::columns(),
            TextColumn::make('paid_count')->label(self::col('paid_count'))->alignEnd()
                ->state(fn (PaymentIntentRecord $record) => DB::table('payment_intents')->where(['proposal_id' => $record->proposal_id, 'status' => 'SUCCEEDED'])->count()),
        ];
    }

    protected function recordActions(): array
    {
        return [PaymentActions::requestRefund()];
    }
}
