<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\AdminScreens;

use BackedEnum;

/** CMP-015 Commission exception review — approval requests of these action codes (see ExceptionReviewQueue). */
final class CommissionExceptionReview extends ExceptionReviewQueue
{
    protected const ACTION_CODES = ['commission.adjust', 'commission_rule.approve', 'partner_statement.approve', 'payout.approve'];

    protected static string|BackedEnum|null $navigationIcon = 'lucide-percent';

    protected static ?int $navigationSort = 13;

    protected static ?string $slug = 'approvals/commission-exceptions';

    protected static string $screen = 'commission_exception_review';
}
