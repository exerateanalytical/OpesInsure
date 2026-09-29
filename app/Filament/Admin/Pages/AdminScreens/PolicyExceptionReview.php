<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\AdminScreens;

use BackedEnum;

/** CMP-013 Policy exception review — approval requests of these action codes (see ExceptionReviewQueue). */
final class PolicyExceptionReview extends ExceptionReviewQueue
{
    protected const ACTION_CODES = ['policy.cancellation', 'policy.endorsement.approve', 'quote.exceptional', 'premium.override'];

    protected static string|BackedEnum|null $navigationIcon = 'lucide-file-warning';

    protected static ?int $navigationSort = 11;

    protected static ?string $slug = 'approvals/policy-exceptions';

    protected static string $screen = 'policy_exception_review';
}
