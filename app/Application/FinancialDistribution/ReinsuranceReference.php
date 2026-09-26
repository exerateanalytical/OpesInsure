<?php

declare(strict_types=1);

namespace App\Application\FinancialDistribution;

/**
 * Reinsurance / co-insurance reference codes from the Workflow Institutional
 * Data Master v1 ("reinsurance_coinsurance"), resolved onto the master-data
 * reinsurance domain and onto the codes stored in bordereaux.type.
 *
 * Both bordereau endpoints (broker POST broker/bordereaux and finance POST
 * bordereaux) accept exactly PRODUCIBLE_BORDEREAU_TYPES, after bordereauType()
 * normalisation, through bordereauTypeRule(). RISK bordereaux are
 * reference-only until the reinsurance engine (batches 9-14) produces them.
 */
final class ReinsuranceReference
{
    public const REINSURANCE_TYPES = ['TREATY', 'FACULTATIVE', 'FACULTATIVE_OBLIGATORY'];

    public const TREATY_TYPES = ['QUOTA_SHARE', 'SURPLUS', 'EXCESS_OF_LOSS', 'STOP_LOSS', 'OTHER'];

    /**
     * Gap Closure Pack v1 (07) treaty_types => engine family stored in reinsurance_treaties.treaty_type (the form itself is kept in
     * treaty_form). Aggregate XL and facultative-obligatory have no automatic calculation in CessionCalculator: family OTHER.
     */
    public const TREATY_FORMS = [
        'QUOTA_SHARE' => 'QUOTA_SHARE', 'SURPLUS' => 'SURPLUS', 'PER_RISK_EXCESS_OF_LOSS' => 'EXCESS_OF_LOSS',
        'CATASTROPHE_EXCESS_OF_LOSS' => 'EXCESS_OF_LOSS', 'AGGREGATE_EXCESS_OF_LOSS' => 'OTHER', 'STOP_LOSS' => 'STOP_LOSS',
        'FACULTATIVE_OBLIGATORY' => 'OTHER', 'OTHER' => 'OTHER',
    ];

    /** Reinsurer approved-security states; only APPROVED_SECURITY may participate in an activated treaty or signed facultative placement. */
    public const SECURITY_STATUSES = ['PENDING_VERIFICATION', 'TENANT_APPROVED', 'CIMA_APPROVED', 'REJECTED', 'SUSPENDED'];

    public const APPROVED_SECURITY = ['TENANT_APPROVED', 'CIMA_APPROVED'];

    public const BORDEREAU_FREQUENCIES = ['MONTHLY', 'QUARTERLY', 'SEMI_ANNUAL', 'ANNUAL'];

    /** Gap Closure arrangement_statuses => engine coinsurance_arrangements.status (BOUND is not a separate engine state). */
    public const COINSURANCE_STATUSES = ['PROPOSED' => 'DRAFT', 'BOUND' => 'DRAFT', 'ACTIVE' => 'ACTIVE', 'EXPIRED' => 'ACTIVE', 'CANCELLED' => 'TERMINATED'];

    /** Workflow / Gap Closure role => master-data reinsurance.coinsurance_role code. */
    public const COINSURANCE_ROLES = ['LEAD_INSURER' => 'LEAD', 'PARTICIPATING_INSURER' => 'FOLLOWER', 'PARTICIPANT' => 'FOLLOWER'];

    /** Engine treaty family for a treaty_type or Gap Closure treaty form; null when unknown. */
    public static function treatyFamily(string $code): ?string
    {
        $code = strtoupper($code);

        return self::TREATY_FORMS[$code] ?? (in_array($code, self::TREATY_TYPES, true) ? $code : null);
    }

    /** Gap Closure arrangement status for an engine arrangement (EXPIRED is computed from effective_until). */
    public static function coinsuranceWorkflowStatus(string $status, ?string $effectiveUntil, ?string $today = null): string
    {
        return match ($status) {
            'DRAFT' => 'PROPOSED',
            'TERMINATED' => 'CANCELLED',
            'ACTIVE' => ($effectiveUntil !== null && substr($effectiveUntil, 0, 10) < ($today ?? now()->toDateString())) ? 'EXPIRED' : 'ACTIVE',
            default => $status,
        };
    }

    /** Workflow bordereau type => stored bordereaux.type / reinsurance.bordereau_type code. */
    public const BORDEREAU_TYPES = ['RISK' => 'RISK', 'PREMIUM' => 'PREMIUM', 'CLAIMS' => 'CLAIM'];

    /** Bordereau types the bordereau endpoints accept (single source for both). */
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

    /** Validation rule shared by every bordereau-creating endpoint. */
    public static function bordereauTypeRule(): \Illuminate\Validation\Rules\In
    {
        return \Illuminate\Validation\Rule::in(self::PRODUCIBLE_BORDEREAU_TYPES);
    }
}
