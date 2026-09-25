<?php

declare(strict_types=1);

namespace App\Application\Ledger\Posting;

use App\Application\Audit\AuditWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REQ-ACC-001 (SCF §73, FRP I): resolves a business accounting event to the tenant's
 * debit/credit ledger accounts. A tenant mapping (latest ACTIVE version) wins over the
 * platform default (tenant_id NULL). Accounts are looked up by code in the tenant chart,
 * then the platform chart, and provisioned from DefaultChartOfAccounts when missing.
 */
final class AccountingEventMappingService
{
    public function __construct(private readonly AuditWriter $audit) {}

    public function isKnownEvent(string $event): bool
    {
        return DB::table('accounting_events')->where('code', $event)->where('status', 'ACTIVE')->exists();
    }

    /** @return array{debit_account_id:string,credit_account_id:string,mapping_id:string,version:int,tenant_scoped:bool}|null */
    public function resolve(?string $tenantId, string $event, string $currency): ?array
    {
        $m = DB::table('accounting_event_mappings')->where('event_code', $event)->where('status', 'ACTIVE')
            ->where(fn ($q) => $tenantId ? $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id') : $q->whereNull('tenant_id'))
            ->orderByRaw('tenant_id IS NULL ASC')->first();
        if (! $m) {
            return null;
        }

        return [
            'debit_account_id' => $this->account($tenantId, $m->debit_account_code, $currency),
            'credit_account_id' => $this->account($tenantId, $m->credit_account_code, $currency),
            'mapping_id' => $m->id, 'version' => (int) $m->version, 'tenant_scoped' => $m->tenant_id !== null,
        ];
    }

    /** Publishes a new mapping version for a tenant (or the platform when null); the previous version is superseded, never edited. */
    public function publish(?string $tenantId, string $event, string $debitCode, string $creditCode, ?string $actorId, string $reason): object
    {
        if (! $this->isKnownEvent($event)) {
            throw ValidationException::withMessages(['event_code' => "Unknown accounting event {$event}."]);
        }
        if ($debitCode === $creditCode) {
            throw ValidationException::withMessages(['credit_account_code' => 'Debit and credit accounts must differ.']);
        }

        return DB::transaction(function () use ($tenantId, $event, $debitCode, $creditCode, $actorId, $reason) {
            $scope = fn ($q) => $tenantId ? $q->where('tenant_id', $tenantId) : $q->whereNull('tenant_id');
            $current = DB::table('accounting_event_mappings')->where('event_code', $event)->where($scope)->lockForUpdate()->pluck('version')->max();
            DB::table('accounting_event_mappings')->where('event_code', $event)->where($scope)->where('status', 'ACTIVE')
                ->update(['status' => 'SUPERSEDED', 'superseded_at' => now(), 'updated_at' => now()]);
            $id = (string) Str::uuid();
            DB::table('accounting_event_mappings')->insert(['id' => $id, 'tenant_id' => $tenantId, 'event_code' => $event, 'version' => ((int) $current) + 1,
                'debit_account_code' => $debitCode, 'credit_account_code' => $creditCode, 'status' => 'ACTIVE', 'reason' => mb_substr($reason, 0, 255),
                'created_by' => $actorId, 'effective_from' => now(), 'created_at' => now(), 'updated_at' => now()]);
            $this->audit->record('ledger.mapping.published', 'accounting_event_mapping', $id, ['event' => $event, 'debit' => $debitCode, 'credit' => $creditCode], $reason);

            return DB::table('accounting_event_mappings')->find($id);
        });
    }

    /** Provisions the default chart for a tenant (null = platform) in one currency. Idempotent. */
    public function provisionChart(?string $tenantId, string $currency): int
    {
        foreach (array_keys(DefaultChartOfAccounts::ACCOUNTS) as $code) {
            $this->account($tenantId, (string) $code, $currency);
        }

        return count(DefaultChartOfAccounts::ACCOUNTS);
    }

    private function account(?string $tenantId, string $code, string $currency): string
    {
        $find = fn (?string $t) => DB::table('ledger_accounts')->where('code', $code)->where('currency', $currency)->where('status', 'ACTIVE')
            ->where(fn ($q) => $t ? $q->where('tenant_id', $t) : $q->whereNull('tenant_id'))->value('id');
        if ($id = ($tenantId ? $find($tenantId) : null) ?? $find(null)) {
            return $id;
        }
        $def = DefaultChartOfAccounts::ACCOUNTS[$code] ?? null;
        if (! $def) {
            throw ValidationException::withMessages(['account' => "Ledger account {$code} ({$currency}) is not in the chart."]);
        }
        // Provisioned in the tenant's own chart so tenant ledgers stay tenant-scoped.
        DB::table('ledger_accounts')->insertOrIgnore(['id' => (string) Str::uuid(), 'tenant_id' => $tenantId, 'code' => $code, 'name' => $def[0], 'type' => $def[1],
            'currency' => $currency, 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);

        return (string) ($tenantId ? $find($tenantId) : $find(null));
    }
}
