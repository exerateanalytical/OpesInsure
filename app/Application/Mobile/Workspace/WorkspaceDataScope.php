<?php

declare(strict_types=1);

namespace App\Application\Mobile\Workspace;

use App\Application\Claims\Adjusters\ExpertAssignmentService;
use App\Application\Identity\CarrierScopeResolver;
use App\Application\Identity\PartyResolver;
use App\Application\Identity\Rbac\DataScope;
use App\Application\Identity\Rbac\DataScopeResolver;
use App\Application\Identity\Rbac\RequestMemo;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;

/**
 * Phase-1 fix S (owner-approved 2026-09-30): the mobile staff workspace (mobile/workspace/*) narrowed to the caller's
 * data scope, on top of the tenant filter. The same boundaries the web portals and the core APIs already apply:
 *
 *  - carrier: a carrier-linked caller (CarrierScopeResolver — membership carrier_id or a CARRIER partner) sees its own
 *    carrier's book only, whatever its role's default scope; an unlinked insurer role outside an insurer tenant sees
 *    nothing (the resolver refuses it);
 *  - tenant: TENANT scope (and an unlinked insurer role inside the insurer's own tenant) = the whole tenant;
 *  - branch: BRANCH_MANAGER (DataScope::BRANCH) and the D10 till operator (CASHIER) = rows stamped with one of the
 *    caller's membership branches (branch_id, S2 branch stamping);
 *  - assigned: ADJUSTER (DataScope::ASSIGNED) = claims assigned to the caller (claims.assigned_to) or carrying an
 *    EXPERT assignment of a provider profile the caller acts for (ExpertAssignmentService::providerIdsFor);
 *  - team / organization: claims of the team's assignees / policies serviced by the caller's partner;
 *  - none: OWN, PLATFORM, REGULATOR_READ, no membership = no business rows (REQ-RBAC-004).
 *
 * Every narrowing is a sub-select on ids, so the callers keep their own joins, orderings and counts.
 */
final class WorkspaceDataScope
{
    /** Tenant roles whose workspace is bound to their branch although their catalogue scope is tenant-wide. */
    public const BRANCH_BOUND_ROLES = ['CASHIER'];

    private const NONE = '00000000-0000-0000-0000-000000000000';

    public function __construct(
        private readonly TenantContext $context,
        private readonly CarrierScopeResolver $carriers,
        private readonly DataScopeResolver $scopes,
        private readonly PartyResolver $parties,
    ) {}

    /**
     * @return array{mode: 'tenant'|'carrier'|'branch'|'assigned'|'team'|'organization'|'none', carrier?: string, branches?: list<string>, users?: list<string>, partner?: ?string}
     */
    public function resolve(User $user): array
    {
        $tenant = $this->context->id();

        return RequestMemo::remember('workspace-scope:'.$user->getKey().':'.$tenant, function () use ($user, $tenant) {
            try {
                $carrier = $this->carriers->carrierIdFor($user, $tenant);
            } catch (AuthorizationException) {
                return ['mode' => 'none'];
            }
            if ($carrier !== null) {
                return ['mode' => 'carrier', 'carrier' => $carrier];
            }
            $scope = $this->scopes->effectiveScope($user, $tenant);
            $roles = DataScopeResolver::activeMemberships($user, $tenant)->pluck('role_code')->map(fn ($r) => (string) $r)->unique()->values()->all();
            if ($scope === DataScope::TENANT && $roles !== [] && array_diff($roles, self::BRANCH_BOUND_ROLES) === []) {
                $scope = DataScope::BRANCH;
            }

            return match ($scope) {
                DataScope::TENANT, DataScope::CARRIER_RELATIONSHIP => ['mode' => 'tenant'],
                DataScope::BRANCH => ['mode' => 'branch', 'branches' => $this->scopes->branchIds($user, $tenant)],
                DataScope::ASSIGNED => ['mode' => 'assigned'],
                DataScope::TEAM => ['mode' => 'team', 'users' => $this->scopes->teamUserIds($user, $tenant)],
                DataScope::ORGANIZATION => ['mode' => 'organization', 'partner' => $this->parties->partnerForUser($user)?->getKey()],
                default => ['mode' => 'none'],
            };
        });
    }

    public function isTenantWide(User $user): bool
    {
        return $this->resolve($user)['mode'] === 'tenant';
    }

    /** policies (table name or alias "policies"). */
    public function policies(EloquentBuilder|QueryBuilder $q, User $user, string $table = 'policies'): EloquentBuilder|QueryBuilder
    {
        $ids = $this->policyIds($user);

        return $ids === null ? $q : $q->whereIn($table.'.id', $ids);
    }

    /** claims (table name or alias "claims"). */
    public function claims(EloquentBuilder|QueryBuilder $q, User $user, string $table = 'claims'): EloquentBuilder|QueryBuilder
    {
        $ids = $this->claimIds($user);

        return $ids === null ? $q : $q->whereIn($table.'.id', $ids);
    }

    /** payment_intents: through their proposal (carrier of the offer, branch of the proposal). */
    public function paymentIntents(EloquentBuilder|QueryBuilder $q, User $user, string $table = 'payment_intents'): EloquentBuilder|QueryBuilder
    {
        $s = $this->resolve($user);
        $t = $this->context->id();
        $proposals = match ($s['mode']) {
            'tenant' => null,
            'carrier' => DB::table('proposals')->join('quote_offers', 'quote_offers.id', '=', 'proposals.quote_offer_id')->where('proposals.tenant_id', $t)->where('quote_offers.carrier_id', $s['carrier'])->select('proposals.id'),
            'branch' => DB::table('proposals')->where('tenant_id', $t)->whereIn('branch_id', $this->branches($s))->select('id'),
            default => false,
        };

        return match (true) {
            $proposals === null => $q,
            $proposals === false => $q->whereRaw('1 = 0'),
            default => $q->whereIn($table.'.proposal_id', $proposals),
        };
    }

    /** tenant_customers: parties holding a policy the caller may see. */
    public function customers(EloquentBuilder|QueryBuilder $q, User $user, string $table = 'tenant_customers'): EloquentBuilder|QueryBuilder
    {
        $ids = $this->policyIds($user);

        return $ids === null ? $q : $q->whereIn($table.'.party_id', DB::table('policies')->whereIn('id', $ids)->select('party_id'));
    }

    /** support_tickets: linked to a visible policy / claim, or to a visible customer; assigned roles see their own. */
    public function supportTickets(EloquentBuilder|QueryBuilder $q, User $user, string $table = 'support_tickets'): EloquentBuilder|QueryBuilder
    {
        $s = $this->resolve($user);
        if ($s['mode'] === 'tenant') {
            return $q;
        }
        if (in_array($s['mode'], ['assigned', 'team'], true)) {
            return $q->whereIn($table.'.assigned_to', $s['mode'] === 'team' ? ($s['users'] ?: [self::NONE]) : [(string) $user->getKey()]);
        }
        $policies = $this->policyIds($user);
        if ($policies === null) {
            return $q;
        }

        return $q->where(fn ($w) => $w->whereIn($table.'.policy_id', $policies)
            ->orWhereIn($table.'.claim_id', $this->claimIds($user))
            ->orWhereIn($table.'.party_id', DB::table('policies')->whereIn('id', $this->policyIds($user))->select('party_id')));
    }

    /** cashier_sessions: the caller's branches; without a branch a till operator sees only their own sessions. */
    public function cashierSessions(EloquentBuilder|QueryBuilder $q, User $user, string $table = 'cashier_sessions'): EloquentBuilder|QueryBuilder
    {
        $s = $this->resolve($user);

        return match ($s['mode']) {
            'tenant' => $q,
            'branch' => $this->branches($s) !== [self::NONE] ? $q->whereIn($table.'.branch_id', $this->branches($s)) : $q->where($table.'.cashier_user_id', $user->getKey()),
            default => $q->where($table.'.cashier_user_id', $user->getKey()),
        };
    }

    /** reinsurance_treaties: the caller's carrier (carrier_id); tenant-wide otherwise, nothing for narrower scopes. */
    public function treaties(EloquentBuilder|QueryBuilder $q, User $user, string $table = 'reinsurance_treaties'): EloquentBuilder|QueryBuilder
    {
        $s = $this->resolve($user);

        return match ($s['mode']) {
            'tenant' => $q,
            'carrier' => $q->where($table.'.carrier_id', $s['carrier']),
            default => $q->whereRaw('1 = 0'),
        };
    }

    /** reinsurance_cessions: of a visible policy. */
    public function cessions(EloquentBuilder|QueryBuilder $q, User $user, string $table = 'reinsurance_cessions'): EloquentBuilder|QueryBuilder
    {
        $ids = $this->policyIds($user);

        return $ids === null ? $q : $q->whereIn($table.'.policy_id', $ids);
    }

    /** compliance_cases: tenant-wide callers see all; anyone else only the cases they own. */
    public function complianceCases(EloquentBuilder|QueryBuilder $q, User $user, string $table = 'compliance_cases'): EloquentBuilder|QueryBuilder
    {
        return $this->isTenantWide($user) ? $q : $q->where($table.'.owner_id', $user->getKey());
    }

    /** Sub-select of the visible policy ids, or null when nothing narrows them (tenant-wide). */
    public function policyIds(User $user): ?QueryBuilder
    {
        $s = $this->resolve($user);
        $base = fn () => DB::table('policies')->where('tenant_id', $this->context->id());

        return match ($s['mode']) {
            'tenant' => null,
            'carrier' => $base()->where('carrier_id', $s['carrier'])->select('id'),
            'branch' => $base()->whereIn('branch_id', $this->branches($s))->select('id'),
            'organization' => $base()->where('servicing_partner_id', $s['partner'] ?? self::NONE)->select('id'),
            // Assigned / team roles reach policies only through the claims they work.
            'assigned', 'team' => $base()->whereIn('id', DB::table('claims')->whereIn('id', $this->claimIds($user))->select('policy_id'))->select('id'),
            default => $base()->whereRaw('1 = 0')->select('id'),
        };
    }

    /** Sub-select of the visible claim ids, or null when nothing narrows them (tenant-wide). */
    public function claimIds(User $user): ?QueryBuilder
    {
        $s = $this->resolve($user);
        $base = fn () => DB::table('claims')->where('tenant_id', $this->context->id());

        return match ($s['mode']) {
            'tenant' => null,
            'carrier' => $base()->whereIn('policy_id', DB::table('policies')->where('carrier_id', $s['carrier'])->select('id'))->select('id'),
            'branch' => $base()->whereIn('branch_id', $this->branches($s))->select('id'),
            'organization' => $base()->whereIn('policy_id', $this->policyIds($user))->select('id'),
            'team' => $base()->whereIn('assigned_to', $s['users'] ?: [self::NONE])->select('id'),
            'assigned' => $base()->where(fn ($w) => $w->where('assigned_to', $user->getKey())
                ->orWhereIn('id', DB::table('claim_assignments')->where('assignment_type', 'EXPERT')->where('status', '!=', 'CANCELLED')
                    ->whereIn('provider_profile_id', $this->providerIds($user))->select('claim_id')))->select('id'),
            default => $base()->whereRaw('1 = 0')->select('id'),
        };
    }

    /** @return list<string> */
    private function providerIds(User $user): array
    {
        return app(ExpertAssignmentService::class)->providerIdsFor($user) ?: [self::NONE];
    }

    /** @return list<string> */
    private function branches(array $s): array
    {
        return ($s['branches'] ?? []) ?: [self::NONE];
    }
}
