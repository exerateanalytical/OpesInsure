<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\BrokerScreens;

use App\Application\WebExperiences\Money;
use App\Filament\Shared\Actions\PaymentActions;
use BackedEnum;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;

/**
 * BRK-045 Payment Dashboard (WF-026): premium collection of the caller's book — collected this month, pending, failed /
 * expired, refunds in progress — and every premium payment with the payment API's actions (retry a failed payment,
 * PaymentRetryService, same as POST payments/{p}/retry; request a refund, refund.request).
 */
final class PaymentDashboardPage extends PaymentScreen
{
    protected static string $key = 'payment_dashboard';

    protected static ?string $slug = 'payment-dashboard';

    protected static string|BackedEnum|null $navigationIcon = 'lucide-wallet';

    protected static ?int $navigationSort = 60;

    public const PENDING = ['CREATED', 'PENDING_CUSTOMER', 'PROCESSING', 'AWAITING_TRANSFER'];

    protected function query(): Builder
    {
        return $this->payments();
    }

    protected function filters(): array
    {
        return [SelectFilter::make('status')->label(self::col('status'))
            ->options(fn () => $this->payments()->reorder()->distinct()->pluck('payment_intents.status', 'payment_intents.status')->all())];
    }

    protected function recordActions(): array
    {
        return [PaymentActions::retry(), PaymentActions::requestRefund()];
    }

    public function kpis(): array
    {
        $sum = fn (array $statuses, bool $month = false) => (int) $this->payments($statuses)->reorder()
            ->when($month, fn ($q) => $q->where('payment_intents.updated_at', '>=', now()->startOfMonth()))->sum('payment_intents.amount_minor');
        $count = fn (array $statuses) => $this->payments($statuses)->reorder()->count();

        return [
            self::kpi('collected_month', self::fcfa($sum(['SUCCEEDED'], true)), 'success'),
            self::kpi('payments_pending', $count(self::PENDING), 'warning'),
            self::kpi('pending_amount', self::fcfa($sum(self::PENDING))),
            self::kpi('payments_failed', $count(['FAILED', 'EXPIRED']), 'danger'),
            self::kpi('refunds_in_progress', $count(['REFUND_PENDING', 'CHARGEBACK_OPEN'])),
        ];
    }

    public static function fcfa(int $minor): string
    {
        return rescue(fn () => Money::display($minor, 'XAF'), number_format($minor, 0, ',', ' ').' FCFA', false);
    }
}
