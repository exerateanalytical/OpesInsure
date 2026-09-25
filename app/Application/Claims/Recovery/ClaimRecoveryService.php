<?php

declare(strict_types=1);

namespace App\Application\Claims\Recovery;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Application\Finance\Obligations\ObligationService;
use App\Application\Ledger\FinancialPostingService;
use App\Models\Claim;
use App\Models\ClaimRecovery;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REQ-REC-001 — recovery / subrogation / salvage / contribution / deductible recovery on a claim.
 *
 * Extends the Wave 7 claim_recoveries row: each recovery is a RECEIVABLE financial obligation (type CLAIM,
 * source claim_recovery); a receipt settles that obligation and posts `claim.recovery.received` (Dr bank / Cr claims expense).
 *   EXPECTED    — raised, nothing received, not yet due
 *   OUTSTANDING — due date passed or partly received (the collections engine chases it)
 *   RECEIVED    — fully received
 *   DISPUTED    — the counterparty contests it (dunning suspended)
 *   CLOSED      — terminal: received and closed, or abandoned / written off
 * Legacy Wave 7 rows keep their stored OPEN status (read as EXPECTED) and the existing receipts endpoint.
 */
final class ClaimRecoveryService
{
    public const TYPES = ['SUBROGATION', 'SALVAGE', 'CONTRIBUTION', 'DEDUCTIBLE_RECOVERY', 'REINSURANCE', 'COINSURANCE', 'THIRD_PARTY', 'OTHER'];

    public const STATUSES = ['EXPECTED', 'RECEIVED', 'OUTSTANDING', 'DISPUTED', 'CLOSED'];

    public const RECEIVABLE_STATUSES = ['EXPECTED', 'OUTSTANDING', 'OPEN'];

    public function __construct(
        private readonly ObligationService $obligations,
        private readonly FinancialPostingService $posting,
        private readonly AuditWriter $audit,
        private readonly OutboxWriter $outbox,
    ) {}

    /** @param array{type:string, counterparty_name:string, target_amount_minor:int, due_at?:mixed, debtor_party_id?:?string, notes?:?string} $d */
    public function open(Claim $claim, array $d, User $actor): ClaimRecovery
    {
        if (! in_array($d['type'], self::TYPES, true)) {
            $this->fail('type', 'RECOVERY_TYPE_INVALID', "Unknown recovery type {$d['type']}.");
        }
        if ((int) $d['target_amount_minor'] <= 0) {
            $this->fail('target_amount_minor', 'AMOUNT_INVALID', 'Expected recovery amount must be positive.');
        }

        return DB::transaction(function () use ($claim, $d, $actor): ClaimRecovery {
            $due = $d['due_at'] ?? now()->addDays(30);
            $r = ClaimRecovery::create([
                'claim_id' => $claim->id, 'type' => $d['type'], 'status' => 'EXPECTED', 'counterparty_name' => $d['counterparty_name'],
                'target_amount_minor' => (int) $d['target_amount_minor'], 'currency' => $claim->currency, 'reference' => 'RCV-'.strtoupper(Str::random(12)),
                'notes' => $d['notes'] ?? null, 'opened_by' => $actor->id, 'debtor_party_id' => $d['debtor_party_id'] ?? null, 'due_at' => $due,
            ]);
            $o = $this->obligations->create([
                'tenant_id' => $claim->tenant_id, 'kind' => 'RECEIVABLE', 'type' => 'CLAIM', 'source_type' => 'claim_recovery', 'source_id' => $r->id,
                'currency' => $claim->currency, 'amount_minor' => (int) $d['target_amount_minor'], 'due_at' => $due, 'policy_id' => $claim->policy_id,
                'debtor_type' => $r->debtor_party_id ? 'party' : null, 'debtor_id' => $r->debtor_party_id,
                'description' => "{$d['type']} recovery on claim {$claim->claim_number} from {$d['counterparty_name']}",
                'metadata' => ['claim_id' => $claim->id, 'recovery_type' => $d['type']],
            ], $actor->id);
            $r->update(['financial_obligation_id' => $o->id]);
            $this->audit->record('claim.recovery.expected', 'claim_recovery', $r->id, ['claim_id' => $claim->id, 'type' => $d['type'], 'amount_minor' => (int) $d['target_amount_minor']]);
            $this->outbox->record('claim.recovery.expected', 'claim_recovery', $r->id, [
                'claim_id' => $claim->id, 'recovery_id' => $r->id, 'type' => $d['type'], 'amount_minor' => (int) $d['target_amount_minor'], 'currency' => $claim->currency, 'obligation_id' => $o->id,
            ]);

            return $r->refresh();
        });
    }

    /** Record money received. Idempotent per (recovery, reference). Settles the obligation and posts claim.recovery.received. */
    public function receive(ClaimRecovery $recovery, int $amountMinor, string $reference, User $actor): ClaimRecovery
    {
        if ($amountMinor <= 0) {
            $this->fail('amount_minor', 'AMOUNT_INVALID', 'Receipt amount must be positive.');
        }

        return DB::transaction(function () use ($recovery, $amountMinor, $reference, $actor): ClaimRecovery {
            $r = ClaimRecovery::whereKey($recovery->id)->lockForUpdate()->firstOrFail();
            if (DB::table('claim_recovery_receipts')->where(['claim_recovery_id' => $r->id, 'reference' => $reference])->exists()) {
                return $r;
            }
            if (! in_array($r->status, self::RECEIVABLE_STATUSES, true)) {
                $this->fail('status', 'RECOVERY_NOT_RECEIVABLE', "Recovery is {$r->status}.");
            }
            $remaining = (int) $r->target_amount_minor - (int) $r->recovered_amount_minor;
            if ($amountMinor > $remaining) {
                $this->fail('amount_minor', 'RECOVERY_OVER_RECEIPT', "Only {$remaining} remains to be recovered.");
            }
            $tenant = (string) Claim::whereKey($r->claim_id)->value('tenant_id');
            $receiptId = (string) Str::uuid();
            $journal = $this->posting->post($tenant, 'claim.recovery.received', $receiptId, $amountMinor, $r->currency, $r->id);
            DB::table('claim_recovery_receipts')->insert([
                'id' => $receiptId, 'claim_recovery_id' => $r->id, 'amount_minor' => $amountMinor, 'currency' => $r->currency, 'reference' => $reference,
                'journal_id' => $journal, 'received_by' => $actor->id, 'received_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($r->financial_obligation_id) {
                $this->obligations->settle($r->financial_obligation_id, $amountMinor, 'recovery-receipt:'.$receiptId, $actor->id);
            }
            $total = (int) $r->recovered_amount_minor + $amountMinor;
            $r->update(['recovered_amount_minor' => $total, 'status' => $total === (int) $r->target_amount_minor ? 'RECEIVED' : 'OUTSTANDING']);
            $this->audit->record('claim.recovery.recorded', 'claim_recovery', $r->id, ['amount_minor' => $amountMinor, 'reference' => $reference, 'journal_id' => $journal, 'actor_id' => $actor->id]);
            $this->outbox->record('claim.recovery.received', 'claim_recovery', $r->id, [
                'claim_id' => $r->claim_id, 'recovery_id' => $r->id, 'receipt_id' => $receiptId, 'amount_minor' => $amountMinor, 'currency' => $r->currency,
                'recovered_total_minor' => $total, 'journal_id' => $journal,
            ]);

            return $r->refresh();
        });
    }

    public function dispute(ClaimRecovery $recovery, string $reason, User $actor): ClaimRecovery
    {
        return DB::transaction(function () use ($recovery, $reason, $actor): ClaimRecovery {
            $r = ClaimRecovery::whereKey($recovery->id)->lockForUpdate()->firstOrFail();
            if (! in_array($r->status, self::RECEIVABLE_STATUSES, true)) {
                $this->fail('status', 'RECOVERY_NOT_DISPUTABLE', "Recovery is {$r->status}.");
            }
            $r->update(['status' => 'DISPUTED', 'dispute_reason' => $reason]);
            $this->audit->record('claim.recovery.disputed', 'claim_recovery', $r->id, ['actor_id' => $actor->id], $reason);
            $this->outbox->record('claim.recovery.disputed', 'claim_recovery', $r->id, ['claim_id' => $r->claim_id, 'recovery_id' => $r->id]);

            return $r->refresh();
        });
    }

    public function resolveDispute(ClaimRecovery $recovery, string $resolution, User $actor): ClaimRecovery
    {
        return DB::transaction(function () use ($recovery, $resolution, $actor): ClaimRecovery {
            $r = ClaimRecovery::whereKey($recovery->id)->lockForUpdate()->firstOrFail();
            if ($r->status !== 'DISPUTED') {
                $this->fail('status', 'RECOVERY_NOT_DISPUTED', "Recovery is {$r->status}.");
            }
            $r->update(['status' => $this->openStatus($r)]);
            $this->audit->record('claim.recovery.dispute_resolved', 'claim_recovery', $r->id, ['actor_id' => $actor->id], $resolution);

            return $r->refresh();
        });
    }

    /** Close: a fully RECEIVED recovery, or abandon the remainder (the open obligation is cancelled; a write-off goes through collections). */
    public function close(ClaimRecovery $recovery, string $reason, User $actor): ClaimRecovery
    {
        return DB::transaction(function () use ($recovery, $reason, $actor): ClaimRecovery {
            $r = ClaimRecovery::whereKey($recovery->id)->lockForUpdate()->firstOrFail();
            if ($r->status === 'CLOSED') {
                return $r;
            }
            if ($r->financial_obligation_id) {
                $o = DB::table('financial_obligations')->where('id', $r->financial_obligation_id)->first();
                if ($o && in_array($o->status, ObligationService::OPEN_STATUSES, true)) {
                    $this->obligations->cancel($o->id, 'Recovery abandoned: '.$reason, $actor->id);
                }
            }
            $r->update(['status' => 'CLOSED', 'closed_at' => now(), 'close_reason' => $reason]);
            $this->audit->record('claim.recovery.closed', 'claim_recovery', $r->id, ['recovered_minor' => (int) $r->recovered_amount_minor, 'actor_id' => $actor->id], $reason);
            $this->outbox->record('claim.recovery.closed', 'claim_recovery', $r->id, ['claim_id' => $r->claim_id, 'recovery_id' => $r->id, 'recovered_minor' => (int) $r->recovered_amount_minor]);

            return $r->refresh();
        });
    }

    /** EXPECTED recoveries past their due date become OUTSTANDING (called by the collections run). Returns the count. */
    public function markOverdue(?string $tenantId = null): int
    {
        return ClaimRecovery::where('status', 'EXPECTED')->whereNotNull('due_at')->where('due_at', '<', now()->startOfDay())
            ->when($tenantId, fn ($q, $t) => $q->whereIn('claim_id', Claim::where('tenant_id', $t)->select('id')))
            ->update(['status' => 'OUTSTANDING', 'updated_at' => now()]);
    }

    /** Called by collections when the recovery obligation is written off (maker-checker approved). */
    public function onObligationWrittenOff(string $recoveryId, string $reason): void
    {
        ClaimRecovery::whereKey($recoveryId)->where('status', '!=', 'CLOSED')->update(['status' => 'CLOSED', 'closed_at' => now(), 'close_reason' => 'Written off: '.$reason, 'updated_at' => now()]);
    }

    private function openStatus(ClaimRecovery $r): string
    {
        return (int) $r->recovered_amount_minor > 0 || ($r->due_at && $r->due_at->lt(now()->startOfDay())) ? 'OUTSTANDING' : 'EXPECTED';
    }

    private function fail(string $field, string $code, string $message): never
    {
        throw ValidationException::withMessages([$field => "{$code}: {$message}"]);
    }
}
