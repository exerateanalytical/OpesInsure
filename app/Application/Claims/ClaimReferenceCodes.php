<?php

declare(strict_types=1);

namespace App\Application\Claims;

/**
 * Claims reference codes from the Workflow Institutional Data Master v1
 * (database/data/workflow_institutional_data_master_2026.json, section "claims").
 *
 * Reference data and validation only: the full claims engine (reserve
 * ledgers by type, recoveries, decision reasons) is built in batches 9-14.
 * Stored codes keep their meaning: workflow codes are resolved onto the
 * codes already stored by the claim lifecycle (claim_decisions.decision,
 * claims.status, claim_recoveries.type) and onto the master-data lists
 * (claims.claim_category, claims.decision_type).
 */
final class ClaimReferenceCodes
{
    public const SOURCE = 'OWNER_WORKFLOW_DATA_MASTER_V1';

    /** Workflow claim type => existing master-data claims.claim_category code. */
    public const CLAIM_TYPES = [
        'MOTOR_DAMAGE' => 'MOTOR', 'MOTOR_TP_BODILY_INJURY' => 'MOTOR', 'MOTOR_TP_PROPERTY_DAMAGE' => 'MOTOR',
        'PROPERTY_DAMAGE' => 'PROPERTY', 'FIRE' => 'PROPERTY', 'THEFT' => 'PROPERTY', 'BUSINESS_INTERRUPTION' => 'PROPERTY',
        'HEALTH_REIMBURSEMENT' => 'HEALTH', 'HEALTH_PROVIDER' => 'HEALTH',
        'LIFE_DEATH' => 'LIFE', 'LIFE_DISABILITY' => 'LIFE',
        'TRAVEL_MEDICAL' => 'TRAVEL', 'TRAVEL_CANCELLATION' => 'TRAVEL',
        'MARINE_CARGO' => 'MARINE', 'ENGINEERING' => 'ENGINEERING', 'LIABILITY' => 'LIABILITY',
        'AGRICULTURE' => 'AGRICULTURE', 'LIVESTOCK' => 'AGRICULTURE', 'OTHER' => 'OTHER',
    ];

    public const RESERVE_TYPES = ['INDEMNITY', 'LEGAL', 'ADJUSTER', 'MEDICAL', 'REPAIR', 'SALVAGE', 'RECOVERY', 'OTHER'];

    /** Superset of the codes already stored in claim_recoveries.type (SUBROGATION, SALVAGE, OTHER). */
    public const RECOVERY_TYPES = ['SUBROGATION', 'SALVAGE', 'REINSURANCE', 'COINSURANCE', 'THIRD_PARTY', 'OTHER', 'CONTRIBUTION', 'DEDUCTIBLE_RECOVERY'];

    /**
     * Workflow decision code => existing stored codes.
     *  api:           claim_decisions.decision (APPROVE | PARTIAL | DECLINE), null when not a decision proposal;
     *  claim_status:  claims.status reached (ClaimStateMachine);
     *  decision_type: master-data claims.decision_type code.
     */
    public const DECISION_CODES = [
        'APPROVED' => ['api' => 'APPROVE', 'claim_status' => 'APPROVED', 'decision_type' => 'ACCEPTED_IN_FULL'],
        'PARTIALLY_APPROVED' => ['api' => 'PARTIAL', 'claim_status' => 'PARTIALLY_APPROVED', 'decision_type' => 'ACCEPTED_PARTIALLY'],
        'REJECTED' => ['api' => 'DECLINE', 'claim_status' => 'DECLINED', 'decision_type' => 'DECLINED'],
        'CLOSED_NO_PAYMENT' => ['api' => null, 'claim_status' => 'CLOSED', 'decision_type' => 'CLOSED_WITHOUT_PAYMENT'],
    ];

    /** Codes accepted by the decision endpoint (stored codes first, then workflow aliases). */
    public const DECISION_INPUTS = ['APPROVE', 'PARTIAL', 'DECLINE', 'APPROVED', 'PARTIALLY_APPROVED', 'REJECTED'];

    /**
     * Taxonomies the owner has not supplied yet. The master-data lists exist
     * (claims.damage_type, …) but stay empty; claims.cause_of_loss already
     * holds platform-normalised codes, which are NOT an authoritative source.
     */
    public const TAXONOMY_STATUS = [
        'cause_of_loss' => 'PENDING_SOURCE',
        'damage_type' => 'PENDING_SOURCE',
        'injury_type' => 'PENDING_SOURCE',
        'evidence_type' => 'PENDING_SOURCE',
        'fraud_indicator' => 'PENDING_SOURCE',
        'decision_reason' => 'PENDING_SOURCE',
        'rejection_reason' => 'PENDING_SOURCE',
    ];

    public static function categoryFor(string $claimType): ?string
    {
        return self::CLAIM_TYPES[strtoupper($claimType)] ?? null;
    }

    public static function isReserveType(string $code): bool
    {
        return in_array($code, self::RESERVE_TYPES, true);
    }

    public static function isRecoveryType(string $code): bool
    {
        return in_array($code, self::RECOVERY_TYPES, true);
    }

    /** Workflow or stored decision code => stored claim_decisions.decision, or null. */
    public static function decisionForApi(string $code): ?string
    {
        $code = strtoupper($code);
        if (in_array($code, ['APPROVE', 'PARTIAL', 'DECLINE'], true)) {
            return $code;
        }

        return self::DECISION_CODES[$code]['api'] ?? null;
    }

    /** Stored claim status => workflow decision code (for reporting). */
    public static function decisionCodeForStatus(string $status): ?string
    {
        foreach (self::DECISION_CODES as $code => $map) {
            if ($map['claim_status'] === $status) {
                return $code;
            }
        }

        return null;
    }
}
