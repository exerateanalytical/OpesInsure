<?php

declare(strict_types=1);

namespace App\Application\Customers\Customer360;

use App\Application\Customers\Roles\PartyRoleService;
use App\Application\Identity\Rbac\DataScopeResolver;
use App\Domain\Tenancy\TenantContext;
use App\Models\Claim;
use App\Models\Document;
use App\Models\Party;
use App\Models\Policy;
use App\Models\TenantCustomer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * REQ-CRM-002 (WF-088) — Customer 360: one read model over the golden party across roles, for the current tenant,
 * every section filtered by DataScopeResolver (the caller only sees rows its data scope allows). Read-only:
 * each section reads the canonical table; nothing is copied.
 */
final class Customer360Service
{
    public function __construct(
        private readonly DataScopeResolver $scopes,
        private readonly TenantContext $tenant,
        private readonly PartyRoleService $roles,
    ) {}

    /** @return array<string, mixed>|null null = not a customer the caller may see */
    public function overview(Party $party, User $user): ?array
    {
        $tenantId = $this->tenant->id();
        $customer = $this->scopes->apply(TenantCustomer::query(), $user, ['tenant' => 'tenant_customers.tenant_id', 'own' => 'tenant_customers.party_id'])
            ->where('tenant_customers.party_id', $party->id)->first(['tenant_customers.id', 'tenant_customers.customer_number', 'tenant_customers.status']);
        if (! $customer) {
            return null;
        }

        $policies = $this->scopes->apply(Policy::query(), $user, ['tenant' => 'policies.tenant_id', 'own' => 'policies.party_id', 'carrier' => 'policies.carrier_id'])
            ->where('policies.party_id', $party->id)
            ->orderByDesc('policies.coverage_starts_at')
            ->get(['policies.id', 'policies.policy_number', 'policies.certificate_number', 'policies.status', 'policies.carrier_id', 'policies.coverage_starts_at', 'policies.coverage_ends_at']);
        $policyIds = $policies->pluck('id')->all();

        $claims = $policyIds === [] ? collect() : $this->scopes->apply(Claim::query()->join('policies', 'policies.id', '=', 'claims.policy_id'), $user,
            ['tenant' => 'claims.tenant_id', 'own' => 'policies.party_id', 'assigned' => 'claims.assigned_to', 'carrier' => 'policies.carrier_id'])
            ->whereIn('claims.policy_id', $policyIds)->orderByDesc('claims.loss_occurred_at')
            ->get(['claims.id', 'claims.claim_number', 'claims.status', 'claims.policy_id', 'claims.loss_occurred_at', 'claims.submitted_at']);

        $beneficiaries = DB::table('beneficiary_designations')->where('tenant_id', $tenantId)
            ->where(fn ($q) => $q->whereIn('policy_id', $policyIds)->orWhere('party_id', $party->id))
            ->where('status', 'ACTIVE')->orderBy('policy_id')
            ->get(['id', 'policy_id', 'designation', 'party_id', 'full_name', 'relationship', 'allocation_pct', 'revocable', 'effective_from', 'effective_to']);
        // designations naming this party on policies the caller cannot see are reduced to the fact that they exist
        $beneficiaries = $beneficiaries->map(fn ($b) => in_array($b->policy_id, $policyIds, true) ? $b : (object) ['id' => $b->id, 'designation' => $b->designation, 'party_id' => $b->party_id, 'policy_id' => null, 'restricted' => true]);

        $kyc = DB::table('kyc_submissions')->where('tenant_id', $tenantId)->where('party_id', $party->id)->orderByDesc('created_at')
            ->get(['id', 'status', 'submitted_at', 'created_at']);

        $documents = $this->scopes->apply(Document::query(), $user, ['tenant' => 'documents.tenant_id', 'own' => 'documents.party_id', 'carrier' => 'documents.issuer_carrier_id'])
            ->where('documents.party_id', $party->id)->orderByDesc('documents.created_at')->limit(100)
            ->get(['documents.id', 'documents.category', 'documents.mime_type', 'documents.verification_status', 'documents.created_at']);

        $leads = DB::table('partner_leads')->where('tenant_id', $tenantId)->where('converted_customer_id', $customer->id)->orderByDesc('created_at')
            ->get(['id', 'partner_id', 'status', 'product_interest', 'converted_at', 'created_at']);

        $attributions = DB::table('customer_attributions')->where('party_id', $party->id)->orderByDesc('effective_from')
            ->get(['id', 'partner_id', 'origin_type', 'status', 'effective_from', 'effective_until']);
        $attributionEvents = DB::table('attribution_events')->whereIn('attribution_id', $attributions->pluck('id'))->orderByDesc('occurred_at')
            ->get(['id', 'attribution_id', 'type', 'from_partner_id', 'to_partner_id', 'reason_code', 'occurred_at']);

        $timeline = collect()
            ->merge($policies->map(fn ($p) => ['at' => $p->coverage_starts_at, 'kind' => 'policy', 'id' => $p->id, 'label' => $p->policy_number ?? $p->status]))
            ->merge($claims->map(fn ($c) => ['at' => $c->submitted_at ?? $c->loss_occurred_at, 'kind' => 'claim', 'id' => $c->id, 'label' => $c->claim_number]))
            ->merge($kyc->map(fn ($k) => ['at' => $k->submitted_at ?? $k->created_at, 'kind' => 'kyc', 'id' => $k->id, 'label' => $k->status]))
            ->merge($leads->map(fn ($l) => ['at' => $l->created_at, 'kind' => 'lead', 'id' => $l->id, 'label' => $l->status]))
            ->merge($attributionEvents->map(fn ($e) => ['at' => $e->occurred_at, 'kind' => 'attribution', 'id' => $e->id, 'label' => $e->type]))
            ->filter(fn ($e) => $e['at'] !== null)->sortByDesc('at')->values()->take(200);

        return [
            'party' => ['id' => $party->id, 'type' => $party->type, 'display_name' => $party->display_name, 'status' => $party->status, 'merged_into_id' => $party->merged_into_id],
            'customer' => ['id' => $customer->id, 'customer_number' => $customer->customer_number, 'status' => $customer->status],
            'roles' => $this->roles->asOf($party->id, null, null, $tenantId)->map(fn ($r) => $r->only(['id', 'role_code', 'context_type', 'context_id', 'valid_from', 'valid_to']))->values(),
            'policies' => $policies, 'claims' => $claims, 'beneficiaries' => $beneficiaries->values(), 'kyc' => $kyc, 'documents' => $documents,
            'leads' => $leads, 'attribution' => ['attributions' => $attributions, 'events' => $attributionEvents],
            'counts' => ['policies' => $policies->count(), 'claims' => $claims->count(), 'documents' => $documents->count()],
            'timeline' => $timeline,
        ];
    }
}
