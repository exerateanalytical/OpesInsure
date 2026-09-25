<?php

declare(strict_types=1);

namespace App\Application\Ledger\Posting;

use App\Application\Ledger\FinancialPostingService;
use Illuminate\Support\Str;

/**
 * REQ-ACC-001 call-site hook: posts a business event to the GL after the business
 * fact is committed. Idempotent per (event, reference) via FinancialPostingService.
 * A posting problem (missing mapping, closed period...) is reported, never undoes the
 * business operation; the journal can be re-posted later with the same reference.
 */
final class AccountingEventPoster
{
    public function __construct(private readonly FinancialPostingService $posting) {}

    public function record(?string $tenantId, string $event, string $referenceId, int $amountMinor, string $currency, ?string $correlationId = null): ?string
    {
        if ($amountMinor <= 0) {
            return null;
        }
        try {
            return $this->posting->post($tenantId, $event, $referenceId, $amountMinor, strtoupper($currency), $correlationId ?? (string) Str::uuid());
        } catch (\Throwable $e) {
            report($e);

            return null;
        }
    }
}
