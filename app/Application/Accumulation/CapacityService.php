<?php

declare(strict_types=1);

namespace App\Application\Accumulation;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Application\Reinsurance\CessionCalculator;
use App\Application\Reinsurance\TreatyService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REQ-CAT-002 — capacity check at underwriting (LOCK-019).
 *
 * Limits are tenant configuration per zone (or any zone) / peril (or ALL) / currency; none are seeded. With no limit
 * configured the result is NOT_CONFIGURED (never an invented number). Otherwise, with G = current gross accumulation,
 * N = current net accumulation, S = requested sum insured and T = the part of S the treaties in force would absorb
 * (CessionCalculator over TreatyService::effectiveVersions):
 *   G + S > gross limit                      → CAPACITY_EXCEEDED
 *   N + S <= retention                       → WITHIN_RETENTION
 *   N + (S − T) <= retention                 → TREATY_COVERED
 *   otherwise                                → FACULTATIVE_REQUIRED
 */
final class CapacityService
{
    public const RESULTS = ['WITHIN_RETENTION', 'TREATY_COVERED', 'CAPACITY_EXCEEDED', 'FACULTATIVE_REQUIRED'];

    public const NOT_CONFIGURED = 'NOT_CONFIGURED';

    public function __construct(
        private readonly ExposureService $exposure,
        private readonly TreatyService $treaties,
        private readonly CessionCalculator $calculator,
        private readonly AuditWriter $audit,
        private readonly OutboxWriter $outbox,
    ) {}

    public function setLimit(string $tenantId, array $data, ?string $actorId = null): array
    {
        if (($data['retention_limit_minor'] ?? null) === null && ($data['gross_limit_minor'] ?? null) === null) {
            throw ValidationException::withMessages(['retention_limit_minor' => 'Give a retention limit, a gross limit or both.']);
        }
        if (isset($data['zone_id'])) {
            $this->exposure->zone($tenantId, $data['zone_id']);
        }
        $peril = strtoupper($data['peril_code'] ?? 'ALL');

        return DB::transaction(function () use ($tenantId, $data, $peril, $actorId) {
            DB::table('accumulation_capacity_limits')->where('tenant_id', $tenantId)->where('status', 'ACTIVE')->where('peril_code', $peril)
                ->where('currency', $data['currency'])->where(fn ($q) => isset($data['zone_id']) ? $q->where('zone_id', $data['zone_id']) : $q->whereNull('zone_id'))
                ->update(['status' => 'SUPERSEDED', 'updated_at' => now()]);
            $id = (string) Str::uuid();
            DB::table('accumulation_capacity_limits')->insert(['id' => $id, 'tenant_id' => $tenantId, 'zone_id' => $data['zone_id'] ?? null, 'peril_code' => $peril,
                'currency' => $data['currency'], 'retention_limit_minor' => $data['retention_limit_minor'] ?? null, 'gross_limit_minor' => $data['gross_limit_minor'] ?? null,
                'status' => 'ACTIVE', 'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->record('accumulation.capacity_limit.set', 'accumulation_capacity_limit', $id, $data + ['peril_code' => $peril]);

            return (array) DB::table('accumulation_capacity_limits')->find($id);
        });
    }

    /**
     * @param  array{zone_id?: ?string, location?: array<string, mixed>, peril_code?: string, sum_insured_minor: int, currency: string, line_code?: ?string, date?: ?string, subject_type?: ?string, subject_id?: ?string}  $in
     */
    public function check(string $tenantId, array $in, ?string $actorId = null): array
    {
        $peril = strtoupper((string) ($in['peril_code'] ?? 'ALL'));
        $sum = (int) $in['sum_insured_minor'];
        $currency = (string) $in['currency'];
        $zoneId = $in['zone_id'] ?? null;
        $resolvedBy = $zoneId ? 'GIVEN' : 'UNRESOLVED';
        if (! $zoneId && isset($in['location'])) {
            $z = $this->exposure->resolveZone($tenantId, (array) $in['location']);
            [$zoneId, $resolvedBy] = [$z['zone_id'], $z['resolved_by']];
        }
        $limit = $this->limit($tenantId, $zoneId, $peril, $currency);
        $totals = $this->exposure->zonePerilTotals($tenantId, $limit?->zone_id ?? $zoneId, $peril, $currency);
        $treaty = $this->treatyAbsorption($tenantId, $sum, $currency, $in['line_code'] ?? null, $in['date'] ?? now()->toDateString());

        if (! $limit) {
            $result = self::NOT_CONFIGURED;
        } elseif ($limit->gross_limit_minor !== null && $totals['gross_minor'] + $sum > (int) $limit->gross_limit_minor) {
            $result = 'CAPACITY_EXCEEDED';
        } elseif ($limit->retention_limit_minor === null || $totals['net_minor'] + $sum <= (int) $limit->retention_limit_minor) {
            $result = 'WITHIN_RETENTION';
        } elseif ($totals['net_minor'] + ($sum - $treaty) <= (int) $limit->retention_limit_minor) {
            $result = 'TREATY_COVERED';
        } else {
            $result = 'FACULTATIVE_REQUIRED';
        }

        $details = ['zone_resolved_by' => $resolvedBy, 'limit_id' => $limit?->id, 'retention_limit_minor' => $limit?->retention_limit_minor !== null ? (int) $limit->retention_limit_minor : null,
            'gross_limit_minor' => $limit?->gross_limit_minor !== null ? (int) $limit->gross_limit_minor : null, 'current_gross_minor' => $totals['gross_minor'],
            'current_net_minor' => $totals['net_minor'], 'treaty_absorbed_minor' => $treaty, 'net_after_treaty_minor' => $totals['net_minor'] + $sum - $treaty];
        $id = (string) Str::uuid();
        DB::table('accumulation_capacity_checks')->insert(['id' => $id, 'tenant_id' => $tenantId, 'subject_type' => $in['subject_type'] ?? null, 'subject_id' => $in['subject_id'] ?? null,
            'zone_id' => $zoneId, 'peril_code' => $peril, 'currency' => $currency, 'requested_minor' => $sum, 'result' => $result, 'details' => json_encode($details),
            'checked_by' => $actorId, 'created_at' => now()]);
        if (in_array($result, ['CAPACITY_EXCEEDED', 'FACULTATIVE_REQUIRED'], true)) {
            $this->outbox->record('accumulation.capacity.breached', 'accumulation_capacity_check', $id, ['tenant_id' => $tenantId, 'result' => $result, 'zone_id' => $zoneId,
                'peril_code' => $peril, 'requested_minor' => $sum, 'currency' => $currency, 'subject_type' => $in['subject_type'] ?? null, 'subject_id' => $in['subject_id'] ?? null]);
        }

        return ['id' => $id, 'result' => $result, 'zone_id' => $zoneId, 'peril_code' => $peril, 'currency' => $currency, 'requested_minor' => $sum] + $details;
    }

    private function limit(string $tenantId, ?string $zoneId, string $peril, string $currency): ?object
    {
        $rows = DB::table('accumulation_capacity_limits')->where('tenant_id', $tenantId)->where('status', 'ACTIVE')->where('currency', $currency)
            ->whereIn('peril_code', array_unique([$peril, 'ALL']))
            ->where(fn ($q) => $q->whereNull('zone_id')->when($zoneId, fn ($q) => $q->orWhere('zone_id', $zoneId)))->get();

        // Most specific first: zone + peril, zone + ALL, any zone + peril, any zone + ALL.
        return $rows->sortBy(fn ($l) => ($l->zone_id ? 0 : 2) + ($l->peril_code === $peril ? 0 : 1))->first();
    }

    private function treatyAbsorption(string $tenantId, int $sum, string $currency, ?string $lineCode, string $date): int
    {
        try {
            $versions = $this->treaties->effectiveVersions($tenantId, $date, $lineCode, $currency);
            if ($versions === []) {
                return 0;
            }
            $cessions = $this->calculator->calculate($sum, 0, $versions);

            return max(0, $sum - (int) CessionCalculator::net($sum, 0, $cessions)['net_sum_minor']);
        } catch (\Throwable $e) {
            report($e);

            return 0;
        }
    }
}
