<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\PartnerWorkspace;

use App\Application\PartnerWorkspace\CarrierWorkspaceActions;
use App\Application\PartnerWorkspace\PartnerWorkspaceScope;
use App\Domain\Tenancy\TenantContext;
use App\Models\Claim;
use App\Models\ClaimDecision;
use App\Models\InsuranceProduct;
use App\Models\Policy;
use App\Models\PolicyIssuanceRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Insurer workspace: products, proposals, policies, claim actions,
 * issuance actions, payments/reconciliation and distribution partners.
 * Every read and every action is restricted to the caller's carrier
 * (CarrierScopeResolver); a record of another insurer is a 404.
 */
final class PartnerCarrierWorkspaceController
{
    public function __construct(private PartnerWorkspaceScope $scope, private CarrierWorkspaceActions $actions) {}

    private function tenant(): string
    {
        return app(TenantContext::class)->id();
    }

    private function carrier(Request $request): ?string
    {
        return $this->scope->carrierId($request->user(), $this->tenant());
    }

    // ------------------------------------------------------------ products

    public function products(Request $request): JsonResponse
    {
        $t = $this->tenant();
        $canToggle = $request->user()->hasPermission('carrier.authority.manage');
        $rows = $this->productQuery($request, $t)->with(['tariffs', 'carrier.party'])->orderBy('name')->orderByDesc('version')->limit(200)->get();

        return response()->json(['data' => $rows->map(fn (InsuranceProduct $p) => $this->productOf($p, $t, $canToggle))->values()]);
    }

    public function productStatus(string $product, Request $request): JsonResponse
    {
        $data = $request->validate(['active' => 'required|boolean', 'reason' => 'required|string|min:3|max:500']);
        $t = $this->tenant();
        $p = $this->productQuery($request, $t)->whereKey($this->uuid($product))->firstOrFail();
        $p = $this->actions->setProductActive($p, (bool) $data['active'], $data['reason'], $request->user());

        return response()->json(['data' => $this->productOf($p->load(['tariffs', 'carrier.party']), $t, true)]);
    }

    private function productQuery(Request $request, string $t)
    {
        $cid = $this->carrier($request);
        if ($cid !== null) {
            return InsuranceProduct::where('carrier_id', $cid);
        }
        // Platform staff: the products actually offered in this tenant.
        $offered = DB::table('quote_offers')->join('quotes', 'quotes.id', '=', 'quote_offers.quote_id')->where('quotes.tenant_id', $t)->select('quote_offers.product_id');

        return InsuranceProduct::where(fn ($q) => $q->whereIn('id', $offered)->orWhereIn('id', DB::table('marketplace_publications')->where('tenant_id', $t)->select('product_id')));
    }

    private function productOf(InsuranceProduct $p, string $t, bool $canToggle): array
    {
        $sold = DB::table('policies')->join('proposals', 'proposals.id', '=', 'policies.proposal_id')->join('quote_offers', 'quote_offers.id', '=', 'proposals.quote_offer_id')
            ->where('policies.tenant_id', $t)->where('quote_offers.product_id', $p->id);

        return [
            'id' => $p->id, 'code' => $p->code, 'name' => $p->name, 'line_code' => $p->line_code, 'version' => (int) $p->version, 'status' => $p->status,
            'carrier_id' => $p->carrier_id, 'carrier_name' => $p->carrier?->party?->display_name,
            'effective_from' => $p->effective_from?->toDateString(), 'effective_until' => $p->effective_until?->toDateString(),
            'tariffs' => $p->tariffs->sortByDesc('version')->map(fn ($tv) => ['id' => $tv->id, 'version' => (int) $tv->version, 'status' => $tv->status, 'effective_from' => $tv->effective_from?->toDateString()])->values(),
            'policies_in_force' => (clone $sold)->where('policies.status', 'ACTIVE')->count(),
            'can_toggle' => $canToggle && in_array($p->status, ['ACTIVE', 'RETIRED'], true),
        ];
    }

    // --------------------------------------------------- proposals/policies

    public function proposals(Request $request): JsonResponse
    {
        $t = $this->tenant();
        $rows = DB::table('proposals')->join('quote_offers', 'quote_offers.id', '=', 'proposals.quote_offer_id')->join('quotes', 'quotes.id', '=', 'quote_offers.quote_id')
            ->leftJoin('insurance_products', 'insurance_products.id', '=', 'quote_offers.product_id')->leftJoin('parties', 'parties.id', '=', 'proposals.party_id')
            ->where('proposals.tenant_id', $t)->when($this->carrier($request), fn ($q, $cid) => $q->where('quote_offers.carrier_id', $cid))
            ->select('proposals.id', 'proposals.proposal_number', 'proposals.status', 'proposals.submitted_at', 'proposals.created_at', 'quote_offers.carrier_id', 'quote_offers.total_minor', 'quote_offers.currency', 'quotes.id as quote_id', 'quotes.line_code', 'quotes.status as quote_status', 'insurance_products.name as product_name', 'parties.display_name')
            ->orderByDesc('proposals.created_at')->limit(100)->get();

        return response()->json(['data' => $rows->map(fn ($r) => [
            'id' => $r->id, 'reference' => $r->proposal_number ?? strtoupper(substr($r->id, 0, 8)), 'status' => $r->status, 'quote_id' => $r->quote_id, 'quote_status' => $r->quote_status,
            'customer_name' => $r->display_name ?? 'Customer', 'product' => $r->product_name ?? $r->line_code, 'line_code' => $r->line_code, 'carrier_id' => $r->carrier_id,
            'premium_minor' => (int) $r->total_minor, 'currency' => $r->currency,
            'submitted_at' => $r->submitted_at ? \Carbon\Carbon::parse($r->submitted_at)->toIso8601String() : null, 'created_at' => \Carbon\Carbon::parse($r->created_at)->toIso8601String(),
        ])->values()]);
    }

    public function policies(Request $request): JsonResponse
    {
        $rows = Policy::with(['party', 'carrier.party'])->where('tenant_id', $this->tenant())->when($this->carrier($request), fn ($q, $cid) => $q->where('carrier_id', $cid))
            ->orderByDesc('issued_at')->limit(100)->get();

        return response()->json(['data' => $rows->map(fn (Policy $p) => PartnerWorkspaceShapes::policy($p))->values()]);
    }

    // --------------------------------------------------------------- claims

    public function claim(string $claim, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->claimOf($this->scopedClaim($claim, $request), $request)]);
    }

    public function acknowledgeClaim(string $claim, Request $request): JsonResponse
    {
        $c = $this->actions->acknowledge($this->scopedClaim($claim, $request), $request->user());

        return response()->json(['data' => $this->claimOf($c, $request)]);
    }

    public function requestClaimInformation(string $claim, Request $request): JsonResponse
    {
        $c = $this->scopedClaim($claim, $request);
        $data = $request->validate(['note' => 'required|string|min:5|max:2000']);

        return response()->json(['data' => $this->claimOf($this->actions->requestInformation($c, $data['note'], $request->user()), $request)]);
    }

    public function proposeClaimDecision(string $claim, Request $request): JsonResponse
    {
        $c = $this->scopedClaim($claim, $request);
        $data = $request->validate([
            'decision' => 'required|in:APPROVE,PARTIAL,DECLINE', 'approved_amount_minor' => 'required_unless:decision,DECLINE|nullable|integer|min:0',
            'reason_code' => 'required|string|max:64', 'rationale' => 'required|string|min:5|max:4000',
        ]);
        $this->actions->proposeDecision($c, $data, $request->user());

        return response()->json(['data' => $this->claimOf($c->refresh(), $request)], 201);
    }

    public function approveClaimDecision(string $claim, string $decision, Request $request): JsonResponse
    {
        $c = $this->scopedClaim($claim, $request);
        $d = ClaimDecision::where('claim_id', $c->id)->whereKey($this->uuid($decision))->firstOrFail();

        return response()->json(['data' => $this->claimOf($this->actions->approveDecision($d, $request->user()), $request)]);
    }

    private function scopedClaim(string $id, Request $request): Claim
    {
        $cid = $this->carrier($request);

        return Claim::with(['policy.party', 'claimant'])->where('tenant_id', $this->tenant())
            ->when($cid, fn ($q) => $q->whereHas('policy', fn ($p) => $p->where('carrier_id', $cid)))->whereKey($this->uuid($id))->firstOrFail();
    }

    private function claimOf(Claim $c, Request $request): array
    {
        $c->loadMissing(['policy.party', 'claimant']);
        $pending = ClaimDecision::where(['claim_id' => $c->id, 'status' => 'PENDING_APPROVAL'])->latest()->first();
        $me = $request->user();
        $actions = match ($c->status) {
            'SUBMITTED' => ['acknowledge', 'request_information'],
            'ACKNOWLEDGED' => ['request_information', 'propose_decision'],
            'EVIDENCE_PENDING', 'ASSESSMENT' => ['propose_decision'],
            'CARRIER_REVIEW' => $pending ? (($pending->proposed_by !== $me->id && $me->hasPermission('carrier.authority.approve')) ? ['approve_decision'] : []) : ['propose_decision'],
            default => [],
        };

        return PartnerWorkspaceShapes::claim($c) + [
            'loss_location' => $c->loss_location, 'description' => $c->loss_details['description'] ?? ($c->loss_details['incident']['description'] ?? null),
            'carrier_id' => $c->policy?->carrier_id, 'actions' => $actions,
            'pending_decision' => $pending ? [
                'id' => $pending->id, 'decision' => $pending->decision, 'approved_amount_minor' => (int) $pending->approved_amount_minor, 'reason_code' => $pending->reason_code,
                'rationale' => $pending->rationale, 'proposed_by_me' => $pending->proposed_by === $me->id, 'proposed_at' => $pending->created_at?->toIso8601String(),
            ] : null,
            'timeline' => DB::table('claim_events')->where('claim_id', $c->id)->orderBy('occurred_at')->get()->map(fn ($e) => [
                'to_status' => $e->to_status, 'reason_code' => $e->reason_code, 'occurred_at' => \Carbon\Carbon::parse($e->occurred_at)->toIso8601String(),
            ])->values(),
        ];
    }

    // ------------------------------------------------------------- issuance

    public function approveIssuance(string $issuance, Request $request): JsonResponse
    {
        $data = $request->validate(['carrier_reference' => 'sometimes|string|max:64', 'policy_number' => 'sometimes|string|max:64']);
        $policy = $this->actions->approveIssuance($this->scopedIssuance($issuance, $request), $data, $request->user());

        return response()->json(['data' => ['id' => $policy->issuance_request_id, 'status' => 'APPROVED', 'policy_id' => $policy->id, 'policy_number' => $policy->policy_number, 'carrier_reference' => $policy->issuance_reference]]);
    }

    public function rejectIssuance(string $issuance, Request $request): JsonResponse
    {
        $r = $this->scopedIssuance($issuance, $request);
        $data = $request->validate(['reason' => 'required|string|min:5|max:2000']);
        $r = $this->actions->rejectIssuance($r, $data['reason'], $request->user());

        return response()->json(['data' => ['id' => $r->id, 'status' => $r->status, 'rejection_reason' => $r->rejection_reason]]);
    }

    private function scopedIssuance(string $id, Request $request): PolicyIssuanceRequest
    {
        return PolicyIssuanceRequest::where('tenant_id', $this->tenant())->when($this->carrier($request), fn ($q, $cid) => $q->where('carrier_id', $cid))
            ->whereKey($this->uuid($id))->firstOrFail();
    }

    // ------------------------------------------------------------- payments

    public function payments(Request $request): JsonResponse
    {
        $t = $this->tenant();
        $rows = DB::table('payment_intents')->join('proposals', 'proposals.id', '=', 'payment_intents.proposal_id')->join('quote_offers', 'quote_offers.id', '=', 'proposals.quote_offer_id')
            ->leftJoin('parties', 'parties.id', '=', 'proposals.party_id')
            ->where('payment_intents.tenant_id', $t)->when($this->carrier($request), fn ($q, $cid) => $q->where('quote_offers.carrier_id', $cid))
            ->select('payment_intents.*', 'proposals.proposal_number', 'parties.display_name', 'quote_offers.carrier_id')
            ->orderByDesc('payment_intents.created_at')->limit(100)->get();
        $exceptions = DB::table('reconciliation_items')->whereIn('matched_id', $rows->pluck('id'))->whereNotNull('exception_code')->whereNull('resolved_at')->pluck('exception_code', 'matched_id');

        $items = $rows->map(function ($p) use ($exceptions) {
            $recon = match (true) {
                $p->reconciled_at !== null => 'RECONCILED',
                isset($exceptions[$p->id]) => 'EXCEPTION',
                $p->status === 'SUCCEEDED' => 'UNRECONCILED',
                default => 'NOT_APPLICABLE',
            };

            return [
                'id' => $p->id, 'reference' => $p->provider_reference ?? strtoupper(substr($p->id, 0, 8)), 'proposal_number' => $p->proposal_number, 'customer_name' => $p->display_name ?? 'Customer',
                'provider' => $p->provider, 'amount_minor' => (int) $p->amount_minor, 'currency' => $p->currency, 'status' => $p->status, 'carrier_id' => $p->carrier_id,
                'reconciliation_status' => $recon, 'exception_code' => $exceptions[$p->id] ?? null,
                'reconciled_at' => $p->reconciled_at ? \Carbon\Carbon::parse($p->reconciled_at)->toIso8601String() : null, 'created_at' => \Carbon\Carbon::parse($p->created_at)->toIso8601String(),
            ];
        })->values();

        return response()->json(['data' => [
            'summary' => [
                'succeeded_minor' => (int) $items->where('status', 'SUCCEEDED')->sum('amount_minor'),
                'reconciled_minor' => (int) $items->where('reconciliation_status', 'RECONCILED')->sum('amount_minor'),
                'unreconciled_count' => $items->where('reconciliation_status', 'UNRECONCILED')->count(),
                'exception_count' => $items->where('reconciliation_status', 'EXCEPTION')->count(),
                'currency' => 'XAF',
            ],
            'items' => $items,
        ]]);
    }

    // ------------------------------------------------------------- partners

    /**
     * Brokers/agents selling this insurer's products: partners whose
     * origin-locked customers hold this insurer's policies, plus partners
     * holding a delegated-authority agreement with it.
     */
    public function partners(Request $request): JsonResponse
    {
        $t = $this->tenant();
        $cid = $this->carrier($request);
        $sales = DB::table('customer_attributions')->join('policies', 'policies.party_id', '=', 'customer_attributions.party_id')
            ->join('partners', 'partners.id', '=', 'customer_attributions.partner_id')
            ->where('customer_attributions.status', 'ACTIVE')->where('policies.tenant_id', $t)->where('partners.tenant_id', $t)
            ->when($cid, fn ($q) => $q->where('policies.carrier_id', $cid))
            ->groupBy('customer_attributions.partner_id')
            ->selectRaw('customer_attributions.partner_id, COUNT(DISTINCT policies.id) as policies, COALESCE(SUM(policies.premium_minor),0) as premium_minor')->get()->keyBy('partner_id');
        $agreements = DB::table('delegated_authority_agreements')->join('partners', 'partners.id', '=', 'delegated_authority_agreements.partner_id')
            ->where('partners.tenant_id', $t)->when($cid, fn ($q) => $q->where('delegated_authority_agreements.carrier_id', $cid))
            ->select('delegated_authority_agreements.partner_id', 'delegated_authority_agreements.agreement_number', 'delegated_authority_agreements.status')->get()->keyBy('partner_id');
        $ids = $sales->keys()->merge($agreements->keys())->unique()->values();
        $partners = DB::table('partners')->leftJoin('parties', 'parties.id', '=', 'partners.party_id')->whereIn('partners.id', $ids)
            ->select('partners.id', 'partners.type', 'partners.status', 'partners.licence_number', 'parties.display_name')->get();

        return response()->json(['data' => $partners->map(fn ($p) => [
            'id' => $p->id, 'name' => $p->display_name ?? 'Partner', 'type' => $p->type, 'status' => $p->status, 'licence_number' => $p->licence_number,
            'policies' => (int) ($sales[$p->id]->policies ?? 0), 'premium_minor' => (int) ($sales[$p->id]->premium_minor ?? 0),
            'agreement_number' => $agreements[$p->id]->agreement_number ?? null, 'agreement_status' => $agreements[$p->id]->status ?? null,
        ])->sortByDesc('premium_minor')->values()]);
    }

    /** Non-UUID ids would make Postgres throw; treat them as not found. */
    private function uuid(string $id): string
    {
        abort_unless(Str::isUuid($id), 404);

        return $id;
    }
}
