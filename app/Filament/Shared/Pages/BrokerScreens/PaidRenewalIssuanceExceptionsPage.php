<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages\BrokerScreens;

use BackedEnum;

/**
 * BRK-068 Paid Renewal Issuance Exceptions (WF-087, WF-043): renewals of the book that were paid but whose successor
 * policy was not issued (issuance_exceptions linked to a renewal case). Same details and actions as the failed
 * issuance queue (IssuanceQueueService retry / escalate / resolve).
 */
final class PaidRenewalIssuanceExceptionsPage extends FailedIssuanceQueuePage
{
    protected static string $key = 'renewal_issuance';

    protected static ?string $slug = 'paid-renewal-issuance-exceptions';

    protected static string|BackedEnum|null $navigationIcon = 'lucide-refresh-ccw-dot';

    protected static ?int $navigationSort = 77;

    protected function renewals(): bool
    {
        return true;
    }
}
