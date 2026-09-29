<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\AdminScreens;

use BackedEnum;

/** CMP-012 Payment risk review — approval requests of these action codes (see ExceptionReviewQueue). */
final class PaymentRiskReview extends ExceptionReviewQueue
{
    protected const ACTION_CODES = ['payment.manual_confirmation', 'payment.reallocation', 'refund.approve', 'write_off.approve'];

    protected static string|BackedEnum|null $navigationIcon = 'lucide-banknote';

    protected static ?int $navigationSort = 10;

    protected static ?string $slug = 'approvals/payment-risk';

    protected static string $screen = 'payment_risk_review';
}
