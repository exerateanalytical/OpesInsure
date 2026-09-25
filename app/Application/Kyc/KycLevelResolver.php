<?php

declare(strict_types=1);

namespace App\Application\Kyc;

use App\Models\KycSubmission;

/**
 * REQ-KYC-001 risk-based KYC level. Deterministic and explainable: returns the level and the factors that
 * produced it. Thresholds that would need a regulatory source (premium bands, country lists) are NOT
 * modelled: only facts the platform actually holds raise the level.
 *   - default: config kyc.default_level (STANDARD); SIMPLIFIED is never computed, only set by an owner-configured default
 *   - CORPORATE subject: at least STANDARD
 *   - any screening POSSIBLE_MATCH / CONFIRMED_MATCH, or a reviewer-declared PEP / HIGH_RISK factor: ENHANCED
 */
final class KycLevelResolver
{
    public const RAISING = ['PEP', 'HIGH_RISK', 'SANCTIONS_POSSIBLE_MATCH', 'SANCTIONS_CONFIRMED_MATCH', 'PEP_POSSIBLE_MATCH', 'PEP_CONFIRMED_MATCH'];

    public static function rank(string $level): int
    {
        return ['SIMPLIFIED' => 0, 'STANDARD' => 1, 'ENHANCED' => 2][$level] ?? -1;
    }

    /**
     * @param  list<string>  $factors
     * @return array{0: string, 1: list<string>}
     */
    public function compute(KycSubmission $s, array $factors): array
    {
        $level = (string) config('kyc.default_level', 'STANDARD');
        $out = array_values(array_unique($factors));
        if ($s->subject_kind === 'CORPORATE') {
            $out[] = 'CORPORATE_SUBJECT';
            if (self::rank($level) < 1) {
                $level = 'STANDARD';
            }
        }
        if (in_array($s->screening_status, ['POSSIBLE_MATCH', 'CONFIRMED_MATCH'], true)) {
            $out[] = 'SCREENING_'.$s->screening_status;
        }
        foreach ($out as $f) {
            if (in_array($f, self::RAISING, true) || str_starts_with($f, 'SCREENING_')) {
                $level = 'ENHANCED';
            }
        }

        return [$level, array_values(array_unique($out))];
    }
}
