<?php

declare(strict_types=1);

namespace App\Application\Finance\Obligations;

use App\Application\Policies\Lapse\PolicyRecoveryService;
use App\Models\PaymentIntentRecord;
use App\Models\Policy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * REQ-PAY-006 — premium obligations and instalment schedules of a policy.
 *
 *   generate  — at issuance: the proposal's cover_terms.schedule (CoverTermsService) becomes
 *               SINGLE plan → one PREMIUM obligation (source policy);
 *               multi-instalment plan → one policy_premium_instalments row + one INSTALMENT obligation per schedule line
 *               (source_type 'policy', source_id policy id, source_reference 'INSTALMENT:<sequence>'; the instalment row carries
 *               financial_obligation_id)
 *               (due: AT_BIND = cover start, +NM = start + N months, CUSTOM_k = start + (k-1) × term/n months).
 *               The bind payment (policies.payment_intent_id) then settles the first obligation. Idempotent.
 *   settlePayment — a reconciled / SUCCEEDED payment settles its obligation (payment_intents.financial_obligation_id,
 *               else the bind obligation of the policy it paid for) through ObligationService::settle, and the linked
 *               instalment through PolicyRecoveryService::settleInstalment (so the premium-cover sweep sees it PAID).
 * Non-payment consequences stay with PremiumDefaultSweep (DUE → OVERDUE → GRACE → DEFAULTED → LAPSED).
 */
final class PolicyPremiumObligations
{
    public const POLICY_SOURCE = 'policy';

    public function __construct(private readonly ObligationService $obligations, private readonly PolicyRecoveryService $recovery) {}

    /** @return list<object> the policy's premium obligations */
    public function generate(Policy $policy, bool $settleBindPayment = true): array
    {
        $existing = $this->forPolicy($policy->id);
        if ($existing !== []) {
            return $existing;
        }
        $currency = $policy->currency ?? 'XAF';
        $total = (int) ($policy->premium_minor ?? $policy->terms_snapshot['total_minor'] ?? 0);
        $terms = $policy->proposal?->cover_terms ?? [];
        $schedule = array_values(array_filter((array) ($terms['schedule'] ?? []), fn ($l) => (int) ($l['amount_minor'] ?? 0) > 0));
        $start = CarbonImmutable::parse($policy->coverage_starts_at ?? $policy->issued_at ?? now());
        $base = [
            'tenant_id' => $policy->tenant_id, 'kind' => 'RECEIVABLE', 'currency' => $currency, 'policy_id' => $policy->id,
            'debtor_type' => $policy->party_id ? 'party' : null, 'debtor_id' => $policy->party_id,
            'creditor_type' => $policy->carrier_id ? 'carrier' : null, 'creditor_id' => $policy->carrier_id,
        ];

        DB::transaction(function () use ($policy, $schedule, $total, $start, $base, $terms, $currency): void {
            if (count($schedule) <= 1) {
                if ($total > 0) {
                    $this->obligations->create($base + [
                        'type' => 'PREMIUM', 'source_type' => self::POLICY_SOURCE, 'source_id' => $policy->id, 'amount_minor' => $total, 'due_at' => $start,
                        'description' => "Premium {$policy->policy_number}",
                    ]);
                }

                return;
            }
            $n = count($schedule);
            $months = ($terms['duration']['unit'] ?? 'MONTH') === 'MONTH' ? (int) ($terms['duration']['value'] ?? 12) : 12;
            foreach ($schedule as $i => $line) {
                $seq = (int) ($line['sequence'] ?? $i + 1);
                $due = $this->dueDate((string) ($line['due'] ?? ''), $start, $seq, $n, $months);
                $instalmentId = DB::table('policy_premium_instalments')->where(['policy_id' => $policy->id, 'sequence' => $seq])->value('id');
                if (! $instalmentId) {
                    $instalmentId = (string) Str::uuid();
                    DB::table('policy_premium_instalments')->insert([
                        'id' => $instalmentId, 'tenant_id' => $policy->tenant_id, 'policy_id' => $policy->id, 'sequence' => $seq,
                        'due_date' => $due->toDateString(), 'amount_minor' => (int) $line['amount_minor'], 'currency' => $currency, 'status' => 'DUE',
                        'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                $instalment = DB::table('policy_premium_instalments')->where('id', $instalmentId)->first();
                $o = $this->obligations->create($base + [
                    'type' => 'INSTALMENT', 'source_type' => self::POLICY_SOURCE, 'source_id' => $policy->id, 'source_reference' => 'INSTALMENT:'.$seq,
                    'amount_minor' => (int) $instalment->amount_minor, 'due_at' => $due,
                    'description' => "Instalment {$seq}/{$n} {$policy->policy_number}", 'metadata' => ['sequence' => $seq, 'instalment_id' => $instalmentId, 'fee_minor' => (int) ($line['fee_minor'] ?? 0)],
                ]);
                DB::table('policy_premium_instalments')->where('id', $instalmentId)->update(['financial_obligation_id' => $o->id]);
            }
        });

        if ($settleBindPayment && $policy->payment_intent_id && ($payment = PaymentIntentRecord::find($policy->payment_intent_id))) {
            $this->settlePayment($payment);
        }

        return $this->forPolicy($policy->id);
    }

    /** Settle the obligation a SUCCEEDED payment pays. Idempotent (reference = payment intent). Returns the obligation or null. */
    public function settlePayment(PaymentIntentRecord $payment): ?object
    {
        if ($payment->status !== 'SUCCEEDED') {
            return null;
        }
        $obligation = $payment->financial_obligation_id
            ? DB::table('financial_obligations')->where('id', $payment->financial_obligation_id)->first()
            : $this->bindObligation($payment);
        if (! $obligation) {
            return null;
        }
        if ($obligation->currency !== $payment->currency) {
            report(new \RuntimeException("Payment {$payment->id} currency {$payment->currency} does not match obligation {$obligation->id}."));

            return null;
        }

        return DB::transaction(function () use ($payment, $obligation): object {
            $settled = $this->obligations->settle($obligation->id, (int) $payment->amount_minor, 'payment_intent:'.$payment->id);
            $instalmentId = DB::table('policy_premium_instalments')->where('financial_obligation_id', $obligation->id)->value('id');
            if ($instalmentId) {
                $this->recovery->settleInstalment($instalmentId, (int) $payment->amount_minor, $payment->id);
            }

            return $settled;
        });
    }

    /** @return list<object> */
    public function forPolicy(string $policyId): array
    {
        return DB::table('financial_obligations')->where('policy_id', $policyId)->whereIn('type', ['PREMIUM', 'INSTALMENT'])
            ->orderBy('due_at')->orderBy('created_at')->get()->all();
    }

    private function bindObligation(PaymentIntentRecord $payment): ?object
    {
        $policyId = DB::table('policies')->where('payment_intent_id', $payment->id)->value('id');
        if (! $policyId) {
            return null;
        }

        return DB::table('financial_obligations')->where(['source_type' => self::POLICY_SOURCE, 'source_id' => $policyId, 'type' => 'PREMIUM'])->first()
            ?? DB::table('financial_obligations')->where(['source_type' => self::POLICY_SOURCE, 'source_id' => $policyId, 'type' => 'INSTALMENT', 'source_reference' => 'INSTALMENT:1'])->first();
    }

    private function dueDate(string $due, CarbonImmutable $start, int $seq, int $n, int $months): CarbonImmutable
    {
        if (preg_match('/^\+(\d+)M$/', $due, $m)) {
            return $start->addMonthsNoOverflow((int) $m[1]);
        }
        if ($due === 'AT_BIND' || $seq <= 1) {
            return $start;
        }

        return $start->addMonthsNoOverflow(intdiv(($seq - 1) * max(1, $months), $n));
    }
}
