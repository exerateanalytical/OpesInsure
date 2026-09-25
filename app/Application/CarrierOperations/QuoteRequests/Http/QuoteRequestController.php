<?php

declare(strict_types=1);

namespace App\Application\CarrierOperations\QuoteRequests\Http;

use App\Application\CarrierOperations\QuoteRequests\Models\CarrierQuoteRequest;
use App\Application\CarrierOperations\QuoteRequests\Models\CarrierQuoteResponse;
use App\Application\CarrierOperations\QuoteRequests\QuoteRequestService;
use App\Application\Cases\Models\SlaClock;
use App\Application\Distribution\Execution\ExecutionContext;
use App\Application\Identity\CarrierScopeResolver;
use App\Application\Quotes\Adapters\ManualQuoteProvider;
use App\Application\Quotes\Adapters\QuoteProviderRegistry;
use App\Domain\Tenancy\TenantContext;
use App\Interfaces\Http\Errors\ApiProblemException;
use App\Models\Quote;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * REQ-QUO-006 manual quotation API.
 *  Distributor side  /v1/quotes/{quote}/carrier-requests   send to a MANUAL insurer, list, cancel, record on the insurer's behalf (evidence).
 *  Insurer side      /v1/carrier/quote-requests             work queue scoped to the caller's carrier; start, offer, decline.
 */
final class QuoteRequestController
{
    public function __construct(private readonly QuoteRequestService $service, private readonly TenantContext $tenant, private readonly CarrierScopeResolver $scope) {}

    public function store(Request $r, string $quote, QuoteProviderRegistry $registry): JsonResponse
    {
        $d = $r->validate(['carrier_id' => 'required|uuid', 'product_id' => 'nullable|uuid', 'notes' => 'nullable|string|max:2000']);
        $q = $this->quote($quote);
        $adapter = $registry->for($d['carrier_id'], $d['product_id'] ?? null);
        if (! $adapter instanceof ManualQuoteProvider) {
            throw new ApiProblemException('CARRIER_NOT_MANUAL', 409, 'This insurer does not quote manually; its offers come from rating.', [], ['execution_mode' => $adapter->executionMode()]);
        }
        $existed = $this->service->openFor($q->id, $d['carrier_id'], $d['product_id'] ?? null) !== null;
        $outcome = $adapter->execute(new ExecutionContext('quote_request', null, $d['carrier_id'], $d['product_id'] ?? null,
            ['quote_id' => $q->id, 'actor' => $r->user(), 'notes' => $d['notes'] ?? null]));
        $request = CarrierQuoteRequest::findOrFail(substr((string) $outcome->handler, strlen('carrier_quote_request:')));

        return response()->json(['data' => $this->present($request), 'meta' => ['execution' => $outcome->toArray()]], $existed ? 200 : 201);
    }

    public function forQuote(string $quote): JsonResponse
    {
        $q = $this->quote($quote);

        return response()->json(['data' => CarrierQuoteRequest::where('quote_id', $q->id)->orderByDesc('requested_at')->get()->map(fn ($x) => $this->present($x))->values()]);
    }

    public function cancel(Request $r, string $quote, string $request): JsonResponse
    {
        $d = $r->validate(['reason' => 'required|string|min:3|max:500']);
        $req = CarrierQuoteRequest::where('quote_id', $this->quote($quote)->id)->findOrFail($request);

        return response()->json(['data' => $this->present($this->service->cancel($req, $r->user(), $d['reason']))]);
    }

    public function offerOnBehalf(Request $r, string $quote, string $request): JsonResponse
    {
        $req = CarrierQuoteRequest::where('quote_id', $this->quote($quote)->id)->findOrFail($request);
        $d = $r->validate($this->offerRules() + ['evidence_document_id' => 'required|uuid']);
        $response = $this->service->recordOffer($req, $d, $r->user(), 'BROKER_ON_BEHALF');

        return response()->json(['data' => $this->present($req->refresh(), true), 'meta' => ['response_id' => $response->id]], 201);
    }

    public function declineOnBehalf(Request $r, string $quote, string $request): JsonResponse
    {
        $req = CarrierQuoteRequest::where('quote_id', $this->quote($quote)->id)->findOrFail($request);
        $d = $r->validate($this->declineRules() + ['evidence_document_id' => 'required|uuid']);
        $this->service->decline($req, $d, $r->user(), 'BROKER_ON_BEHALF');

        return response()->json(['data' => $this->present($req->refresh(), true)], 201);
    }

    // ---- insurer work queue -------------------------------------------------------------------

    public function index(Request $r): JsonResponse
    {
        $d = $r->validate(['status' => 'nullable|in:REQUESTED,IN_PROGRESS,OFFERED,DECLINED,CANCELLED,EXPIRED', 'open' => 'nullable|boolean']);
        $q = $this->carrierScoped($r);
        if (! empty($d['status'])) {
            $q->where('status', $d['status']);
        } elseif ($r->boolean('open', true)) {
            $q->whereIn('status', CarrierQuoteRequest::OPEN_STATES);
        }
        $rows = $q->orderByRaw('response_due_at IS NULL, response_due_at')->orderBy('requested_at')->limit(200)->get();

        return response()->json(['data' => $rows->map(fn ($x) => $this->present($x))->values(), 'meta' => ['count' => $rows->count()]]);
    }

    public function show(Request $r, string $request): JsonResponse
    {
        return response()->json(['data' => $this->present($this->carrierScoped($r)->findOrFail($request), true)]);
    }

    public function start(Request $r, string $request): JsonResponse
    {
        return response()->json(['data' => $this->present($this->service->start($this->carrierScoped($r)->findOrFail($request), $r->user()))]);
    }

    public function offer(Request $r, string $request): JsonResponse
    {
        $req = $this->carrierScoped($r)->findOrFail($request);
        $d = $r->validate($this->offerRules() + ['source' => 'nullable|in:INSURER_PORTAL,INSURER_API']);
        $response = $this->service->recordOffer($req, $d, $r->user(), $d['source'] ?? 'INSURER_PORTAL');

        return response()->json(['data' => $this->present($req->refresh(), true), 'meta' => ['response_id' => $response->id]], 201);
    }

    public function decline(Request $r, string $request): JsonResponse
    {
        $req = $this->carrierScoped($r)->findOrFail($request);
        $d = $r->validate($this->declineRules() + ['source' => 'nullable|in:INSURER_PORTAL,INSURER_API']);
        $this->service->decline($req, $d, $r->user(), $d['source'] ?? 'INSURER_PORTAL');

        return response()->json(['data' => $this->present($req->refresh(), true)], 201);
    }

    // ---- helpers -------------------------------------------------------------------------------

    /** @return array<string, string> */
    private function offerRules(): array
    {
        return [
            'product_id' => 'nullable|uuid', 'premium_minor' => 'required|integer|min:0', 'tax_minor' => 'nullable|integer|min:0', 'fee_minor' => 'nullable|integer|min:0',
            'total_minor' => 'nullable|integer|min:0', 'currency' => 'nullable|string|size:3',
            'premium_breakdown' => 'nullable|array|max:50', 'premium_breakdown.*.code' => 'required|string|max:64', 'premium_breakdown.*.label' => 'nullable|string|max:200',
            'premium_breakdown.*.amount_minor' => 'required|integer',
            'conditions' => 'nullable|array|max:50', 'conditions.*.code' => 'nullable|string|max:64', 'conditions.*.text' => 'required|string|max:2000',
            'document_ids' => 'nullable|array|max:20', 'document_ids.*' => 'uuid',
            'valid_until' => 'required|date', 'carrier_reference' => 'nullable|string|max:120', 'notes' => 'nullable|string|max:2000',
        ];
    }

    /** @return array<string, string> */
    private function declineRules(): array
    {
        return ['decline_reason_code' => 'required|string|max:64', 'notes' => 'nullable|string|max:2000'];
    }

    private function quote(string $id): Quote
    {
        return Quote::where('tenant_id', $this->tenant->id())->findOrFail($id);
    }

    private function carrierScoped(Request $r)
    {
        $tenantId = $this->tenant->id();
        $carrierId = $this->scope->carrierIdFor($r->user(), $tenantId);

        return CarrierQuoteRequest::where('tenant_id', $tenantId)->when($carrierId, fn ($q) => $q->where('carrier_id', $carrierId));
    }

    /** @return array<string, mixed> */
    private function present(CarrierQuoteRequest $x, bool $detail = false): array
    {
        $out = [
            'id' => $x->id, 'request_number' => $x->request_number, 'status' => $x->status, 'quote_id' => $x->quote_id,
            'carrier_id' => $x->carrier_id, 'product_id' => $x->product_id, 'case_id' => $x->case_id, 'channel' => $x->channel,
            'requested_at' => $x->requested_at?->toIso8601String(), 'response_due_at' => $x->response_due_at?->toIso8601String(),
            'responded_at' => $x->responded_at?->toIso8601String(), 'quote_offer_id' => $x->quote_offer_id,
            'decline_reason_code' => $x->decline_reason_code, 'version' => $x->version,
        ];
        // Case context for the app: waiting states (e.g. WAITING_FOR_CUSTOMER) and the owner's case family/subtype.
        $case = $x->case_id ? \Illuminate\Support\Facades\DB::table('cases')->where('id', $x->case_id)->first(['status', 'case_family', 'case_subtype']) : null;
        $out['case_status'] = $case?->status;
        $out['case_family'] = $case?->case_family;
        $out['case_subtype'] = $case?->case_subtype;
        if ($detail) {
            $out['risk_snapshot'] = $x->risk_snapshot;
            $out['notes'] = $x->notes;
            $out['sla'] = $x->case_id ? SlaClock::where('case_id', $x->case_id)->get()->map(fn ($c) => [
                'metric' => $c->metric, 'due_at' => $c->due_at?->toIso8601String(), 'stopped_at' => $c->stopped_at?->toIso8601String(),
                'breached_at' => $c->breached_at?->toIso8601String(), 'label' => $c->deadline_label ?? 'PLATFORM_SLA',
            ])->values() : [];
            $out['responses'] = CarrierQuoteResponse::where('carrier_quote_request_id', $x->id)->orderBy('responded_at')->get()->map(fn ($p) => [
                'id' => $p->id, 'response_type' => $p->response_type, 'source' => $p->source, 'product_id' => $p->product_id,
                'premium_minor' => $p->premium_minor, 'tax_minor' => $p->tax_minor, 'fee_minor' => $p->fee_minor, 'total_minor' => $p->total_minor,
                'currency' => $p->currency, 'premium_breakdown' => $p->premium_breakdown, 'conditions' => $p->conditions, 'document_ids' => $p->document_ids,
                'valid_until' => $p->valid_until?->toIso8601String(), 'carrier_reference' => $p->carrier_reference,
                'decline_reason_code' => $p->decline_reason_code, 'notes' => $p->notes, 'evidence_document_id' => $p->evidence_document_id,
                'responded_by' => $p->responded_by, 'responded_at' => $p->responded_at?->toIso8601String(),
            ])->values();
        }

        return $out;
    }
}
