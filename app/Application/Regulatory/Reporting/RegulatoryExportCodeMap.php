<?php

declare(strict_types=1);

namespace App\Application\Regulatory\Reporting;

use InvalidArgumentException;

/**
 * Owner decision item 24: platform reporting codes (ISSUED, COLLECTED, BROKER_COMMISSION, AGENT_COMMISSION) stay
 * internal. Regulatory export adapters translate them here — the only place that knows the regulator's codes.
 * Targets are the Article 557 measures of the CIMA dictionary (regulatory_reporting_categories, ART_557_MEASURE).
 * Broker vs agent is an intermediary category, not a measure: both map to the same commission measure and keep
 * their internal code as the intermediary type. Anything not listed is refused, never guessed.
 */
final class RegulatoryExportCodeMap
{
    public const INTERNAL_PREMIUM = ['ISSUED', 'COLLECTED'];

    public const INTERNAL_COMMISSION = ['BROKER_COMMISSION', 'AGENT_COMMISSION'];

    /** regime => [kind:internal status => regulator measure] */
    private const MAP = [
        'CIMA' => [
            'PREMIUM:ISSUED' => 'PREMIUM_WRITTEN',
            'PREMIUM:COLLECTED' => 'PREMIUM_COLLECTED',
            'COMMISSION:ISSUED' => 'COMMISSION_RECORDED',
            'COMMISSION:COLLECTED' => 'COMMISSION_COLLECTED',
        ],
    ];

    /**
     * @param  string  $amountCode  ISSUED | COLLECTED (premium) or BROKER_COMMISSION | AGENT_COMMISSION (commission)
     * @param  string|null  $premiumStatus  ISSUED | COLLECTED — required for commission codes
     * @return array{measure: string, intermediary_type: ?string}
     */
    public function translate(string $amountCode, ?string $premiumStatus = null, string $regime = 'CIMA'): array
    {
        $map = self::MAP[$regime] ?? throw new InvalidArgumentException("No regulatory export mapping for regime {$regime}.");
        if (in_array($amountCode, self::INTERNAL_PREMIUM, true)) {
            return ['measure' => $map['PREMIUM:'.$amountCode], 'intermediary_type' => null];
        }
        if (in_array($amountCode, self::INTERNAL_COMMISSION, true)) {
            $key = 'COMMISSION:'.($premiumStatus ?? '');
            if (! isset($map[$key])) {
                throw new InvalidArgumentException("Commission code {$amountCode} needs the premium status (ISSUED or COLLECTED) to be exported.");
            }

            return ['measure' => $map[$key], 'intermediary_type' => $amountCode];
        }
        throw new InvalidArgumentException("Internal code {$amountCode} has no regulatory export mapping.");
    }

    /** @return list<string> the regulator measures this adapter can emit */
    public function measures(string $regime = 'CIMA'): array
    {
        return array_values(array_unique(self::MAP[$regime] ?? []));
    }
}
