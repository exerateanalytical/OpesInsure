<?php

declare(strict_types=1);

namespace App\Application\Partners;

use App\Application\Identity\PartyResolver;
use App\Domain\Tenancy\TenantContext;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Owner decision: "Partners quote for their own clients and new clients."
 *
 * A caller whose only active non-CUSTOMER roles in the current tenant are
 * partner roles (AGENT, BROKER_*) is "book scoped": every quote → offer
 * accept → proposal → payment step they take must target a party in their
 * book. The book is the parties with an ACTIVE customer_attribution to the
 * caller's Partner (the same origin lock /mobile/agent/clients and
 * /mobile/broker/clients list by — see PartnerWorkspaceScope). A client the
 * partner onboards (agent intake, broker client onboarding) is origin-locked
 * to that partner in the same transaction, so it is in the book at once.
 * The partner may also act for their own party.
 *
 * Staff/admin callers holding any non-partner role keep tenant-wide
 * behaviour; customers stay on OwnershipScope's own-party rule.
 */
final class PartnerBook
{
    public const PARTNER_ROLES = ['AGENT', 'BROKER_ADMIN', 'BROKER_SUPERVISOR', 'BROKER_STAFF'];

    public function __construct(private readonly PartyResolver $parties, private readonly TenantContext $context) {}

    public function isBookScoped(User $user): bool
    {
        $roles = $user->memberships()->where('tenant_id', $this->context->id())->where('status', 'ACTIVE')
            ->where('role_code', '!=', 'CUSTOMER')->pluck('role_code');

        return $roles->isNotEmpty() && $roles->every(fn ($r) => in_array((string) $r, self::PARTNER_ROLES, true));
    }

    public function partner(User $user): ?Partner
    {
        $partner = $this->parties->partnerForUser($user);

        return $partner && in_array($partner->type, ['AGENT', 'BROKER'], true) ? $partner : null;
    }

    public function contains(User $user, ?string $partyId): bool
    {
        if (! $this->isBookScoped($user)) {
            return true;
        }
        if ($partyId === null) {
            return false;
        }
        if ($partyId === $user->party_id) {
            return true;
        }
        $partner = $this->partner($user);

        return $partner !== null && DB::table('customer_attributions')
            ->where(['party_id' => $partyId, 'partner_id' => $partner->id, 'status' => 'ACTIVE'])->exists();
    }

    /** 403 when a book-scoped partner targets a party outside their book. */
    public function assertInBook(User $user, ?string $partyId): void
    {
        if (! $this->contains($user, $partyId)) {
            abort(403, __('quotes.partner_outside_book'));
        }
    }
}
