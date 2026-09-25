<?php

declare(strict_types=1);

namespace App\Application\Policies\Endorsements;

use App\Application\Audit\AuditWriter;
use App\Application\Authority\AuthorityService;
use App\Application\Events\OutboxWriter;
use App\Application\Payments\FinancialCaseService;
use App\Application\Policies\Chronology\PolicyChronologyWriter;
use App\Models\PaymentIntentRecord;
use App\Models\Policy;
use App\Models\PolicyTransaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REQ-END-001 — endorsement (avenant) rules on top of the PolicyServicingService machine
 * (request → [PAYMENT_PENDING] → PENDING_APPROVAL → APPROVED | REJECTED; policy ACTIVE → ENDORSEMENT_PENDING → ACTIVE).
 *
 *  prepare()   at request: type rule (allowed paths, backdating), rerate / manual / nil premium delta (WF-035/036)
 *  authorise() before approval: ENDORSE authority for rule types that need it (owner decision 12)
 *  finalise()  inside approval: new ENDORSEMENT policy_version (REQ-POL-002), refund for a return premium (WF-038),
 *              outbox policy.endorsement.issued. Additional premium (WF-037) is collected by the existing
 *              PAYMENT_PENDING → requestPayment/linkPayment path before approval.
 */
final class EndorsementService
{
    public function __construct(
        private readonly EndorsementTypeRules $rules,
        private readonly EndorsementRerater $rerater,
        private readonly PolicyChronologyWriter $chronology,
        private readonly AuthorityService $authority,
        private readonly FinancialCaseService $financialCases,
        private readonly AuditWriter $audit,
        private readonly OutboxWriter $outbox,
    ) {}

    /** @return array{premium_delta_minor: int, columns: array<string, mixed>} */
    public function prepare(Policy $policy, array $data, CarbonImmutable $effectiveAt, array $termsAfter): array
    {
        $rule = $this->rules->resolve($data['endorsement_type'] ?? EndorsementTypeRules::DEFAULT_TYPE, $policy->tenant_id);
        $this->rules->validate($rule, $policy, (array) ($data['requested_changes'] ?? []), $effectiveAt, CarbonImmutable::now());

        $manual = (int) ($data['premium_delta_minor'] ?? 0);
        [$delta, $basis] = match ($rule->financial_effect) {
            'NONE' => [0, ['method' => 'NIL']],
            'MANUAL' => [$manual, ['method' => 'MANUAL']],
            'RERATE' => ($r = $this->rerater->rerate($policy, (array) $policy->terms_snapshot, $termsAfter, $effectiveAt))
                ? [$r['premium_delta_minor'], $r['basis']]
                : [$manual, ['method' => 'MANUAL', 'reason' => 'RERATE_UNAVAILABLE']],
        };

        return ['premium_delta_minor' => $delta, 'columns' => [
            'endorsement_type' => $rule->code,
            'financial_effect' => self::effect($delta),
            'rating_basis' => json_encode($basis + ['rule_id' => $rule->id]),
            'channel' => $data['channel'] ?? 'STAFF',
            'service_request_id' => $data['service_request_id'] ?? null,
        ]];
    }

    /** Stores the endorsement columns; closes the customer service request the avenant was raised from. */
    public function attach(PolicyTransaction $transaction, array $columns, User $actor): void
    {
        if ($requestId = $columns['service_request_id'] ?? null) {
            $updated = DB::table('policy_transactions')->where('id', $requestId)->where('policy_id', $transaction->policy_id)
                ->where('status', 'REQUESTED')->update(['status' => 'CONVERTED', 'updated_at' => now()]);
            if (! $updated) {
                throw ValidationException::withMessages(['service_request_id' => 'The service request is not open on this policy.']);
            }
            DB::table('policy_transaction_events')->insert(['id' => (string) Str::uuid(), 'policy_transaction_id' => $requestId,
                'from_status' => 'REQUESTED', 'to_status' => 'CONVERTED', 'reason_code' => 'ENDORSEMENT_RAISED', 'actor_id' => $actor->id,
                'metadata' => json_encode(['message' => 'Endorsement '.$transaction->transaction_number.' raised', 'transaction_id' => $transaction->id]), 'occurred_at' => now()]);
        }
        $transaction->forceFill($columns)->save();
    }

    /**
     * ENDORSE authority check, run OUTSIDE the approval transaction so a DENIED attempt stays recorded.
     * Governed only when the rule requires authority and the carrier has ENDORSE limits configured.
     */
    public function authorise(PolicyTransaction $transaction, User $actor): ?string
    {
        if ($transaction->type !== 'ENDORSEMENT' || ! $transaction->getAttribute('endorsement_type')) {
            return null;
        }
        $policy = Policy::findOrFail($transaction->policy_id);
        $rule = $this->rules->resolve($transaction->getAttribute('endorsement_type'), $policy->tenant_id);
        if (! $rule->requires_authority) {
            return null;
        }
        $configured = DB::table('authority_limits')->where('authority_type', 'ENDORSE')->where('status', 'ACTIVE')
            ->where(fn ($q) => $q->whereNull('carrier_id')->orWhere('carrier_id', $policy->carrier_id))->exists();
        if (! $configured) {
            return null;
        }

        $line = data_get($policy->terms_snapshot, 'line_code');
        $limit = $this->authority->effectiveLimit('USER', $actor->id, $policy->carrier_id, 'ENDORSE', $line, now()->toDateString());
        $id = (string) Str::uuid();
        $outcome = $limit ? 'ALLOWED' : 'DENIED';
        DB::table('authority_checks')->insert([
            'id' => $id, 'tenant_id' => $policy->tenant_id, 'carrier_id' => $policy->carrier_id,
            'holder_type' => 'USER', 'holder_id' => $actor->id, 'authority_type' => 'ENDORSE', 'action' => 'ENDORSE',
            'subject_type' => 'policy_transaction', 'subject_id' => $transaction->id, 'line_code' => $line,
            'amount_minor' => abs((int) $transaction->premium_delta_minor), 'currency' => $transaction->currency,
            'outcome' => $outcome, 'reason' => $limit ? 'WITHIN_AUTHORITY' : 'NO_ENDORSE_AUTHORITY',
            'source' => 'AUTHORITY_LIMIT', 'authority_limit_id' => $limit?->id, 'checked_by' => $actor->id, 'created_at' => now(),
        ]);
        $this->audit->record('authority.checked', 'authority_check', $id, ['outcome' => $outcome, 'action' => 'ENDORSE']);
        if (! $limit) {
            throw ValidationException::withMessages(['authority' => 'You do not hold ENDORSE authority for this carrier.']);
        }

        return $id;
    }

    /** Called inside PolicyServicingService::approve after the policy row carries the new terms/version. */
    public function finalise(Policy $policy, PolicyTransaction $transaction, User $actor, ?string $authorityCheckId): void
    {
        $versionId = $this->chronology->record($policy, 'ENDORSEMENT', $transaction->effective_at, [
            'source_type' => 'policy_transaction', 'source_id' => $transaction->id, 'actor_id' => $actor->id,
        ]);

        $refundId = null;
        if ($transaction->premium_delta_minor < 0 && $policy->payment_intent_id) {
            $payment = PaymentIntentRecord::findOrFail($policy->payment_intent_id);
            $refundId = $this->financialCases->requestRefund($payment, [
                'amount_minor' => -$transaction->premium_delta_minor,
                'reason_code' => 'POLICY_ENDORSEMENT',
                'notes' => 'Return premium from avenant '.$transaction->transaction_number,
                'idempotency_key' => 'policy-endorsement-'.$transaction->id,
            ], $actor)->id;
        }

        $transaction->forceFill(['policy_version_id' => $versionId, 'refund_id' => $refundId, 'authority_check_id' => $authorityCheckId])->save();
        $this->audit->record('policy.endorsement.issued', 'policy_transaction', $transaction->id, [
            'policy_id' => $policy->id, 'policy_version_id' => $versionId, 'premium_delta_minor' => $transaction->premium_delta_minor,
        ], $transaction->reason_code);
        $this->outbox->record('policy.endorsement.issued', 'policy', $policy->id, [
            'policy_id' => $policy->id, 'transaction_id' => $transaction->id, 'policy_version_id' => $versionId,
            'endorsement_type' => $transaction->getAttribute('endorsement_type'), 'financial_effect' => self::effect((int) $transaction->premium_delta_minor),
            'premium_delta_minor' => (int) $transaction->premium_delta_minor, 'refund_id' => $refundId,
        ]);
    }

    public static function effect(int $delta): string
    {
        return $delta > 0 ? 'ADDITIONAL_PREMIUM' : ($delta < 0 ? 'REFUND' : 'NIL');
    }
}
