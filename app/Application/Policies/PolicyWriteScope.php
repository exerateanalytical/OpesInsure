<?php

declare(strict_types=1);

namespace App\Application\Policies;

use App\Application\Identity\CarrierScopeResolver;
use App\Application\Identity\OwnershipScope;
use App\Application\Identity\Rbac\DataScope;
use App\Application\Partners\BookScope;
use App\Domain\Tenancy\TenantContext;
use App\Models\Policy;
use App\Models\User;
use Illuminate\Contracts\Database\Eloquent\Builder;

/**
 * Launch security review R1 (2026-09-29): which policies of the current tenant a caller may WRITE to through the core
 * servicing routes (POST policies/{p}/transactions, /cancellations, /sticker). Tenant filtering alone let any broker,
 * agent or insurer user act on every policy of a shared tenant. Narrowing, fail closed:
 *  - customer (owner scoped) → own party only (OwnershipScope); platform-only roles → nothing;
 *  - book-scoped distribution users (agent, broker admin/supervisor/staff, branch manager) → their book (BookScope);
 *  - insurer users linked to a carrier → that carrier's policies (CarrierScopeResolver);
 *  - tenant operations staff → the tenant.
 * A foreign policy is indistinguishable from a missing one (404).
 */
final class PolicyWriteScope
{
    public function __construct(
        private readonly OwnershipScope $own,
        private readonly BookScope $book,
        private readonly CarrierScopeResolver $carriers,
        private readonly TenantContext $context,
    ) {}

    public function apply(Builder $query, User $user): Builder
    {
        $query = $this->own->apply($query, $user);
        if ($this->own->isOwnerScoped($user)) {
            return $query;
        }
        $parties = $this->book->parties($user);
        if ($parties !== null) {
            $query->whereIn('policies.party_id', $parties);
        }
        if ($this->book->scope($user) === DataScope::CARRIER_RELATIONSHIP) {
            $carrier = rescue(fn () => $this->carriers->carrierIdFor($user, (string) $this->context->id()), false, false);
            if ($carrier === false) {
                return $query->whereRaw('1 = 0');
            }
            if ($carrier !== null) {
                $query->where('policies.carrier_id', $carrier);
            }
        }

        return $query;
    }

    public function find(string $policyId, User $user): Policy
    {
        return $this->apply(Policy::query()->where('policies.tenant_id', $this->context->id()), $user)->findOrFail($policyId);
    }

    public function allows(Policy $policy, User $user): bool
    {
        return $this->apply(Policy::query()->where('policies.tenant_id', $policy->tenant_id), $user)->whereKey($policy->getKey())->exists();
    }
}
