<?php

declare(strict_types=1);

namespace App\Application\Import\Legacy;

use App\Models\ExternalRecordMapping;
use App\Models\IntegrationClient;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * REQ-IMP-002 — what an adapter sees while one legacy batch runs: the tenant, the batch, the actor, the legacy
 * source (its integration client owns the external_record_mappings) and helpers to resolve other legacy ids
 * already migrated (customers before policies, policies before premiums / claims).
 */
final class LegacyContext
{
    /** Currencies without minor units (ISO 4217 exponent 0). */
    private const ZERO_DECIMAL = ['XAF', 'XOF', 'JPY', 'KRW', 'CLP', 'GNF', 'RWF', 'KMF', 'DJF', 'UGX', 'VND'];

    public function __construct(
        public readonly string $tenantId,
        public readonly string $batchId,
        public readonly User $actor,
        public readonly IntegrationClient $source,
        public readonly bool $dryRun,
        public readonly ?string $makerId = null,
        public readonly ?string $checkerId = null,
    ) {}

    /** OpesInsure id of a legacy record already migrated from the same source (any batch), or null. */
    public function resolve(string $recordType, ?string $legacyId): ?string
    {
        if ($legacyId === null || $legacyId === '') {
            return null;
        }

        return ExternalRecordMapping::where(['integration_client_id' => $this->source->id, 'record_type' => $recordType, 'external_record_id' => $legacyId])
            ->value('opesinsure_record_id');
    }

    /** Decimal legacy amount → integer minor units of the currency (never floats in storage). */
    public static function minor(?string $amount, string $currency): ?int
    {
        $amount = str_replace([' ', "\u{00A0}"], '', (string) $amount);
        if ($amount === '' || ! preg_match('/^-?\d+([.,]\d+)?$/', $amount)) {
            return null;
        }
        $amount = str_replace(',', '.', $amount);
        $exp = in_array(strtoupper($currency), self::ZERO_DECIMAL, true) ? 0 : 2;
        [$int, $frac] = array_pad(explode('.', ltrim($amount, '-')), 2, '');
        if (strlen(rtrim($frac, '0')) > $exp) {
            return null;   // more precision than the currency carries: reject rather than round money
        }
        $minor = (int) $int * (10 ** $exp) + (int) str_pad(substr($frac, 0, $exp), $exp, '0');

        return str_starts_with($amount, '-') ? -$minor : $minor;
    }

    public static function date(?string $value): ?CarbonImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }
        foreach (['Y-m-d', 'd/m/Y', 'Y-m-d H:i:s', DATE_ATOM] as $f) {
            try {
                $d = CarbonImmutable::createFromFormat('!'.$f, $value);
            } catch (\Throwable) {
                continue;
            }
            if ($d instanceof CarbonImmutable && $d->format($f) === $value) {
                return $d;
            }
        }

        return null;
    }
}
