<?php

declare(strict_types=1);

namespace App\Application\Finance\Subledger;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Application\Ledger\Posting\AccountingEventMappingService;
use App\Application\Ledger\Posting\DefaultChartOfAccounts;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Agent F1 — spec counterparty_accounts: one account per (relationship, account type, counterparty, currency), each linked to a GL
 * control account in the tenant chart (ledger_accounts). An account holds no balance of its own (principles.no_direct_balance_editing):
 * its balance is Σ finance_ledger_entries tagged to it, which are projected from posted journal lines. Opening is maker-checker:
 * PENDING_APPROVAL → ACTIVE by a different user; SUSPENDED / CLOSED are terminal-ish states recorded with an audit reason.
 */
final class CounterpartyAccountService
{
    public function __construct(private AuditWriter $audit, private OutboxWriter $outbox, private AccountingEventMappingService $mappings) {}

    /** @param array{relationship_type:string, account_type:string, counterparty_id:string, currency:string, holder_type?:?string, holder_id?:?string, branch_id?:?string, effective_from?:?string, gl_account_code?:?string} $d */
    public function open(string $tenantId, array $d, ?string $actorId): object
    {
        $rel = strtoupper($d['relationship_type']);
        $type = strtoupper($d['account_type']);
        if (! isset(SubledgerCatalogue::RELATIONSHIPS[$rel]) || ! in_array($type, SubledgerCatalogue::RELATIONSHIPS[$rel], true)) {
            throw ValidationException::withMessages(['account_type' => "Account type {$type} is not defined for relationship {$rel}."]);
        }
        $currency = strtoupper($d['currency']);
        $cpType = SubledgerCatalogue::COUNTERPARTY_TYPE[$rel];
        $code = 'CPA-'.$rel.'-'.$type.'-'.substr(str_replace('-', '', $d['counterparty_id']), 0, 12);
        if ($existing = DB::table('finance_counterparty_accounts')->where(['tenant_id' => $tenantId, 'account_code' => $code, 'currency' => $currency])->first()) {
            return $existing;
        }
        $glCode = $d['gl_account_code'] ?? SubledgerCatalogue::CONTROL_ACCOUNTS[$rel][$type];
        $gl = $this->controlAccount($tenantId, $glCode, $currency);
        $id = (string) Str::uuid();
        DB::table('finance_counterparty_accounts')->insert([
            'id' => $id, 'tenant_id' => $tenantId, 'holder_type' => $d['holder_type'] ?? null, 'holder_id' => $d['holder_id'] ?? null,
            'counterparty_type' => $cpType, 'counterparty_id' => $d['counterparty_id'], 'relationship_type' => $rel, 'account_type' => $type,
            'account_code' => $code, 'currency' => $currency, 'status' => 'PENDING_APPROVAL', 'effective_from' => $d['effective_from'] ?? now()->toDateString(),
            'gl_control_account_id' => $gl, 'branch_id' => $d['branch_id'] ?? null, 'created_by' => $actorId, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->audit->record('finance.counterparty_account.opened', 'finance_counterparty_account', $id, ['relationship' => $rel, 'account_type' => $type, 'gl_code' => $glCode]);
        $this->outbox->record('finance.counterparty_account.opened', 'finance_counterparty_account', $id, ['account_id' => $id, 'relationship_type' => $rel, 'account_type' => $type]);

        return DB::table('finance_counterparty_accounts')->find($id);
    }

    /** Maker-checker: the opener cannot approve. */
    public function approve(string $tenantId, string $id, string $actorId): object
    {
        return DB::transaction(function () use ($tenantId, $id, $actorId) {
            $a = DB::table('finance_counterparty_accounts')->where('tenant_id', $tenantId)->where('id', $id)->lockForUpdate()->first();
            abort_unless($a, 404);
            if ($a->status !== 'PENDING_APPROVAL') {
                throw ValidationException::withMessages(['status' => 'Only a pending account can be approved.']);
            }
            if ($a->created_by === $actorId) {
                throw ValidationException::withMessages(['approved_by' => 'Maker-checker: the user who opened the account cannot approve it.']);
            }
            DB::table('finance_counterparty_accounts')->where('id', $id)->update(['status' => 'ACTIVE', 'approved_by' => $actorId, 'approved_at' => now(), 'opened_at' => now(), 'updated_at' => now()]);
            $this->audit->record('finance.counterparty_account.approved', 'finance_counterparty_account', $id, []);
            $this->outbox->record('finance.counterparty_account.approved', 'finance_counterparty_account', $id, ['account_id' => $id]);

            return DB::table('finance_counterparty_accounts')->find($id);
        });
    }

    public function changeStatus(string $tenantId, string $id, string $status, string $reason, string $actorId): object
    {
        $allowed = ['ACTIVE' => ['SUSPENDED', 'CLOSED'], 'SUSPENDED' => ['ACTIVE', 'CLOSED']];
        $a = DB::table('finance_counterparty_accounts')->where('tenant_id', $tenantId)->where('id', $id)->first();
        abort_unless($a, 404);
        if (! in_array($status, $allowed[$a->status] ?? [], true)) {
            throw ValidationException::withMessages(['status' => "Cannot move an account from {$a->status} to {$status}."]);
        }
        DB::table('finance_counterparty_accounts')->where('id', $id)->update(['status' => $status, 'closed_at' => $status === 'CLOSED' ? now() : null, 'updated_at' => now()]);
        $this->audit->record('finance.counterparty_account.status_changed', 'finance_counterparty_account', $id, ['from' => $a->status, 'to' => $status], $reason);

        return DB::table('finance_counterparty_accounts')->find($id);
    }

    /** Opens (and leaves pending) every account type of a relationship for one counterparty — the account set a relationship needs. */
    public function openRelationship(string $tenantId, string $relationship, string $counterpartyId, string $currency, ?string $actorId): array
    {
        return array_map(fn (string $type) => $this->open($tenantId, ['relationship_type' => $relationship, 'account_type' => $type, 'counterparty_id' => $counterpartyId, 'currency' => $currency], $actorId),
            SubledgerCatalogue::RELATIONSHIPS[strtoupper($relationship)] ?? throw ValidationException::withMessages(['relationship_type' => 'Unknown relationship.']));
    }

    /** Balance of an account = Σ its sub-ledger entries (never a stored figure). */
    public function balance(string $tenantId, string $id): array
    {
        app(SubledgerProjector::class)->catchUp($tenantId);
        $row = DB::table('finance_ledger_entries')->where('tenant_id', $tenantId)->where('counterparty_account_id', $id)
            ->selectRaw('COALESCE(SUM(debit_amount),0) d, COALESCE(SUM(credit_amount),0) c, COUNT(*) n')->first();

        return ['debit_minor' => (int) $row->d, 'credit_minor' => (int) $row->c, 'balance_minor' => (int) $row->d - (int) $row->c, 'entries' => (int) $row->n];
    }

    private function controlAccount(string $tenantId, string $code, string $currency): string
    {
        $find = fn () => DB::table('ledger_accounts')->where('code', $code)->where('currency', $currency)->where('status', 'ACTIVE')
            ->where(fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))->orderByRaw('tenant_id IS NULL ASC')->value('id');
        if ($id = $find()) {
            return $id;
        }
        if (! isset(DefaultChartOfAccounts::ACCOUNTS[$code])) {
            throw ValidationException::withMessages(['gl_account_code' => "GL control account {$code} ({$currency}) is not in the chart."]);
        }
        $this->mappings->provisionChart($tenantId, $currency);

        return (string) $find();
    }
}
