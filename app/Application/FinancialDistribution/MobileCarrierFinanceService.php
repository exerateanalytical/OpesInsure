<?php

declare(strict_types=1);

namespace App\Application\FinancialDistribution;

use App\Models\Bordereau;
use App\Models\SettlementBatch;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Carrier-side "settlements & bordereaux" read surface — read-only.
 *
 * SettlementBatch and Bordereau both carry tenant_id but no partner_id
 * (see the batch report: they are tenant-wide financial documents
 * prepared by whichever tenant calls the Wave6 FinancialDistribution API
 * for a given carrier relationship, not per-partner records), so
 * ownership here is plain tenant scoping — the same boundary
 * BrokerOperationsController and CarrierOperationsController already use
 * for these two tables. No PartyResolver/Partner involved on purpose:
 * requiring a Partner link here would incorrectly lock out tenant staff
 * (e.g. finance/claims operators) who have no personal Partner
 * registration but legitimately need to see their own tenant's
 * settlement and bordereau status.
 *
 * Reads exclusively through the Wave6 FinancialDistribution Eloquent
 * models — see MobilePartnerFinanceService's docblock and the batch
 * report for why that matters here.
 */
final class MobileCarrierFinanceService
{
    /** @return array<string, mixed> */
    public function dashboard(string $tenantId, ?string $carrierId = null): array
    {
        return [
            'settlements_status_counts' => SettlementBatch::where('tenant_id', $tenantId)->when($carrierId, fn ($q) => $q->where('carrier_id', $carrierId))
                ->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status')->all(),
            // SUM() over a bigint is numeric in Postgres, which PDO returns as
            // a string — cast so the mobile client sees a JSON number here,
            // exactly as it does for a plain net_amount_minor column.
            'settlements_net_by_currency' => SettlementBatch::where('tenant_id', $tenantId)->when($carrierId, fn ($q) => $q->where('carrier_id', $carrierId))
                ->selectRaw('currency, status, SUM(net_amount_minor) as net_amount_minor')->groupBy('currency', 'status')->get()
                ->map(fn (SettlementBatch $row) => ['currency' => $row->currency, 'status' => $row->status, 'net_amount_minor' => (int) $row->getAttribute('net_amount_minor')]),
            'bordereaux_status_counts' => Bordereau::where('tenant_id', $tenantId)->when($carrierId, fn ($q) => $q->where('carrier_id', $carrierId))
                ->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status')->all(),
        ];
    }

    public function settlements(string $tenantId, int $perPage = 20, ?string $carrierId = null): LengthAwarePaginator
    {
        return SettlementBatch::where('tenant_id', $tenantId)->when($carrierId, fn ($q) => $q->where('carrier_id', $carrierId))->orderByDesc('created_at')->paginate($perPage);
    }

    public function settlement(string $settlementId, string $tenantId, ?string $carrierId = null): SettlementBatch
    {
        $settlement = SettlementBatch::where('tenant_id', $tenantId)->when($carrierId, fn ($q) => $q->where('carrier_id', $carrierId))->find($settlementId);

        if (! $settlement) {
            $exists = SettlementBatch::where('id', $settlementId)->exists();

            throw $exists ? new AuthorizationException : new ModelNotFoundException;
        }

        return $settlement->load('items');
    }

    public function bordereaux(string $tenantId, int $perPage = 20, ?string $carrierId = null): LengthAwarePaginator
    {
        return Bordereau::where('tenant_id', $tenantId)->when($carrierId, fn ($q) => $q->where('carrier_id', $carrierId))->orderByDesc('created_at')->paginate($perPage);
    }

    public function bordereau(string $bordereauId, string $tenantId, ?string $carrierId = null): Bordereau
    {
        $bordereau = Bordereau::where('tenant_id', $tenantId)->when($carrierId, fn ($q) => $q->where('carrier_id', $carrierId))->find($bordereauId);

        if (! $bordereau) {
            $exists = Bordereau::where('id', $bordereauId)->exists();

            throw $exists ? new AuthorizationException : new ModelNotFoundException;
        }

        return $bordereau->load('items');
    }
}
