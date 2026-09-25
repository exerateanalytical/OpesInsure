<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\PartnerWorkspace;

use App\Application\PartnerWorkspace\AgentLeadService;
use App\Application\PartnerWorkspace\PartnerWorkspaceScope;
use App\Domain\Tenancy\TenantContext;
use App\Models\Policy;
use App\Models\Quote;
use App\Models\TenantCustomer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/** Agent workspace: leads, consented intake, quotes and policies (Wave 16). */
final class PartnerAgentWorkspaceController
{
    public function __construct(private AgentLeadService $leads, private PartnerWorkspaceScope $scope) {}

    private function tenant(): string
    {
        return app(TenantContext::class)->id();
    }

    public function leads(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->leads->list($request->user(), $this->tenant())->map(fn ($l) => $this->leadOf($l))->values()]);
    }

    public function lead(string $lead, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->leadOf($this->leads->show($lead, $request->user(), $this->tenant()))]);
    }

    public function createLead(Request $request): JsonResponse
    {
        $data = $request->validate([
            'full_name' => 'required|string|min:3|max:160', 'phone_e164' => 'required|string|min:8|max:32', 'city' => 'nullable|string|max:80',
            'product_interest' => 'nullable|string|max:32', 'notes' => 'nullable|string|max:2000',
        ]);

        return response()->json(['data' => $this->leadOf($this->leads->create($data, $request->user(), $this->tenant()))], 201);
    }

    public function updateLead(string $lead, Request $request): JsonResponse
    {
        $data = $request->validate([
            'status' => ['sometimes', Rule::in(AgentLeadService::MANUAL_STATUSES)], 'notes' => 'sometimes|nullable|string|max:2000', 'lost_reason' => 'sometimes|nullable|string|max:255',
            'city' => 'sometimes|nullable|string|max:80', 'product_interest' => 'sometimes|nullable|string|max:32',
        ]);

        return response()->json(['data' => $this->leadOf($this->leads->update($lead, $data, $request->user(), $this->tenant()))]);
    }

    public function convertLead(string $lead, Request $request): JsonResponse
    {
        $data = $request->validate(['consent_confirmed' => 'required|accepted', 'city' => 'sometimes|nullable|string|max:80']);
        $result = $this->leads->convert($lead, $data, $request->user(), $this->tenant());

        return response()->json(['data' => ['lead' => $this->leadOf($result['lead']), 'client' => $this->clientOf($result['customer'], $result['consent_reference'])]], 201);
    }

    /** Consented intake: replaces the app-fabricated consent_reference contract. */
    public function createClient(Request $request): JsonResponse
    {
        $data = $request->validate(['full_name' => 'required|string|min:3|max:160', 'phone_e164' => 'required|string|max:32', 'city' => 'required|string|max:80', 'consent_confirmed' => 'required|accepted']);
        $result = $this->leads->registerClient($data, $request->user(), $this->tenant());

        return response()->json(['data' => $this->clientOf($result['customer'], $result['consent_reference'])], 201);
    }

    /** Quotes this agent created for clients (assisted sales), newest first. */
    public function quotes(Request $request): JsonResponse
    {
        $t = $this->tenant();
        $partner = $this->scope->agent($request->user());
        $book = $this->scope->bookPartyIds($partner);
        $quotes = Quote::with(['party', 'offers'])->where('tenant_id', $t)
            ->where(fn ($q) => $q->where('comparison_context->agent_user_id', $request->user()->id)
                ->orWhere(fn ($q) => $q->where('channel', 'AGENT')->whereIn('party_id', $book)->whereNull('comparison_context->agent_user_id')))
            ->orderByDesc('created_at')->limit(100)->get();

        return response()->json(['data' => $quotes->map(fn (Quote $q) => PartnerWorkspaceShapes::quote($q, $t))->values()]);
    }

    /** Policies of clients origin-locked to this agent. */
    public function policies(Request $request): JsonResponse
    {
        $t = $this->tenant();
        $book = $this->scope->bookPartyIds($this->scope->agent($request->user()));
        $rows = Policy::with(['party', 'carrier.party'])->where('tenant_id', $t)->whereIn('party_id', $book)->orderByDesc('issued_at')->limit(100)->get();

        return response()->json(['data' => $rows->map(fn (Policy $p) => PartnerWorkspaceShapes::policy($p))->values()]);
    }

    // ------------------------------------------------------------ shapes

    private function leadOf(object $l): array
    {
        return [
            'id' => $l->id, 'full_name' => $l->full_name, 'phone_e164' => $l->phone_e164, 'city' => $l->city, 'product_interest' => $l->product_interest,
            'notes' => $l->notes, 'status' => $l->status, 'next_statuses' => \App\Application\PartnerWorkspace\LeadPipeline::next($l->status), 'lost_reason' => $l->lost_reason ?? null, 'converted_customer_id' => $l->converted_customer_id,
            'created_at' => \Carbon\Carbon::parse($l->created_at)->toIso8601String(), 'updated_at' => \Carbon\Carbon::parse($l->updated_at)->toIso8601String(),
        ];
    }

    private function clientOf(TenantCustomer $c, string $consentReference): array
    {
        $t = $this->tenant();
        $active = Policy::where('tenant_id', $t)->where('party_id', $c->party_id)->where('status', 'ACTIVE');

        return [
            'id' => $c->id, 'party_id' => $c->party_id, 'full_name' => $c->party?->display_name ?? 'Client', 'phone_e164' => $c->party?->contacts?->firstWhere('type', 'PHONE')?->normalized_value ?? '',
            'city' => DB::table('party_addresses')->where('party_id', $c->party_id)->value('city'),
            'kyc_status' => DB::table('kyc_submissions')->where('party_id', $c->party_id)->orderByDesc('created_at')->value('status') ?? 'NOT_STARTED',
            'origin_locked' => DB::table('customer_attributions')->where('party_id', $c->party_id)->where('status', 'ACTIVE')->exists(),
            'active_policies' => (clone $active)->count(), 'renewal_due_at' => ($min = (clone $active)->min('coverage_ends_at')) ? \Carbon\Carbon::parse($min)->toIso8601String() : null,
            'consent_reference' => $consentReference,
        ];
    }
}
