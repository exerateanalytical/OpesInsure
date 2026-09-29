<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\PartnerWorkspace;

use App\Application\PartnerWorkspace\AgentServicingQuery;
use App\Application\PartnerWorkspace\PartnerBookQuery;
use App\Application\PartnerWorkspace\PartnerWorkspaceScope;
use App\Application\Policies\Cancellation\CancellationService;
use App\Application\Policies\Endorsements\ServiceRequestIntake;
use App\Application\Risks\RiskAssetService;
use App\Application\Stickers\StickerCustodyService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Party;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Agent servicing (web /account agent pages AGT-037..056, launch 2026-10-02): policy detail, service requests
 * (endorsement / change) and cancellation requests for a book policy, payment history, client vehicles, sticker
 * stock and assignment, claim detail. Thin: every write goes through the existing service (ServiceRequestIntake,
 * CancellationService, RiskAssetService, StickerCustodyService); every read is bounded by the agent's own book.
 */
final class PartnerAgentServicingController
{
    public function __construct(private readonly PartnerWorkspaceScope $scope, private readonly AgentServicingQuery $q) {}

    private function tenant(): string
    {
        return app(TenantContext::class)->id();
    }

    /** @return list<string> */
    private function book(Request $r): array
    {
        return $this->scope->bookPartyIds($r->user(), $this->scope->agent($r->user()));
    }

    public function policy(string $policy, Request $r): JsonResponse
    {
        return response()->json(['data' => $this->q->policyDetail($this->q->policy($this->tenant(), $this->book($r), $policy))]);
    }

    /** AGT-043: service requests / endorsements / cancellations on the book's policies. */
    public function serviceRequests(Request $r): JsonResponse
    {
        $t = $this->tenant();

        return response()->json(['data' => $this->q->transactions($t, $this->q->bookPolicyIds($t, $this->book($r)))]);
    }

    public function serviceRequest(string $id, Request $r): JsonResponse
    {
        return response()->json(['data' => $this->q->transactionFor($this->tenant(), $this->book($r), $id)]);
    }

    /** AGT-042: assisted endorsement / change request (the same REQUESTED row a customer raises; staff triage it). */
    public function requestService(string $policy, Request $r, ServiceRequestIntake $intake): JsonResponse
    {
        $d = $r->validate(['type' => ['required', Rule::in(array_values(array_diff(ServiceRequestIntake::TYPES, ['CANCELLATION_REVIEW'])))], 'reason' => 'required|string|min:5|max:4000']);
        $t = $this->tenant();
        $p = $this->q->policy($t, $this->book($r), $policy);
        $id = $intake->submit($p, $d['type'], $d['reason'], $r->user(), $r->header('Idempotency-Key'), 'AGENT');

        return response()->json(['data' => $this->q->transactionFor($t, $this->book($r), $id)], 201);
    }

    /** AGT-046: cancellation refund preview for a book policy. */
    public function previewCancellation(string $policy, Request $r, CancellationService $cancellations): JsonResponse
    {
        $d = $r->validate(['effective_at' => 'required|date', 'initiated_by' => ['required', Rule::in(['INSURED', 'INTERMEDIARY'])]]);

        return response()->json(['data' => $cancellations->quote($this->q->policy($this->tenant(), $this->book($r), $policy), $d['effective_at'], $d['initiated_by'])]);
    }

    /** AGT-046: cancellation request on behalf of the client (maker; the insurer reviews and approves). */
    public function requestCancellation(string $policy, Request $r, CancellationService $cancellations): JsonResponse
    {
        $d = $r->validate([
            'effective_at' => 'required|date', 'reason_code' => 'required|string|max:64',
            'initiated_by' => ['required', Rule::in(['INSURED', 'INTERMEDIARY'])], 'notes' => 'nullable|string|max:2000',
        ]);
        $case = $cancellations->request($this->q->policy($this->tenant(), $this->book($r), $policy), $d, $r->user());

        return response()->json(['data' => $case->only(['id', 'status', 'initiated_by', 'reason_code', 'effective_at', 'refund_minor', 'currency'])], 201);
    }

    /** AGT-037: payments across the book. */
    public function payments(Request $r): JsonResponse
    {
        return response()->json(['data' => $this->q->payments($this->tenant(), $this->book($r))]);
    }

    /** AGT-038: payment history of one book client. */
    public function clientPayments(string $customer, Request $r, PartnerBookQuery $book): JsonResponse
    {
        $c = $book->client($this->tenant(), $this->book($r), $customer);

        return response()->json(['data' => $this->q->payments($this->tenant(), [$c->party_id])]);
    }

    /** AGT-047: the client's vehicles. */
    public function clientVehicles(string $customer, Request $r, PartnerBookQuery $book): JsonResponse
    {
        $c = $book->client($this->tenant(), $this->book($r), $customer);

        return response()->json(['data' => $this->q->vehicles($this->tenant(), $c->party_id)]);
    }

    public function vehicle(string $asset, Request $r): JsonResponse
    {
        return response()->json(['data' => $this->q->vehicle($this->q->asset($this->tenant(), $this->book($r), $asset))]);
    }

    /** AGT-048: register a vehicle for a book client (RiskAssetService: customer-of-tenant check, duplicate plate check, audit). */
    public function registerVehicle(string $customer, Request $r, PartnerBookQuery $book, RiskAssetService $assets): JsonResponse
    {
        $d = $r->validate([
            'display_name' => 'required|string|max:160', 'external_reference' => 'nullable|string|max:100',
            'facts' => 'required|array', 'facts.registration_number' => 'required|string|max:20', 'facts.make' => 'required|string|max:60', 'facts.model' => 'required|string|max:60',
            'facts.year' => 'nullable|integer|min:1950|max:2100', 'facts.usage_type' => 'nullable|string|max:32', 'facts.fiscal_power' => 'nullable|integer|min:1|max:60',
        ]);
        $t = $this->tenant();
        $c = $book->client($t, $this->book($r), $customer);
        $asset = $assets->create(Tenant::findOrFail($t), Party::findOrFail($c->party_id), ['type' => 'VEHICLE'] + $d, $r->user());

        return response()->json(['data' => $this->q->vehicle($asset)], 201);
    }

    /** AGT-049 / AGT-050: stickers the agent holds and the handovers to or from them. */
    public function stickers(Request $r): JsonResponse
    {
        return response()->json(['data' => $this->q->stickers($r->user(), $this->tenant())]);
    }

    /** AGT-049: assign one of the agent's stickers to a book motor policy. */
    public function assignSticker(string $policy, Request $r, StickerCustodyService $stickers): JsonResponse
    {
        $d = $r->validate(['serial_number' => 'required|string|max:100']);
        $s = $stickers->assignToPolicy($this->q->policy($this->tenant(), $this->book($r), $policy), $d['serial_number'], $r->user());

        return response()->json(['data' => $s->only(['serial_number', 'status', 'assigned_policy_id', 'assigned_at'])], 201);
    }

    /** AGT-053 / AGT-054: claim detail with timeline and evidence. */
    public function claim(string $claim, Request $r): JsonResponse
    {
        return response()->json(['data' => $this->q->claimDetail($this->q->claim($this->tenant(), $this->book($r), $claim))]);
    }
}
