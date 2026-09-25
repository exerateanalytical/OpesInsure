<?php

declare(strict_types=1);

namespace App\Application\Claims\Execution;

use App\Application\Audit\AuditWriter;
use App\Application\Capabilities\CapabilityPinner;
use App\Application\Claims\ClaimCarrierExchangeService;
use App\Application\Distribution\Execution\ExecutionContext;
use App\Application\Distribution\Execution\ExecutionOutcome;
use App\Application\Events\OutboxWriter;
use App\Application\Shared\CanonicalJson;
use App\Models\Claim;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REQ-CLM-011 — runs a claim through its carrier's claims execution mode. The mode is the claim's
 * CLAIMS_INTAKE capability pin (CapabilityPinner, taken when the claim is created or on first use here), so a
 * later profile change never re-routes a claim in flight. Outbound submission goes through the existing
 * ClaimCarrierExchangeService; carrier answers come back either as HMAC-signed API messages
 * (API_SYNCHRONIZED) or as manual entries approved by a second person (BROKER_ASSISTED / MANUAL_CARRIER /
 * INSURER_PORTAL). FULLY_DIGITAL claims are decided in the OPES workflow and exchange nothing with the carrier.
 */
final class ClaimExecutionService
{
    public const INBOUND_TYPES = ['CLAIM_ACKNOWLEDGEMENT', 'CLAIM_DECISION', 'INFORMATION_REQUEST'];

    public const DECISION_OUTCOMES = ['APPROVED', 'PARTIALLY_APPROVED', 'REJECTED'];

    public function __construct(
        private readonly CapabilityPinner $pinner,
        private readonly ClaimExecutionRegistry $registry,
        private readonly ClaimCarrierExchangeService $exchange,
        private readonly ClaimCarrierSignatureVerifier $signatures,
        private readonly CanonicalJson $json,
        private readonly AuditWriter $audit,
        private readonly OutboxWriter $outbox,
    ) {}

    /** The claim's pinned claims mode (pinning it now when the claim predates pinning). */
    public function mode(Claim $claim, ?User $actor = null): string
    {
        $pin = $this->pinner->pin(ClaimExecution::SUBJECT_TYPE, $claim->id, $this->carrierId($claim), ClaimExecution::CAPABILITY, null, $actor);

        return ClaimExecutionModes::fromResolved($pin->mode, $pin->source);
    }

    /** @return array{claim_id:string,mode:string,semantics:array,execution:array,pin:array} */
    public function describe(Claim $claim, ?User $actor = null): array
    {
        $mode = $this->mode($claim, $actor);
        $pin = $this->pinner->pinned(ClaimExecution::SUBJECT_TYPE, $claim->id, ClaimExecution::CAPABILITY);

        return [
            'claim_id' => $claim->id,
            'mode' => $mode,
            'semantics' => ClaimExecutionModes::semantics($mode),
            'execution' => $this->execution($claim)->toArray(),
            'pin' => ['profile_id' => $pin?->profile_id, 'profile_version' => $pin?->profile_version, 'source' => $pin?->source, 'pinned_at' => $pin?->pinned_at],
        ];
    }

    public function execution(Claim $claim): ExecutionOutcome
    {
        $this->mode($claim);

        return $this->registry->execute(new ExecutionContext(ClaimExecution::SUBJECT_TYPE, $claim->id, $this->carrierId($claim)));
    }

    /**
     * Outbound CLAIM_SUBMISSION to the carrier (idempotent per key). FULLY_DIGITAL claims are not submitted.
     *
     * @return array{mode:string,message:object,execution:array}
     */
    public function submit(Claim $claim, string $idempotencyKey, User $actor, array $extra = []): array
    {
        $mode = $this->mode($claim, $actor);
        if (! ClaimExecutionModes::semantics($mode)['outbound_submission']) {
            throw ValidationException::withMessages(['mode' => __('batch11_claims_execution.no_carrier_submission', ['mode' => $mode])]);
        }
        $claim->loadMissing('policy');
        $payload = [
            'claim_number' => $claim->claim_number,
            'policy_number' => $claim->policy?->policy_number,
            'loss_occurred_at' => $claim->loss_occurred_at?->toIso8601String(),
            'loss_location' => $claim->loss_location,
            'description' => $claim->loss_details['description'] ?? null,
            'estimated_loss_minor' => $claim->estimated_loss_minor,
            'currency' => $claim->currency,
            'claims_mode' => $mode,
        ] + array_intersect_key($extra, array_flip(['portal_reference', 'notes']));
        $message = $this->exchange->queue($claim, 'CLAIM_SUBMISSION', $payload, $idempotencyKey, $actor);
        DB::table('carrier_exchange_messages')->where('id', $message->id)->whereNull('channel')->update(['channel' => $mode]);

        return ['mode' => $mode, 'message' => DB::table('carrier_exchange_messages')->find($message->id), 'execution' => $this->execution($claim)->toArray()];
    }

    /**
     * API_SYNCHRONIZED inbound carrier message; the raw body must carry a valid HMAC signature of an active key of the claim's carrier.
     *
     * @return array{message:object,replayed:bool}
     */
    public function receiveSigned(Claim $claim, string $rawBody, string $keyId, int $timestamp, string $signature): array
    {
        $carrierId = $this->carrierId($claim);
        $this->signatures->verify($carrierId, $keyId, $timestamp, $rawBody, $signature);
        $mode = $this->mode($claim);
        if (ClaimExecutionModes::semantics($mode)['inbound_channel'] !== ClaimExecutionModes::INBOUND_SIGNED_API) {
            throw ValidationException::withMessages(['mode' => __('batch11_claims_execution.channel_not_allowed', ['mode' => $mode, 'channel' => ClaimExecutionModes::INBOUND_SIGNED_API])]);
        }
        $body = json_decode($rawBody, true);
        $data = $this->validateInbound(is_array($body) ? $body : [], true);

        return $this->exchange->recordInbound($claim, $data['message_type'], $data['payload'], (string) $data['event_id'], ClaimExecutionModes::INBOUND_SIGNED_API, $keyId);
    }

    /** Maker step: a staff member keys in a carrier acknowledgement / decision received off-platform. */
    public function proposeManualEntry(Claim $claim, array $input, User $maker): object
    {
        $mode = $this->mode($claim, $maker);
        if (! ClaimExecutionModes::semantics($mode)['maker_checker']) {
            throw ValidationException::withMessages(['mode' => __('batch11_claims_execution.channel_not_allowed', ['mode' => $mode, 'channel' => ClaimExecutionModes::INBOUND_MANUAL_ENTRY])]);
        }
        $data = $this->validateInbound($input, false);
        $id = (string) Str::uuid();
        DB::table('claim_carrier_manual_entries')->insert([
            'id' => $id, 'tenant_id' => $claim->tenant_id, 'claim_id' => $claim->id, 'carrier_id' => $this->carrierId($claim), 'claims_mode' => $mode,
            'message_type' => $data['message_type'], 'payload' => json_encode($data['payload'], JSON_THROW_ON_ERROR), 'payload_hash' => $this->json->hash($data['payload']),
            'status' => 'PENDING_APPROVAL', 'entered_by' => $maker->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $meta = ['claim_id' => $claim->id, 'message_type' => $data['message_type'], 'claims_mode' => $mode];
        $this->audit->record('claim.carrier_entry.proposed', 'claim_carrier_manual_entry', $id, $meta);
        $this->outbox->record('claim.carrier_entry.proposed', 'claim', $claim->id, $meta + ['entry_id' => $id]);

        return DB::table('claim_carrier_manual_entries')->find($id);
    }

    /** Checker step: a different person approves (the carrier message is then recorded) or rejects the entry. */
    public function reviewManualEntry(Claim $claim, string $entryId, bool $approve, User $checker, ?string $reason = null): object
    {
        return DB::transaction(function () use ($claim, $entryId, $approve, $checker, $reason) {
            $entry = DB::table('claim_carrier_manual_entries')->where(['id' => $entryId, 'claim_id' => $claim->id])->lockForUpdate()->first() ?? abort(404);
            if ($entry->status !== 'PENDING_APPROVAL') {
                throw ValidationException::withMessages(['entry' => __('batch11_claims_execution.entry_not_pending')]);
            }
            if ($entry->entered_by === $checker->id) {
                throw ValidationException::withMessages(['entry' => __('batch11_claims_execution.maker_checker')]);
            }
            $messageId = null;
            if ($approve) {
                $messageId = $this->exchange->recordInbound($claim, $entry->message_type, json_decode($entry->payload, true), 'manual:'.$entry->id, ClaimExecutionModes::INBOUND_MANUAL_ENTRY)['message']->id;
            }
            $status = $approve ? 'APPROVED' : 'REJECTED';
            DB::table('claim_carrier_manual_entries')->where('id', $entry->id)->update(['status' => $status, 'reviewed_by' => $checker->id, 'reviewed_at' => now(),
                'rejection_reason' => $approve ? null : $reason, 'carrier_exchange_message_id' => $messageId, 'updated_at' => now()]);
            $event = $approve ? 'claim.carrier_entry.approved' : 'claim.carrier_entry.rejected';
            $this->audit->record($event, 'claim_carrier_manual_entry', $entry->id, ['claim_id' => $claim->id, 'carrier_exchange_message_id' => $messageId], $reason);
            $this->outbox->record($event, 'claim', $claim->id, ['claim_id' => $claim->id, 'entry_id' => $entry->id, 'carrier_exchange_message_id' => $messageId]);

            return DB::table('claim_carrier_manual_entries')->find($entry->id);
        });
    }

    /** @return array{event_id:?string,message_type:string,payload:array} */
    private function validateInbound(array $input, bool $requireEventId): array
    {
        $v = validator($input, [
            'event_id' => ($requireEventId ? 'required' : 'nullable').'|string|min:8|max:80',
            'message_type' => 'required|in:'.implode(',', self::INBOUND_TYPES),
            'external_reference' => 'required_if:message_type,CLAIM_ACKNOWLEDGEMENT|nullable|string|max:255',
            'in_reply_to' => 'nullable|uuid',
            'decision' => 'required_if:message_type,CLAIM_DECISION|array',
            'decision.outcome' => 'required_if:message_type,CLAIM_DECISION|in:'.implode(',', self::DECISION_OUTCOMES),
            'decision.amount_minor' => 'nullable|integer|min:0',
            'decision.currency' => 'nullable|string|size:3',
            'decision.reason' => 'nullable|string|max:2000',
            'requested_items' => 'nullable|array',
            'requested_items.*' => 'string|max:255',
            'notes' => 'nullable|string|max:2000',
        ])->validate();

        return ['event_id' => $v['event_id'] ?? null, 'message_type' => $v['message_type'], 'payload' => array_diff_key($v, ['event_id' => true])];
    }

    private function carrierId(Claim $claim): string
    {
        return (string) ($claim->policy?->carrier_id ?? DB::table('policies')->where('id', $claim->policy_id)->value('carrier_id'));
    }
}
