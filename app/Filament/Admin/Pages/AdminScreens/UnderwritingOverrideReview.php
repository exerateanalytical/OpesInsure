<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\AdminScreens;

use BackedEnum;

/** CMP-014 Underwriting override review — approval requests of these action codes (see ExceptionReviewQueue). */
final class UnderwritingOverrideReview extends ExceptionReviewQueue
{
    protected const ACTION_CODES = ['underwriting.override', 'engine.override'];

    protected static string|BackedEnum|null $navigationIcon = 'lucide-shield-alert';

    protected static ?int $navigationSort = 12;

    protected static ?string $slug = 'approvals/underwriting-overrides';

    protected static string $screen = 'underwriting_override_review';
}
