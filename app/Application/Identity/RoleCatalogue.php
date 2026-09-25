<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Application\Identity\Rbac\DataScope;

/**
 * The ONE role catalogue (REQ-RBAC-003): membership role codes the backend
 * issues, their labels, source, default data scope and default permission
 * set. It merges:
 *   - BP III role profiles (customer, agent, broker producer/supervisor/admin,
 *     underwriter, senior underwriter, claims officer/manager, finance
 *     officer/approver, compliance, provider user, reinsurance officer,
 *     system administrator);
 *   - SCF §7 insurer roles (CARRIER_SUPER_ADMIN … CUSTOMER_SERVICE — only the
 *     two named ends of that range are added; intermediate SCF roles are not
 *     enumerated in the spec and are NOT invented here);
 *   - FRP V provider roles (PROVIDER_ADMIN, FRONT_DESK, DOCTOR, BILLING,
 *     PHARMACY, LAB, FINANCE);
 *   - ESR actors (BRANCH_MANAGER, ADJUSTER, DEVELOPER, REGULATOR).
 *
 * An existing tenant Role row always wins over these defaults (invitation
 * acceptance and seeders use firstOrCreate), so administrators' edits to a
 * role are never overwritten.
 *
 * REQ-RBAC-004: PLATFORM_ONLY roles administer the platform; their grants
 * (including '*') never cover business-data permissions — see
 * Rbac\PermissionEvaluator. Business data needs a business role or an
 * approved, audited break-glass grant.
 */
final class RoleCatalogue
{
    public const LABELS = [
        // Platform operators
        'SYSTEM_ADMIN' => 'System administrator',
        'DEVELOPER' => 'Developer / API integrator',
        'PLATFORM_ADMIN' => 'Platform administrator',
        'COMPLIANCE_ADMIN' => 'Compliance administrator',
        'FINANCE_ADMIN' => 'Finance administrator',
        'FINANCE_MANAGER' => 'Finance manager (approver)',
        'FINANCE_OFFICER' => 'Finance officer',
        'CLAIMS_MANAGER' => 'Claims manager',
        'CLAIMS_OFFICER' => 'Claims officer',
        // Distribution
        'BROKER_ADMIN' => 'Broker administrator',
        'BROKER_SUPERVISOR' => 'Broker supervisor',
        'BROKER_STAFF' => 'Broker staff (producer)',
        'AGENT' => 'Agent',
        'BRANCH_MANAGER' => 'Branch manager',
        // Insurer (SCF §7)
        'CARRIER_SUPER_ADMIN' => 'Insurer super administrator',
        'CARRIER_ADMIN' => 'Insurer administrator',
        'CARRIER_STAFF' => 'Insurer staff',
        'UNDERWRITER' => 'Underwriter',
        'SENIOR_UNDERWRITER' => 'Senior underwriter',
        'REINSURANCE_OFFICER' => 'Reinsurance officer',
        'CUSTOMER_SERVICE' => 'Customer service',
        'ADJUSTER' => 'Loss adjuster / expert',
        // Provider network (FRP V)
        'PROVIDER_ADMIN' => 'Provider administrator',
        'PROVIDER_FRONT_DESK' => 'Provider front desk',
        'PROVIDER_DOCTOR' => 'Provider doctor',
        'PROVIDER_BILLING' => 'Provider billing',
        'PROVIDER_PHARMACY' => 'Provider pharmacy',
        'PROVIDER_LAB' => 'Provider laboratory',
        'PROVIDER_FINANCE' => 'Provider finance',
        // External
        'REGULATOR' => 'Regulator (read only)',
        'CUSTOMER' => 'Customer',
    ];

    /** Source reference per role (traceability). */
    public const SOURCES = [
        'SYSTEM_ADMIN' => 'BP III', 'DEVELOPER' => 'ESR DEV', 'PLATFORM_ADMIN' => 'existing', 'COMPLIANCE_ADMIN' => 'BP III',
        'FINANCE_ADMIN' => 'existing', 'FINANCE_MANAGER' => 'BP III', 'FINANCE_OFFICER' => 'BP III', 'CLAIMS_MANAGER' => 'BP III',
        'CLAIMS_OFFICER' => 'BP III', 'BROKER_ADMIN' => 'BP III', 'BROKER_SUPERVISOR' => 'BP III', 'BROKER_STAFF' => 'BP III',
        'AGENT' => 'BP III', 'BRANCH_MANAGER' => 'ESR/MSG', 'CARRIER_SUPER_ADMIN' => 'SCF §7', 'CARRIER_ADMIN' => 'SCF §7',
        'CARRIER_STAFF' => 'SCF §7', 'UNDERWRITER' => 'BP III', 'SENIOR_UNDERWRITER' => 'BP III', 'REINSURANCE_OFFICER' => 'BP III',
        'CUSTOMER_SERVICE' => 'SCF §7', 'ADJUSTER' => 'ESR CLP', 'PROVIDER_ADMIN' => 'FRP V', 'PROVIDER_FRONT_DESK' => 'FRP V',
        'PROVIDER_DOCTOR' => 'FRP V', 'PROVIDER_BILLING' => 'FRP V', 'PROVIDER_PHARMACY' => 'FRP V', 'PROVIDER_LAB' => 'FRP V',
        'PROVIDER_FINANCE' => 'FRP V', 'REGULATOR' => 'ESR REG', 'CUSTOMER' => 'BP III',
    ];

    /** Role codes an administrator may invite someone into. */
    public const INVITABLE = [
        'PLATFORM_ADMIN', 'COMPLIANCE_ADMIN', 'BROKER_ADMIN', 'BROKER_SUPERVISOR', 'BROKER_STAFF', 'AGENT', 'BRANCH_MANAGER',
        'CARRIER_SUPER_ADMIN', 'CARRIER_ADMIN', 'CARRIER_STAFF', 'UNDERWRITER', 'SENIOR_UNDERWRITER', 'REINSURANCE_OFFICER',
        'CUSTOMER_SERVICE', 'ADJUSTER', 'FINANCE_OFFICER', 'DEVELOPER',
        'PROVIDER_ADMIN', 'PROVIDER_FRONT_DESK', 'PROVIDER_DOCTOR', 'PROVIDER_BILLING', 'PROVIDER_PHARMACY', 'PROVIDER_LAB', 'PROVIDER_FINANCE',
    ];

    /** Roles linked to one insurer via tenant_memberships.carrier_id. */
    public const CARRIER_ROLES = ['CARRIER_SUPER_ADMIN', 'CARRIER_ADMIN', 'CARRIER_STAFF'];

    public const PROVIDER_ROLES = ['PROVIDER_ADMIN', 'PROVIDER_FRONT_DESK', 'PROVIDER_DOCTOR', 'PROVIDER_BILLING', 'PROVIDER_PHARMACY', 'PROVIDER_LAB', 'PROVIDER_FINANCE'];

    /**
     * REQ-RBAC-004: platform administration roles. Their grants never reach
     * business-data permissions (PermissionCatalogue::isBusinessData()).
     */
    public const PLATFORM_ONLY = ['SYSTEM_ADMIN', 'DEVELOPER'];

    /** Explicit, audited break-glass permission (REQ-RBAC-004). */
    public const BREAK_GLASS_PERMISSION = 'platform.break-glass.use';

    public const AGENT_PERMISSIONS = ['agent.clients.read', 'agent.clients.manage', 'agent.commissions.read', 'agent.withdrawals.read', 'agent.withdrawals.request', 'agent.sync.read', 'agent.sync.retry', 'agent.sync.dispatch', 'crm.leads.read', 'crm.leads.manage', 'beneficiaries.read', 'distribution.catalogue.view', 'quotes.manage', 'quotes.send', 'quotes.carrier_requests.view', 'quotes.carrier_requests.create', 'quotes.premium_override.request', 'stickers.view', 'stickers.handover', 'stickers.assign'];

    public const BROKER_STAFF_PERMISSIONS = ['broker.portal.read', 'broker.finance.read', 'broker.bordereaux.manage', 'broker.bordereaux.submit', 'broker.renewals.manage', 'renewals.manage', 'quotes.rate', 'crm.leads.read', 'crm.leads.manage', 'beneficiaries.read', 'beneficiaries.manage', 'distribution.catalogue.view', 'quotes.manage', 'quotes.send', 'quotes.carrier_requests.view', 'quotes.carrier_requests.create', 'quotes.premium_override.request', 'stickers.view', 'stickers.handover', 'stickers.assign'];

    public const BROKER_SUPERVISOR_PERMISSIONS = [...self::BROKER_STAFF_PERMISSIONS, 'crm.leads.read', 'crm.leads.manage', 'crm.leads.assign', 'beneficiaries.read', 'beneficiaries.manage', 'distribution.catalogue.view', 'quotes.carrier_requests.record_on_behalf', 'stickers.allocate', 'stickers.reconcile', 'stickers.assign.any'];

    public const BROKER_ADMIN_PERMISSIONS = [...self::BROKER_STAFF_PERMISSIONS, 'broker.marketplace.manage', 'crm.leads.read', 'crm.leads.manage', 'crm.leads.assign', 'attribution.transfer', 'beneficiaries.read', 'beneficiaries.manage', 'parties.roles.manage', 'parties.relationships.manage', 'distribution.catalogue.view', 'quotes.carrier_requests.record_on_behalf', 'stickers.allocate', 'stickers.reconcile', 'stickers.assign.any', 'policies.issuance_queue.view', 'policies.issuance_queue.manage'];

    public const CARRIER_STAFF_PERMISSIONS = ['carrier.dashboard.read', 'carrier.referrals.read', 'carrier.referrals.decide', 'carrier.issuance.read', 'carrier.claims.read', 'carrier.finance.read', 'documents.carrier.upload', 'carrier.quote_requests.view', 'carrier.quote_requests.respond', 'proposals.issuability.read', 'policies.issuance_queue.view', 'stickers.view', 'providers.view', 'provider_networks.view'];

    public const CARRIER_ADMIN_PERMISSIONS = [...self::CARRIER_STAFF_PERMISSIONS, 'carrier.bordereaux.decide', 'carrier.authority.manage', 'carrier.authority.approve', 'documents.status.request', 'documents.confidential.read', 'documents.financial.read', 'approvals.inbox.view', 'approvals.decide', 'beneficiaries.read', 'beneficiaries.manage', 'parties.roles.manage', 'parties.relationships.manage', 'parties.match.review', 'parties.merge.request', 'carrier.quote_requests.view', 'carrier.quote_requests.respond', 'proposals.issuability.read', 'quotes.premium_override.approve', 'catalogue.review', 'catalogue.test', 'policies.issuance_queue.manage', 'stickers.handover', 'stickers.allocate', 'stickers.allocate.carrier', 'stickers.reconcile', 'stickers.receive', 'providers.manage', 'providers.credential', 'provider_networks.manage'];

    public const CARRIER_SUPER_ADMIN_PERMISSIONS = [...self::CARRIER_ADMIN_PERMISSIONS, 'documents.status.approve', 'documents.medical.read', 'documents.regulatory.read', 'approvals.matrix.view', 'beneficiaries.read', 'beneficiaries.manage', 'parties.roles.manage', 'parties.relationships.manage', 'parties.match.review', 'parties.merge.request', 'parties.merge.approve', 'catalogue.review', 'catalogue.test', 'policies.issuance_queue.resolve', 'provider_tariffs.approve', 'coinsurance.view', 'coinsurance.approve', 'reinsurance.treaties.view', 'reinsurance.cessions.view', 'reinsurance.treaties.approve'];

    public const UNDERWRITER_PERMISSIONS = ['carrier.dashboard.read', 'carrier.referrals.read', 'carrier.referrals.decide', 'underwriting.decide', 'documents.review', 'documents.confidential.read', 'policies.read', 'risk_assets.read', 'carrier.quote_requests.view', 'carrier.quote_requests.respond', 'proposals.issuability.read', 'quotes.premium_override.approve', 'coinsurance.view', 'coinsurance.manage', 'coinsurance.apportion'];

    public const SENIOR_UNDERWRITER_PERMISSIONS = [...self::UNDERWRITER_PERMISSIONS, 'underwriting.assign', 'documents.medical.read', 'documents.status.request', 'carrier.quote_requests.view', 'carrier.quote_requests.respond', 'proposals.issuability.read', 'quotes.premium_override.approve', 'coinsurance.approve'];

    public const ADJUSTER_PERMISSIONS = ['claims.view', 'claims.evidence.manage', 'documents.carrier.upload'];

    public const CUSTOMER_SERVICE_PERMISSIONS = ['customers.read', 'policies.read', 'claims.view', 'support.manage', 'beneficiaries.read', 'crm.leads.read' ];

    public const REINSURANCE_OFFICER_PERMISSIONS = ['policies.read', 'claims.view', 'documents.financial.read', 'reports.insurance.read', 'reinsurance.reinsurers.manage', 'reinsurance.treaties.view', 'reinsurance.treaties.manage', 'reinsurance.treaties.approve', 'reinsurance.cessions.view', 'reinsurance.cessions.calculate', 'coinsurance.view'];

    public const FINANCE_OFFICER_PERMISSIONS = ['ledger.read', 'reconciliation.read', 'reconciliation.import', 'settlement.read', 'refund.request', 'payout.request', 'documents.financial.read', 'policies.issuance_queue.view', 'policies.issuance_queue.manage', 'coinsurance.view', 'coinsurance.apportion'];

    public const BRANCH_MANAGER_PERMISSIONS = ['customers.read', 'policies.read', 'risk_assets.read', 'claims.view', 'commission.read', 'renewals.manage', 'quotes.rate', 'crm.leads.read', 'crm.leads.manage', 'crm.leads.assign', 'beneficiaries.read', 'distribution.catalogue.view', 'policies.issuance_queue.view', 'stickers.view', 'stickers.handover', 'stickers.allocate', 'stickers.reconcile', 'stickers.assign'];

    public const PROVIDER_PERMISSIONS = [
        'PROVIDER_ADMIN' => ['provider.portal.read', 'provider.staff.manage', 'provider.claims.submit', 'provider.claims.read', 'provider.finance.read'],
        'PROVIDER_FRONT_DESK' => ['provider.portal.read', 'provider.eligibility.check'],
        'PROVIDER_DOCTOR' => ['provider.portal.read', 'provider.eligibility.check', 'provider.claims.submit', 'documents.medical.read'],
        'PROVIDER_BILLING' => ['provider.portal.read', 'provider.claims.submit', 'provider.claims.read'],
        'PROVIDER_PHARMACY' => ['provider.portal.read', 'provider.eligibility.check', 'provider.claims.submit'],
        'PROVIDER_LAB' => ['provider.portal.read', 'provider.eligibility.check', 'provider.claims.submit'],
        'PROVIDER_FINANCE' => ['provider.portal.read', 'provider.finance.read', 'provider.claims.read'],
    ];

    public const REGULATOR_PERMISSIONS = ['regulator.reports.read', 'reports.insurance.read'];

    public const DEVELOPER_PERMISSIONS = ['developer.portal.read', 'integrations.manage'];

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::LABELS);
    }

    /** @return array<string, string> */
    public static function invitableOptions(): array
    {
        return array_intersect_key(self::LABELS, array_flip(self::INVITABLE));
    }

    public static function isPlatformOnly(string $roleCode): bool
    {
        return in_array($roleCode, self::PLATFORM_ONLY, true);
    }

    /** Default data scope of a role (REQ-RBAC-002); roles.data_scope overrides it. */
    public static function defaultScope(string $roleCode): DataScope
    {
        return match ($roleCode) {
            'CUSTOMER' => DataScope::OWN,
            'AGENT', 'BROKER_STAFF', 'ADJUSTER', 'PROVIDER_FRONT_DESK', 'PROVIDER_DOCTOR', 'PROVIDER_PHARMACY', 'PROVIDER_LAB' => DataScope::ASSIGNED,
            'BROKER_SUPERVISOR' => DataScope::TEAM,
            'BRANCH_MANAGER' => DataScope::BRANCH,
            'BROKER_ADMIN', 'PROVIDER_ADMIN', 'PROVIDER_BILLING', 'PROVIDER_FINANCE' => DataScope::ORGANIZATION,
            'CARRIER_SUPER_ADMIN', 'CARRIER_ADMIN', 'CARRIER_STAFF', 'UNDERWRITER', 'SENIOR_UNDERWRITER', 'CUSTOMER_SERVICE', 'REINSURANCE_OFFICER' => DataScope::CARRIER_RELATIONSHIP,
            'SYSTEM_ADMIN', 'DEVELOPER' => DataScope::PLATFORM,
            'REGULATOR' => DataScope::REGULATOR_READ,
            // Tenant operations staff (platform-tenant roles) and unknown codes.
            default => DataScope::TENANT,
        };
    }

    /** @return list<string> */
    public static function defaultPermissions(string $roleCode): array
    {
        // Role sets build on each other (spread), so drop repeats.
        return array_values(array_unique(self::rawDefaultPermissions($roleCode)));
    }

    private static function rawDefaultPermissions(string $roleCode): array
    {
        return match ($roleCode) {
            'CUSTOMER' => ['quotes.rate', 'beneficiaries.read'],
            'AGENT' => self::AGENT_PERMISSIONS,
            'BROKER_STAFF' => self::BROKER_STAFF_PERMISSIONS,
            'BROKER_SUPERVISOR' => self::BROKER_SUPERVISOR_PERMISSIONS,
            'BROKER_ADMIN' => self::BROKER_ADMIN_PERMISSIONS,
            'CARRIER_STAFF' => self::CARRIER_STAFF_PERMISSIONS,
            'CARRIER_ADMIN' => self::CARRIER_ADMIN_PERMISSIONS,
            'CARRIER_SUPER_ADMIN' => self::CARRIER_SUPER_ADMIN_PERMISSIONS,
            'UNDERWRITER' => self::UNDERWRITER_PERMISSIONS,
            'SENIOR_UNDERWRITER' => self::SENIOR_UNDERWRITER_PERMISSIONS,
            'ADJUSTER' => self::ADJUSTER_PERMISSIONS,
            'CUSTOMER_SERVICE' => self::CUSTOMER_SERVICE_PERMISSIONS,
            'REINSURANCE_OFFICER' => self::REINSURANCE_OFFICER_PERMISSIONS,
            'FINANCE_OFFICER' => self::FINANCE_OFFICER_PERMISSIONS,
            'BRANCH_MANAGER' => self::BRANCH_MANAGER_PERMISSIONS,
            'REGULATOR' => self::REGULATOR_PERMISSIONS,
            'DEVELOPER' => self::DEVELOPER_PERMISSIONS,
            'PROVIDER_ADMIN', 'PROVIDER_FRONT_DESK', 'PROVIDER_DOCTOR', 'PROVIDER_BILLING', 'PROVIDER_PHARMACY', 'PROVIDER_LAB', 'PROVIDER_FINANCE' => self::PROVIDER_PERMISSIONS[$roleCode],
            // SYSTEM_ADMIN keeps '*', but PermissionEvaluator confines a
            // PLATFORM_ONLY role's grants to platform permissions.
            'SYSTEM_ADMIN', 'PLATFORM_ADMIN', 'COMPLIANCE_ADMIN', 'FINANCE_ADMIN', 'FINANCE_MANAGER', 'CLAIMS_MANAGER', 'CLAIMS_OFFICER' => ['*'],
            default => [],
        };
    }
}
