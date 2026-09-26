<?php

declare(strict_types=1);

namespace App\Application\Ledger\Journals;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Application\Ledger\LedgerService;
use App\Domain\Ledger\Journal;
use App\Domain\Ledger\JournalLine;
use App\Domain\Ledger\JournalStatus;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Batch 10-7 REQ-ACC-002 / ESR FIN-014..016: MANUAL journals DRAFT -> VALIDATED -> APPROVED -> POSTED -> REVERSED.
 * Maker-checker: the approver must differ from the creator (also enforced by journals_maker_checker).
 * Reversal never edits the original: LedgerService::reverse posts a mirror journal.
 */
final class ManualJournalService
{
    public function __construct(private LedgerService $ledger, private AuditWriter $audit, private OutboxWriter $outbox) {}

    /** @param array{reference_type:string,reference_id:string,currency:string,reason_code:string,description?:?string,journal_date?:?string,lines:list<array{account_id:string,debit_minor?:int,credit_minor?:int,dimensions?:array}>} $data */
    public function createDraft(string $tenantId, string $actorId, array $data, string $correlationId): string
    {
        $this->assertLineShape($data['lines']);
        foreach ($data['lines'] as $line) { // Agent GP6: a cost_centre_id dimension must be an active cost centre of the tenant.
            app(\App\Application\Finance\ReferenceMasters\FinanceReferenceService::class)->assertDimensions($tenantId, (array) ($line['dimensions'] ?? []));
        }
        $id = (string) Str::uuid();
        DB::transaction(function () use ($id, $tenantId, $actorId, $data, $correlationId) {
            DB::table('journals')->insert([
                'id' => $id, 'tenant_id' => $tenantId, 'reference_type' => $data['reference_type'], 'reference_id' => $data['reference_id'],
                'currency' => $data['currency'], 'status' => JournalStatus::Draft->value, 'journal_type' => 'MANUAL',
                'description' => $data['description'] ?? null, 'reason_code' => $data['reason_code'], 'journal_date' => $data['journal_date'] ?? now()->toDateString(),
                'created_by' => $actorId, 'correlation_id' => $correlationId, 'posted_at' => null, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($data['lines'] as $line) {
                DB::table('journal_lines')->insert([
                    'id' => (string) Str::uuid(), 'journal_id' => $id, 'account_id' => $line['account_id'],
                    'debit_minor' => (int) ($line['debit_minor'] ?? 0), 'credit_minor' => (int) ($line['credit_minor'] ?? 0),
                    'dimensions' => json_encode($line['dimensions'] ?? []), 'created_at' => now(), 'updated_at' => now(),
                ]);
            }
            $this->audit->record('ledger.journal.drafted', 'journal', $id, ['reference_type' => $data['reference_type']], $data['reason_code']);
            $this->outbox->record('ledger.journal.drafted', 'journal', $id, ['journal_id' => $id]);
        });

        return $id;
    }

    /** Balances, active same-currency tenant accounts and an open period. */
    public function validate(string $tenantId, string $journalId, string $actorId): void
    {
        DB::transaction(function () use ($tenantId, $journalId, $actorId) {
            $j = $this->lock($tenantId, $journalId);
            $this->transition($j, JournalStatus::Validated);
            $lines = DB::table('journal_lines')->where('journal_id', $journalId)->get();
            try {
                new Journal($j->currency, $lines->map(fn ($l) => new JournalLine($l->account_id, (int) $l->debit_minor, (int) $l->credit_minor))->all());
            } catch (DomainException $e) {
                throw ValidationException::withMessages(['lines' => $e->getMessage()]);
            }
            $accounts = DB::table('ledger_accounts')->whereIn('id', $lines->pluck('account_id')->unique())->get()->keyBy('id');
            foreach ($lines->pluck('account_id')->unique() as $accountId) {
                $a = $accounts->get($accountId);
                if (! $a || ($a->tenant_id !== null && $a->tenant_id !== $tenantId)) {
                    throw ValidationException::withMessages(['lines' => "Account {$accountId} is not available to this tenant."]);
                }
                if ($a->status !== 'ACTIVE') {
                    throw ValidationException::withMessages(['lines' => "Account {$a->code} is not active."]);
                }
                if ($a->currency !== $j->currency) {
                    throw ValidationException::withMessages(['lines' => "Account {$a->code} is not a {$j->currency} account."]);
                }
            }
            $this->assertPeriodOpen($tenantId, $j->journal_date);
            DB::table('journals')->where('id', $journalId)->update(['status' => JournalStatus::Validated->value, 'validated_by' => $actorId, 'validated_at' => now(), 'updated_at' => now()]);
            $this->audit->record('ledger.journal.validated', 'journal', $journalId);
            $this->outbox->record('ledger.journal.validated', 'journal', $journalId, ['journal_id' => $journalId]);
        });
    }

    public function approve(string $tenantId, string $journalId, string $actorId): void
    {
        DB::transaction(function () use ($tenantId, $journalId, $actorId) {
            $j = $this->lock($tenantId, $journalId);
            $this->transition($j, JournalStatus::Approved);
            abort_if($j->created_by === $actorId, 403, 'The maker of a journal cannot approve it.');
            DB::table('journals')->where('id', $journalId)->update(['status' => JournalStatus::Approved->value, 'approved_by' => $actorId, 'approved_at' => now(), 'updated_at' => now()]);
            $this->audit->record('ledger.journal.approved', 'journal', $journalId);
            $this->outbox->record('ledger.journal.approved', 'journal', $journalId, ['journal_id' => $journalId]);
        });
    }

    /** Sends a VALIDATED/APPROVED journal back to DRAFT (e.g. checker rejects). */
    public function reject(string $tenantId, string $journalId, string $actorId, string $reasonCode): void
    {
        DB::transaction(function () use ($tenantId, $journalId, $actorId, $reasonCode) {
            $j = $this->lock($tenantId, $journalId);
            $this->transition($j, JournalStatus::Draft);
            DB::table('journals')->where('id', $journalId)->update(['status' => JournalStatus::Draft->value, 'validated_by' => null, 'validated_at' => null, 'approved_by' => null, 'approved_at' => null, 'updated_at' => now()]);
            $this->audit->record('ledger.journal.rejected', 'journal', $journalId, ['actor_id' => $actorId], $reasonCode);
            $this->outbox->record('ledger.journal.rejected', 'journal', $journalId, ['journal_id' => $journalId]);
        });
    }

    public function post(string $tenantId, string $journalId, string $actorId): void
    {
        DB::transaction(function () use ($tenantId, $journalId, $actorId) {
            $j = $this->lock($tenantId, $journalId);
            $this->transition($j, JournalStatus::Posted);
            $this->ledger->postApproved($journalId, $actorId);
            $this->audit->record('ledger.journal.posted', 'journal', $journalId);
            $this->outbox->record('ledger.journal.posted', 'journal', $journalId, ['journal_id' => $journalId]);
        });
    }

    /** @return string the mirror (reversal) journal id */
    public function reverse(string $tenantId, string $journalId, string $actorId, string $reasonCode, string $correlationId): string
    {
        return DB::transaction(function () use ($tenantId, $journalId, $actorId, $reasonCode, $correlationId) {
            $j = $this->lock($tenantId, $journalId);
            $this->transition($j, JournalStatus::Reversed);
            $mirror = $this->ledger->reverse($journalId, $correlationId);
            DB::table('journals')->where('id', $journalId)->update(['reversed_by' => $actorId]);
            DB::table('journals')->where('id', $mirror)->update(['reason_code' => $reasonCode, 'created_by' => $actorId]);
            $this->audit->record('ledger.journal.reversed', 'journal', $journalId, ['reversal_journal_id' => $mirror], $reasonCode);
            $this->outbox->record('ledger.journal.reversed', 'journal', $journalId, ['journal_id' => $journalId, 'reversal_journal_id' => $mirror]);

            return $mirror;
        });
    }

    private function lock(string $tenantId, string $journalId): object
    {
        $j = DB::table('journals')->where('id', $journalId)->where('tenant_id', $tenantId)->lockForUpdate()->first();
        abort_unless($j, 404);

        return $j;
    }

    private function transition(object $j, JournalStatus $to): void
    {
        $from = JournalStatus::from($j->status);
        if (($j->journal_type ?? 'AUTOMATIC') !== 'MANUAL' && $to !== JournalStatus::Reversed) {
            abort(409, 'Only manual journals follow the approval lifecycle.');
        }
        abort_unless($from->canMoveTo($to), 409, "Journal cannot move from {$from->value} to {$to->value}.");
    }

    private function assertPeriodOpen(string $tenantId, ?string $date): void
    {
        $guard = 'App\\Application\\Ledger\\Periods\\PeriodGuard';
        if (class_exists($guard)) {
            app($guard)->assertOpen($tenantId, $date ?? now()->toDateString());
        }
    }

    private function assertLineShape(array $lines): void
    {
        foreach ($lines as $i => $l) {
            try {
                new JournalLine((string) $l['account_id'], (int) ($l['debit_minor'] ?? 0), (int) ($l['credit_minor'] ?? 0));
            } catch (DomainException $e) {
                throw ValidationException::withMessages(["lines.{$i}" => $e->getMessage()]);
            }
        }
    }
}
