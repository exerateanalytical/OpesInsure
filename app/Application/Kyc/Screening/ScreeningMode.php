<?php

declare(strict_types=1);

namespace App\Application\Kyc\Screening;

/**
 * Owner decision 27 (2026-09-25): screening runs in MANUAL_AUDITED mode until a provider is integrated. A reviewer
 * consults the lists and records what was consulted; every step is audited. The platform never claims automated
 * screening. "MANUAL" is the pre-decision code of the same mode: rows already stored with it stay as they are and
 * read back as MANUAL_AUDITED.
 */
final class ScreeningMode
{
    public const MANUAL_AUDITED = 'MANUAL_AUDITED';

    /** Workflow Data Master v1 kyc_aml.screening_modes: supported codes, but no provider is configured (CONFIG_REQUIRED). */
    public const EXTERNAL_PROVIDER = 'EXTERNAL_PROVIDER';

    public const HYBRID = 'HYBRID';

    public const MODES = [self::MANUAL_AUDITED, self::EXTERNAL_PROVIDER, self::HYBRID];

    /** Data status of each mode: MANUAL_AUDITED is operational; the provider modes need a configured provider. */
    public static function configurationStatus(string $mode): string
    {
        $mode = self::normalize($mode);
        if ($mode === self::MANUAL_AUDITED) {
            return 'PLATFORM_NORMALIZED';
        }

        return filled(config('kyc.screening.provider')) ? 'UNVERIFIED' : 'CONFIG_REQUIRED';
    }

    /** Legacy code (before 2026-09-25) — accepted on read and in configuration, never written. */
    public const LEGACY_MANUAL = 'MANUAL';

    public static function normalize(?string $mode): string
    {
        $mode = strtoupper(trim((string) $mode));

        return $mode === '' || $mode === self::LEGACY_MANUAL ? self::MANUAL_AUDITED : $mode;
    }

    /** No automated provider is integrated; this is false for every mode the platform ships. */
    public static function isAutomated(?string $mode): bool
    {
        return false;
    }

    /** @return array{screening_mode: string, automated: false, statement: string} */
    public static function describe(?string $mode = null): array
    {
        return ['screening_mode' => self::normalize($mode ?? (string) config('kyc.screening.mode', self::MANUAL_AUDITED)), 'automated' => false,
            'statement' => 'Sanctions and PEP screening is performed manually by a reviewer and audited. No automated screening provider is integrated.'];
    }
}
