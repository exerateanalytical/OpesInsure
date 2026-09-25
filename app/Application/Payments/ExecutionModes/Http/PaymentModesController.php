<?php

declare(strict_types=1);

namespace App\Application\Payments\ExecutionModes\Http;

use App\Application\Identity\OwnershipScope;
use App\Application\Payments\ExecutionModes\PaymentCollectionModeService;
use App\Application\Payments\Retries\PaymentRetryService;
use App\Domain\Tenancy\TenantContext;
use App\Models\PaymentIntentRecord;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Batch 9-4 — REQ-PAY-008 retry of a failed payment (new attempt under the same intent) and REQ-PAY-014
 * collection mode of a payment. Payments are resolved like PaymentController: tenant + ownership scoped.
 */
final class PaymentModesController
{
    public function __construct(private readonly OwnershipScope $own) {}

    public function retry(Request $r, string $payment, PaymentRetryService $retries): JsonResponse
    {
        $d = $r->validate(['idempotency_key' => 'nullable|string|min:16|max:128']);
        $key = $d['idempotency_key'] ?? $r->header('Idempotency-Key');
        $out = $retries->retry($this->payment($payment), $r->user(), $key ?: null);

        return response()->json(['data' => $out['payment']->load('attempts'), 'meta' => [
            'attempt_number' => $out['attempt']?->attempt_number, 'replayed' => $out['replayed'], 'customer_message' => $out['customer_message'],
        ]], $out['replayed'] ? 200 : 202);
    }

    public function attempts(string $payment, PaymentRetryService $retries): JsonResponse
    {
        $row = $this->payment($payment);

        return response()->json(['data' => $row->attempts()->orderBy('attempt_number')->get(), 'meta' => $retries->status($row) + ['financial_obligation_id' => $row->financial_obligation_id]]);
    }

    public function collectionMode(string $payment, PaymentCollectionModeService $modes): JsonResponse
    {
        $row = $this->payment($payment);
        $outcome = $modes->execution($row);
        $row->refresh();

        return response()->json(['data' => ['payment_intent_id' => $row->id, 'collection_mode' => $row->collection_mode, 'semantics' => $row->collection_semantics, 'execution' => $outcome->toArray()]]);
    }

    private function payment(string $id): PaymentIntentRecord
    {
        return $this->own->applyVia(PaymentIntentRecord::where('tenant_id', app(TenantContext::class)->id()), request()->user(), 'proposal')->findOrFail($id);
    }
}
