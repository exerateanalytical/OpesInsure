<?php

declare(strict_types=1);

namespace App\Application\Health\Benefits;

/**
 * REQ-HLT-004: a benefit accumulator movement was refused.
 * Reason codes: schedule_not_found, benefit_exhausted, family_exhausted, per_event_exceeded,
 * per_visit_exceeded, visits_exhausted, waiting_period, insufficient_reserve, invalid_amount,
 * idempotency_conflict, not_reversible, member_required.
 */
final class BenefitRefused extends \DomainException
{
    public function __construct(public readonly string $reasonCode, string $message, public readonly ?int $remainingMinor = null)
    {
        parent::__construct($message);
    }
}
