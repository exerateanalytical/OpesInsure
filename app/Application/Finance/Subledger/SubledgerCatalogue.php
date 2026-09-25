<?php

declare(strict_types=1);

namespace App\Application\Finance\Subledger;

/**
 * Agent F1 — vocabulary of the owner's canonical spec "Finance Counterparty Accounts & Commission Sub-Ledger v1"
 * (docs/spec/canonical/OpesInsure_Finance_Counterparty_Accounts_Commission_Subledger_v1.json), mapped onto the codes the
 * existing Batch 9-10 money chain already stores. Nothing here is a rate, a tax or a settlement rule (CONFIG_REQUIRED).
 */
final class SubledgerCatalogue
{
    public const SPEC_FILE = 'docs/spec/canonical/OpesInsure_Finance_Counterparty_Accounts_Commission_Subledger_v1.json';

    public const SUBLEDGERS = ['CUSTOMER_RECEIVABLE', 'CUSTOMER_DEPOSIT', 'INSURER_PREMIUM_PAYABLE', 'INSURER_COMMISSION_RECEIVABLE', 'BROKER_PREMIUM_RECEIVABLE',
        'BROKER_COMMISSION_PAYABLE', 'AGENT_COMMISSION_PAYABLE', 'PROVIDER_PAYABLE', 'REINSURER_RECEIVABLE', 'REINSURER_PAYABLE', 'COINSURER_RECEIVABLE', 'COINSURER_PAYABLE',
        'REFUND_CLEARING', 'TAX_CLEARING', 'LEVY_CLEARING', 'PAYMENT_GATEWAY_CLEARING', 'BANK_CLEARING', 'MOBILE_MONEY_CLEARING', 'SETTLEMENT_CLEARING', 'SUSPENSE'];

    /** relationship => account types (spec counterparty_accounts). */
    public const RELATIONSHIPS = [
        'BROKER_TO_INSURER' => ['PREMIUM_PAYABLE', 'COMMISSION_RECEIVABLE', 'REFUND_CLEARING', 'TAX_CLEARING', 'LEVY_CLEARING', 'SETTLEMENT', 'SUSPENSE'],
        'INSURER_TO_BROKER' => ['PREMIUM_RECEIVABLE', 'COMMISSION_PAYABLE', 'REFUND_CLEARING', 'SETTLEMENT', 'SUSPENSE'],
        'BROKER_TO_AGENT' => ['COMMISSION_PAYABLE', 'ADVANCE_RECOVERABLE', 'CLAWBACK_RECEIVABLE', 'SETTLEMENT', 'SUSPENSE'],
        'INSURER_TO_PROVIDER' => ['CLAIMS_PAYABLE', 'ADVANCE', 'WITHHOLDING', 'SETTLEMENT', 'SUSPENSE'],
        'INSURER_TO_REINSURER' => ['PREMIUM_PAYABLE', 'COMMISSION_RECEIVABLE', 'CLAIM_RECOVERY_RECEIVABLE', 'SETTLEMENT', 'SUSPENSE'],
        'CUSTOMER' => ['PREMIUM_RECEIVABLE', 'CUSTOMER_CREDIT', 'REFUND_PAYABLE', 'SUSPENSE'],
    ];

    /** relationship => the counterparty type the account is held against. */
    public const COUNTERPARTY_TYPE = [
        'BROKER_TO_INSURER' => 'INSURER', 'INSURER_TO_BROKER' => 'BROKER', 'BROKER_TO_AGENT' => 'AGENT',
        'INSURER_TO_PROVIDER' => 'PROVIDER', 'INSURER_TO_REINSURER' => 'REINSURER', 'CUSTOMER' => 'CUSTOMER',
    ];

    /** counterparty type => ledger-entry dimension that identifies it. */
    public const COUNTERPARTY_DIMENSION = [
        'INSURER' => 'insurer_id', 'BROKER' => 'broker_id', 'AGENT' => 'agent_id', 'PROVIDER' => 'provider_id', 'REINSURER' => 'reinsurer_id', 'CUSTOMER' => 'customer_id',
    ];

    /**
     * Default GL control account (DefaultChartOfAccounts code) per relationship + account type. A tenant re-maps codes in its own
     * chart; the default is a chart choice, not an accounting rule for any product.
     */
    public const CONTROL_ACCOUNTS = [
        'BROKER_TO_INSURER' => ['PREMIUM_PAYABLE' => '401100', 'COMMISSION_RECEIVABLE' => '412000', 'REFUND_CLEARING' => '419000', 'TAX_CLEARING' => '443000',
            'LEVY_CLEARING' => '445000', 'SETTLEMENT' => '581000', 'SUSPENSE' => '471000'],
        'INSURER_TO_BROKER' => ['PREMIUM_RECEIVABLE' => '411100', 'COMMISSION_PAYABLE' => '421000', 'REFUND_CLEARING' => '419000', 'SETTLEMENT' => '581000', 'SUSPENSE' => '471000'],
        'BROKER_TO_AGENT' => ['COMMISSION_PAYABLE' => '421000', 'ADVANCE_RECOVERABLE' => '409100', 'CLAWBACK_RECEIVABLE' => '409200', 'SETTLEMENT' => '581000', 'SUSPENSE' => '471000'],
        'INSURER_TO_PROVIDER' => ['CLAIMS_PAYABLE' => '481000', 'ADVANCE' => '409300', 'WITHHOLDING' => '447000', 'SETTLEMENT' => '581000', 'SUSPENSE' => '471000'],
        'INSURER_TO_REINSURER' => ['PREMIUM_PAYABLE' => '401200', 'COMMISSION_RECEIVABLE' => '412100', 'CLAIM_RECOVERY_RECEIVABLE' => '416000', 'SETTLEMENT' => '581000', 'SUSPENSE' => '471000'],
        'CUSTOMER' => ['PREMIUM_RECEIVABLE' => '411000', 'CUSTOMER_CREDIT' => '471100', 'REFUND_PAYABLE' => '419000', 'SUSPENSE' => '471000'],
    ];

    /** GL control account code (DefaultChartOfAccounts) => spec sub-ledger; 421000 splits into broker/agent by the entry's dimensions. */
    public const SUBLEDGER_BY_CODE = [
        '411000' => 'CUSTOMER_RECEIVABLE', '471100' => 'CUSTOMER_DEPOSIT', '401100' => 'INSURER_PREMIUM_PAYABLE', '412000' => 'INSURER_COMMISSION_RECEIVABLE',
        '411100' => 'BROKER_PREMIUM_RECEIVABLE', '481000' => 'PROVIDER_PAYABLE', '416000' => 'REINSURER_RECEIVABLE', '412100' => 'REINSURER_RECEIVABLE',
        '401200' => 'REINSURER_PAYABLE', '401300' => 'COINSURER_PAYABLE', '419000' => 'REFUND_CLEARING', '443000' => 'TAX_CLEARING', '445000' => 'LEVY_CLEARING',
        '447000' => 'TAX_CLEARING', '585000' => 'MOBILE_MONEY_CLEARING', '521000' => 'BANK_CLEARING', '571000' => 'BANK_CLEARING', '581000' => 'SETTLEMENT_CLEARING',
        '471000' => 'SUSPENSE', '409100' => 'AGENT_COMMISSION_PAYABLE', '409200' => 'AGENT_COMMISSION_PAYABLE',
    ];

    public const DIMENSIONS = ['insurer_id', 'broker_id', 'agent_id', 'customer_id', 'provider_id', 'reinsurer_id', 'coinsurer_id', 'branch_id', 'department_id',
        'sales_channel_id', 'region_id', 'product_id', 'product_version_id', 'insurance_class_id', 'cima_branch_id', 'quote_id', 'proposal_id', 'policy_id',
        'policy_version_id', 'endorsement_id', 'claim_id', 'invoice_id', 'payment_id', 'receipt_id', 'commission_id', 'settlement_id', 'reconciliation_id',
        'document_id', 'batch_id'];

    public const FINANCIALS = ['gross_premium', 'net_premium', 'tax_amount', 'levy_amount', 'fee_amount', 'commission_basis_amount', 'commission_rate', 'commission_gross',
        'commission_tax', 'commission_net', 'withholding_amount', 'refund_amount', 'reversal_amount', 'settlement_amount'];

    public const JOURNAL_TYPES = ['PREMIUM_BILLING', 'PREMIUM_COLLECTION', 'PREMIUM_REMITTANCE', 'COMMISSION_ACCRUAL', 'COMMISSION_PAYABLE', 'COMMISSION_PAYMENT',
        'COMMISSION_CLAWBACK', 'REFUND', 'PAYMENT_REVERSAL', 'DEBIT_NOTE', 'CREDIT_NOTE', 'CLAIM_RESERVE', 'CLAIM_PAYMENT', 'PROVIDER_SETTLEMENT', 'REINSURANCE_PREMIUM',
        'REINSURANCE_RECOVERY', 'COINSURANCE_ALLOCATION', 'TAX_LEVY', 'BANK_RECONCILIATION', 'MOBILE_MONEY_RECONCILIATION', 'MANUAL_ADJUSTMENT', 'PERIOD_CLOSE'];

    /** Existing accounting event (journals.reference_type) => spec journal type. */
    public const EVENT_JOURNAL_TYPE = [
        'finance.obligation.created' => 'PREMIUM_BILLING', 'premium.tax.assessed' => 'TAX_LEVY',
        'payment.succeeded' => 'PREMIUM_COLLECTION', 'cashier.collection.recorded' => 'PREMIUM_COLLECTION',
        'payment.reconciled' => 'MOBILE_MONEY_RECONCILIATION', 'payment.clearing.settled' => 'BANK_RECONCILIATION',
        'premium.remittance.recorded' => 'PREMIUM_REMITTANCE', 'premium.remittance.allocated' => 'PREMIUM_REMITTANCE',
        'settlement.approved' => 'PREMIUM_REMITTANCE', 'settlement.settled' => 'PREMIUM_REMITTANCE',
        'commission.accrued' => 'COMMISSION_ACCRUAL', 'commission.earned' => 'COMMISSION_PAYABLE', 'commission.paid' => 'COMMISSION_PAYMENT',
        'commission.clawed_back' => 'COMMISSION_CLAWBACK', 'refund.approved' => 'REFUND', 'refund.paid' => 'REFUND',
        'claim.reserve.changed' => 'CLAIM_RESERVE', 'claim.settlement.approved' => 'CLAIM_PAYMENT', 'claim.settlement.paid' => 'CLAIM_PAYMENT',
        'claim.recovery.received' => 'CLAIM_PAYMENT', 'health.provider_claim.approved' => 'PROVIDER_SETTLEMENT', 'health.provider_claim.paid' => 'PROVIDER_SETTLEMENT',
        'reinsurance.policy.ceded' => 'REINSURANCE_PREMIUM', 'reinsurance.facultative.bound' => 'REINSURANCE_PREMIUM',
        'reinsurance.recovery.billed' => 'REINSURANCE_RECOVERY', 'reinsurance.recovery.settled' => 'REINSURANCE_RECOVERY',
        'coinsurance.apportioned' => 'COINSURANCE_ALLOCATION', 'JOURNAL_REVERSAL' => 'PAYMENT_REVERSAL',
    ];

    /** journals.status => spec journal status (VALIDATED = maker done, awaiting the checker). */
    public const JOURNAL_STATUS = ['DRAFT' => 'DRAFT', 'VALIDATED' => 'PENDING_APPROVAL', 'APPROVED' => 'PENDING_APPROVAL', 'POSTED' => 'POSTED', 'REVERSED' => 'REVERSED', 'REJECTED' => 'REJECTED'];

    public const COMMISSION_STATES = ['EXPECTED', 'ACCRUED', 'PAYABLE', 'PARTIALLY_PAID', 'PAID', 'HELD', 'DISPUTED', 'REVERSED', 'CLAWED_BACK', 'CANCELLED'];

    public const PREMIUM_STATUSES = ['BILLED', 'PARTIALLY_COLLECTED', 'COLLECTED', 'PARTIALLY_REMITTED', 'REMITTED', 'REFUNDED', 'PARTIALLY_REFUNDED', 'REVERSED', 'WRITTEN_OFF'];

    public const REMITTANCE_STATUSES = ['NOT_DUE', 'DUE', 'PARTIALLY_REMITTED', 'REMITTED', 'OVERDUE', 'DISPUTED', 'RECONCILIATION_HOLD'];

    /** settlement_batches.status (both lifecycles) => spec settlement status. */
    public const SETTLEMENT_STATUS = ['DRAFT' => 'DRAFT', 'CALCULATED' => 'CALCULATED', 'REVIEW' => 'PENDING_APPROVAL', 'APPROVED' => 'APPROVED',
        'PROCESSING' => 'PAYMENT_INITIATED', 'SUBMITTED' => 'PAYMENT_INITIATED', 'SETTLED' => 'PAID', 'PAID' => 'PAID', 'RECONCILED' => 'RECONCILED',
        'FAILED' => 'APPROVED', 'REVERSED' => 'REVERSED', 'CANCELLED' => 'CANCELLED'];

    /** accounting_periods.status => spec period status. */
    public const PERIOD_STATUS = ['OPEN' => 'OPEN', 'CLOSING' => 'PENDING_REVIEW', 'CLOSED' => 'CLOSED', 'REOPENED' => 'REOPENED'];

    /** reconciliation_items.outcome => spec reconciliation status. */
    public const RECONCILIATION_STATUS = ['MATCHED' => 'MATCHED', 'PARTIAL' => 'PARTIALLY_MATCHED', 'UNMATCHED' => 'UNMATCHED_EXTERNAL', 'DUPLICATE' => 'DUPLICATE',
        'OVERPAYMENT' => 'AMOUNT_MISMATCH', 'UNDERPAYMENT' => 'AMOUNT_MISMATCH', 'OVER' => 'AMOUNT_MISMATCH', 'UNDER' => 'AMOUNT_MISMATCH', 'RESOLVED' => 'RESOLVED'];

    /** Spec aging buckets (from_days..to_days, null = open ended). */
    public const AGING_BUCKETS = ['CURRENT' => [0, 0], 'DAYS_1_30' => [1, 30], 'DAYS_31_60' => [31, 60], 'DAYS_61_90' => [61, 90], 'DAYS_91_120' => [91, 120], 'DAYS_120_PLUS' => [121, null]];

    public const AGING_BASIS = ['DUE_DATE', 'TRANSACTION_DATE', 'COMMISSION_PAYABLE_DATE', 'REMITTANCE_DUE_DATE', 'INVOICE_DATE'];

    public const AGING_SCOPES = ['RECEIVABLE', 'PAYABLE', 'COMMISSION', 'REMITTANCE'];

    /**
     * Spec DOC-186..200 → the document catalogue's FINANCE.* subtypes. The spec's DOC-nnn numbers collide with the 220-document
     * register (DOC-193 is CUSTOMS_BOND there), so documents are typed by canonical code; the spec number is kept in provenance.
     */
    public const STATEMENT_DOCUMENTS = [
        'DOC-193' => ['spec' => 'CUSTOMER_ACCOUNT_STATEMENT', 'type' => 'CUSTOMER_STATEMENT'],
        'DOC-194' => ['spec' => 'BROKER_STATEMENT', 'type' => 'BROKER_STATEMENT'],
        'DOC-195' => ['spec' => 'AGENT_COMMISSION_STATEMENT', 'type' => 'AGENT_COMMISSION_STATEMENT'],
        'DOC-196' => ['spec' => 'BROKER_COMMISSION_STATEMENT', 'type' => 'BROKER_COMMISSION_STATEMENT'],
        'DOC-197' => ['spec' => 'CARRIER_SETTLEMENT_STATEMENT', 'type' => 'CARRIER_SETTLEMENT_STATEMENT'],
        'DOC-198' => ['spec' => 'PROVIDER_SETTLEMENT_STATEMENT', 'type' => 'PROVIDER_SETTLEMENT_STATEMENT'],
        'DOC-199' => ['spec' => 'TAX_LEVY_BREAKDOWN', 'type' => 'TAX_FEE_STATEMENT'],
        'DOC-200' => ['spec' => 'RECONCILIATION_STATEMENT', 'type' => 'RECONCILIATION_STATEMENT'],
    ];

    /** Spec PascalCase event => catalogued dotted event (DomainEventCatalogue aliases). */
    public const EVENTS = [
        'PremiumBilled' => 'finance.obligation.created', 'PremiumCollected' => 'finance.obligation.settled', 'PremiumPartiallyCollected' => 'payment.allocated',
        'PremiumRemitted' => 'premium.remittance.allocated', 'PremiumRemittanceOverdue' => 'premium.remittance.overdue',
        'CommissionExpected' => 'commission.calculated', 'CommissionAccrued' => 'commission.accrued', 'CommissionBecamePayable' => 'commission.payable',
        'CommissionPartiallyPaid' => 'partner.payout.paid', 'CommissionPaid' => 'commission.paid', 'CommissionClawedBack' => 'commission.clawed_back',
        'RefundApproved' => 'refund.approved', 'RefundPaid' => 'refund.paid', 'PaymentReversed' => 'payment.allocation.reversed',
        'DebitNoteIssued' => 'finance.debit_note.issued', 'CreditNoteIssued' => 'finance.credit_note.issued',
        'SettlementCalculated' => 'settlement.calculated', 'SettlementApproved' => 'settlement.approved', 'SettlementPaid' => 'settlement.settled',
        'ReconciliationMatched' => 'reconciliation.manual_match.approved', 'ReconciliationExceptionRaised' => 'reconciliation.exception.raised',
        'JournalPosted' => 'ledger.journal.posted', 'JournalReversed' => 'ledger.journal.reversed', 'PeriodClosed' => 'ledger.period.closed', 'PeriodReopened' => 'ledger.period.reopened',
    ];

    public const PERMISSIONS = ['finance.accounts.view', 'finance.accounts.export', 'finance.ledger.view', 'finance.journals.create', 'finance.journals.approve',
        'finance.journals.post', 'finance.journals.reverse', 'finance.commissions.view', 'finance.commissions.configure', 'finance.commissions.approve',
        'finance.commissions.pay', 'finance.settlements.view', 'finance.settlements.create', 'finance.settlements.approve', 'finance.reconciliation.view',
        'finance.reconciliation.match', 'finance.reconciliation.override', 'finance.period.close', 'finance.period.reopen', 'finance.adjustments.create',
        'finance.adjustments.approve'];

    public static function journalType(string $referenceType, ?string $storedType = null): ?string
    {
        if ($storedType === 'MANUAL') {
            return 'MANUAL_ADJUSTMENT';
        }

        return self::EVENT_JOURNAL_TYPE[$referenceType] ?? null;
    }

    public static function spec(): array
    {
        return json_decode((string) file_get_contents(base_path(self::SPEC_FILE)), true, 512, JSON_THROW_ON_ERROR);
    }
}
