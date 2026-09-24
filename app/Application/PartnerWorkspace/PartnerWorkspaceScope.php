<?php

declare(strict_types=1);

namespace App\Application\PartnerWorkspace;

use App\Application\Agents\AgentPartnerResolver;
use App\Application\Identity\CarrierScopeResolver;
use App\Application\Identity\PartyResolver;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * One place that answers "whose book is this caller allowed to see?" for
 * the Wave 16 partner workspaces:
 *
 *  - agent   → the AGENT Partner behind the user (AgentPartnerResolver; 403 otherwise)
 *  - broker  → the BROKER Partner behind the user (PartyResolver), or none — a
 *              broker user with no partner sees an empty book, never the tenant's
 *  - carrier → the carrier id from CarrierScopeResolver (null = platform staff,
 *              tenant-wide; an unlinked insurer outside a CARRIER tenant is 403)
 *
 * "Book" = parties with an ACTIVE customer_attribution to that partner,
 * the same origin-lock the existing agent/broker portals count clients by.
 */
final class PartnerWorkspaceScope
{
    public function __construct(
        private readonly AgentPartnerResolver $agents,
        private readonly PartyResolver $parties,
        private readonly CarrierScopeResolver $carriers,
    ) {}

    public function agent(User $user): Partner
    {
        return $this->agents->resolve($user);
    }

    public function activeAgent(User $user): Partner
    {
        return $this->agents->resolveActive($user);
    }

    public function broker(User $user): ?Partner
    {
        $partner = $this->parties->partnerForUser($user);

        return $partner && $partner->type === 'BROKER' ? $partner : null;
    }

    /** @return list<string> party ids origin-locked to $partner */
    public function bookPartyIds(?Partner $partner): array
    {
        if (! $partner) {
            return [];
        }

        return DB::table('customer_attributions')->where('partner_id', $partner->id)->where('status', 'ACTIVE')->pluck('party_id')->unique()->values()->all();
    }

    public function carrierId(User $user, string $tenantId): ?string
    {
        return $this->carriers->carrierIdFor($user, $tenantId);
    }
}
