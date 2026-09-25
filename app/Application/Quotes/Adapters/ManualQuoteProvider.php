<?php

declare(strict_types=1);

namespace App\Application\Quotes\Adapters;

use App\Application\CarrierOperations\QuoteRequests\QuoteRequestService;
use App\Application\Distribution\Execution\BaseExecutionAdapter;
use App\Application\Distribution\Execution\ExecutionContext;
use App\Application\Distribution\Execution\ExecutionOutcome;
use App\Models\Quote;

/**
 * REQ-AOM-002 / REQ-QUO-006 — MANUAL QUOTATION adapter. A preview (planner) has no side effects; when
 * the context carries payload.quote_id the quote is sent to the insurer's work queue as a carrier quote
 * request (QuoteRequestService, idempotent), and the outcome's handler names the request.
 */
final class ManualQuoteProvider extends BaseExecutionAdapter implements QuoteProvider
{
    public function capability(): string
    {
        return self::CAPABILITY;
    }

    public function executionMode(): string
    {
        return 'MANUAL';
    }

    public function execute(ExecutionContext $context): ExecutionOutcome
    {
        $quoteId = $context->payload['quote_id'] ?? null;
        if (! is_string($quoteId) || $quoteId === '') {
            return parent::execute($context);
        }
        $request = app(QuoteRequestService::class)->open(Quote::findOrFail($quoteId), $context->carrierId, $context->productId,
            $context->payload['actor'] ?? auth()->user(), ['notes' => $context->payload['notes'] ?? null]);

        return new ExecutionOutcome(ExecutionOutcome::AWAITING_CARRIER, $this->capability(), 'MANUAL', self::class,
            'carrier_quote_request:'.$request->id, $this->nextAction());
    }

    protected function handler(): ?string
    {
        return null;
    }

    protected function nextAction(): string
    {
        return 'Carrier prices the risk manually and records the offer (carrier quote request queue).';
    }
}
