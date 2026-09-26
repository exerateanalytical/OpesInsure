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

    /**
     * Evidence / documents attached to a claim on the caller's OWN carrier
     * (canonical document_subject_links, REQ-DUP-021). Metadata only — the
     * bytes are reached through evidenceAccess(), which mints a short-lived
     * signed URL per document and logs the read.
     */
    public function claimEvidence(string $claim, Request $request): JsonResponse
    {
        $c = $this->scopedClaim($claim, $request);
        $rows = app(\App\Application\Documents\SubjectDocuments::class)->query('CLAIM', $c->id)
            ->orderByDesc('links.linked_at')
            ->get(['links.document_id', 'links.role', 'links.link_status', 'links.linked_at', 'links.verified_at',
                'documents.category', 'documents.mime_type', 'documents.size_bytes', 'documents.scan_status']);

        return response()->json(['data' => $rows->map(fn ($r) => [
            'document_id' => $r->document_id, 'evidence_type' => $r->role, 'status' => $r->link_status,
            'category' => $r->category, 'mime_type' => $r->mime_type, 'size_bytes' => $r->size_bytes !== null ? (int) $r->size_bytes : null,
            'scan_status' => $r->scan_status, 'is_image' => str_starts_with((string) $r->mime_type, 'image/'),
            'downloadable' => $r->scan_status === 'CLEAN',
            'submitted_at' => $r->linked_at ? \Carbon\Carbon::parse($r->linked_at)->toIso8601String() : null,
            'verified_at' => $r->verified_at ? \Carbon\Carbon::parse($r->verified_at)->toIso8601String() : null,
        ])->values()]);
    }

    /** Short-lived signed URL for one CLEAN document linked to an own-carrier claim; logged in document_access_log. */
    public function claimEvidenceAccess(string $claim, string $document, Request $request): JsonResponse
    {
        $c = $this->scopedClaim($claim, $request);
        $linked = app(\App\Application\Documents\SubjectDocuments::class)->query('CLAIM', $c->id)
            ->where('links.document_id', $this->uuid($document))->exists();
        abort_unless($linked, 404);
        $doc = \App\Models\Document::where('tenant_id', $this->tenant())->findOrFail($document);
        if ($doc->scan_status !== 'CLEAN') {
            throw \Illuminate\Validation\ValidationException::withMessages(['document' => __('wave12.document_not_ready')]);
        }
        $signed = app(\App\Application\Documents\Adapters\SignedUrlAdapter::class)->sign($doc, 300);
        DB::table('document_access_log')->insert([
            'document_id' => $doc->id, 'actor_id' => $request->user()->id, 'action' => 'READ', 'purpose' => 'CARRIER_CLAIM_REVIEW',
            'request_id' => $request->header('X-Request-Id') ?: (string) Str::uuid(), 'occurred_at' => now(),
        ]);

        return response()->json(['data' => ['document_id' => $doc->id, 'mime_type' => $doc->mime_type, 'url' => $signed->url, 'expires_at' => $signed->expiresAt]]);
    }

    /**
     * Insured item (vehicle facts for MOTOR, from the accepted quote's risk
     * facts) and the coverage deductibles frozen on the policy terms — only
     * what the data actually holds; nulls/empty otherwise.
     *
     * @return array{insured_item: ?array<string, mixed>, deductibles: array<int, array<string, mixed>>, deductible_minor: ?int}
     */
    private function claimRisk(Claim $c): array
    {
        $policy = $c->policy;
        $terms = $policy?->terms_snapshot ?? [];
        // Risk facts: frozen on the policy terms, else the accepted quote (terms quote_id, else proposal -> offer -> quote).
        $line = $terms['line_code'] ?? null;
        $facts = is_array($terms['risk_facts'] ?? null) ? $terms['risk_facts'] : null;
        if ($facts === null && $policy) {
            $quoteId = $terms['quote_id'] ?? null;
            if (! $quoteId && $policy->proposal_id) {
                $quoteId = DB::table('proposals')->join('quote_offers', 'quote_offers.id', '=', 'proposals.quote_offer_id')
                    ->where('proposals.id', $policy->proposal_id)->where('proposals.tenant_id', $this->tenant())->value('quote_offers.quote_id');
            }
            if ($quoteId && Str::isUuid((string) $quoteId)) {
                $q = DB::table('quotes')->where('tenant_id', $this->tenant())->where('id', $quoteId)->first(['line_code', 'risk_facts']);
                if ($q) {
                    $facts = json_decode((string) $q->risk_facts, true) ?: [];
                    $line = $line ?? $q->line_code;
                }
            }
        }
        $item = null;
        if ($facts !== null) {
            // Whitelisted insured-item fields only — never contact or personal data from the rating facts.
            $keys = ['registration_number', 'make', 'model', 'year', 'body_type', 'usage_type', 'vehicle_usage', 'powertrain', 'fiscal_power', 'vehicle_value', 'chassis_number', 'zone'];
            $item = ['line_code' => $line] + array_filter(array_intersect_key($facts, array_flip($keys)), fn ($v) => $v !== null && $v !== '');
        }
        $deductibles = collect($terms['coverage_snapshot']['coverages'] ?? [])->map(fn ($cv) => [
            'code' => $cv['code'] ?? null, 'name' => $cv['name'] ?? null,
            'deductible_minor' => isset($cv['deductible_minor']) ? (int) $cv['deductible_minor'] : null,
            'limit_minor' => isset($cv['limit_minor']) ? (int) $cv['limit_minor'] : null,
        ])->values()->all();
        if (! $deductibles && $policy) {
            $version = DB::table('policy_versions')->where('policy_id', $policy->id)->whereNull('superseded_at')->orderByDesc('version_no')->value('id');
            $deductibles = $version ? DB::table('policy_coverages')->where('policy_version_id', $version)->orderBy('coverage_code')->get(['coverage_code', 'limit_minor', 'deductible_minor'])
                ->map(fn ($r) => ['code' => $r->coverage_code, 'name' => null, 'deductible_minor' => $r->deductible_minor !== null ? (int) $r->deductible_minor : null, 'limit_minor' => $r->limit_minor !== null ? (int) $r->limit_minor : null])->all() : [];
        }
        $code = $c->loss_details['coverage_code'] ?? null;
        $match = $code ? collect($deductibles)->firstWhere('code', $code) : null;

        return ['insured_item' => $item, 'deductibles' => $deductibles, 'deductible_minor' => $match['deductible_minor'] ?? null];
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
        ] + $this->claimRisk($c) + [
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
