<?php

declare(strict_types=1);

namespace App\Application\Collections;

use App\Application\Audit\AuditWriter;
use App\Application\Cases\CaseService;
use App\Application\Claims\Recovery\ClaimRecoveryService;
use App\Application\Events\OutboxWriter;
use App\Application\Finance\Obligations\ObligationService;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REQ-REC-003 — recovery & debt collection engine over overdue RECEIVABLE obligations:
 * premium arrears (PREMIUM / INSTALMENT), commission clawbacks (COMMISSION, Batch 10) and claim recoveries (CLAIM).
 *
 *   run()            — daily: advance each overdue obligation through the dunning stages (one notice per stage),
 *                      evaluate promises-to-pay (an unexpired PENDING promise suspends dunning), escalate at the
 *                      last stage to a RECOVERY case, and close accounts whose obligation is no longer open.
 *   promise()        — record a promise-to-pay.
 *   escalate()       — manual escalation to a RECOVERY collection case (idempotent per obligation).
 *   requestWriteOff / approveWriteOff / rejectWriteOff — maker-checker; the approval calls ObligationService::writeOff.
 *
 * STAGE_DAYS are platform defaults (days past due) pending an owner decision on the dunning calendar.
 */
final class CollectionService
{
    public const TYPES = ['PREMIUM', 'INSTALMENT', 'COMMISSION', 'CLAIM', 'FEE'];

    /** stage => minimum days past due (ordered). */
    public const STAGE_DAYS = ['REMINDER_1' => 1, 'REMINDER_2' => 15, 'FINAL_NOTICE' => 30, 'ESCALATED' => 60];

    public const STAGES = ['CURRENT', 'REMINDER_1', 'REMINDER_2', 'FINAL_NOTICE', 'ESCALATED'];

    public function __construct(
        private readonly ObligationService $obligations,
        private readonly ClaimRecoveryService $recoveries,
        private readonly CaseService $cases,
        private readonly AuditWriter $audit,
        private readonly OutboxWriter $outbox,
    ) {}

    /** @return array{notices:int, escalated:int, promises_kept:int, promises_broken:int, closed:int, recoveries_overdue:int} */
    public function run(?string $tenantId = null, ?\DateTimeInterface $asOf = null): array
    {
        $today = CarbonImmutable::instance($asOf ?? now())->startOfDay();
        $s = ['notices' => 0, 'escalated' => 0, 'promises_kept' => 0, 'promises_broken' => 0, 'closed' => 0, 'recoveries_overdue' => $this->recoveries->markOverdue($tenantId)];

        // Accounts whose obligation left the open statuses.
        DB::table('collection_accounts as a')->join('financial_obligations as o', 'o.id', '=', 'a.financial_obligation_id')
            ->where('a.status', 'ACTIVE')->whereNotIn('o.status', ObligationService::OPEN_STATUSES)
            ->when($tenantId, fn ($q, $t) => $q->where('a.tenant_id', $t))
            ->select(['a.id', 'o.status'])->orderBy('a.id')->get()
            ->each(function ($row) use (&$s): void {
                DB::table('collection_accounts')->where('id', $row->id)->update(['status' => match ($row->status) {
                    'SETTLED' => 'SETTLED', 'WRITTEN_OFF' => 'WRITTEN_OFF', default => 'CLOSED'}, 'updated_at' => now()]);
                $s['closed']++;
            });

        DB::table('financial_obligations')->where('kind', 'RECEIVABLE')->whereIn('type', self::TYPES)->whereIn('status', ObligationService::OPEN_STATUSES)
            ->where('due_at', '<', $today)->when($tenantId, fn ($q, $t) => $q->where('tenant_id', $t))
            ->orderBy('id')->chunk(200, function ($rows) use ($today, &$s): void {
                foreach ($rows as $o) {
                    $this->advance($o, $today, $s);
                }
            });

        return $s;
    }

    public function promise(string $tenantId, string $obligationId, int $amountMinor, mixed $promisedFor, ?string $notes, User $actor): object
    {
        if ($amountMinor <= 0) {
            $this->fail('amount_minor', 'AMOUNT_INVALID', 'Promised amount must be positive.');
        }
        $o = $this->openObligation($tenantId, $obligationId);
        $date = CarbonImmutable::parse($promisedFor)->startOfDay();
        if ($date->lt(now()->startOfDay())) {
            $this->fail('promised_for', 'PROMISE_DATE_PAST', 'A promise-to-pay date cannot be in the past.');
        }

        return DB::transaction(function () use ($o, $amountMinor, $date, $notes, $actor): object {
            $account = $this->account($o);
            DB::table('collection_promises')->where(['collection_account_id' => $account->id, 'status' => 'PENDING'])
                ->update(['status' => 'BROKEN', 'evaluated_at' => now(), 'updated_at' => now()]); // superseded
            $id = (string) Str::uuid();
            DB::table('collection_promises')->insert(['id' => $id, 'collection_account_id' => $account->id, 'amount_minor' => min($amountMinor, (int) $o->outstanding_minor),
                'outstanding_at_promise_minor' => (int) $o->outstanding_minor, 'promised_for' => $date->toDateString(), 'status' => 'PENDING', 'notes' => $notes,
                'recorded_by' => $actor->id, 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->record('collections.promise.recorded', 'financial_obligation', $o->id, ['promise_id' => $id, 'amount_minor' => $amountMinor, 'promised_for' => $date->toDateString()]);
            $this->outbox->record('collections.promise.recorded', 'financial_obligation', $o->id, ['obligation_id' => $o->id, 'promise_id' => $id, 'amount_minor' => $amountMinor, 'promised_for' => $date->toDateString()]);

            return DB::table('collection_promises')->where('id', $id)->first();
        });
    }

    public function escalate(string $tenantId, string $obligationId, ?User $actor, string $reason = 'Manual escalation'): object
    {
        $o = $this->openObligation($tenantId, $obligationId);

        return DB::transaction(function () use ($o, $actor, $reason): object {
            $account = $this->account($o);
            if ($account->case_id) {
                return $account;
            }
            $case = $this->cases->open($o->tenant_id, 'RECOVERY', [
                'title' => "Collection — {$o->type} {$o->currency} ".number_format((int) $o->outstanding_minor, 0, '.', ' '),
                'subject_type' => 'financial_obligation', 'subject_id' => $o->id, 'source_type' => 'financial_obligation', 'source_id' => $o->id,
                'idempotency_key' => 'collection:'.$o->id, 'priority' => 'HIGH',
            ], $actor);
            DB::table('collection_accounts')->where('id', $account->id)->update(['stage' => 'ESCALATED', 'case_id' => $case->id, 'updated_at' => now()]);
            $this->audit->record('collections.escalated', 'financial_obligation', $o->id, ['case_id' => $case->id, 'actor_id' => $actor?->id], $reason);
            $this->outbox->record('collections.escalated', 'financial_obligation', $o->id, ['obligation_id' => $o->id, 'case_id' => $case->id, 'type' => $o->type, 'outstanding_minor' => (int) $o->outstanding_minor, 'currency' => $o->currency]);

            return DB::table('collection_accounts')->where('id', $account->id)->first();
        });
    }

    public function requestWriteOff(string $tenantId, string $obligationId, string $reason, User $maker): object
    {
        $o = $this->openObligation($tenantId, $obligationId);
        if (DB::table('collection_write_off_requests')->where(['financial_obligation_id' => $o->id, 'status' => 'PENDING'])->exists()) {
            $this->fail('obligation_id', 'WRITE_OFF_PENDING', 'A write-off request is already pending for this obligation.');
        }
        $id = (string) Str::uuid();
        DB::table('collection_write_off_requests')->insert(['id' => $id, 'tenant_id' => $tenantId, 'financial_obligation_id' => $o->id, 'outstanding_minor' => (int) $o->outstanding_minor,
            'currency' => $o->currency, 'reason' => $reason, 'status' => 'PENDING', 'requested_by' => $maker->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit->record('collections.write_off.requested', 'financial_obligation', $o->id, ['request_id' => $id, 'outstanding_minor' => (int) $o->outstanding_minor], $reason);
        $this->outbox->record('collections.write_off.requested', 'financial_obligation', $o->id, ['obligation_id' => $o->id, 'request_id' => $id, 'outstanding_minor' => (int) $o->outstanding_minor, 'currency' => $o->currency]);

        return DB::table('collection_write_off_requests')->where('id', $id)->first();
    }

    public function approveWriteOff(string $tenantId, string $requestId, User $checker, ?string $note = null): object
    {
        return DB::transaction(function () use ($tenantId, $requestId, $checker, $note): object {
            $req = $this->pendingRequest($tenantId, $requestId, $checker);
            $o = $this->obligations->writeOff($req->financial_obligation_id, $req->reason, $checker->id);
            DB::table('collection_write_off_requests')->where('id', $req->id)->update(['status' => 'APPROVED', 'decided_by' => $checker->id, 'decision_note' => $note, 'decided_at' => now(), 'updated_at' => now()]);
            DB::table('collection_accounts')->where('financial_obligation_id', $o->id)->update(['status' => 'WRITTEN_OFF', 'updated_at' => now()]);
            if ($o->source_type === 'claim_recovery') {
                $this->recoveries->onObligationWrittenOff($o->source_id, $req->reason);
            }
            $this->outbox->record('collections.write_off.approved', 'financial_obligation', $o->id, ['obligation_id' => $o->id, 'request_id' => $req->id, 'written_off_minor' => (int) $o->outstanding_minor, 'currency' => $o->currency]);

            return DB::table('collection_write_off_requests')->where('id', $req->id)->first();
        });
    }

    public function rejectWriteOff(string $tenantId, string $requestId, User $checker, string $note): object
    {
        $req = $this->pendingRequest($tenantId, $requestId, $checker);
        DB::table('collection_write_off_requests')->where('id', $req->id)->update(['status' => 'REJECTED', 'decided_by' => $checker->id, 'decision_note' => $note, 'decided_at' => now(), 'updated_at' => now()]);
        $this->audit->record('collections.write_off.rejected', 'financial_obligation', $req->financial_obligation_id, ['request_id' => $req->id], $note);

        return DB::table('collection_write_off_requests')->where('id', $req->id)->first();
    }

    public static function stageFor(int $daysOverdue): string
    {
        $stage = 'CURRENT';
        foreach (self::STAGE_DAYS as $code => $days) {
            if ($daysOverdue >= $days) {
                $stage = $code;
            }
        }

        return $stage;
    }

    private function advance(object $o, CarbonImmutable $today, array &$s): void
    {
        if ($o->source_type === 'claim_recovery' && DB::table('claim_recoveries')->where('id', $o->source_id)->value('status') === 'DISPUTED') {
            return; // a disputed recovery is not dunned
        }
        DB::transaction(function () use ($o, $today, &$s): void {
            $account = $this->account($o);
            $promise = DB::table('collection_promises')->where(['collection_account_id' => $account->id, 'status' => 'PENDING'])->lockForUpdate()->first();
            if ($promise) {
                if (CarbonImmutable::parse($promise->promised_for)->gte($today)) {
                    return; // promise-to-pay pending: dunning suspended
                }
                $kept = (int) $o->outstanding_minor <= (int) $promise->outstanding_at_promise_minor - (int) $promise->amount_minor;
                DB::table('collection_promises')->where('id', $promise->id)->update(['status' => $kept ? 'KEPT' : 'BROKEN', 'evaluated_at' => now(), 'updated_at' => now()]);
                $s[$kept ? 'promises_kept' : 'promises_broken']++;
                if (! $kept) {
                    $this->outbox->record('collections.promise.broken', 'financial_obligation', $o->id, ['obligation_id' => $o->id, 'promise_id' => $promise->id]);
                }
            }
            $days = (int) CarbonImmutable::parse($o->due_at)->startOfDay()->diffInDays($today, false);
            $target = self::stageFor($days);
            $current = array_search($account->stage, self::STAGES, true);
            $targetRank = array_search($target, self::STAGES, true);
            if ($targetRank <= $current) {
                return;
            }
            // One notice per stage passed (stages are never skipped silently: the latest reached stage is noticed).
            $inserted = DB::table('collection_notices')->insertOrIgnore(['id' => (string) Str::uuid(), 'collection_account_id' => $account->id, 'stage' => $target,
                'outstanding_minor' => (int) $o->outstanding_minor, 'currency' => $o->currency, 'days_overdue' => $days, 'issued_at' => now()]);
            DB::table('collection_accounts')->where('id', $account->id)->update(['stage' => $target, 'last_notice_at' => now(), 'updated_at' => now()]);
            if ($inserted) {
                $s['notices']++;
                $this->outbox->record('collections.notice.issued', 'financial_obligation', $o->id, [
                    'obligation_id' => $o->id, 'stage' => $target, 'type' => $o->type, 'debtor_type' => $o->debtor_type, 'debtor_id' => $o->debtor_id,
                    'outstanding_minor' => (int) $o->outstanding_minor, 'currency' => $o->currency, 'days_overdue' => $days,
                ]);
            }
            if ($target === 'ESCALATED' && ! $account->case_id) {
                $this->escalate($o->tenant_id, $o->id, null, "Dunning: {$days} days past due");
                $s['escalated']++;
            }
        });
    }

    private function account(object $o): object
    {
        DB::table('collection_accounts')->insertOrIgnore(['id' => (string) Str::uuid(), 'tenant_id' => $o->tenant_id, 'financial_obligation_id' => $o->id,
            'stage' => 'CURRENT', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('collection_accounts')->where('financial_obligation_id', $o->id)->lockForUpdate()->first();
    }

    private function openObligation(string $tenantId, string $id): object
    {
        $o = DB::table('financial_obligations')->where(['tenant_id' => $tenantId, 'id' => $id])->first() ?? abort(404);
        if ($o->kind !== 'RECEIVABLE' || ! in_array($o->status, ObligationService::OPEN_STATUSES, true)) {
            $this->fail('obligation_id', 'OBLIGATION_NOT_COLLECTABLE', 'Only an open receivable can be collected.');
        }

        return $o;
    }

    private function pendingRequest(string $tenantId, string $id, User $checker): object
    {
        $req = DB::table('collection_write_off_requests')->where(['tenant_id' => $tenantId, 'id' => $id])->lockForUpdate()->first() ?? abort(404);
        if ($req->status !== 'PENDING') {
            $this->fail('status', 'WRITE_OFF_DECIDED', "Request is {$req->status}.");
        }
        if ($req->requested_by === $checker->id) {
            $this->fail('status', 'MAKER_CHECKER', 'The requester cannot decide their own write-off.');
        }

        return $req;
    }

    private function fail(string $field, string $code, string $message): never
    {
        throw ValidationException::withMessages([$field => "{$code}: {$message}"]);
    }
}
