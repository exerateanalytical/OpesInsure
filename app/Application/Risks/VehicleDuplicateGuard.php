<?php

declare(strict_types=1);

namespace App\Application\Risks;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * REQ-RSK-001 / WF-077: one physical vehicle is one insured object per
 * tenant. A VIN (chassis number) or registration plate already carried by
 * another ACTIVE vehicle asset of the tenant is rejected (409) with the
 * existing asset id, so the caller reuses it instead of creating a twin.
 * Comparison is on the normalised form (upper-case, A-Z0-9 only).
 */
final class VehicleDuplicateGuard
{
    public static function normalize(?string $value): ?string
    {
        $n = preg_replace('/[^A-Z0-9]/', '', strtoupper((string) $value));

        return $n === '' ? null : $n;
    }

    public function assertUnique(string $tenantId, array $facts, ?string $selfId = null): void
    {
        $checks = [
            'vin' => self::normalize($facts['vin'] ?? $facts['chassis_number'] ?? null),
            'registration_number' => self::normalize($facts['registration_number'] ?? null),
        ];
        foreach ($checks as $column => $value) {
            if ($value === null) {
                continue;
            }
            $existing = DB::table('risk_asset_vehicles as v')->join('risk_assets as a', 'a.id', '=', 'v.risk_asset_id')
                ->where('a.tenant_id', $tenantId)->where('a.status', 'ACTIVE')->whereNull('a.deleted_at')
                ->when($selfId, fn ($q) => $q->where('a.id', '!=', $selfId))
                ->whereRaw("regexp_replace(upper(v.{$column}), '[^A-Z0-9]', '', 'g') = ?", [$value])
                ->value('a.id');
            if ($existing) {
                throw ValidationException::withMessages([$column => [__('risks.duplicate_vehicle', ['field' => $column, 'id' => $existing])]])->status(409);
            }
        }
    }
}
