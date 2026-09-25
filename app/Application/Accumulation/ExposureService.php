<?php

declare(strict_types=1);

namespace App\Application\Accumulation;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REQ-CAT-001 — exposure zones, risk locations and accumulation per zone / peril, gross and net of reinsurance.
 *
 * Zones are tenant configuration: a list of master-data geography codes (region / division / city names, matched
 * case-insensitively against the risk address) and an optional [[lat, lng], ...] polygon (tested first when the risk
 * carries coordinates). Risk locations are derived from the in-force policies' latest policy_risks (facts, then the
 * linked risk_asset facts); the net figure applies the policy's CALCULATED reinsurance cessions (CessionService /
 * CessionCalculator::net) pro rata. Snapshots freeze the per zone / peril totals at a point in time.
 */
final class ExposureService
{
    public const IN_FORCE = ['ACTIVE', 'EXPIRING', 'ENDORSEMENT_PENDING', 'CANCELLATION_PENDING'];

    private const ADDRESS_KEYS = ['geography_code', 'region_code', 'region', 'division', 'department', 'subdivision', 'city', 'town', 'district', 'quarter'];

    public function __construct(private readonly AuditWriter $audit, private readonly OutboxWriter $outbox) {}

    public function createZone(string $tenantId, array $data): array
    {
        if (DB::table('accumulation_zones')->where('tenant_id', $tenantId)->where('code', $data['code'])->exists()) {
            throw ValidationException::withMessages(['code' => 'Zone code already exists.']);
        }
        $polygon = $data['polygon'] ?? null;
        if ($polygon !== null && count($polygon) < 3) {
            throw ValidationException::withMessages(['polygon' => 'A polygon needs at least three points.']);
        }
        $id = (string) Str::uuid();
        DB::table('accumulation_zones')->insert(['id' => $id, 'tenant_id' => $tenantId, 'code' => $data['code'], 'name' => $data['name'],
            'country_code' => $data['country_code'] ?? null, 'geography_codes' => json_encode(array_values(array_map('strval', $data['geography_codes'] ?? []))),
            'polygon' => $polygon === null ? null : json_encode($polygon), 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
        $this->audit->record('accumulation.zone.created', 'accumulation_zone', $id, ['code' => $data['code']]);

        return $this->zone($tenantId, $id);
    }

    public function zone(string $tenantId, string $id): array
    {
        $z = DB::table('accumulation_zones')->where('tenant_id', $tenantId)->where('id', $id)->first() ?? abort(404, 'Zone not found.');

        return self::decodeZone($z);
    }

    /** @return list<array<string, mixed>> */
    public function zones(string $tenantId): array
    {
        return DB::table('accumulation_zones')->where('tenant_id', $tenantId)->where('status', 'ACTIVE')->orderBy('code')->get()
            ->map(fn ($z) => self::decodeZone($z))->all();
    }

    /**
     * Resolve an address / coordinates to a zone: polygon first, then geography codes.
     *
     * @return array{zone_id: ?string, zone_code: ?string, resolved_by: string}
     */
    public function resolveZone(string $tenantId, array $location): array
    {
        $zones = $this->zones($tenantId);
        $lat = $location['latitude'] ?? $location['lat'] ?? null;
        $lng = $location['longitude'] ?? $location['lng'] ?? null;
        if (is_numeric($lat) && is_numeric($lng)) {
            foreach ($zones as $z) {
                if ($z['polygon'] && self::inPolygon((float) $lat, (float) $lng, $z['polygon'])) {
                    return ['zone_id' => $z['id'], 'zone_code' => $z['code'], 'resolved_by' => 'POLYGON'];
                }
            }
        }
        if (isset($location['zone_code'])) {
            foreach ($zones as $z) {
                if (strcasecmp($z['code'], (string) $location['zone_code']) === 0) {
                    return ['zone_id' => $z['id'], 'zone_code' => $z['code'], 'resolved_by' => 'GEOGRAPHY'];
                }
            }
        }
        $values = [];
        foreach (self::ADDRESS_KEYS as $k) {
            if (isset($location[$k]) && is_scalar($location[$k]) && $location[$k] !== '') {
                $values[] = mb_strtolower(trim((string) $location[$k]));
            }
        }
        foreach ($zones as $z) {
            $codes = array_map(fn ($c) => mb_strtolower(trim($c)), $z['geography_codes']);
            if (array_intersect($values, $codes) !== []) {
                return ['zone_id' => $z['id'], 'zone_code' => $z['code'], 'resolved_by' => 'GEOGRAPHY'];
            }
        }

        return ['zone_id' => null, 'zone_code' => null, 'resolved_by' => 'UNRESOLVED'];
    }

    /** Re-derive the tenant's risk locations from in-force policies (replaces the previous derivation). */
    public function rebuildLocations(string $tenantId): array
    {
        return DB::transaction(function () use ($tenantId) {
            DB::table('accumulation_risk_locations')->where('tenant_id', $tenantId)->delete();
            $count = 0;
            $unresolved = 0;
            DB::table('policies')->where('tenant_id', $tenantId)->whereIn('status', self::IN_FORCE)->orderBy('id')
                ->each(function ($p) use ($tenantId, &$count, &$unresolved) {
                    foreach ($this->locationsForPolicy($tenantId, $p) as $row) {
                        DB::table('accumulation_risk_locations')->insert($row);
                        $count++;
                        $unresolved += $row['zone_id'] === null ? 1 : 0;
                    }
                });
            $this->audit->record('accumulation.locations.rebuilt', 'tenant', $tenantId, ['locations' => $count, 'unresolved' => $unresolved]);

            return ['locations' => $count, 'unresolved' => $unresolved];
        });
    }

    /**
     * Current accumulation per zone / peril for locations in force at $asOf.
     *
     * @return list<array{zone_id: ?string, zone_code: ?string, peril_code: string, currency: string, location_count: int, gross_minor: int, net_minor: int}>
     */
    public function accumulation(string $tenantId, ?\DateTimeInterface $asOf = null, ?string $zoneId = null): array
    {
        $at = $asOf ?? now();
        $codes = DB::table('accumulation_zones')->where('tenant_id', $tenantId)->pluck('code', 'id');
        $rows = DB::table('accumulation_risk_locations')->where('tenant_id', $tenantId)
            ->when($zoneId, fn ($q) => $q->where('zone_id', $zoneId))
            ->where(fn ($q) => $q->whereNull('coverage_starts_at')->orWhere('coverage_starts_at', '<=', $at))
            ->where(fn ($q) => $q->whereNull('coverage_ends_at')->orWhere('coverage_ends_at', '>=', $at))->get();
        $out = [];
        foreach ($rows as $r) {
            foreach (json_decode((string) $r->perils, true) ?: ['ALL'] as $peril) {
                $key = ($r->zone_id ?? '-').'|'.$peril.'|'.$r->currency;
                $out[$key] ??= ['zone_id' => $r->zone_id, 'zone_code' => $r->zone_id ? $codes[$r->zone_id] ?? null : null, 'peril_code' => (string) $peril,
                    'currency' => $r->currency, 'location_count' => 0, 'gross_minor' => 0, 'net_minor' => 0];
                $out[$key]['location_count']++;
                $out[$key]['gross_minor'] += (int) $r->gross_sum_insured_minor;
                $out[$key]['net_minor'] += (int) $r->net_sum_insured_minor;
            }
        }
        ksort($out);

        return array_values($out);
    }

    /** Totals of one zone / peril (a location covering ALL perils counts toward every peril). */
    public function zonePerilTotals(string $tenantId, ?string $zoneId, string $peril, string $currency): array
    {
        $gross = 0;
        $net = 0;
        foreach ($this->accumulation($tenantId, null, $zoneId) as $line) {
            if ($line['currency'] === $currency && ($zoneId === null || $line['zone_id'] === $zoneId)
                && ($line['peril_code'] === $peril || $line['peril_code'] === 'ALL' || $peril === 'ALL')) {
                $gross += $line['gross_minor'];
                $net += $line['net_minor'];
            }
        }

        return ['gross_minor' => $gross, 'net_minor' => $net];
    }

    /** Point-in-time snapshot of the accumulation (append-only). */
    public function snapshot(string $tenantId, string $currency, ?string $actorId = null): array
    {
        return DB::transaction(function () use ($tenantId, $currency, $actorId) {
            $at = now();
            $lines = array_values(array_filter($this->accumulation($tenantId, $at), fn ($l) => $l['currency'] === $currency));
            $locations = DB::table('accumulation_risk_locations')->where('tenant_id', $tenantId)->where('currency', $currency);
            $id = (string) Str::uuid();
            // Totals count each location once (not once per peril).
            DB::table('accumulation_snapshots')->insert(['id' => $id, 'tenant_id' => $tenantId, 'as_of' => $at, 'currency' => $currency,
                'gross_total_minor' => (int) (clone $locations)->sum('gross_sum_insured_minor'), 'net_total_minor' => (int) (clone $locations)->sum('net_sum_insured_minor'),
                'location_count' => (clone $locations)->count(), 'unresolved_count' => (clone $locations)->whereNull('zone_id')->count(), 'created_by' => $actorId, 'created_at' => $at]);
            foreach ($lines as $l) {
                DB::table('accumulation_snapshot_lines')->insert(['id' => (string) Str::uuid(), 'snapshot_id' => $id, 'zone_id' => $l['zone_id'], 'peril_code' => $l['peril_code'],
                    'location_count' => $l['location_count'], 'gross_minor' => $l['gross_minor'], 'net_minor' => $l['net_minor']]);
            }
            $this->outbox->record('accumulation.snapshot.taken', 'accumulation_snapshot', $id, ['tenant_id' => $tenantId, 'currency' => $currency, 'lines' => count($lines)]);
            $this->audit->record('accumulation.snapshot.taken', 'accumulation_snapshot', $id, ['currency' => $currency, 'lines' => count($lines)]);

            return $this->snapshotView($tenantId, $id);
        });
    }

    public function snapshotView(string $tenantId, string $id): array
    {
        $s = DB::table('accumulation_snapshots')->where('tenant_id', $tenantId)->where('id', $id)->first() ?? abort(404, 'Snapshot not found.');

        return (array) $s + ['lines' => DB::table('accumulation_snapshot_lines')->where('snapshot_id', $id)->orderBy('peril_code')->get()->map(fn ($l) => (array) $l)->all()];
    }

    /** @return list<array<string, mixed>> location rows to insert */
    private function locationsForPolicy(string $tenantId, object $policy): array
    {
        $terms = json_decode((string) $policy->terms_snapshot, true) ?: [];
        $versionId = DB::table('policy_versions')->where('policy_id', $policy->id)->orderByDesc('version_no')->value('id');
        $risks = $versionId ? DB::table('policy_risks')->where('policy_version_id', $versionId)->get() : collect();
        $policySum = is_numeric($terms['sum_insured_minor'] ?? null) ? (int) $terms['sum_insured_minor'] : null;
        $netRatio = $this->netRatio($policy->id, $policySum);

        $candidates = [];
        foreach ($risks as $r) {
            $facts = json_decode((string) $r->facts, true) ?: [];
            if ($r->risk_asset_id) {
                $facts += json_decode((string) DB::table('risk_assets')->where('id', $r->risk_asset_id)->value('facts'), true) ?: [];
            }
            $candidates[] = ['risk' => $r, 'facts' => $facts];
        }
        if ($candidates === [] && (isset($terms['risk_location']) || isset($terms['address']))) {
            $candidates[] = ['risk' => null, 'facts' => (array) ($terms['risk_location'] ?? $terms['address'])];
        }
        $rows = [];
        foreach ($candidates as $c) {
            $facts = $c['facts'];
            $address = is_array($facts['address'] ?? null) ? $facts['address'] + $facts : $facts;
            $gross = is_numeric($facts['sum_insured_minor'] ?? null) ? (int) $facts['sum_insured_minor'] : ($policySum !== null ? intdiv($policySum, max(1, count($candidates))) : 0);
            $zone = $this->resolveZone($tenantId, $address);
            $perils = (array) ($facts['perils'] ?? $terms['perils'] ?? ['ALL']);
            $rows[] = ['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'policy_id' => $policy->id, 'policy_risk_id' => $c['risk']?->id,
                'risk_asset_id' => $c['risk']?->risk_asset_id, 'zone_id' => $zone['zone_id'], 'resolved_by' => $zone['resolved_by'],
                'address' => json_encode(array_intersect_key($address, array_flip([...self::ADDRESS_KEYS, 'line1', 'street', 'country_code', 'zone_code']))),
                'latitude' => is_numeric($address['latitude'] ?? null) ? $address['latitude'] : null, 'longitude' => is_numeric($address['longitude'] ?? null) ? $address['longitude'] : null,
                'perils' => json_encode(array_values(array_map(fn ($p) => strtoupper((string) $p), $perils))), 'currency' => $policy->currency,
                'gross_sum_insured_minor' => $gross, 'net_sum_insured_minor' => (int) round($gross * $netRatio),
                'coverage_starts_at' => $policy->coverage_starts_at, 'coverage_ends_at' => $policy->coverage_ends_at, 'created_at' => now(), 'updated_at' => now()];
        }

        return $rows;
    }

    /** Retained share of the sum insured after the policy's current CALCULATED cessions (1.0 when none). */
    private function netRatio(string $policyId, ?int $policySum): float
    {
        $cessions = DB::table('reinsurance_cessions')->where('policy_id', $policyId)->where('status', 'CALCULATED')->get()
            ->map(fn ($c) => ['treaty_type' => $c->treaty_type, 'ceded_sum_minor' => (int) $c->ceded_sum_minor, 'ceded_premium_minor' => (int) $c->ceded_premium_minor])->all();
        $sum = $policySum ?? (int) DB::table('reinsurance_cessions')->where('policy_id', $policyId)->where('status', 'CALCULATED')->max('sum_insured_minor');
        if ($cessions === [] || $sum <= 0) {
            return 1.0;
        }
        $net = \App\Application\Reinsurance\CessionCalculator::net($sum, 0, $cessions)['net_sum_minor'];

        return max(0.0, min(1.0, $net / $sum));
    }

    private static function decodeZone(object $z): array
    {
        return ['id' => $z->id, 'code' => $z->code, 'name' => $z->name, 'country_code' => $z->country_code,
            'geography_codes' => json_decode((string) $z->geography_codes, true) ?: [], 'polygon' => $z->polygon ? json_decode((string) $z->polygon, true) : null, 'status' => $z->status];
    }

    /** Ray casting; polygon points are [lat, lng]. */
    public static function inPolygon(float $lat, float $lng, array $polygon): bool
    {
        $inside = false;
        $n = count($polygon);
        for ($i = 0, $j = $n - 1; $i < $n; $j = $i++) {
            [$yi, $xi] = [(float) $polygon[$i][0], (float) $polygon[$i][1]];
            [$yj, $xj] = [(float) $polygon[$j][0], (float) $polygon[$j][1]];
            if ((($yi > $lat) !== ($yj > $lat)) && ($lng < ($xj - $xi) * ($lat - $yi) / (($yj - $yi) ?: 1e-12) + $xi)) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }
}
