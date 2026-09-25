<?php

declare(strict_types=1);

namespace App\Application\Policies\Lapse;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Domain\Policies\PolicyStateMachine;
use App\Models\Policy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Default PolicySuspender: ACTIVE → SUSPENDED through PolicyStateMachine, recorded in policy_status_history,
 * audited and published as policy.premium.suspended. Reinstatement goes back through
 * PolicyServicingService (REINSTATEMENT) or PolicyRecoveryService — this class never reactivates.
 */
final class StateMachinePolicySuspender implements PolicySuspender
{
    public function __construct(
        private readonly PolicyStateMachine $machine,
        private readonly AuditWriter $audit,
        private readonly OutboxWriter $outbox,
    ) {}

    public function suspend(string $policyId, string $reasonCode, array $context = []): bool
    {
        return DB::transaction(function () use ($policyId, $reasonCode, $context): bool {
            $policy = Policy::whereKey($policyId)->lockForUpdate()->first();
            if (! $policy || $policy->status !== 'ACTIVE') {
                return false;
            }
            $this->machine->assert('ACTIVE', 'SUSPENDED');
            $policy->update(['status' => 'SUSPENDED']);
            DB::table('policy_status_history')->insert([
                'id' => (string) Str::uuid(), 'policy_id' => $policy->id, 'from_status' => 'ACTIVE', 'to_status' => 'SUSPENDED',
                'reason_code' => $reasonCode, 'actor_id' => null,
                'metadata' => json_encode(['source' => 'premium-cover'] + $context), 'occurred_at' => now(),
            ]);
            $this->audit->record('policy.premium.suspended', 'policy', $policy->id, $context, $reasonCode);
            $this->outbox->record('policy.premium.suspended', 'policy', $policy->id, ['policy_id' => $policy->id, 'reason_code' => $reasonCode] + $context);

            return true;
        });
    }
}
