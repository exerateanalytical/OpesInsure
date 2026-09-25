<?php

declare(strict_types=1);

namespace App\Application\Policies\Lapse;

use App\Application\Events\OutboxWriter;
use App\Application\Policies\Suspension\PolicySuspensionService;
use App\Models\Policy;
use Illuminate\Support\Facades\DB;

/**
 * PolicySuspender over the canonical suspension path (REQ-POL-006): premium default opens a policy_suspensions
 * episode with source PREMIUM_DEFAULT (SUSPENSION chronology version, history, policy.suspended) and additionally
 * publishes policy.premium.suspended for premium-cover consumers. Reinstatement goes through PolicySuspensionService
 * or PolicyRecoveryService — this class never reactivates.
 */
final class SuspensionServicePolicySuspender implements PolicySuspender
{
    public function __construct(
        private readonly PolicySuspensionService $suspensions,
        private readonly OutboxWriter $outbox,
    ) {}

    public function suspend(string $policyId, string $reasonCode, array $context = []): bool
    {
        return DB::transaction(function () use ($policyId, $reasonCode, $context): bool {
            $policy = Policy::whereKey($policyId)->lockForUpdate()->first();
            if (! $policy || $policy->status !== 'ACTIVE') {
                return false;
            }
            $suspension = $this->suspensions->suspend($policy, $reasonCode, null, [
                'source' => 'PREMIUM_DEFAULT',
                'notes' => isset($context['instalment_id']) ? 'Instalment '.$context['instalment_id'] : null,
            ]);
            $this->outbox->record('policy.premium.suspended', 'policy', $policy->id, [
                'policy_id' => $policy->id, 'reason_code' => $reasonCode, 'suspension_id' => $suspension->id,
            ] + $context);

            return true;
        });
    }
}
