<?php

declare(strict_types=1);

namespace App\Application\Payments\ExecutionModes;

use App\Application\Audit\AuditWriter;
use App\Application\Capabilities\CapabilityPinner;
use App\Application\Capabilities\CapabilityResolver;
use App\Application\Distribution\Execution\ExecutionContext;
use App\Application\Distribution\Execution\ExecutionOutcome;
use App\Application\Events\OutboxWriter;
use App\Models\PaymentIntentRecord;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * REQ-PAY-014 — assigns and records the collection mode of one payment: pins the carrier's PAYMENT capability
 * (subject payment_intent, CapabilityPinner) and freezes who collects / who holds funds / which account is
 * credited on payment_intents. Once set it never changes, so a later profile or agreement edit cannot
 * re-route money already in flight. PaymentInitiationService calls gate() before prompting a payer.
 */
final class PaymentCollectionModeService
{
    public function __construct(
        private readonly CapabilityResolver $resolver,
        private readonly CapabilityPinner $pinner,
        private readonly PaymentExecutionRegistry $registry,
        private readonly AuditWriter $audit,
        private readonly OutboxWriter $outbox,
    ) {}

    public function assign(PaymentIntentRecord $intent): PaymentIntentRecord
    {
        if ($intent->collection_mode !== null) {
            return $intent;
        }
        $carrier = $this->carrierOf($intent);
        $mode = null;
        $config = [];
        if ($carrier?->carrier_id) {
            $actor = auth()->user();
            $pin = $this->pinner->pin(PaymentExecution::SUBJECT_TYPE, $intent->id, $carrier->carrier_id, PaymentExecution::CAPABILITY, $carrier->product_id, $actor instanceof User ? $actor : null);
            $mode = PaymentCollectionModes::fromResolved($pin->mode, $pin->source, $intent->provider);
            $config = (array) ($this->resolver->mode($carrier->carrier_id, PaymentExecution::CAPABILITY, $carrier->product_id)['config'] ?? []);
        }
        $mode ??= PaymentCollectionModes::fromResolved('MANUAL', CapabilityResolver::SOURCE_DEFAULT, $intent->provider);
        $semantics = PaymentCollectionModes::semantics($mode, $config, $intent->provider);
        $semantics['execution_mode'] = $this->registry->forCollectionMode($mode)->executionMode();

        $updated = PaymentIntentRecord::whereKey($intent->id)->whereNull('collection_mode')->update(['collection_mode' => $mode, 'collection_semantics' => json_encode($semantics, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
        if ($updated === 1) {
            $this->audit->record('payment.collection_mode.assigned', 'payment_intent', $intent->id, ['collection_mode' => $mode, 'credited_account' => $semantics['credited_account']]);
            $this->outbox->record('payment.collection_mode.assigned', 'payment_intent', $intent->id, ['payment_intent_id' => $intent->id, 'collection_mode' => $mode, 'collector' => $semantics['collector'], 'funds_holder' => $semantics['funds_holder'], 'credited_account' => $semantics['credited_account']]);
        }

        return $intent->refresh();
    }

    /** Execute through the pinned PaymentExecution adapter (the same capability-pinned path as quote/claim). */
    public function execution(PaymentIntentRecord $intent): ExecutionOutcome
    {
        $intent = $this->assign($intent);
        $carrier = $this->carrierOf($intent);

        return $this->registry->forCollectionMode($intent->collection_mode)->execute(
            new ExecutionContext(PaymentExecution::SUBJECT_TYPE, $intent->id, (string) $carrier?->carrier_id, $carrier?->product_id)
        );
    }

    /** Refuse an on-platform payer prompt when the payment's collection mode says OPES does not collect it. */
    public function gate(PaymentIntentRecord $intent): PaymentIntentRecord
    {
        $intent = $this->assign($intent);
        if (! ($intent->collection_semantics['on_platform_prompt'] ?? false)) {
            throw ValidationException::withMessages(['collection_mode' => __('batch9_payments.off_platform_collection', ['mode' => $intent->collection_mode])]);
        }

        return $intent;
    }

    private function carrierOf(PaymentIntentRecord $intent): ?object
    {
        return DB::table('proposals')->join('quote_offers', 'quote_offers.id', '=', 'proposals.quote_offer_id')
            ->where('proposals.id', $intent->proposal_id)->first(['quote_offers.carrier_id', 'quote_offers.product_id']);
    }
}
