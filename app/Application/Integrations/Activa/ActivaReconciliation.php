<?php

declare(strict_types=1);

namespace App\Application\Integrations\Activa;

use App\Application\Audit\AuditWriter;
use App\Models\CarrierApiConnection;
use App\Models\CarrierApiSyncRecord;
use App\Models\PaymentIntentRecord;
use App\Models\Policy;
use Illuminate\Support\Facades\DB;

/**
 * Catch-up job (activa:reconcile, every 10 minutes). For each Activa connection:
 *   1. waiting on Activa (PENDING_VERIFICATION / AUTH_FAILED): one auth probe per service — the moment Activa approves
 *      the subscription the probe succeeds, the connection turns ACTIVE and everything below runs (nothing to switch on);
 *   2. policies of the carrier issued in the back-fill window with no SYNCED contract, or with a due retry → sync;
 *   3. synced contracts whose attestation / document is still missing → sync;
 *   4. succeeded payments of synced contracts not yet recorded (EncaissementCMR) → record;
 *   5. travel: cancelled policies → cancel at Activa; policy version moved → PATCH.
 * FAILED / MAPPING_REQUIRED rows are left for a person (issuance-exception + operations queues) unless $includeFailed.
 */
final class ActivaReconciliation
{
    public function __construct(
        private readonly ActivaConnections $connections,
        private readonly ActivaPolicySync $sync,
        private readonly ActivaGateway $gateway,
        private readonly AuditWriter $audit,
    ) {}

    /** @return array{connections: int, probed: int, policies: int, payments: int, cancelled: int, updated: int, skipped_config: int} */
    public function run(int $limit = 200, bool $includeFailed = false): array
    {
        $stats = ['connections' => 0, 'probed' => 0, 'policies' => 0, 'payments' => 0, 'cancelled' => 0, 'updated' => 0, 'skipped_config' => 0];
        foreach ($this->connections->all() as $c) {
            if ($c->status === 'DISABLED' || $c->status === 'CONFIG_REQUIRED') {
                $stats['skipped_config']++;

                continue;
            }
            if ($this->connections->forCarrier($c->carrier_id)?->id !== $c->id) {
                continue; // the carrier's live connection (PRODUCTION preferred) drives traffic
            }
            if ($c->status !== 'ACTIVE') {
                $this->connections->test($c);
                $stats['probed']++;
                $c->refresh();
                if ($c->status !== 'ACTIVE') {
                    continue;
                }
            }
            $stats['connections']++;
            $s = $this->reconcile($c, $limit, $includeFailed);
            foreach ($s as $k => $v) {
                $stats[$k] += $v;
            }
            $c->forceFill(['last_reconciled_at' => now()])->save();
            $this->audit->record('integration.carrier_api.reconciled', 'carrier_api_connection', $c->id, ['carrier_id' => $c->carrier_id] + $s);
        }

        return $stats;
    }

    /** @return array{policies: int, payments: int, cancelled: int, updated: int} */
    private function reconcile(CarrierApiConnection $c, int $limit, bool $includeFailed): array
    {
        $out = ['policies' => 0, 'payments' => 0, 'cancelled' => 0, 'updated' => 0];
        $retryable = ['PENDING', 'RETRY_PENDING', 'CONFIG_REQUIRED', ...($includeFailed ? ['FAILED', 'MAPPING_REQUIRED'] : [])];
        $since = now()->subDays((int) config('activa.sync.backfill_days', 30));

        // Policies with a step to do: no contract step yet, a due retryable contract step, or a synced contract whose
        // attestation / document step is missing or due.
        $record = fn ($s, array $ops) => $s->from('carrier_api_sync_records as r')->whereColumn('r.subject_id', 'policies.id')->where('r.subject_type', 'policy')
            ->where('r.carrier_id', $c->carrier_id)->whereIn('r.operation', $ops);
        $due = fn ($s) => $s->whereIn('r.status', $retryable)->where(fn ($w) => $w->whereNull('r.next_attempt_at')->orWhere('r.next_attempt_at', '<=', now()));
        $ids = Policy::query()->where('carrier_id', $c->carrier_id)->whereIn('status', ['ACTIVE', 'EXPIRING'])->where('issued_at', '>=', $since)
            ->where(function ($q) use ($record, $due) {
                $q->whereNotExists(fn ($s) => $record($s, [ActivaPolicySync::CONTRACT]))
                    ->orWhereExists(fn ($s) => $due($record($s, [ActivaPolicySync::CONTRACT])))
                    ->orWhere(fn ($w) => $w->whereExists(fn ($s) => $record($s, [ActivaPolicySync::CONTRACT])->where('r.status', 'SYNCED'))
                        ->where(fn ($d) => $d->whereNotExists(fn ($s) => $record($s, [ActivaPolicySync::DOCUMENT]))
                            ->orWhereExists(fn ($s) => $due($record($s, [ActivaPolicySync::ATTESTATION, ActivaPolicySync::DOCUMENT])))));
            })
            ->orderBy('issued_at')->limit($limit)->pluck('id');
        foreach ($ids as $id) {
            $policy = Policy::find($id);
            if ($policy && $this->sync->applies($policy)) {
                $this->sync->syncPolicy($policy, $includeFailed);
                $out['policies']++;
            }
        }

        // Payments of synced contracts not recorded yet.
        $payments = PaymentIntentRecord::query()->where('status', 'SUCCEEDED')
            ->whereIn('proposal_id', DB::table('carrier_api_sync_records as r')->join('policies as p', 'p.id', '=', 'r.subject_id')
                ->where('r.carrier_id', $c->carrier_id)->where('r.subject_type', 'policy')->where('r.operation', ActivaPolicySync::CONTRACT)->where('r.status', 'SYNCED')
                ->select('p.proposal_id'))
            ->whereNotExists(fn ($s) => $s->from('carrier_api_sync_records as r')->whereColumn('r.subject_id', 'payment_intents.id')->where('r.subject_type', 'payment_intent')
                ->whereNotIn('r.status', $retryable))
            ->limit($limit)->get();
        foreach ($payments as $payment) {
            if ($this->sync->syncPayment($payment, $includeFailed) !== null) {
                $out['payments']++;
            }
        }

        // Travel servicing.
        $travel = CarrierApiSyncRecord::query()->where('carrier_id', $c->carrier_id)->where('subject_type', 'policy')->where('operation', ActivaPolicySync::CONTRACT)
            ->where('status', 'SYNCED')->where('external_data->family', 'TRAVEL')->limit($limit)->get();
        foreach ($travel as $rec) {
            $policy = Policy::find($rec->subject_id);
            if ($policy === null) {
                continue;
            }
            if ($policy->status === 'CANCELLED' && $this->sync->record($c->carrier_id, 'policy', $policy->id, ActivaPolicySync::CANCEL)?->status !== 'SYNCED') {
                $reason = (string) (DB::table('policy_status_history')->where('policy_id', $policy->id)->where('to_status', 'CANCELLED')->orderByDesc('occurred_at')->value('reason_code') ?? 'CANCELLED');
                $this->sync->cancelTravel($policy, $reason);
                $out['cancelled']++;
            } elseif (in_array($policy->status, ['ACTIVE', 'EXPIRING'], true) && (int) $policy->version > (int) ($rec->external_data['synced_version'] ?? 1)) {
                $this->sync->updateTravel($policy);
                $out['updated']++;
            }
        }

        return $out;
    }
}
