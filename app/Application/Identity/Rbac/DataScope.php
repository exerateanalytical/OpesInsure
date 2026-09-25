<?php

declare(strict_types=1);

namespace App\Application\Identity\Rbac;

/**
 * REQ-RBAC-002: the nine data scopes a role can read business data at.
 *
 * Business scopes are ranked from narrowest to widest (rank()); a user with
 * several roles in a tenant gets the widest. PLATFORM is the scope of
 * platform operators (SYSTEM_ADMIN, DEVELOPER): it covers platform
 * configuration across tenants but grants NO business rows (REQ-RBAC-004).
 * REGULATOR_READ is read-only regulatory visibility; it never grants writes
 * and never grants row-level business data outside regulatory reads.
 */
enum DataScope: string
{
    case OWN = 'OWN';
    case ASSIGNED = 'ASSIGNED';            // portfolio / assigned records
    case TEAM = 'TEAM';
    case BRANCH = 'BRANCH';
    case ORGANIZATION = 'ORGANIZATION';    // the caller's broker/agency/provider organisation (Partner)
    case CARRIER_RELATIONSHIP = 'CARRIER_RELATIONSHIP';
    case TENANT = 'TENANT';
    case PLATFORM = 'PLATFORM';
    case REGULATOR_READ = 'REGULATOR_READ';

    public function rank(): int
    {
        return match ($this) {
            self::PLATFORM, self::REGULATOR_READ => 0, // no business rows
            self::OWN => 1,
            self::ASSIGNED => 2,
            self::TEAM => 3,
            self::BRANCH => 4,
            self::ORGANIZATION => 5,
            self::CARRIER_RELATIONSHIP => 6,
            self::TENANT => 7,
        };
    }

    public function grantsBusinessRows(): bool
    {
        return $this->rank() > 0;
    }
}
