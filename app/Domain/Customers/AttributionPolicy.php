<?php

declare(strict_types=1);

namespace App\Domain\Customers;

final readonly class AttributionDecision
{
    public function __construct(public ?string $partnerId, public string $reason, public string $termsVersion) {}
}

final class AttributionPolicy
{
    public function decide(array $effectiveAttributions, \DateTimeImmutable $at, string $termsVersion): AttributionDecision
    {
        $eligible = array_values(array_filter($effectiveAttributions, static fn (array $row): bool =>
            $row['effective_from'] <= $at
            && (! isset($row['effective_until']) || $row['effective_until'] >= $at)
            && ($row['status'] ?? 'ACTIVE') === 'ACTIVE'
        ));
        usort($eligible, static fn (array $a, array $b): int => $b['effective_from'] <=> $a['effective_from']);
        return isset($eligible[0])
            ? new AttributionDecision($eligible[0]['partner_id'], 'EFFECTIVE_ORIGIN', $termsVersion)
            : new AttributionDecision(null, 'DIRECT_SYSTEM', $termsVersion);
    }
}
