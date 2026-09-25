<?php

declare(strict_types=1);

namespace App\Application\Policies\Lapse;

use Illuminate\Container\Attributes\Bind;

/**
 * Seam for suspending an in-force policy on premium default (REQ-POL-008 SUSPEND_ON_DEFAULT).
 *
 * The canonical suspension service (App\Application\Policies\Suspension, REQ-POL-006) is built separately;
 * until it is merged the default binding is StateMachinePolicySuspender. On merge, rebind this interface
 * to an adapter over the Suspension service — the lapse sweep only depends on this contract.
 */
#[Bind(StateMachinePolicySuspender::class)]
interface PolicySuspender
{
    /**
     * Suspend the policy. Returns true when the policy moved to SUSPENDED, false when it was already
     * suspended or is not in a suspendable state (never throws for those; callers stay idempotent).
     *
     * @param  array<string, mixed>  $context
     */
    public function suspend(string $policyId, string $reasonCode, array $context = []): bool;
}
