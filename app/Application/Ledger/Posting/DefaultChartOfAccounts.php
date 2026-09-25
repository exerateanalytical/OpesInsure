<?php

declare(strict_types=1);

namespace App\Application\Ledger\Posting;

/**
 * REQ-ACC-001 default chart for an OHADA/CIMA-style insurer or broker.
 * Codes follow SYSCOHADA class numbering (4 = third parties, 5 = treasury,
 * 6 = expenses, 7 = income). A tenant re-maps any event to its own codes via
 * AccountingEventMappingService::publish (a new mapping version).
 */
final class DefaultChartOfAccounts
{
    /** code => [name, type] */
    public const ACCOUNTS = [
        '411000' => ['Premium receivable (policyholders)', 'ASSET'],
        '401100' => ['Insurer / carrier premium payable', 'LIABILITY'],
        '401200' => ['Reinsurance premium payable', 'LIABILITY'],
        '401300' => ['Co-insurer share payable', 'LIABILITY'],
        '419000' => ['Refunds payable to customers', 'LIABILITY'],
        '421000' => ['Commission payable (intermediaries)', 'LIABILITY'],
        '443000' => ['Taxes payable (VAT / insurance tax)', 'LIABILITY'],
        '471000' => ['Suspense account', 'ASSET'],
        '481000' => ['Claims payable (approved settlements)', 'LIABILITY'],
        '481500' => ['Outstanding claims reserve', 'LIABILITY'],
        '521000' => ['Bank', 'ASSET'],
        '571000' => ['Cash on hand', 'ASSET'],
        '585000' => ['Mobile-money clearing', 'ASSET'],
        '601000' => ['Claims expense', 'EXPENSE'],
        '602000' => ['Reinsurance ceded premium', 'EXPENSE'],
        '622000' => ['Commission expense', 'EXPENSE'],
        '702000' => ['Premium income', 'INCOME'],
        '706000' => ['Commission income', 'INCOME'],
    ];

    /** Stable business accounting event catalogue: event => [category, default debit, default credit, description]. */
    public const EVENTS = [
        'finance.obligation.created' => ['PREMIUM', '411000', '702000', 'Premium due recognised as receivable.'],
        'payment.succeeded' => ['TREASURY', '585000', '411000', 'Mobile-money payment confirmed; receivable collected into clearing.'],
        'payment.reconciled' => ['TREASURY', '585000', '471000', 'Statement-matched payment moved out of suspense into clearing.'],
        'cashier.collection.recorded' => ['TREASURY', '571000', '411000', 'Cash or cheque collected at a cashier desk.'],
        'payment.clearing.settled' => ['TREASURY', '521000', '585000', 'Provider clearing batch credited to the bank.'],
        'refund.approved' => ['REFUND', '702000', '419000', 'Refund approved: premium income reversed into refunds payable.'],
        'refund.paid' => ['REFUND', '419000', '521000', 'Refund paid out to the customer.'],
        'commission.accrued' => ['COMMISSION', '622000', '421000', 'Commission accrued to an intermediary.'],
        'commission.earned' => ['COMMISSION', '421000', '706000', 'Broker commission earned (vested).'],
        'commission.clawed_back' => ['COMMISSION', '421000', '622000', 'Commission clawed back.'],
        'commission.paid' => ['COMMISSION', '421000', '521000', 'Commission paid to an intermediary.'],
        'settlement.approved' => ['SETTLEMENT', '702000', '401100', 'Carrier settlement approved: net premium due to insurer.'],
        'settlement.settled' => ['SETTLEMENT', '401100', '521000', 'Carrier settlement paid.'],
        'reinsurance.policy.ceded' => ['REINSURANCE', '602000', '401200', 'Premium ceded to reinsurer.'],
        'reinsurance.facultative.bound' => ['REINSURANCE', '602000', '401200', 'Facultative premium ceded on binding.'],
        'coinsurance.apportioned' => ['COINSURANCE', '702000', '401300', 'Co-insurer share of premium apportioned.'],
        'claim.reserve.changed' => ['CLAIMS', '601000', '481500', 'Claim reserve increased (a decrease posts the mapped accounts reversed).'],
        'claim.settlement.approved' => ['CLAIMS', '601000', '481000', 'Claim settlement approved (claims payable).'],
        'claim.settlement.paid' => ['CLAIMS', '481000', '521000', 'Claim settlement paid.'],
        'claim.recovery.received' => ['CLAIMS', '521000', '601000', 'Claim recovery (subrogation / salvage / contribution) received.'],
        'premium.tax.assessed' => ['TAX', '411000', '443000', 'Tax on premium assessed.'],
    ];
}
