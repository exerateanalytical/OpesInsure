<?php

declare(strict_types=1);

namespace App\Application\Claims;

/**
 * Repair network and health network reference codes from the Workflow
 * Institutional Data Master v1 (sections "repair_network" and "health").
 *
 * Each workflow code resolves onto the canonical master-data code already
 * stored (partners.adjuster_type, provider.provider_type,
 * provider.medical_specialty, health.benefit_category). Codes that had no
 * equivalent were added to those lists by
 * database/data/master_data/workflow_finance_claims_2026.json.
 * Garage, adjuster and provider masters, the service catalogue and the
 * tariff master are not supplied: their lists exist and stay empty.
 */
final class ClaimNetworkReference
{
    /** repair_network.expert_types => partners.adjuster_type */
    public const EXPERT_TYPES = [
        'MOTOR_ADJUSTER' => 'MOTOR_EXPERT', 'PROPERTY_ADJUSTER' => 'PROPERTY_FIRE_EXPERT', 'MARINE_SURVEYOR' => 'MARINE_SURVEYOR',
        'MEDICAL_EXPERT' => 'MEDICAL_EXPERT', 'ENGINEERING_EXPERT' => 'ENGINEERING_EXPERT', 'VALUER' => 'VALUER',
        'INVESTIGATOR' => 'INVESTIGATOR', 'OTHER' => 'OTHER',
    ];

    /** health.provider_types => provider.provider_type */
    public const PROVIDER_TYPES = [
        'HOSPITAL' => 'HOSPITAL', 'CLINIC' => 'CLINIC', 'HEALTH_CENTRE' => 'HEALTH_CENTRE', 'PHARMACY' => 'PHARMACY',
        'LABORATORY' => 'LABORATORY', 'IMAGING_CENTRE' => 'IMAGING_CENTRE', 'DENTAL' => 'DENTAL', 'OPTICAL' => 'OPTICAL',
        'PHYSIOTHERAPY' => 'PHYSIOTHERAPY', 'AMBULANCE' => 'AMBULANCE_PROVIDER', 'SPECIALIST_PRACTICE' => 'SPECIALIST_PRACTICE',
    ];

    /** health.specialties => provider.medical_specialty */
    public const SPECIALTIES = [
        'GENERAL_MEDICINE' => 'GENERAL_PRACTICE', 'INTERNAL_MEDICINE' => 'INTERNAL_MEDICINE', 'PEDIATRICS' => 'PAEDIATRICS',
        'OBSTETRICS_GYNECOLOGY' => 'GYNAECOLOGY_OBSTETRICS', 'SURGERY' => 'GENERAL_SURGERY', 'CARDIOLOGY' => 'CARDIOLOGY',
        'ORTHOPEDICS' => 'ORTHOPAEDICS', 'DERMATOLOGY' => 'DERMATOLOGY', 'ENT' => 'ENT', 'OPHTHALMOLOGY' => 'OPHTHALMOLOGY',
        'DENTISTRY' => 'DENTISTRY', 'PSYCHIATRY' => 'PSYCHIATRY', 'RADIOLOGY' => 'RADIOLOGY', 'PATHOLOGY' => 'PATHOLOGY',
        'PHARMACY' => 'PHARMACY', 'PHYSIOTHERAPY' => 'PHYSIOTHERAPY',
    ];

    /** health.benefit_categories => health.benefit_category */
    public const BENEFIT_CATEGORIES = [
        'OUTPATIENT' => 'OUTPATIENT', 'INPATIENT' => 'INPATIENT', 'EMERGENCY' => 'EMERGENCY', 'MATERNITY' => 'MATERNITY',
        'PHARMACY' => 'PHARMACY', 'LABORATORY' => 'LABORATORY', 'IMAGING' => 'IMAGING', 'SURGERY' => 'SURGERY',
        'DENTAL' => 'DENTAL', 'OPTICAL' => 'OPTICAL', 'PHYSIOTHERAPY' => 'PHYSIOTHERAPY', 'AMBULANCE' => 'AMBULANCE',
        'PREVENTIVE_CARE' => 'PREVENTIVE', 'CHRONIC_CARE' => 'CHRONIC_DISEASE',
    ];

    /** Masters that are not production data yet: domain.list => status. */
    public const MASTER_STATUS = [
        'partners.garage' => 'PENDING_SOURCE',
        'partners.adjuster' => 'PENDING_SOURCE',
        'partners.hospital' => 'PENDING_SOURCE',
        'provider.medical_service' => 'PENDING_SOURCE',
        'provider.provider_tariff' => 'CONFIG_REQUIRED',
    ];

    /** @param 'expert'|'provider'|'specialty'|'benefit' $kind */
    public static function canonical(string $kind, string $workflowCode): ?string
    {
        $map = match ($kind) {
            'expert' => self::EXPERT_TYPES,
            'provider' => self::PROVIDER_TYPES,
            'specialty' => self::SPECIALTIES,
            'benefit' => self::BENEFIT_CATEGORIES,
        };
        $code = strtoupper($workflowCode);

        return $map[$code] ?? (in_array($code, $map, true) ? $code : null);
    }
}
