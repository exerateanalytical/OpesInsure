<?php

declare(strict_types=1);

namespace App\Application\Distribution;

/**
 * REQ-DST-001 — default selling channel per viewer. carrier_broker_agreements.channels is a free list
 * (seeded values: B2C, AGENT); an empty list means every channel is allowed.
 */
final class DistributionChannels
{
    public const BY_PARTNER_TYPE = ['AGENT' => 'AGENT', 'BROKER' => 'BROKER'];

    public static function defaultFor(?string $partnerType): string
    {
        return self::BY_PARTNER_TYPE[$partnerType] ?? 'B2C';
    }
}
