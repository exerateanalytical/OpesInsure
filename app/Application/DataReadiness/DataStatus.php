<?php

declare(strict_types=1);

namespace App\Application\DataReadiness;

/**
 * Workflow Institutional Data Master v1 (database/data/workflow_institutional_data_master_2026.json, dataset.status_codes):
 * the platform's single verification vocabulary. Stored statuses are NEVER renamed; every module keeps its own column
 * values and they are read through LEGACY_MAP. Only VERIFIED and PLATFORM_NORMALIZED values may be used as production
 * values; everything else is supported (the domain exists and carries its status) but refused by ProductionUseGuard.
 */
final class DataStatus
{
    public const VERIFIED = 'VERIFIED';

    public const PLATFORM_NORMALIZED = 'PLATFORM_NORMALIZED';

    public const UNVERIFIED = 'UNVERIFIED';

    public const PENDING_SOURCE = 'PENDING_SOURCE';

    public const CONFIG_REQUIRED = 'CONFIG_REQUIRED';

    public const DEMO_ONLY = 'DEMO_ONLY';

    public const RETIRED = 'RETIRED';

    public const CODES = [self::VERIFIED, self::PLATFORM_NORMALIZED, self::UNVERIFIED, self::PENDING_SOURCE, self::CONFIG_REQUIRED, self::DEMO_ONLY, self::RETIRED];

    public const PRODUCTION = [self::VERIFIED, self::PLATFORM_NORMALIZED];

    public const SOURCE = 'OWNER_WORKFLOW_DATA_MASTER_V1';

    /** Existing stored status values (any module) => vocabulary code. Unknown values read as UNVERIFIED (fail closed). */
    public const LEGACY_MAP = [
        // verified
        'OWNER_CONFIRMED' => self::VERIFIED, 'VERIFIED_RULE' => self::VERIFIED, 'AUTHORIZED' => self::VERIFIED,
        // platform-normalized vocabularies and completed platform datasets
        'COMPLETE' => self::PLATFORM_NORMALIZED, 'PARTIALLY_COMPLETE' => self::PLATFORM_NORMALIZED, 'OWNER_DECISION' => self::PLATFORM_NORMALIZED, 'BLUEPRINT' => self::PLATFORM_NORMALIZED,
        // demo
        'DEMO' => self::DEMO_ONLY, 'DEMO_UNVERIFIED' => self::DEMO_ONLY,
        // unverified / in verification / reconstructed
        'PENDING_VERIFICATION' => self::UNVERIFIED, 'REJECTED' => self::UNVERIFIED, 'RECONSTRUCTED_PENDING_OWNER' => self::UNVERIFIED,
        'LEGACY_ACTIVE_AUTHORIZATION_PENDING_VERIFICATION' => self::UNVERIFIED,
        'PARTIALLY_KNOWN' => self::UNVERIFIED, 'PENDING_MASTER_REVIEW' => self::UNVERIFIED, 'LEGACY' => self::UNVERIFIED, 'PLATFORM_DEFAULT_UNVERIFIED' => self::UNVERIFIED,
        'BLOCK_NEW_PRODUCT_PUBLICATION' => self::PENDING_SOURCE,
        // Gap Closure Pack v1 vocabulary (database/data/gap_closure_2026)
        'VERIFIED_PUBLIC_SOURCE' => self::VERIFIED, 'PENDING_PRIVATE_SOURCE' => self::PENDING_SOURCE, 'PENDING_OFFICIAL_IMPORT' => self::PENDING_SOURCE,
        // retired
        'REVOKED' => self::RETIRED, 'WITHDRAWN' => self::RETIRED, 'SUPERSEDED' => self::RETIRED, 'EXPIRED' => self::RETIRED,
    ];

    public static function normalize(?string $stored): string
    {
        $s = strtoupper(trim((string) $stored));
        if (in_array($s, self::CODES, true)) {
            return $s;
        }

        return self::LEGACY_MAP[$s] ?? self::UNVERIFIED;
    }

    public static function isProduction(?string $stored): bool
    {
        return in_array(self::normalize($stored), self::PRODUCTION, true);
    }

    /** @return array<string, list<string>> vocabulary code => stored values that map to it */
    public static function mapping(): array
    {
        $out = array_fill_keys(self::CODES, []);
        foreach (self::LEGACY_MAP as $stored => $code) {
            $out[$code][] = $stored;
        }

        return $out;
    }
}
