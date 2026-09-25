<?php

declare(strict_types=1);

namespace App\Application\Reporting\Kpi;

use App\Application\Finance\Obligations\ObligationService;
use App\Application\Finance\ExceptionCentre\FinanceExceptionCentre;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Agent B2 — REQ-RPT-003 registered KPI queries. The ONLY place a KPI's SQL lives: users pick a `query_key`
 * and may only narrow it with the query's whitelisted equality filters. Each query builds the exact record set a
 * KPI counts/sums, so the drill-down (KPI → filtered list → record) returns the rows behind the figure.
 *
 * Shape per query:
 *  - label, unit (count|money), sources (tables), date_basis (column the period filter applies to, or null = snapshot)
 *  - currency_column (money queries), sum_column (money queries)
 *  - filters: whitelist name => qualified column (equality / IN only)
 *  - record: resource (list screen / API resource the drill-down lands on), id column, columns shown
 *  - attention_when_positive: status semantics — a non-zero value is an exception/queue needing action
 */
final class KpiQueryRegistry
{
    public const UNIT_COUNT = 'count';

    public const UNIT_MONEY = 'money';

    public const OPEN_CLAIM_EXCLUDED = ['CLOSED', 'CLOSED_PAID', 'REJECTED', 'WITHDRAWN'];

    /** @return array<string, array<string, mixed>> */
    public static function definitions(): array
    {
        $policyCols = ['policies.id', 'policies.policy_number', 'policies.status', 'policies.party_id', 'policies.carrier_id', 'policies.coverage_starts_at', 'policies.coverage_ends_at', 'policies.premium_minor', 'policies.currency'];
        $claimCols = ['claims.id', 'claims.claim_number', 'claims.status', 'claims.policy_id', 'claims.current_reserve_minor', 'claims.currency', 'claims.created_at'];
        $policyFilters = ['status' => 'policies.status', 'carrier_id' => 'policies.carrier_id', 'currency' => 'policies.currency'];

        return [
            'policies.active' => ['label' => 'Active policies', 'unit' => self::UNIT_COUNT, 'sources' => ['policies'], 'date_basis' => null,
                'filters' => $policyFilters, 'record' => ['resource' => 'policies', 'id' => 'policies.id', 'columns' => $policyCols]],
            'policies.pending_issuance' => ['label' => 'Paid, pending issuance', 'unit' => self::UNIT_COUNT, 'sources' => ['policies'], 'date_basis' => null, 'attention_when_positive' => true,
                'filters' => $policyFilters, 'record' => ['resource' => 'policies', 'id' => 'policies.id', 'columns' => $policyCols]],
            'policies.expiring_30d' => ['label' => 'Policies expiring within 30 days', 'unit' => self::UNIT_COUNT, 'sources' => ['policies'], 'date_basis' => null, 'attention_when_positive' => true,
                'filters' => $policyFilters, 'record' => ['resource' => 'policies', 'id' => 'policies.id', 'columns' => $policyCols]],
            'policies.issued' => ['label' => 'Policies issued', 'unit' => self::UNIT_COUNT, 'sources' => ['policies'], 'date_basis' => 'policies.issued_at',
                'filters' => $policyFilters, 'record' => ['resource' => 'policies', 'id' => 'policies.id', 'columns' => $policyCols]],
            'premium.written' => ['label' => 'Written premium (issued policies)', 'unit' => self::UNIT_MONEY, 'sources' => ['policies'], 'date_basis' => 'policies.issued_at',
                'currency_column' => 'policies.currency', 'sum_column' => 'policies.premium_minor',
                'filters' => $policyFilters, 'record' => ['resource' => 'policies', 'id' => 'policies.id', 'columns' => $policyCols]],
            'claims.open' => ['label' => 'Open claims', 'unit' => self::UNIT_COUNT, 'sources' => ['claims'], 'date_basis' => null,
                'filters' => ['status' => 'claims.status', 'currency' => 'claims.currency'], 'record' => ['resource' => 'claims', 'id' => 'claims.id', 'columns' => $claimCols]],
            'claims.outstanding_reserve' => ['label' => 'Outstanding claim reserve', 'unit' => self::UNIT_MONEY, 'sources' => ['claims'], 'date_basis' => null,
                'currency_column' => 'claims.currency', 'sum_column' => 'claims.current_reserve_minor',
                'filters' => ['status' => 'claims.status', 'currency' => 'claims.currency'], 'record' => ['resource' => 'claims', 'id' => 'claims.id', 'columns' => $claimCols]],
            'claims.not_closed' => ['label' => 'Claims without a closure date', 'unit' => self::UNIT_COUNT, 'sources' => ['claims'], 'date_basis' => null,
                'filters' => ['status' => 'claims.status', 'currency' => 'claims.currency'], 'record' => ['resource' => 'claims', 'id' => 'claims.id', 'columns' => $claimCols]],
            'claims.reported' => ['label' => 'Claims reported', 'unit' => self::UNIT_COUNT, 'sources' => ['claims'], 'date_basis' => 'claims.created_at',
                'filters' => ['status' => 'claims.status', 'currency' => 'claims.currency'], 'record' => ['resource' => 'claims', 'id' => 'claims.id', 'columns' => $claimCols]],
            'claims.failed_payments' => ['label' => 'Failed claim payments', 'unit' => self::UNIT_COUNT, 'sources' => ['claim_payments', 'claims'], 'date_basis' => null, 'attention_when_positive' => true,
                'filters' => [], 'record' => ['resource' => 'claims', 'id' => 'claim_payments.id', 'columns' => ['claim_payments.id', 'claim_payments.claim_id', 'claims.claim_number', 'claim_payments.status']]],
            'quotes.created' => ['label' => 'Quotes created', 'unit' => self::UNIT_COUNT, 'sources' => ['quotes'], 'date_basis' => 'quotes.created_at',
                'filters' => ['status' => 'quotes.status', 'line_code' => 'quotes.line_code', 'channel' => 'quotes.channel'],
                'record' => ['resource' => 'quotes', 'id' => 'quotes.id', 'columns' => ['quotes.id', 'quotes.quote_number', 'quotes.status', 'quotes.line_code', 'quotes.party_id', 'quotes.created_at']]],
            'proposals.open' => ['label' => 'Open proposals', 'unit' => self::UNIT_COUNT, 'sources' => ['proposals'], 'date_basis' => null, 'attention_when_positive' => true,
                'filters' => ['status' => 'proposals.status'],
                'record' => ['resource' => 'proposals', 'id' => 'proposals.id', 'columns' => ['proposals.id', 'proposals.proposal_number', 'proposals.status', 'proposals.party_id', 'proposals.created_at']]],
            'underwriting.queue' => ['label' => 'Underwriting queue', 'unit' => self::UNIT_COUNT, 'sources' => ['underwriting_cases'], 'date_basis' => null, 'attention_when_positive' => true,
                'filters' => ['status' => 'underwriting_cases.status', 'carrier_id' => 'underwriting_cases.carrier_id'],
                'record' => ['resource' => 'underwriting-cases', 'id' => 'underwriting_cases.id', 'columns' => ['underwriting_cases.id', 'underwriting_cases.status', 'underwriting_cases.priority', 'underwriting_cases.proposal_id', 'underwriting_cases.decision_due_at']]],
            'payments.pending' => ['label' => 'Payments pending', 'unit' => self::UNIT_COUNT, 'sources' => ['payment_intents'], 'date_basis' => null, 'attention_when_positive' => true,
                'filters' => ['status' => 'payment_intents.status', 'provider' => 'payment_intents.provider', 'currency' => 'payment_intents.currency'],
                'record' => ['resource' => 'payments', 'id' => 'payment_intents.id', 'columns' => ['payment_intents.id', 'payment_intents.status', 'payment_intents.provider', 'payment_intents.amount_minor', 'payment_intents.currency', 'payment_intents.created_at']]],
            'payments.collected' => ['label' => 'Premium collected', 'unit' => self::UNIT_MONEY, 'sources' => ['payment_intents'], 'date_basis' => 'payment_intents.created_at',
                'currency_column' => 'payment_intents.currency', 'sum_column' => 'payment_intents.amount_minor',
                'filters' => ['provider' => 'payment_intents.provider', 'currency' => 'payment_intents.currency'],
                'record' => ['resource' => 'payments', 'id' => 'payment_intents.id', 'columns' => ['payment_intents.id', 'payment_intents.status', 'payment_intents.provider', 'payment_intents.amount_minor', 'payment_intents.currency', 'payment_intents.created_at']]],
            'receivables.outstanding' => ['label' => 'Receivables outstanding', 'unit' => self::UNIT_MONEY, 'sources' => ['financial_obligations'], 'date_basis' => null,
                'currency_column' => 'financial_obligations.currency', 'sum_column' => 'financial_obligations.outstanding_minor',
                'filters' => ['type' => 'financial_obligations.type', 'currency' => 'financial_obligations.currency'],
                'record' => ['resource' => 'financial-obligations', 'id' => 'financial_obligations.id', 'columns' => ['financial_obligations.id', 'financial_obligations.type', 'financial_obligations.status', 'financial_obligations.outstanding_minor', 'financial_obligations.currency', 'financial_obligations.due_at']]],
            'receivables.overdue' => ['label' => 'Receivables overdue', 'unit' => self::UNIT_MONEY, 'sources' => ['financial_obligations'], 'date_basis' => null, 'attention_when_positive' => true,
                'currency_column' => 'financial_obligations.currency', 'sum_column' => 'financial_obligations.outstanding_minor',
                'filters' => ['type' => 'financial_obligations.type', 'currency' => 'financial_obligations.currency'],
                'record' => ['resource' => 'financial-obligations', 'id' => 'financial_obligations.id', 'columns' => ['financial_obligations.id', 'financial_obligations.type', 'financial_obligations.status', 'financial_obligations.outstanding_minor', 'financial_obligations.currency', 'financial_obligations.due_at']]],
            'commissions.pending' => ['label' => 'Commission pending', 'unit' => self::UNIT_MONEY, 'sources' => ['commission_accruals'], 'date_basis' => null,
                'currency_column' => 'commission_accruals.currency', 'sum_column' => 'commission_accruals.amount_minor',
                'filters' => ['partner_id' => 'commission_accruals.partner_id', 'currency' => 'commission_accruals.currency'],
                'record' => ['resource' => 'commission-accruals', 'id' => 'commission_accruals.id', 'columns' => ['commission_accruals.id', 'commission_accruals.partner_id', 'commission_accruals.policy_id', 'commission_accruals.status', 'commission_accruals.amount_minor', 'commission_accruals.currency']]],
            'renewals.due' => ['label' => 'Renewals due', 'unit' => self::UNIT_COUNT, 'sources' => ['renewal_work_items'], 'date_basis' => 'renewal_work_items.renewal_due_on', 'attention_when_positive' => true,
                'filters' => ['assigned_to' => 'renewal_work_items.assigned_to'],
                'record' => ['resource' => 'renewals', 'id' => 'renewal_work_items.id', 'columns' => ['renewal_work_items.id', 'renewal_work_items.policy_id', 'renewal_work_items.renewal_due_on', 'renewal_work_items.status', 'renewal_work_items.assigned_to']]],
            'kyc.pending_review' => ['label' => 'KYC awaiting review', 'unit' => self::UNIT_COUNT, 'sources' => ['kyc_submissions'], 'date_basis' => null, 'attention_when_positive' => true,
                'filters' => ['status' => 'kyc_submissions.status'],
                'record' => ['resource' => 'kyc-submissions', 'id' => 'kyc_submissions.id', 'columns' => ['kyc_submissions.id', 'kyc_submissions.party_id', 'kyc_submissions.status', 'kyc_submissions.submitted_at']]],
            'refunds.open' => ['label' => 'Refunds awaiting action', 'unit' => self::UNIT_COUNT, 'sources' => ['refunds'], 'date_basis' => null, 'attention_when_positive' => true,
                'filters' => ['status' => 'refunds.status', 'currency' => 'refunds.currency'],
                'record' => ['resource' => 'refunds', 'id' => 'refunds.id', 'columns' => ['refunds.id', 'refunds.refund_number', 'refunds.status', 'refunds.amount_minor', 'refunds.currency', 'refunds.created_at']]],
            'regulatory.runs_open' => ['label' => 'Regulatory report runs not yet submitted', 'unit' => self::UNIT_COUNT, 'sources' => ['regulatory_report_runs'], 'date_basis' => null, 'attention_when_positive' => true,
                'filters' => ['status' => 'regulatory_report_runs.status'],
                'record' => ['resource' => 'regulatory-report-runs', 'id' => 'regulatory_report_runs.id', 'columns' => ['regulatory_report_runs.id', 'regulatory_report_runs.definition_id', 'regulatory_report_runs.period_key', 'regulatory_report_runs.status']]],
        ];
    }

    /** @return array<string, mixed> */
    public static function get(string $key): array
    {
        return self::definitions()[$key] ?? throw new InvalidArgumentException("Unknown registered KPI query {$key}.");
    }

    public static function has(string $key): bool
    {
        return isset(self::definitions()[$key]);
    }

    /** Tenant-scoped base record set for a registered query (no period/filters applied yet). */
    public static function base(string $key, string $tenantId): Builder
    {
        return match ($key) {
            'policies.active' => DB::table('policies')->where('policies.tenant_id', $tenantId)->where('policies.status', 'ACTIVE'),
            'policies.pending_issuance' => DB::table('policies')->where('policies.tenant_id', $tenantId)->where('policies.status', 'PAID_PENDING_ISSUANCE'),
            'policies.expiring_30d' => DB::table('policies')->where('policies.tenant_id', $tenantId)->whereIn('policies.status', ['ACTIVE', 'EXPIRING'])->whereBetween('policies.coverage_ends_at', [now(), now()->addDays(30)]),
            'policies.issued', 'premium.written' => DB::table('policies')->where('policies.tenant_id', $tenantId)->whereNotNull('policies.issued_at'),
            'claims.open', 'claims.outstanding_reserve' => DB::table('claims')->where('claims.tenant_id', $tenantId)->whereNull('claims.closed_at')->whereNotIn('claims.status', self::OPEN_CLAIM_EXCLUDED),
            'claims.not_closed' => DB::table('claims')->where('claims.tenant_id', $tenantId)->whereNull('claims.closed_at'),
            'claims.reported' => DB::table('claims')->where('claims.tenant_id', $tenantId),
            'claims.failed_payments' => DB::table('claim_payments')->join('claims', 'claims.id', '=', 'claim_payments.claim_id')->where('claims.tenant_id', $tenantId)->where('claim_payments.status', 'FAILED'),
            'quotes.created' => DB::table('quotes')->where('quotes.tenant_id', $tenantId),
            'proposals.open' => DB::table('proposals')->where('proposals.tenant_id', $tenantId)->whereNotIn('proposals.status', ['ISSUED', 'CANCELLED', 'REJECTED', 'DECLINED', 'EXPIRED']),
            'underwriting.queue' => DB::table('underwriting_cases')->where('underwriting_cases.tenant_id', $tenantId)->whereIn('underwriting_cases.status', ['QUEUED', 'IN_REVIEW', 'REFERRED']),
            'payments.pending' => DB::table('payment_intents')->where('payment_intents.tenant_id', $tenantId)->whereIn('payment_intents.status', ['PENDING', 'REQUESTED', 'STARTED', 'PROCESSING']),
            'payments.collected' => DB::table('payment_intents')->where('payment_intents.tenant_id', $tenantId)->where('payment_intents.status', 'SUCCEEDED'),
            'receivables.outstanding' => DB::table('financial_obligations')->where('financial_obligations.tenant_id', $tenantId)->where('financial_obligations.kind', 'RECEIVABLE')->whereIn('financial_obligations.status', ObligationService::OPEN_STATUSES),
            'receivables.overdue' => DB::table('financial_obligations')->where('financial_obligations.tenant_id', $tenantId)->where('financial_obligations.kind', 'RECEIVABLE')->whereIn('financial_obligations.status', ObligationService::OPEN_STATUSES)->where('financial_obligations.due_at', '<', now()),
            'commissions.pending' => DB::table('commission_accruals')->where('commission_accruals.tenant_id', $tenantId)->where('commission_accruals.status', 'PENDING'),
            'renewals.due' => DB::table('renewal_work_items')->where('renewal_work_items.tenant_id', $tenantId)->where('renewal_work_items.status', 'DUE'),
            'kyc.pending_review' => DB::table('kyc_submissions')->where('kyc_submissions.tenant_id', $tenantId)->whereIn('kyc_submissions.status', ['SUBMITTED', 'REVIEWING']),
            'refunds.open' => DB::table('refunds')->where('refunds.tenant_id', $tenantId)->whereIn('refunds.status', FinanceExceptionCentre::REFUND_ACTION_STATUSES),
            'regulatory.runs_open' => DB::table('regulatory_report_runs')->where('regulatory_report_runs.tenant_id', $tenantId)->whereNull('regulatory_report_runs.submitted_at'),
            default => throw new InvalidArgumentException("Unknown registered KPI query {$key}."),
        };
    }
}
