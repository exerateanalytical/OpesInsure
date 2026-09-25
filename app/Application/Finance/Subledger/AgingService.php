<?php

declare(strict_types=1);

namespace App\Application\Finance\Subledger;

use App\Application\Audit\AuditWriter;
use App\Application\Commissions\Machine\CommissionMachine;
use App\Application\Finance\Obligations\ObligationService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Agent F1 — spec aging: buckets CURRENT, 1-30, 31-60, 61-90, 91-120, 120+ (ObligationService::aging keeps its legacy 90_PLUS view for
 * FR-02/FR-03) with a configurable date basis per tenant and scope (finance_aging_settings). The basis only changes which date a row
 * is aged from; amounts are always the open balance of the source rows (obligations / commission accruals), never a stored balance.
 */
final class AgingService
{
    /** Default basis per scope when a tenant has configured none. */
    public const DEFAULT_BASIS = ['RECEIVABLE' => 'DUE_DATE', 'PAYABLE' => 'DUE_DATE', 'COMMISSION' => 'COMMISSION_PAYABLE_DATE', 'REMITTANCE' => 'REMITTANCE_DUE_DATE'];

    public function __construct(private AuditWriter $audit) {}

    public function basis(string $tenantId, string $scope): string
    {
        return DB::table('finance_aging_settings')->where('tenant_id', $tenantId)->where('scope', $scope)->value('basis') ?? self::DEFAULT_BASIS[$scope];
    }

    public function configure(string $tenantId, string $scope, string $basis, string $actorId): void
    {
        $scope = strtoupper($scope);
        $basis = strtoupper($basis);
        if (! in_array($scope, SubledgerCatalogue::AGING_SCOPES, true) || ! in_array($basis, SubledgerCatalogue::AGING_BASIS, true)) {
            throw ValidationException::withMessages(['basis' => 'Unknown aging scope or basis.']);
        }
        $old = $this->basis($tenantId, $scope);
        DB::table('finance_aging_settings')->updateOrInsert(['tenant_id' => $tenantId, 'scope' => $scope],
            ['id' => DB::table('finance_aging_settings')->where('tenant_id', $tenantId)->where('scope', $scope)->value('id') ?? (string) Str::uuid(), 'basis' => $basis, 'updated_by' => $actorId, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit->record('finance.aging.basis_changed', 'finance_aging_setting', null, ['scope' => $scope, 'from' => $old, 'to' => $basis]);
    }

    public static function bucket(?string $date, CarbonImmutable $asOf): string
    {
        if (! $date) {
            return 'CURRENT';
        }
        $days = (int) CarbonImmutable::parse($date)->startOfDay()->diffInDays($asOf->startOfDay(), false);
        foreach (SubledgerCatalogue::AGING_BUCKETS as $code => [$from, $to]) {
            if ($days <= 0 && $code === 'CURRENT') {
                return 'CURRENT';
            }
            if ($days >= $from && ($to === null || $days <= $to) && $code !== 'CURRENT') {
                return $code;
            }
        }

        return 'CURRENT';
    }

    /**
     * @param  array<string, mixed>  $filters  SubledgerFilters subset (currency, insurer_id, broker_id, agent_id, customer_id, policy_id)
     * @return array{scope:string, basis:string, as_of:string, buckets:array<string, array<string, int>>, rows:list<array<string, mixed>>}
     */
    public function age(string $tenantId, string $scope, array $filters = [], ?CarbonImmutable $asOf = null, ?string $basis = null): array
    {
        $scope = strtoupper($scope);
        if (! in_array($scope, SubledgerCatalogue::AGING_SCOPES, true)) {
            throw ValidationException::withMessages(['scope' => 'Unknown aging scope.']);
        }
        $asOf ??= CarbonImmutable::now();
        $basis ??= $this->basis($tenantId, $scope);
        $rows = $scope === 'COMMISSION' ? $this->commissionRows($tenantId, $filters, $basis) : $this->obligationRows($tenantId, $scope, $filters, $basis);
        $buckets = [];
        $out = [];
        foreach ($rows as $r) {
            $b = self::bucket($r['aging_date'], $asOf);
            if (! empty($filters['aging_bucket']) && $filters['aging_bucket'] !== $b) {
                continue;
            }
            $buckets[$r['currency']] ??= array_fill_keys(array_keys(SubledgerCatalogue::AGING_BUCKETS), 0);
            $buckets[$r['currency']][$b] += $r['outstanding_minor'];
            $out[] = $r + ['aging_bucket' => $b];
        }

        return ['scope' => $scope, 'basis' => $basis, 'as_of' => $asOf->toDateString(), 'buckets' => $buckets, 'rows' => $out];
    }

    private function obligationRows(string $tenantId, string $scope, array $f, string $basis): array
    {
        $q = DB::table('financial_obligations')->where('tenant_id', $tenantId)->whereIn('status', ObligationService::OPEN_STATUSES)
            ->when($scope === 'RECEIVABLE', fn ($q) => $q->where('kind', 'RECEIVABLE'))
            ->when($scope === 'PAYABLE', fn ($q) => $q->where('kind', 'PAYABLE'))
            ->when($scope === 'REMITTANCE', fn ($q) => $q->where('kind', 'PAYABLE')->where('creditor_type', 'carrier'))
            ->when($f['currency'] ?? null, fn ($q, $c) => $q->where('currency', strtoupper($c)))
            ->when($f['policy_id'] ?? null, fn ($q, $v) => $q->where('policy_id', $v))
            ->when($f['customer_id'] ?? null, fn ($q, $v) => $q->where('debtor_type', 'party')->where('debtor_id', $v))
            ->when($f['insurer_id'] ?? null, fn ($q, $v) => $q->where('creditor_type', 'carrier')->where('creditor_id', $v));

        return $q->orderBy('due_at')->get()->map(fn ($o) => [
            'source_type' => 'financial_obligation', 'source_id' => $o->id, 'policy_id' => $o->policy_id, 'type' => $o->type, 'currency' => $o->currency,
            'amount_minor' => (int) $o->amount_minor, 'outstanding_minor' => (int) $o->outstanding_minor,
            'aging_date' => (string) match ($basis) { 'TRANSACTION_DATE', 'INVOICE_DATE' => $o->created_at, default => $o->due_at },
            'status' => $scope === 'REMITTANCE' ? PremiumRemittanceService::remittanceStatus($o) : $o->status,
        ])->all();
    }

    private function commissionRows(string $tenantId, array $f, string $basis): array
    {
        return DB::table('commission_accruals')->where('tenant_id', $tenantId)->whereNotIn('status', ['PAID', 'REVERSED', 'CLAWED_BACK'])
            ->when($f['currency'] ?? null, fn ($q, $c) => $q->where('currency', strtoupper($c)))
            ->when($f['policy_id'] ?? null, fn ($q, $v) => $q->where('policy_id', $v))
            ->when($f['agent_id'] ?? $f['broker_id'] ?? null, fn ($q, $v) => $q->where('partner_id', $v))
            ->orderBy('created_at')->get()->map(fn ($a) => [
                'source_type' => 'commission_accrual', 'source_id' => $a->id, 'policy_id' => $a->policy_id, 'type' => 'COMMISSION', 'currency' => $a->currency,
                'amount_minor' => (int) $a->amount_minor, 'outstanding_minor' => max(0, (int) $a->amount_minor - (int) $a->paid_minor - (int) $a->clawed_back_minor),
                'aging_date' => (string) match ($basis) { 'COMMISSION_PAYABLE_DATE' => $a->payable_at ?? $a->vests_at ?? $a->created_at, 'DUE_DATE' => $a->vests_at ?? $a->created_at, default => $a->created_at },
                'status' => CommissionMachine::specState($a),
            ])->all();
    }
}
