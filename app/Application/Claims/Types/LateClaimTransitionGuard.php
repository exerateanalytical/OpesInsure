<?php

declare(strict_types=1);

namespace App\Application\Claims\Types;

use App\Domain\Claims\ClaimTransitionGuard;
use App\Models\Claim;

/** REQ-CLM-007 — a claim reported after its configured reporting period cannot start assessment until the late report is approved (maker-checker). */
final class LateClaimTransitionGuard implements ClaimTransitionGuard
{
    public function __construct(private readonly ClaimReportingService $reporting) {}

    public function events(): array
    {
        return ['start_assessment'];
    }

    public function check(Claim $claim, string $event, array $context): ?string
    {
        return $event === 'start_assessment' ? $this->reporting->assessmentBlocker($claim) : null;
    }
}
