<?php

declare(strict_types=1);

namespace App\Application\Policies\Lapse;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Domain\Policies\PolicyStateMachine;
use App\Models\Policy;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REQ-POL-010 / WF-083 — recovery of SUSPENDED (premium default), EXPIRED and LAPSED policies.
 *
 *   open    — maker opens a recovery case; arrears = outstanding on DEFAULTED / LAPSED / GRACE / OVERDUE instalments.
 *   settle  — instalment payments (or a checker waiver) clear arrears.
 *   approve — a different user (maker-checker) approves once arrears are zero; a policy whose term has ended
 *             needs a new coverage end in the future. Policy → ACTIVE through PolicyStateMachine.
 *   reject  — closes the case; the policy is untouched.
 * A renewed policy (a successor points at it) is never recovered: the successor carries the cover.
 */
final class PolicyRecoveryService
{
    public const RECOVERABLE = ['SUSPENDED', 'EXPIRED', 'LAPSED'];

    public const ARREARS_STATUSES = ['OVERDUE', 'GRACE', 'DEFAULTED', 'LAPSED'];

    public function __construct(
        private readonly PolicyStateMachine $machine,
        private readonly AuditWriter $audit,
        private readonly OutboxWriter $outbox,
    ) {}

    /** @param array{reason_code: string, new_coverage_ends_at?: ?string, notes?: ?string} $data */
    public function open(Policy $policy, array $data, User $actor): object
    {
        return DB::transaction(function () use ($policy, $data, $actor): object {
            $policy = Policy::whereKey($policy->id)->lockForUpdate()->firstOrFail();
            if (! in_array($policy->status, self::RECOVERABLE, true)) {
                $this->fail('status', 'POLICY_NOT_RECOVERABLE', "Policy in {$policy->status} cannot be recovered.");
            }
            if (Policy::where('previous_policy_id', $policy->id)->exists()) {
                $this->fail('status', 'POLICY_RENEWED', 'A renewed policy is recovered through its successor.');
            }
            if (DB::table('policy_recovery_cases')->where('policy_id', $policy->id)->where('status', 'OPEN')->exists()) {
                $this->fail('status', 'RECOVERY_ALREADY_OPEN', 'A recovery case is already open for this policy.');
            }
            $id = (string) Str::uuid();
            DB::table('policy_recovery_cases')->insert([
                'id' => $id, 'tenant_id' => $policy->tenant_id, 'policy_id' => $policy->id,
                'case_number' => 'PRC-'.strtoupper(Str::random(10)), 'status' => 'OPEN', 'policy_status_at_open' => $policy->status,
                'reason_code' => $data['reason_code'], 'arrears_minor' => $this->arrears($policy->id), 'currency' => $policy->currency ?? 'XAF',
                'new_coverage_ends_at' => $data['new_coverage_ends_at'] ?? null, 'notes' => $data['notes'] ?? null,
                'requested_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $case = DB::table('policy_recovery_cases')->where('id', $id)->first();
            $this->audit->record('policy.recovery.requested', 'policy_recovery_case', $id, ['policy_id' => $policy->id, 'arrears_minor' => $case->arrears_minor], $data['reason_code']);
            $this->outbox->record('policy.recovery.requested', 'policy', $policy->id, [
                'policy_id' => $policy->id, 'recovery_case_id' => $id, 'policy_status' => $policy->status, 'arrears_minor' => (int) $case->arrears_minor,
            ]);

            return $case;
        });
    }

    /** Apply a (reconciled) payment to an instalment. Idempotent per payment intent. */
    public function settleInstalment(string $instalmentId, int $amountMinor, ?string $paymentIntentId = null): object
    {
        if ($amountMinor <= 0) {
            $this->fail('amount_minor', 'AMOUNT_INVALID', 'Payment amount must be positive.');
        }

        return DB::transaction(function () use ($instalmentId, $amountMinor, $paymentIntentId): object {
            $i = DB::table('policy_premium_instalments')->where('id', $instalmentId)->lockForUpdate()->first()
                ?? $this->fail('instalment_id', 'INSTALMENT_NOT_FOUND', 'Instalment not found.');
            if (in_array($i->status, ['PAID', 'WAIVED'], true) || ($paymentIntentId !== null && $i->payment_intent_id === $paymentIntentId)) {
                return $i;
            }
            $paid = (int) $i->paid_minor + $amountMinor;
            $settled = $paid >= (int) $i->amount_minor;
            DB::table('policy_premium_instalments')->where('id', $i->id)->update([
                'paid_minor' => $paid, 'payment_intent_id' => $paymentIntentId ?? $i->payment_intent_id, 'updated_at' => now(),
            ] + ($settled ? ['status' => 'PAID', 'settled_at' => now()] : []));
            if ($settled) {
                $this->outbox->record('policy.premium.instalment_settled', 'policy', $i->policy_id, [
                    'policy_id' => $i->policy_id, 'instalment_id' => $i->id, 'previous_status' => $i->status, 'paid_minor' => $paid, 'currency' => $i->currency,
                ]);
            }

            return DB::table('policy_premium_instalments')->where('id', $i->id)->first();
        });
    }

    public function waiveInstalment(string $instalmentId, string $reason, User $actor): object
    {
        return DB::transaction(function () use ($instalmentId, $reason, $actor): object {
            $i = DB::table('policy_premium_instalments')->where('id', $instalmentId)->lockForUpdate()->first()
                ?? $this->fail('instalment_id', 'INSTALMENT_NOT_FOUND', 'Instalment not found.');
            if (in_array($i->status, ['PAID', 'WAIVED'], true)) {
                return $i;
            }
            DB::table('policy_premium_instalments')->where('id', $i->id)->update(['status' => 'WAIVED', 'settled_at' => now(), 'updated_at' => now()]);
            $this->audit->record('policy.premium.instalment_waived', 'policy_premium_instalment', $i->id, ['policy_id' => $i->policy_id, 'actor_id' => $actor->id], $reason);
            $this->outbox->record('policy.premium.instalment_settled', 'policy', $i->policy_id, [
                'policy_id' => $i->policy_id, 'instalment_id' => $i->id, 'previous_status' => $i->status, 'waived' => true, 'currency' => $i->currency,
            ]);

            return DB::table('policy_premium_instalments')->where('id', $i->id)->first();
        });
    }

    public function approve(string $caseId, User $actor, ?string $newCoverageEndsAt = null): Policy
    {
        return DB::transaction(function () use ($caseId, $actor, $newCoverageEndsAt): Policy {
            $case = $this->openCase($caseId);
            if ($case->requested_by === $actor->id) {
                $this->fail('actor', 'MAKER_CHECKER', 'The requester cannot approve their own recovery case.');
            }
            $policy = Policy::whereKey($case->policy_id)->lockForUpdate()->firstOrFail();
            if (! in_array($policy->status, self::RECOVERABLE, true)) {
                $this->fail('status', 'POLICY_NOT_RECOVERABLE', "Policy in {$policy->status} cannot be recovered.");
            }
            $arrears = $this->arrears($policy->id);
            if ($arrears > 0) {
                $this->fail('arrears', 'ARREARS_OUTSTANDING', "Arrears of {$arrears} must be settled or waived first.");
            }
            $endsAt = $newCoverageEndsAt ?? $case->new_coverage_ends_at;
            $update = ['status' => 'ACTIVE'];
            if ($endsAt !== null) {
                $ends = CarbonImmutable::parse($endsAt);
                if ($ends->lessThanOrEqualTo(now())) {
                    $this->fail('new_coverage_ends_at', 'COVERAGE_END_INVALID', 'The new coverage end must be in the future.');
                }
                $update['coverage_ends_at'] = $ends;
            } elseif (CarbonImmutable::parse($policy->coverage_ends_at)->lessThanOrEqualTo(now())) {
                $this->fail('new_coverage_ends_at', 'COVERAGE_END_REQUIRED', 'The term has ended: a new coverage end is required.');
            }

            $from = $policy->status;
            $this->machine->assert($from, 'ACTIVE');
            $policy->update($update);
            DB::table('policy_status_history')->insert([
                'id' => (string) Str::uuid(), 'policy_id' => $policy->id, 'from_status' => $from, 'to_status' => 'ACTIVE',
                'reason_code' => 'POLICY_RECOVERED', 'actor_id' => $actor->id,
                'metadata' => json_encode(['recovery_case_id' => $case->id, 'coverage_ends_at' => $update['coverage_ends_at'] ?? null]), 'occurred_at' => now(),
            ]);
            DB::table('policy_recovery_cases')->where('id', $case->id)->update([
                'status' => 'APPROVED', 'decided_by' => $actor->id, 'decided_at' => now(), 'new_coverage_ends_at' => $update['coverage_ends_at'] ?? $case->new_coverage_ends_at, 'updated_at' => now(),
            ]);
            $this->audit->record('policy.recovery.approved', 'policy_recovery_case', $case->id, ['policy_id' => $policy->id, 'from_status' => $from], $case->reason_code);
            $this->outbox->record('policy.recovery.approved', 'policy', $policy->id, [
                'policy_id' => $policy->id, 'recovery_case_id' => $case->id, 'from_status' => $from, 'to_status' => 'ACTIVE',
            ]);

            return $policy->refresh();
        });
    }

    public function reject(string $caseId, User $actor, string $reason): object
    {
        return DB::transaction(function () use ($caseId, $actor, $reason): object {
            $case = $this->openCase($caseId);
            DB::table('policy_recovery_cases')->where('id', $case->id)->update([
                'status' => 'REJECTED', 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_reason' => $reason, 'updated_at' => now(),
            ]);
            $this->audit->record('policy.recovery.rejected', 'policy_recovery_case', $case->id, ['policy_id' => $case->policy_id], $reason);
            $this->outbox->record('policy.recovery.rejected', 'policy', $case->policy_id, ['policy_id' => $case->policy_id, 'recovery_case_id' => $case->id]);

            return DB::table('policy_recovery_cases')->where('id', $case->id)->first();
        });
    }

    public function arrears(string $policyId): int
    {
        return (int) DB::table('policy_premium_instalments')->where('policy_id', $policyId)
            ->whereIn('status', self::ARREARS_STATUSES)->sum(DB::raw('amount_minor - paid_minor'));
    }

    private function openCase(string $caseId): object
    {
        $case = DB::table('policy_recovery_cases')->where('id', $caseId)->lockForUpdate()->first();
        if (! $case || $case->status !== 'OPEN') {
            $this->fail('status', 'RECOVERY_NOT_OPEN', 'Recovery case is not open.');
        }

        return $case;
    }

    private function fail(string $field, string $code, string $message): never
    {
        throw ValidationException::withMessages([$field => "{$code}: {$message}"]);
    }
}
