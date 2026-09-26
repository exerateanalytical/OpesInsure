<?php

declare(strict_types=1);

namespace App\Application\Documents\Security;

use App\Application\DataReadiness\DataReadinessRegistry;
use App\Application\DataReadiness\DataStatus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Physical security controls (Security Matrix §4 PS-01..04, §6 SEAL-01 artwork, §11.8/§11.9): they only count when
 * backed by owner-recorded, VERIFIED assets — print supplier, UV capability, hologram and secure stock serial batches,
 * corporate seal artwork (document_physical_security_assets, admin screen "Physical security assets", work item D6).
 * Nothing is ever assumed: with no verified asset the profile is CONFIG_REQUIRED, and even with assets the physical
 * issuance switch (document_security.physical_issuance_enabled) must be on before a control is APPLIED.
 */
final class PhysicalSecurityRegistry
{
    public const KINDS = ['PRINT_SUPPLIER', 'SECURE_STOCK_BATCH', 'HOLOGRAM_BATCH', 'UV_CAPABILITY', 'SEAL_ARTWORK'];

    /** Asset kind each physical profile needs (§4). */
    public const PROFILE_NEEDS = ['PS-01' => 'PRINT_SUPPLIER', 'PS-02' => 'UV_CAPABILITY', 'PS-03' => 'HOLOGRAM_BATCH', 'PS-04' => 'SECURE_STOCK_BATCH'];

    public static function register(): void
    {
        DataReadinessRegistry::extend('documents', fn (array $s) => self::readinessRows());
    }

    /** VERIFIED assets of a kind (carrier-specific or platform-wide). */
    public static function verifiedCount(string $kind, ?string $carrierId = null): int
    {
        try {
            if (! Schema::hasTable('document_physical_security_assets')) {
                return 0;
            }

            return DB::table('document_physical_security_assets')->where('asset_kind', $kind)->where('status', 'VERIFIED')->whereNotNull('verified_at')
                ->when($carrierId, fn ($q) => $q->where(fn ($w) => $w->whereNull('carrier_id')->orWhere('carrier_id', $carrierId)))->count();
        } catch (Throwable) {
            return 0;
        }
    }

    public static function corporateSealArtwork(?string $carrierId): bool
    {
        try {
            return Schema::hasTable('document_physical_security_assets') && DB::table('document_physical_security_assets')
                ->where('asset_kind', 'SEAL_ARTWORK')->where('status', 'VERIFIED')->whereNotNull('verified_at')->whereNotNull('artwork_sha256')
                ->where(fn ($q) => $q->whereNull('seal_profile_code')->orWhere('seal_profile_code', 'SEAL-01'))
                ->when($carrierId, fn ($q) => $q->where(fn ($w) => $w->whereNull('carrier_id')->orWhere('carrier_id', $carrierId)))->exists();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  list<string>  $profiles
     * @return array<string, array{status: string, asset_kind: string, verified_assets: int}>
     */
    public static function profileStates(array $profiles, ?string $carrierId = null): array
    {
        $out = [];
        foreach ($profiles as $ps) {
            $kind = self::PROFILE_NEEDS[$ps] ?? null;
            if (! $kind) {
                continue;
            }
            $n = self::verifiedCount($kind, $carrierId);
            $out[$ps] = ['status' => $n > 0 && config('document_security.physical_issuance_enabled') ? 'APPLIED' : 'CONFIG_REQUIRED',
                'asset_kind' => $kind, 'verified_assets' => $n];
        }

        return $out;
    }

    /** @return list<array<string, mixed>> */
    public static function readinessRows(): array
    {
        $reg = app(DataReadinessRegistry::class);
        $rows = [];
        foreach (array_merge(self::PROFILE_NEEDS, ['SEAL-01' => 'SEAL_ARTWORK']) as $profile => $kind) {
            $n = self::verifiedCount($kind);
            $rows[] = $reg->row('documents', 'physical_security_'.strtolower($kind), 'CONFIG_REQUIRED', $n > 0 ? DataStatus::VERIFIED : DataStatus::CONFIG_REQUIRED,
                $n > 0 ? [] : ["No VERIFIED $kind recorded for $profile (Admin › Document engine › Physical security assets)"],
                "$n verified $kind asset(s); physical issuance ".(config('document_security.physical_issuance_enabled') ? 'enabled' : 'disabled'), SecurityMatrix::SOURCE);
        }

        return $rows;
    }
}
