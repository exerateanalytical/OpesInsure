<?php

declare(strict_types=1);

namespace App\Application\FinancialDistribution;

/**
 * Reinsurance / co-insurance reference codes from the Workflow Institutional
 * Data Master v1 ("reinsurance_coinsurance"), resolved onto the master-data
 * reinsurance domain and onto the codes stored in bordereaux.type.
 *
 * Bordereau engines stay as they are: the broker endpoint stores PREMIUM,
 * CLAIM, ENDORSEMENT, CANCELLATION; the carrier/partner endpoint stores
 * PREMIUM, COMMISSION. RISK bordereaux are reference-only until the
 * reinsurance engine (batches 9-14) produces them.
 */
final class ReinsuranceReference
{
    public const REINSURANCE_TYPES = ['TREATY', 'FACULTATIVE', 'FACULTATIVE_OBLIGATORY'];

    public const TREATY_TYPES = ['QUOTA_SHARE', 'SURPLUS', 'EXCESS_OF_LOSS', 'STOP_LOSS', 'OTHER'];

    /** Workflow role => master-data reinsurance.coinsurance_role code. */
    public const COINSURANCE_ROLES = ['LEAD_INSURER' => 'LEAD', 'PARTICIPATING_INSURER' => 'FOLLOWER'];

    /** Workflow bordereau type => stored bordereaux.type / reinsurance.bordereau_type code. */
    public const BORDEREAU_TYPES = ['RISK' => 'RISK', 'PREMIUM' => 'PREMIUM', 'CLAIMS' => 'CLAIM'];

    /** Bordereau types an existing endpoint can produce today. */
    public const PRODUCIBLE_BORDEREAU_TYPES = ['PREMIUM', 'CLAIM', 'ENDORSEMENT', 'CANCELLATION', 'COMMISSION'];

    public const MASTER_STATUS = [
        'reinsurance.reinsurer' => 'PENDING_SOURCE',
        'reinsurance.reinsurance_broker' => 'PENDING_SOURCE',
    ];

    public static function coinsuranceRole(string $code): ?string
    {
        $code = strtoupper($code);

        return self::COINSURANCE_ROLES[$code] ?? (in_array($code, self::COINSURANCE_ROLES, true) ? $code : null);
    }

    /** Workflow or stored bordereau type => stored code (CLAIMS => CLAIM); unknown codes pass through unchanged for the endpoint's own validation. */
    public static function bordereauType(string $code): string
    {
        $code = strtoupper($code);

        return self::BORDEREAU_TYPES[$code] ?? $code;
    }
}
