<?php

declare(strict_types=1);

namespace App\Application\Agents;

use App\Application\Identity\PartyResolver;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * Resolves the freelance-agent Partner org behind an authenticated mobile
 * caller — the agent-persona analogue of PartyResolver::forUser() that every
 * customer-facing batch scopes its data by. A Partner's own party_id is set
 * to the SAME party as the agent User's users.party_id (see
 * PartnerController::store and the Wave12 agent fixture helpers), so
 * PartyResolver::partnerForUser() already does the actual lookup; this class
 * only adds the two checks the Patch 4 merge guide requires on top of that:
 * the resolved Partner must be of type AGENT (broker staff have their own
 * broker.* surface elsewhere — see BrokerOperationsController), and —
 * for resolveActive() only — must be ACTIVE.
 *
 * "Suspended/expired agents may view permitted history but cannot register
 * clients, sell or withdraw": read endpoints call resolve() (any status),
 * mutating endpoints call resolveActive() (ACTIVE only).
 */
final class AgentPartnerResolver
{
    public function __construct(private readonly PartyResolver $parties)
    {
    }

    public function resolve(User $user): Partner
    {
        $partner = $this->parties->partnerForUser($user);

        if (! $partner || $partner->type !== 'AGENT') {
            throw new AuthorizationException(__('wave12.agent_not_registered'));
        }

        return $partner;
    }

    public function resolveActive(User $user): Partner
    {
        $partner = $this->resolve($user);

        if ($partner->status !== 'ACTIVE') {
            throw ValidationException::withMessages(['partner' => [__('wave12.agent_not_active')]]);
        }

        return $partner;
    }
}
