<?php

declare(strict_types=1);

namespace App\Application\Finance\Allocations;

/**
 * Seam onto REQ-OBL-001 financial obligations (owned by agent 9-1).
 * The allocation engine never depends on the obligation tables directly.
 */
interface ObligationGateway
{
    /**
     * Open receivable obligations to allocate against.
     *
     * @param  list<string>  $ids  explicit obligation ids (may be empty)
     * @return list<array{id: string, type: string, currency: string, outstanding_minor: int, due_at: ?string}>
     */
    public function openReceivables(string $tenantId, array $ids, ?string $policyId): array;

    /** Settle (positive amount) or undo a settlement (negative amount, on reversal). */
    public function settle(string $obligationId, int $amountMinor, string $reference): void;
}
