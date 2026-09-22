<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Models\Partner;
use App\Models\Party;
use App\Models\PartyContact;
use App\Models\TenantCustomer;
use App\Models\User;

/**
 * users.party_id is the authoritative link (added specifically to replace
 * the phone-number heuristic this class used to be built entirely around —
 * see MOBILE_BACKEND_CONTRACTS_PROGRESS.md and the migration that added the
 * column for why). The phone match is now only a one-time bootstrap for
 * users created before that FK existed: forUser() persists whatever it
 * finds back onto the User row, so the heuristic is consulted at most once
 * per user, not on every request.
 */
final class PartyResolver
{
    public function forUser(User $user): ?Party
    {
        if ($user->party_id) {
            return $user->party ?? $this->backfillFromPhone($user);
        }

        return $this->backfillFromPhone($user);
    }

    public function partnerForUser(User $user): ?Partner
    {
        $party = $this->forUser($user);

        if (! $party) {
            return null;
        }

        return Partner::where('party_id', $party->id)->first();
    }

    public function tenantCustomerId(User $user, string $tenantId): ?string
    {
        $party = $this->forUser($user);

        if (! $party) {
            return null;
        }

        return TenantCustomer::where('tenant_id', $tenantId)->where('party_id', $party->id)->value('id');
    }

    private function backfillFromPhone(User $user): ?Party
    {
        if (! $user->phone_e164) {
            return null;
        }

        $party = PartyContact::where('type', 'PHONE')->where('normalized_value', $user->phone_e164)->first()?->party;

        if ($party && $user->party_id !== $party->id) {
            $user->update(['party_id' => $party->id]);
        }

        return $party;
    }
}
