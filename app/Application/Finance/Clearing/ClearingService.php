<?php

declare(strict_types=1);

namespace App\Application\Finance\Clearing;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Models\PaymentIntentRecord;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * REQ-PAY-011 — mobile-money clearing. A provider saying SUCCEEDED means the wallet was debited, not that the
 * insurer's bank has the money. Each provider settlement (report) is one clearing batch:
 *   open()      OPEN        batch per (provider, settlement_reference)
 *   attach()    OPEN        succeeded payments of that provider/currency are added (each payment clears once)
 *   settle()    SETTLED     the bank credit is recorded (settled_minor, fee_minor, bank_reference)
 *   reconcile() RECONCILED  settled + fee == expected, else VARIANCE; reconciler ≠ settler (DB check too)
 * suspense() is the clearing account: provider-succeeded money not yet allocated (payment not reconciled) or not
 * yet bank-settled (no SETTLED/RECONCILED batch), per provider and currency.
 */
final class ClearingService
{
    public function __construct(private readonly AuditWriter $audit, private readonly OutboxWriter $outbox) {}

    /** @param array{provider:string,settlement_reference:string,settlement_date:string,currency:string,notes?:string|null} $d */
    public function open(string $tenantId, array $d, User $actor): ClearingBatch
    {
        $batch = ClearingBatch::firstOrCreate(['tenant_id' => $tenantId, 'provider' => $d['provider'], 'settlement_reference' => $d['settlement_reference']],
            ['settlement_date' => $d['settlement_date'], 'currency' => $d['currency'], 'status' => 'OPEN', 'created_by' => $actor->id, 'notes' => $d['notes'] ?? null]);
        if ($batch->wasRecentlyCreated) {
            $this->audit->record('payment.clearing.opened', 'clearing_batch', $batch->id, ['provider' => $batch->provider, 'settlement_reference' => $batch->settlement_reference]);
        }

        return $batch;
    }

    /** @param list<string> $paymentIds */
    public function attach(ClearingBatch $batch, array $paymentIds, User $actor): ClearingBatch
    {
        $this->assertStatus($batch, ['OPEN']);

        return DB::transaction(function () use ($batch, $paymentIds, $actor): ClearingBatch {
            $batch = ClearingBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            $payments = PaymentIntentRecord::where('tenant_id', $batch->tenant_id)->whereIn('id', $paymentIds)->get();
            if ($payments->count() !== count(array_unique($paymentIds))) {
                throw ValidationException::withMessages(['payment_ids' => 'Unknown payment in this tenant.']);
            }
            foreach ($payments as $p) {
                if ($p->status !== 'SUCCEEDED' || $p->provider !== $batch->provider || $p->currency !== $batch->currency) {
                    throw ValidationException::withMessages(['payment_ids' => "Payment {$p->id} is not a SUCCEEDED {$batch->provider} {$batch->currency} payment."]);
                }
                $cleared = DB::table('mobile_money_clearing_items')->where('payment_intent_id', $p->id)->value('clearing_batch_id');
                if ($cleared && $cleared !== $batch->id) {
                    throw ValidationException::withMessages(['payment_ids' => "Payment {$p->id} already belongs to another clearing batch."]);
                }
                if (! $cleared) {
                    ClearingItem::create(['clearing_batch_id' => $batch->id, 'payment_intent_id' => $p->id, 'amount_minor' => (int) $p->amount_minor]);
                }
            }
            $batch->update(['expected_minor' => (int) ClearingItem::where('clearing_batch_id', $batch->id)->sum('amount_minor')]);
            $this->audit->record('payment.clearing.items_attached', 'clearing_batch', $batch->id, ['count' => count($paymentIds), 'expected_minor' => $batch->expected_minor]);

            return $batch->refresh();
        });
    }

    /** @param array{settled_minor:int,fee_minor?:int,bank_reference:string} $d */
    public function settle(ClearingBatch $batch, array $d, User $actor): ClearingBatch
    {
        $this->assertStatus($batch, ['OPEN']);
        if ($batch->expected_minor < 1) {
            throw ValidationException::withMessages(['batch' => 'Attach the settled payments before recording the bank credit.']);
        }
        $batch->update(['status' => 'SETTLED', 'settled_minor' => (int) $d['settled_minor'], 'fee_minor' => (int) ($d['fee_minor'] ?? 0), 'bank_reference' => $d['bank_reference'],
            'settled_by' => $actor->id, 'settled_at' => now()]);
        $this->outbox->record('payment.clearing.settled', 'clearing_batch', $batch->id, ['clearing_batch_id' => $batch->id, 'provider' => $batch->provider,
            'settled_minor' => $batch->settled_minor, 'currency' => $batch->currency]);
        $this->audit->record('payment.clearing.settled', 'clearing_batch', $batch->id, ['settled_minor' => $batch->settled_minor, 'fee_minor' => $batch->fee_minor]);
        // REQ-ACC-001: bank credit posted against mobile-money clearing, idempotent per batch.
        app(\App\Application\Ledger\Posting\AccountingEventPoster::class)->record($batch->tenant_id, 'payment.clearing.settled', $batch->id, (int) $batch->settled_minor, (string) $batch->currency);

        return $batch->refresh();
    }

    public function reconcile(ClearingBatch $batch, User $actor, ?string $notes = null): ClearingBatch
    {
        $this->assertStatus($batch, ['SETTLED', 'VARIANCE']);
        if ($batch->settled_by === $actor->id) {
            throw ValidationException::withMessages(['actor' => __('wave4.maker_checker')]);
        }
        $variance = (int) $batch->settled_minor + (int) $batch->fee_minor - (int) $batch->expected_minor;
        $status = $variance === 0 ? 'RECONCILED' : 'VARIANCE';
        $batch->update(['status' => $status, 'variance_minor' => $variance, 'reconciled_by' => $actor->id, 'reconciled_at' => now(), 'notes' => $notes ?? $batch->notes]);
        $this->outbox->record('payment.clearing.reconciled', 'clearing_batch', $batch->id, ['clearing_batch_id' => $batch->id, 'status' => $status, 'variance_minor' => $variance]);
        $this->audit->record('payment.clearing.reconciled', 'clearing_batch', $batch->id, ['status' => $status, 'variance_minor' => $variance], $notes);

        return $batch->refresh();
    }

    /**
     * Clearing / suspense balance per provider + currency (integer minor units).
     *
     * @return list<array{provider:string,currency:string,unallocated_minor:int,unsettled_minor:int,suspense_minor:int,variance_minor:int}>
     */
    public function suspense(string $tenantId): array
    {
        $settled = fn ($q) => $q->from('mobile_money_clearing_items as i')->join('mobile_money_clearing_batches as b', 'b.id', '=', 'i.clearing_batch_id')
            ->whereColumn('i.payment_intent_id', 'payment_intents.id')->whereIn('b.status', ['SETTLED', 'RECONCILED', 'VARIANCE']);
        $rows = PaymentIntentRecord::where('tenant_id', $tenantId)->where('status', 'SUCCEEDED')->whereNotExists($settled)
            ->selectRaw('provider, currency, COALESCE(SUM(CASE WHEN reconciled_at IS NULL THEN amount_minor ELSE 0 END),0) AS unallocated_minor, COALESCE(SUM(CASE WHEN reconciled_at IS NOT NULL THEN amount_minor ELSE 0 END),0) AS unsettled_minor')
            ->groupBy('provider', 'currency')->get()->keyBy(fn ($r) => $r->provider.'|'.$r->currency);
        $variances = DB::table('mobile_money_clearing_batches')->where('tenant_id', $tenantId)->where('status', 'VARIANCE')
            ->selectRaw('provider, currency, SUM(variance_minor) AS variance_minor')->groupBy('provider', 'currency')->get()->keyBy(fn ($r) => $r->provider.'|'.$r->currency);
        $out = [];
        foreach (array_unique([...$rows->keys()->all(), ...$variances->keys()->all()]) as $key) {
            [$provider, $currency] = explode('|', $key);
            $u = (int) ($rows[$key]->unallocated_minor ?? 0);
            $s = (int) ($rows[$key]->unsettled_minor ?? 0);
            $out[] = ['provider' => $provider, 'currency' => $currency, 'unallocated_minor' => $u, 'unsettled_minor' => $s, 'suspense_minor' => $u + $s,
                'variance_minor' => (int) ($variances[$key]->variance_minor ?? 0)];
        }
        usort($out, fn ($a, $b) => [$a['provider'], $a['currency']] <=> [$b['provider'], $b['currency']]);

        return $out;
    }

    /** @param list<string> $allowed */
    private function assertStatus(ClearingBatch $b, array $allowed): void
    {
        if (! in_array($b->status, $allowed, true)) {
            throw ValidationException::withMessages(['status' => "Clearing batch is {$b->status}; expected ".implode(' or ', $allowed).'.']);
        }
    }
}
