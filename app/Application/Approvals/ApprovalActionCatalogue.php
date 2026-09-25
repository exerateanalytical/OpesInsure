<?php

declare(strict_types=1);

namespace App\Application\Approvals;

/**
 * REQ-RBAC-005 — the union of every maker-checker action list (traceability §3.1):
 * WRS WF-081 (+E7), FRP VI, AOM strict layer, SCF governing rules / §58, MPS §97, ICE engines.
 * Each entry is the platform default; approval_matrix_rules rows (seeded from here) refine it by
 * amount / product / insurer / branch / tenant. An action that is not catalogued cannot be requested.
 *
 * checker_permission is NULL where no spec names the approving permission (UNVERIFIED): the
 * domain route middleware still applies; the matrix only adds maker≠checker + SoD until configured.
 */
final class ApprovalActionCatalogue
{
    /** @var array<string, array{workflow: string, category: string, description: string, sources: string, checker_permission?: string|null}> */
    public const ACTIONS = [
        // Configuration governance (SCF Draft→Review→Approved→Published; PRE §74/§75)
        'tariff.approve' => ['workflow' => 'RATING', 'category' => 'CONFIGURATION', 'description' => 'Tariff version approval', 'sources' => 'WF-081; SCF; PRE §75'],
        'product.publish' => ['workflow' => 'PRODUCT', 'category' => 'CONFIGURATION', 'description' => 'Product version approval / publication', 'sources' => 'SCF; PRE §74; MPS §97'],
        'cancellation_rule.approve' => ['workflow' => 'POLICY', 'category' => 'CONFIGURATION', 'description' => 'Cancellation rule approval', 'sources' => 'WF-081'],
        'commission_rule.approve' => ['workflow' => 'COMMISSION', 'category' => 'CONFIGURATION', 'description' => 'Commission rule approval', 'sources' => 'WF-081; FRP VI'],
        'document_template.publish' => ['workflow' => 'DOCUMENT', 'category' => 'CONFIGURATION', 'description' => 'Document template approval / publication', 'sources' => 'WF-081; DCP'],
        'configuration.publish' => ['workflow' => 'CONFIGURATION', 'category' => 'CONFIGURATION', 'description' => 'Sensitive configuration change set', 'sources' => 'SCF §31-33; REQ-SET-005'],
        'reference_date_rule.approve' => ['workflow' => 'TEMPORAL', 'category' => 'CONFIGURATION', 'description' => 'Reference-date rule change', 'sources' => 'ICE E1'],
        'cima.insurer_authorization.approve' => ['workflow' => 'COMPLIANCE', 'category' => 'CONFIGURATION', 'description' => 'Insurer CIMA agrement + authorized branches (from regulator evidence)', 'sources' => 'CIMA dictionary; REQ-CIMA-002; REQ-DUP-017'],
        'regulatory_change.approve' => ['workflow' => 'COMPLIANCE', 'category' => 'CONFIGURATION', 'description' => 'Regulatory change / waiver', 'sources' => 'ICE E4'],
        'approval_matrix.change' => ['workflow' => 'ADMIN', 'category' => 'CONFIGURATION', 'description' => 'Change to the approval matrix itself', 'sources' => 'SCF §58; ESR ADM-029'],
        // Access (AOM strict; WF-081)
        'privileged_access.grant' => ['workflow' => 'SECURITY', 'category' => 'ACCESS', 'description' => 'Privileged (break-glass) access grant', 'sources' => 'WF-081; AOM'],
        'permission.change' => ['workflow' => 'SECURITY', 'category' => 'ACCESS', 'description' => 'Role / permission change', 'sources' => 'AOM strict; REQ-RBAC-005 gap'],
        'authority_grant.approve' => ['workflow' => 'AUTHORITY', 'category' => 'ACCESS', 'description' => 'Authority profile / grant', 'sources' => 'ICE E3'],
        'da_agreement.approve' => ['workflow' => 'DISTRIBUTION', 'category' => 'ACCESS', 'description' => 'Delegated-authority agreement', 'sources' => 'WF-081'],
        // Financial (FRP VI)
        'payment.manual_confirmation' => ['workflow' => 'PAYMENT', 'category' => 'FINANCIAL', 'description' => 'Manual payment confirmation', 'sources' => 'WF-081 E7; AOM'],
        'payment.reallocation' => ['workflow' => 'PAYMENT', 'category' => 'FINANCIAL', 'description' => 'Manual payment reallocation', 'sources' => 'ICE gap 33'],
        'refund.approve' => ['workflow' => 'PAYMENT', 'category' => 'FINANCIAL', 'description' => 'Refund', 'sources' => 'FRP VI; WF-081'],
        'journal.manual.approve' => ['workflow' => 'ACCOUNTING', 'category' => 'FINANCIAL', 'description' => 'Manual journal', 'sources' => 'FRP VI; ESR FIN-016'],
        'write_off.approve' => ['workflow' => 'ACCOUNTING', 'category' => 'FINANCIAL', 'description' => 'Write-off', 'sources' => 'FRP VI'],
        'reconciliation.approve' => ['workflow' => 'RECONCILIATION', 'category' => 'FINANCIAL', 'description' => 'Reconciliation sign-off', 'sources' => 'WF-081'],
        'commission.adjust' => ['workflow' => 'COMMISSION', 'category' => 'FINANCIAL', 'description' => 'Commission adjustment', 'sources' => 'FRP VI; WF-081 E7'],
        'partner_statement.approve' => ['workflow' => 'COMMISSION', 'category' => 'FINANCIAL', 'description' => 'Partner statement', 'sources' => 'WF-081'],
        'payout.approve' => ['workflow' => 'COMMISSION', 'category' => 'FINANCIAL', 'description' => 'Partner payout', 'sources' => 'WF-081'],
        'settlement.carrier.approve' => ['workflow' => 'SETTLEMENT', 'category' => 'FINANCIAL', 'description' => 'Carrier settlement', 'sources' => 'FRP VI; WF-081'],
        'settlement.provider.approve' => ['workflow' => 'SETTLEMENT', 'category' => 'FINANCIAL', 'description' => 'Provider settlement', 'sources' => 'FRP VI'],
        'settlement.reinsurance.approve' => ['workflow' => 'SETTLEMENT', 'category' => 'FINANCIAL', 'description' => 'Reinsurance settlement', 'sources' => 'FRP VI'],
        'premium.override' => ['workflow' => 'QUOTE', 'category' => 'FINANCIAL', 'description' => 'Premium override', 'sources' => 'WF-081 E7; ESR BRK-035'],
        // Claims (FRP VI; WF-081)
        'claim.reserve.change' => ['workflow' => 'CLAIM', 'category' => 'CLAIM', 'description' => 'Reserve change / override', 'sources' => 'FRP VI; WF-081'],
        'claim.decision.approve' => ['workflow' => 'CLAIM', 'category' => 'CLAIM', 'description' => 'Claim decision', 'sources' => 'WF-081'],
        'claim.payment.approve' => ['workflow' => 'CLAIM', 'category' => 'FINANCIAL', 'description' => 'Claim payment', 'sources' => 'FRP VI; WF-081'],
        // Policy / underwriting
        'policy.cancellation' => ['workflow' => 'POLICY', 'category' => 'POLICY', 'description' => 'Policy cancellation', 'sources' => 'WF-081 E7'],
        'policy.endorsement.approve' => ['workflow' => 'POLICY', 'category' => 'POLICY', 'description' => 'Policy servicing transaction', 'sources' => 'BATCH 3 servicing'],
        'underwriting.override' => ['workflow' => 'UNDERWRITING', 'category' => 'UNDERWRITING', 'description' => 'Underwriting override review', 'sources' => 'ESR CMP-014; UND-019'],
        'quote.exceptional' => ['workflow' => 'QUOTE', 'category' => 'UNDERWRITING', 'description' => 'Exceptional quote review', 'sources' => 'ESR BRK-034'],
        'engine.override' => ['workflow' => 'OVERRIDE', 'category' => 'OVERRIDE', 'description' => 'Controlled engine override (engine_overrides)', 'sources' => 'ICE §0.4; AOM; REQ-OVR-001'],
        // Organization setup activation (REQ-SET-002 / REQ-SET-003; SCF activation checklists + maker-checker)
        'insurer_setup.activate' => ['workflow' => 'ORGANIZATION_SETUP', 'category' => 'CONFIGURATION', 'description' => 'Insurer activation after the 23-item setup checklist', 'sources' => 'SCF lifecycles; MPS §109-113; REQ-SET-002', 'checker_permission' => 'carrier_setup.approve'],
        'broker_setup.activate' => ['workflow' => 'ORGANIZATION_SETUP', 'category' => 'CONFIGURATION', 'description' => 'Broker activation after the 19-item setup checklist', 'sources' => 'SCF lifecycles; MPS §109-113; REQ-SET-003', 'checker_permission' => 'partner_setup.approve'],
        // Documents / data
        'document.status_change' => ['workflow' => 'DOCUMENT', 'category' => 'DOCUMENT', 'description' => 'Document revoke / replace / cancel', 'sources' => 'WF-081 E7; DCP'],
        'master_data.import.approve' => ['workflow' => 'MASTER_DATA', 'category' => 'DATA', 'description' => 'Master-data import', 'sources' => 'MDC'],
        'entity.merge' => ['workflow' => 'MASTER_DATA', 'category' => 'DATA', 'description' => 'Duplicate entity merge', 'sources' => 'ICE gap 15'],
        'release.certify' => ['workflow' => 'RELEASE', 'category' => 'CONFIGURATION', 'description' => 'Release certification', 'sources' => 'Wave 11'],
    ];

    /**
     * Default segregation-of-duties pairs (REQ-RBAC-006; ICE gap 30 / REQ-FRD-002: collect vs reconcile vs refund).
     * A user who requested or decided the first action on a subject may not decide the second on the same subject.
     *
     * @var list<array{0: string, 1: string, 2: string}>
     */
    public const SOD_CONFLICTS = [
        ['payment.manual_confirmation', 'reconciliation.approve', 'Collector cannot reconcile the same payment (ICE gap 30)'],
        ['payment.manual_confirmation', 'refund.approve', 'Collector cannot approve a refund of the same payment (ICE gap 30)'],
        ['reconciliation.approve', 'refund.approve', 'Reconciler cannot approve a refund on the same item (ICE gap 30)'],
        ['claim.reserve.change', 'claim.payment.approve', 'Reserve setter cannot approve payment on the same claim (FRP VI)'],
        ['claim.decision.approve', 'claim.payment.approve', 'Decision approver cannot approve payment on the same claim (FRP VI)'],
        ['commission.adjust', 'payout.approve', 'Commission adjuster cannot approve the payout (FRP VI)'],
    ];

    public static function has(string $action): bool
    {
        return isset(self::ACTIONS[$action]);
    }

    public static function get(string $action): array
    {
        return self::ACTIONS[$action] ?? throw new \InvalidArgumentException("Uncatalogued approval action {$action}.");
    }
}
