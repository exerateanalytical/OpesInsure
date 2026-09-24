<?php

declare(strict_types=1);

namespace App\Application\Identity;

/**
 * The definitive list of membership role codes the backend issues, their
 * human labels, and the default permission set a Role row of that code is
 * created with when none exists yet in the tenant (invitation acceptance,
 * seeders). An existing tenant Role row always wins over these defaults, so
 * an administrator's edits to a role are never overwritten.
 */
final class RoleCatalogue
{
    public const LABELS = [
        'SYSTEM_ADMIN' => 'System administrator',
        'PLATFORM_ADMIN' => 'Platform administrator',
        'COMPLIANCE_ADMIN' => 'Compliance administrator',
        'FINANCE_ADMIN' => 'Finance administrator',
        'FINANCE_MANAGER' => 'Finance manager',
        'CLAIMS_MANAGER' => 'Claims manager',
        'CLAIMS_OFFICER' => 'Claims officer',
        'BROKER_ADMIN' => 'Broker administrator',
        'BROKER_STAFF' => 'Broker staff',
        'AGENT' => 'Agent',
        'CARRIER_ADMIN' => 'Insurer administrator',
        'CARRIER_STAFF' => 'Insurer staff',
        'CUSTOMER' => 'Customer',
    ];

    /** Role codes an administrator may invite someone into. */
    public const INVITABLE = ['PLATFORM_ADMIN', 'COMPLIANCE_ADMIN', 'BROKER_ADMIN', 'BROKER_STAFF', 'AGENT', 'CARRIER_ADMIN', 'CARRIER_STAFF'];

    public const CARRIER_ROLES = ['CARRIER_ADMIN', 'CARRIER_STAFF'];

    public const AGENT_PERMISSIONS = ['agent.clients.read', 'agent.clients.manage', 'agent.commissions.read', 'agent.withdrawals.read', 'agent.withdrawals.request', 'agent.sync.read', 'agent.sync.retry', 'agent.sync.dispatch'];

    public const BROKER_STAFF_PERMISSIONS = ['broker.portal.read', 'broker.finance.read', 'broker.bordereaux.manage', 'broker.bordereaux.submit', 'broker.renewals.manage', 'renewals.manage', 'quotes.rate'];

    public const BROKER_ADMIN_PERMISSIONS = [...self::BROKER_STAFF_PERMISSIONS, 'broker.marketplace.manage'];

    public const CARRIER_STAFF_PERMISSIONS = ['carrier.dashboard.read', 'carrier.referrals.read', 'carrier.referrals.decide', 'carrier.issuance.read', 'carrier.claims.read', 'carrier.finance.read'];

    public const CARRIER_ADMIN_PERMISSIONS = [...self::CARRIER_STAFF_PERMISSIONS, 'carrier.bordereaux.decide', 'carrier.authority.manage', 'carrier.authority.approve'];

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

    /** @return list<string> */
    public static function defaultPermissions(string $roleCode): array
    {
        return match ($roleCode) {
            'CUSTOMER' => ['quotes.rate'],
            'AGENT' => self::AGENT_PERMISSIONS,
            'BROKER_STAFF' => self::BROKER_STAFF_PERMISSIONS,
            'BROKER_ADMIN' => self::BROKER_ADMIN_PERMISSIONS,
            'CARRIER_STAFF' => self::CARRIER_STAFF_PERMISSIONS,
            'CARRIER_ADMIN' => self::CARRIER_ADMIN_PERMISSIONS,
            // Platform staff: same wildcard the seeded demo staff roles use.
            'SYSTEM_ADMIN', 'PLATFORM_ADMIN', 'COMPLIANCE_ADMIN', 'FINANCE_ADMIN', 'FINANCE_MANAGER', 'CLAIMS_MANAGER', 'CLAIMS_OFFICER' => ['*'],
            default => [],
        };
    }
}
