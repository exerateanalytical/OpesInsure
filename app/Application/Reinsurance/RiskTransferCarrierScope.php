<?php

declare(strict_types=1);

namespace App\Application\Reinsurance;

use App\Application\Identity\CarrierScopeResolver;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * S5 (insurer E2E review, 2026-09-29): reinsurers, reinsurance treaties, facultative placements and co-insurance
 * arrangements carry a nullable carrier_id. One rule for every screen, API route and service lookup:
 *  - a caller linked to a carrier (CarrierScopeResolver: /insurer portal, carrier-linked API users) sees and acts on
 *    its own carrier's rows only; NULL rows (tenant-wide legacy) are hidden from it;
 *  - tenant-wide / platform callers and system jobs (no user) keep the whole tenant;
 *  - an insurer-side account that is not linked to a carrier sees nothing (fails closed).
 */
final class RiskTransferCarrierScope
{
    /** Sentinel: the caller may see no carrier-owned row at all. */
    public const NONE = '__none__';

    /** Acting carrier of the current caller in $tenantId: null = tenant-wide, NONE = nothing. */
    public static function carrier(string $tenantId): ?string
    {
        $user = auth()->user();
        if (! $user instanceof User) {
            return null;
        }
        try {
            return app(CarrierScopeResolver::class)->carrierIdFor($user, $tenantId);
        } catch (AuthorizationException) {
            return self::NONE;
        }
    }

    /** Narrow $q (already tenant-filtered) to the caller's carrier. */
    public static function apply(Builder $q, string $tenantId, string $column = 'carrier_id'): Builder
    {
        $carrier = self::carrier($tenantId);

        return match ($carrier) {
            null => $q,
            self::NONE => $q->whereRaw('1 = 0'),
            default => $q->where($column, $carrier),
        };
    }

    /**
     * carrier_id stored on create: the caller's own carrier; a tenant-wide caller may name one explicitly (platform staff)
     * or leave it NULL; $derived (e.g. the linked policy's carrier) wins when given, and must agree with the others.
     */
    public static function forCreate(string $tenantId, ?string $explicit = null, ?string $derived = null): ?string
    {
        $carrier = self::carrier($tenantId);
        if ($carrier === self::NONE) {
            throw new AuthorizationException('Your insurer account is not linked to a carrier yet.');
        }
        $explicit = $explicit === '' ? null : $explicit;
        foreach ([$explicit, $derived] as $other) {
            if ($carrier !== null && $other !== null && $other !== $carrier) {
                throw ValidationException::withMessages(['carrier_id' => 'You can only act for your own carrier.']);
            }
        }
        if ($explicit !== null && $derived !== null && $explicit !== $derived) {
            throw ValidationException::withMessages(['carrier_id' => 'The carrier does not match the linked policy.']);
        }
        $chosen = $carrier ?? $derived ?? $explicit;
        if ($chosen !== null && ! DB::table('carriers')->where('id', $chosen)->exists()) {
            throw ValidationException::withMessages(['carrier_id' => 'Unknown carrier.']);
        }

        return $chosen;
    }
}
