<?php

declare(strict_types=1);

namespace App\Application\CarrierOperations\QuoteRequests;

use App\Application\Audit\AuditWriter;
use App\Application\CarrierOperations\QuoteRequests\Models\CarrierQuoteRequest;
use App\Application\CarrierOperations\QuoteRequests\Models\CarrierQuoteResponse;
use App\Application\Cases\CaseService;
use App\Application\Cases\Models\WorkCase;
use App\Application\Events\OutboxWriter;
use App\Application\Notifications\CustomerNotifier;
use App\Application\Quotes\QuoteMachine;
use App\Application\Quotes\QuoteService;
use App\Interfaces\Http\Errors\ApiProblemException;
use App\Models\Carrier;
use App\Models\InsuranceProduct;
use App\Models\Quote;
use App\Models\QuoteOffer;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * REQ-QUO-006 manual quotation (AOM Mode 1). When an insurer's QUOTATION capability is MANUAL
 * (ManualQuoteProvider), the quote is sent to that insurer as a carrier quote request worked as a
 * CARRIER_QUOTE_REQUEST case (queue routing + SLA clocks from the case engine). Insurer staff (portal
 * or API), or a broker on the insurer's behalf with evidence, record the offer or a decline. An offer
 * becomes an ordinary quote_offers row (origin MANUAL), so accept → proposal → bind is unchanged.
 */
final class QuoteRequestService
{
    public const CASE_TYPE = 'CARRIER_QUOTE_REQUEST';

    public const SOURCES = ['INSURER_PORTAL', 'INSURER_API', 'BROKER_ON_BEHALF'];


    public function __construct(
        private readonly CaseService $cases,
        private readonly AuditWriter $audit,
        private readonly OutboxWriter $outbox,
        private readonly CustomerNotifier $notifier,
    ) {}

    /**
     * Idempotent: returns the open request for the same quote/carrier/product when there is one.
     *
     * @param  array{channel?: string, notes?: ?string}  $attrs
     */
    public function open(Quote $quote, string $carrierId, ?string $productId, ?User $actor, array $attrs = []): CarrierQuoteRequest
    {
        if (! $this->quoteOpen($quote)) {
            throw $this->problem('QUOTE_NOT_OPEN', 409, 'The quote is no longer open for insurer offers.', ['quote_status' => $quote->status]);
        }
        $carrier = Carrier::whereKey($carrierId)->where('status', 'ACTIVE')->first()
            ?? throw $this->problem('CARRIER_NOT_ACTIVE', 422, 'The insurer is not active.');
        if ($productId !== null) {
            $this->assertProduct($productId, $carrier->id, $quote);
        }
        if ($existing = $this->openFor($quote->id, $carrier->id, $productId)) {
            return $existing;
        }

        return DB::transaction(function () use ($quote, $carrier, $productId, $actor, $attrs) {
            $now = now();
            $request = CarrierQuoteRequest::create([
                'tenant_id' => $quote->tenant_id, 'quote_id' => $quote->id, 'carrier_id' => $carrier->id, 'product_id' => $productId,
                'request_number' => 'CQR-'.$now->format('Ym').'-'.strtoupper(Str::random(8)), 'status' => 'REQUESTED',
                'channel' => $attrs['channel'] ?? 'PLATFORM', 'notes' => $attrs['notes'] ?? null,
                'risk_snapshot' => ['line_code' => $quote->line_code, 'risk_facts' => $quote->risk_facts ?? [], 'risk_asset_id' => $quote->risk_asset_id, 'currency' => $quote->currency],
                'requested_at' => $now, 'requested_by' => $actor?->id,
            ]);
            $case = $this->cases->open($quote->tenant_id, self::CASE_TYPE, [
                'title' => "Manual quotation {$request->request_number} ({$quote->line_code})",
                'subject_type' => 'carrier_quote_request', 'subject_id' => $request->id, 'carrier_id' => $carrier->id,
                'source_type' => 'carrier_quote_request', 'source_id' => $request->id, 'idempotency_key' => 'cqr:'.$request->id,
            ], $actor);
            $request->update(['case_id' => $case->id, 'response_due_at' => $case->due_at]);

            $this->audit->record('carrier_quote_request.opened', 'carrier_quote_request', $request->id, ['quote_id' => $quote->id, 'carrier_id' => $carrier->id, 'case_id' => $case->id]);
            $this->outbox->record('carrier_quote_request.opened', 'carrier_quote_request', $request->id, [
                'request_id' => $request->id, 'quote_id' => $quote->id, 'carrier_id' => $carrier->id, 'product_id' => $productId,
                'case_id' => $case->id, 'queue_id' => $case->queue_id, 'response_due_at' => $case->due_at?->toIso8601String(),
            ]);
            if ($case->owner_user_id && ($owner = User::find($case->owner_user_id))) {
                $this->notifier->toUser($owner, $quote->tenant_id, 'CARRIER_QUOTE_REQUEST', 'New manual quotation request',
                    "Quote request {$request->request_number} is waiting for your offer.", 'INFO', "/carrier/quote-requests/{$request->id}");
            }
            $this->notifier->toParty($quote->party_id, $quote->tenant_id, 'QUOTE', 'Your request was sent to an insurer',
                'An insurer is preparing a personalised offer. We will tell you as soon as it arrives.', 'INFO', "/quotes/{$quote->id}");

            return $request->refresh();
        });
    }

    /** Insurer staff take the request: case OPEN → IN_PROGRESS (stops the FIRST_RESPONSE clock). */
    public function start(CarrierQuoteRequest $request, User $actor): CarrierQuoteRequest
    {
        return DB::transaction(function () use ($request, $actor) {
            $request = $this->lock($request->id);
            if ($request->status !== 'REQUESTED') {
                throw $this->problem('REQUEST_NOT_STARTABLE', 409, 'Only a new request can be started.', ['status' => $request->status]);
            }
            $this->caseEvent($request, 'start', $actor);
            $request->update(['status' => 'IN_PROGRESS', 'version' => $request->version + 1]);
            $this->audit->record('carrier_quote_request.started', 'carrier_quote_request', $request->id, []);

            return $request->refresh();
        });
    }

    /**
     * Records the insurer's offer; creates the quote offer and notifies the customer.
     *
     * @param  array<string, mixed>  $d  product_id, premium_minor, tax_minor, fee_minor, total_minor, currency,
     *                                   premium_breakdown[], conditions[], document_ids[], valid_until, carrier_reference, notes, evidence_document_id
     */
    public function recordOffer(CarrierQuoteRequest $request, array $d, User $actor, string $source): CarrierQuoteResponse
    {
        return DB::transaction(function () use ($request, $d, $actor, $source) {
            $request = $this->lock($request->id);
            $quote = Quote::whereKey($request->quote_id)->lockForUpdate()->firstOrFail();
            $this->assertAnswerable($request, $quote, $source, $d['evidence_document_id'] ?? null);

            $productId = $d['product_id'] ?? $request->product_id ?? throw $this->problem('PRODUCT_REQUIRED', 422, 'The offer must name the insurer product.');
            $this->assertProduct($productId, $request->carrier_id, $quote);
            $premium = (int) $d['premium_minor'];
            $tax = (int) ($d['tax_minor'] ?? 0);
            $fee = (int) ($d['fee_minor'] ?? 0);
            $total = (int) ($d['total_minor'] ?? $premium + $tax + $fee);
            if ($premium < 0 || $tax < 0 || $fee < 0 || $total !== $premium + $tax + $fee) {
                throw $this->problem('PREMIUM_INCONSISTENT', 422, 'total_minor must equal premium_minor + tax_minor + fee_minor.');
            }
            $lines = array_values($d['premium_breakdown'] ?? []);
            if ($lines !== [] && array_sum(array_map(fn ($l) => (int) $l['amount_minor'], $lines)) !== $total) {
                throw $this->problem('BREAKDOWN_INCONSISTENT', 422, 'The premium breakdown lines must add up to total_minor.');
            }
            $validUntil = Carbon::parse($d['valid_until']);
            if ($validUntil->isPast()) {
                throw $this->problem('VALIDITY_IN_PAST', 422, 'valid_until must be in the future.');
            }
            $documents = $this->assertDocuments($d['document_ids'] ?? [], $request->tenant_id);

            $response = CarrierQuoteResponse::create([
                'carrier_quote_request_id' => $request->id, 'response_type' => 'OFFER', 'source' => $source, 'product_id' => $productId,
                'premium_minor' => $premium, 'tax_minor' => $tax, 'fee_minor' => $fee, 'total_minor' => $total, 'currency' => $d['currency'] ?? $quote->currency,
                'premium_breakdown' => $lines, 'conditions' => array_values($d['conditions'] ?? []), 'document_ids' => $documents,
                'valid_until' => $validUntil, 'carrier_reference' => $d['carrier_reference'] ?? null, 'notes' => $d['notes'] ?? null,
                'evidence_document_id' => $d['evidence_document_id'] ?? null, 'responded_by' => $actor->id, 'responded_at' => now(), 'created_at' => now(),
            ]);

            $offer = new QuoteOffer;
            $offer->forceFill([
                'quote_id' => $quote->id, 'carrier_id' => $request->carrier_id, 'product_id' => $productId, 'tariff_version_id' => null,
                'premium_minor' => $premium, 'tax_minor' => $tax, 'fee_minor' => $fee, 'total_minor' => $total, 'currency' => $response->currency,
                'status' => 'OFFERED', 'valid_until' => $validUntil, 'external_reference' => $response->carrier_reference,
                'calculation_breakdown' => $lines !== [] ? $lines : [['code' => 'CARRIER_PREMIUM', 'amount_minor' => $total]],
                'coverage_snapshot' => ['source' => 'CARRIER_MANUAL', 'conditions' => $response->conditions, 'document_ids' => $documents, 'carrier_quote_request_id' => $request->id],
                'origin' => 'MANUAL', 'carrier_quote_response_id' => $response->id,
            ])->save();

            if ($request->status === 'REQUESTED') {
                $this->caseEvent($request, 'start', $actor);
            }
            $this->caseEvent($request, 'resolve', $actor, ['outcome' => 'OFFERED']);
            $this->caseEvent($request, 'close', $actor, ['outcome' => 'OFFERED']);
            $request->update(['status' => 'OFFERED', 'responded_at' => now(), 'quote_offer_id' => $offer->id, 'product_id' => $productId, 'version' => $request->version + 1]);

            $wasOffered = $quote->status === 'OFFERED';
            $offers = QuoteOffer::where('quote_id', $quote->id)->where('status', 'OFFERED')->count();
            $quote->forceFill(['comparison_context' => array_merge($quote->comparison_context ?? [], ['offer_count' => $offers])])->save();
            // An unpriced quote (DRAFT / RATING / REFERRED) becomes CALCULATED through the quote machine (6B); a priced one keeps its state.
            $quote = app(QuoteService::class)->manualOfferRecorded($quote, $actor);
            if ($wasOffered) { // status unchanged, so the lifecycle producer stays silent: tell the customer here.
                $this->notifier->toParty($quote->party_id, $quote->tenant_id, 'QUOTE', 'A new insurer offer is ready',
                    'An insurer has sent you a personalised offer to review.', 'SUCCESS', "/quotes/{$quote->id}");
            }

            $this->audit->record('carrier_quote_request.offered', 'carrier_quote_request', $request->id, ['quote_offer_id' => $offer->id, 'source' => $source, 'total_minor' => $total]);
            $this->outbox->record('carrier_quote_request.offered', 'carrier_quote_request', $request->id, [
                'request_id' => $request->id, 'quote_id' => $quote->id, 'quote_offer_id' => $offer->id, 'carrier_id' => $request->carrier_id, 'source' => $source,
            ]);

            return $response;
        });
    }

    /** @param array{decline_reason_code: string, notes?: ?string, evidence_document_id?: ?string} $d */
    public function decline(CarrierQuoteRequest $request, array $d, User $actor, string $source): CarrierQuoteResponse
    {
        return DB::transaction(function () use ($request, $d, $actor, $source) {
            $request = $this->lock($request->id);
            $quote = Quote::whereKey($request->quote_id)->lockForUpdate()->firstOrFail();
            $this->assertAnswerable($request, $quote, $source, $d['evidence_document_id'] ?? null);
            $response = CarrierQuoteResponse::create([
                'carrier_quote_request_id' => $request->id, 'response_type' => 'DECLINE', 'source' => $source,
                'decline_reason_code' => $d['decline_reason_code'], 'notes' => $d['notes'] ?? null,
                'evidence_document_id' => $d['evidence_document_id'] ?? null, 'responded_by' => $actor->id, 'responded_at' => now(), 'created_at' => now(),
            ]);
            if ($request->status === 'REQUESTED') {
                $this->caseEvent($request, 'start', $actor);
            }
            $this->caseEvent($request, 'resolve', $actor, ['outcome' => 'DECLINED']);
            $this->caseEvent($request, 'close', $actor, ['outcome' => 'DECLINED']);
            $request->update(['status' => 'DECLINED', 'responded_at' => now(), 'decline_reason_code' => $d['decline_reason_code'], 'version' => $request->version + 1]);

            // Nothing else pending and no offer on the table: the quote is referred back to the distributor.
            $stillOpen = CarrierQuoteRequest::where('quote_id', $quote->id)->whereIn('status', CarrierQuoteRequest::OPEN_STATES)->exists();
            if (! $stillOpen && ! QuoteOffer::where('quote_id', $quote->id)->where('status', 'OFFERED')->exists()) {
                $this->notifier->toParty($quote->party_id, $quote->tenant_id, 'QUOTE', 'The insurer could not offer cover',
                    'The insurer declined to quote this risk. Your adviser will suggest alternatives.', 'WARNING', "/quotes/{$quote->id}");
            }
            $this->audit->record('carrier_quote_request.declined', 'carrier_quote_request', $request->id, ['reason' => $d['decline_reason_code'], 'source' => $source]);
            $this->outbox->record('carrier_quote_request.declined', 'carrier_quote_request', $request->id, ['request_id' => $request->id, 'quote_id' => $quote->id, 'carrier_id' => $request->carrier_id, 'reason' => $d['decline_reason_code']]);

            return $response;
        });
    }

    public function cancel(CarrierQuoteRequest $request, User $actor, string $reason): CarrierQuoteRequest
    {
        return DB::transaction(function () use ($request, $actor, $reason) {
            $request = $this->lock($request->id);
            if (! $request->isOpen()) {
                throw $this->problem('REQUEST_CLOSED', 409, 'The request is already closed.', ['status' => $request->status]);
            }
            $this->close($request, 'CANCELLED', $reason, $actor);

            return $request->refresh();
        });
    }

    /**
     * Closes open requests whose quote is no longer open (cancelled, accepted elsewhere, expired). SLA breaches
     * and escalation are the case engine's job (cases:sla-tick).
     *
     * @return int requests closed
     */
    public function sweep(): int
    {
        $n = 0;
        $open = CarrierQuoteRequest::whereIn('status', CarrierQuoteRequest::OPEN_STATES)->with('quote')->limit(500)->get();
        foreach ($open as $request) {
            $quote = $request->quote;
            $expired = $quote === null || $quote->is_expired;
            if (! $expired && $this->quoteOpen($quote)) {
                continue;
            }
            DB::transaction(function () use ($request, $expired, $quote) {
                $locked = $this->lock($request->id);
                if ($locked->isOpen()) {
                    $this->close($locked, $expired ? 'EXPIRED' : 'CANCELLED', $expired ? 'Quote expired' : 'Quote '.strtolower((string) $quote?->status), null);
                }
            });
            $n++;
        }

        return $n;
    }

    public function openFor(string $quoteId, string $carrierId, ?string $productId): ?CarrierQuoteRequest
    {
        return CarrierQuoteRequest::where('quote_id', $quoteId)->where('carrier_id', $carrierId)
            ->where(fn ($q) => $productId === null ? $q->whereNull('product_id') : $q->where('product_id', $productId))
            ->whereIn('status', CarrierQuoteRequest::OPEN_STATES)->first();
    }

    private function close(CarrierQuoteRequest $request, string $status, string $reason, ?User $actor): void
    {
        if ($request->case_id && ($case = WorkCase::withoutGlobalScopes()->find($request->case_id)) && $case->status !== 'CANCELLED' && $case->closed_at === null) {
            $this->cases->transition($case, 'cancel', $actor, $reason, ['outcome' => $status]);
        }
        $request->update(['status' => $status, 'version' => $request->version + 1]);
        $event = $status === 'EXPIRED' ? 'carrier_quote_request.expired' : 'carrier_quote_request.cancelled';
        $this->audit->record($event, 'carrier_quote_request', $request->id, [], $reason);
        $this->outbox->record($event, 'carrier_quote_request', $request->id, ['request_id' => $request->id, 'quote_id' => $request->quote_id, 'reason' => $reason]);
    }

    private function assertAnswerable(CarrierQuoteRequest $request, Quote $quote, string $source, ?string $evidenceId): void
    {
        if (! in_array($source, self::SOURCES, true)) {
            throw $this->problem('SOURCE_INVALID', 422, 'Unknown response source.');
        }
        if (! $request->isOpen()) {
            throw $this->problem('REQUEST_CLOSED', 409, 'The request already has an answer or was closed.', ['status' => $request->status]);
        }
        if (! $this->quoteOpen($quote)) {
            throw $this->problem('QUOTE_NOT_OPEN', 409, 'The quote is no longer open for insurer offers.', ['quote_status' => $quote->status]);
        }
        if ($source === 'BROKER_ON_BEHALF') {
            if (! $evidenceId) {
                throw $this->problem('EVIDENCE_REQUIRED', 422, 'A broker recording an offer on the insurer\'s behalf must attach the insurer\'s written offer as evidence.');
            }
            $this->assertDocuments([$evidenceId], $request->tenant_id);
        }
    }

    private function quoteOpen(?Quote $quote): bool
    {
        return $quote !== null && ! $quote->is_expired && in_array(QuoteMachine::stateOf($quote), QuoteMachine::OPEN, true);
    }

    private function assertProduct(string $productId, string $carrierId, Quote $quote): void
    {
        $product = InsuranceProduct::find($productId);
        if (! $product || $product->carrier_id !== $carrierId || $product->line_code !== $quote->line_code) {
            throw $this->problem('PRODUCT_MISMATCH', 422, 'The product must belong to the insurer and match the quote\'s line of business.');
        }
    }

    /** @param list<string> $ids @return list<string> */
    private function assertDocuments(array $ids, string $tenantId): array
    {
        $ids = array_values(array_unique(array_map('strval', $ids)));
        if ($ids === []) {
            return [];
        }
        $found = DB::table('documents')->whereIn('id', $ids)->where('tenant_id', $tenantId)->count();
        if ($found !== count($ids)) {
            throw $this->problem('DOCUMENT_NOT_FOUND', 422, 'Every document must exist in this workspace.');
        }

        return $ids;
    }

    /** @param array<string, mixed> $payload */
    private function caseEvent(CarrierQuoteRequest $request, string $event, ?User $actor, array $payload = []): void
    {
        if (! $request->case_id) {
            return;
        }
        $case = WorkCase::withoutGlobalScopes()->findOrFail($request->case_id);
        $this->cases->transition($case, $event, $actor, null, $payload);
    }

    private function lock(string $id): CarrierQuoteRequest
    {
        return CarrierQuoteRequest::whereKey($id)->lockForUpdate()->firstOrFail();
    }

    /** @param array<string, mixed> $extra */
    private function problem(string $code, int $status, string $message, array $extra = []): ApiProblemException
    {
        return new ApiProblemException($code, $status, $message, [], $extra);
    }
}
