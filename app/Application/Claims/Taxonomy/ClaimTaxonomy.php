<?php

declare(strict_types=1);

namespace App\Application\Claims\Taxonomy;

use App\Application\MasterData\WorkflowDataStatuses;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Agent GP3 — Gap Closure Pack v1 file 03 claims taxonomies and motor classifications
 * (database/data/gap_closure_2026/03_motor_vehicle_claims_repair_experts.json).
 *
 * Values live in the canonical stores (no parallel tables):
 *  - master-data lists claims.cause_of_loss (line-scoped <CATEGORY>__<CAUSE>, generic pack codes are aliases),
 *    claims.motor_damage_area, claims.damage_severity, claims.injury_severity, claims.evidence_type,
 *    claims.decision_reason, claims.rejection_reason (database/data/master_data/workflow_gap_closure_motor_claims_2026.json);
 *  - claim_decision_reason_codes (what the decision endpoint validates), synonyms stored as aliases;
 *  - vehicle_reference_values groups commercial_vehicle_class / motorcycle_class.
 */
final class ClaimTaxonomy
{
    public const SOURCE = 'GAP_CLOSURE_PACK_V1';

    public const PACK_FILE = 'database/data/gap_closure_2026/03_motor_vehicle_claims_repair_experts.json';

    /** Pack list => master-data claims list. */
    public const LISTS = [
        'cause_of_loss' => 'cause_of_loss', 'motor_damage_areas' => 'motor_damage_area', 'damage_severity' => 'damage_severity',
        'injury_severity' => 'injury_severity', 'evidence_types' => 'evidence_type', 'claim_decision_reasons' => 'decision_reason',
        'claim_rejection_reasons' => 'rejection_reason',
    ];

    /** Generic pack cause => line-scoped claims.cause_of_loss codes (parent = claims.claim_category). */
    public const CAUSE_OF_LOSS = [
        'COLLISION' => ['MOTOR__COLLISION', 'MARINE__COLLISION'], 'OVERTURN' => ['MOTOR__OVERTURN'],
        'IMPACT_FIXED_OBJECT' => ['MOTOR__IMPACT_FIXED_OBJECT'], 'FIRE' => ['MOTOR__FIRE', 'PROPERTY__FIRE'],
        'EXPLOSION' => ['MOTOR__EXPLOSION', 'PROPERTY__EXPLOSION'], 'THEFT' => ['MOTOR__THEFT', 'PROPERTY__BURGLARY', 'MARINE__THEFT_PILFERAGE'],
        'ATTEMPTED_THEFT' => ['MOTOR__ATTEMPTED_THEFT'], 'VANDALISM' => ['MOTOR__VANDALISM'], 'FLOOD' => ['MOTOR__FLOOD', 'PROPERTY__FLOOD'],
        'STORM' => ['MOTOR__STORM', 'PROPERTY__STORM'], 'LIGHTNING' => ['PROPERTY__LIGHTNING'], 'FALLING_OBJECT' => ['MOTOR__FALLING_OBJECT'],
        'GLASS_BREAKAGE' => ['MOTOR__GLASS_BREAKAGE'], 'THIRD_PARTY_DAMAGE' => ['MOTOR__THIRD_PARTY_PROPERTY_DAMAGE', 'LIABILITY__PROPERTY_DAMAGE'],
        'BODILY_INJURY' => ['MOTOR__THIRD_PARTY_INJURY', 'LIABILITY__BODILY_INJURY', 'PERSONAL_ACCIDENT__ACCIDENTAL_INJURY'],
        'ACCIDENTAL_DAMAGE' => ['MOTOR__ACCIDENTAL_DAMAGE'], 'WATER_DAMAGE' => ['PROPERTY__WATER_DAMAGE', 'MARINE__WATER_INGRESS'],
        'ELECTRICAL_DAMAGE' => ['MOTOR__ELECTRICAL_DAMAGE', 'PROPERTY__ELECTRICAL_DAMAGE'],
        'MECHANICAL_BREAKDOWN' => ['MOTOR__MECHANICAL_BREAKDOWN', 'ENGINEERING__MACHINERY_BREAKDOWN'],
        'CARGO_DAMAGE' => ['MOTOR__CARGO_DAMAGE', 'MARINE__HANDLING_DAMAGE'], 'LIABILITY_EVENT' => ['LIABILITY__LIABILITY_EVENT'],
        'MEDICAL_EVENT' => ['HEALTH__ILLNESS', 'HEALTH__ACCIDENT', 'TRAVEL__MEDICAL_EMERGENCY_ABROAD'],
        'DEATH' => ['LIFE__NATURAL_DEATH', 'LIFE__ACCIDENTAL_DEATH', 'PERSONAL_ACCIDENT__ACCIDENTAL_DEATH'],
        'DISABILITY' => ['LIFE__DISABILITY', 'PERSONAL_ACCIDENT__PERMANENT_DISABILITY'], 'OTHER' => ['OTHER'],
    ];

    /**
     * Pack decision + rejection reasons => [applies_to, label, group, alias_of existing code | null].
     * Order matters: a code must exist before another is aliased to it.
     */
    public const DECISION_REASONS = [
        'COVERED_EVENT_CONFIRMED' => ['APPROVE', 'Covered event confirmed', 'DECISION', null],
        'PARTIAL_COVERAGE' => ['PARTIAL', 'Only part of the loss is covered', 'DECISION', null],
        'DEDUCTIBLE_APPLIED' => ['PARTIAL', 'Policy deductible/excess applied', 'DECISION', null],
        'LIMIT_APPLIED' => ['PARTIAL', 'Limit applied', 'DECISION', 'SUB_LIMIT_APPLIED'],
        'EXCLUSION_APPLIES' => ['DECLINE', 'A policy exclusion applies', 'DECISION', null],
        'POLICY_NOT_EFFECTIVE' => ['DECLINE', 'Policy not effective', 'DECISION', 'POLICY_NOT_IN_FORCE'],
        'PREMIUM_CONDITION_NOT_MET' => ['DECLINE', 'Premium payment condition not met', 'DECISION', null],
        'INSUFFICIENT_EVIDENCE' => ['DECLINE', 'Evidence insufficient to establish the loss', 'DECISION', null],
        'DUPLICATE_CLAIM' => ['DECLINE', 'Duplicate of a claim already filed', 'DECISION', null],
        'FRAUD_REVIEW' => ['ANY', 'Referred for fraud review', 'DECISION', null],
        'LATE_NOTIFICATION_REVIEW' => ['ANY', 'Late notification under review', 'DECISION', null],
        'LIABILITY_NOT_ESTABLISHED' => ['DECLINE', 'Liability of the insured not established', 'DECISION', null],
        'AMOUNT_NOT_SUPPORTED' => ['ANY', 'Claimed amount not supported by evidence', 'DECISION', null],
        'OTHER' => ['ANY', 'Other (rationale required)', 'DECISION', null],
        'NO_ACTIVE_COVER' => ['DECLINE', 'No active cover', 'REJECTION', 'POLICY_NOT_IN_FORCE'],
        'RISK_NOT_INSURED' => ['DECLINE', 'Risk not insured', 'REJECTION', 'NOT_COVERED'],
        'EXCLUDED_EVENT' => ['DECLINE', 'Excluded event', 'REJECTION', 'EXCLUSION_APPLIES'],
        'POLICY_CANCELLED' => ['DECLINE', 'Policy cancelled before the loss', 'REJECTION', null],
        'MISREPRESENTATION_REVIEWED' => ['DECLINE', 'Misrepresentation reviewed', 'REJECTION', 'MISREPRESENTATION'],
        'FRAUD_CONFIRMED_BY_AUTHORIZED_PROCESS' => ['DECLINE', 'Fraud confirmed by the authorised process', 'REJECTION', 'FRAUD_CONFIRMED'],
        'INSUFFICIENT_DOCUMENTATION' => ['DECLINE', 'Insufficient documentation', 'REJECTION', 'INSUFFICIENT_EVIDENCE'],
        'DUPLICATE' => ['DECLINE', 'Duplicate', 'REJECTION', 'DUPLICATE_CLAIM'],
        'OUTSIDE_TERRITORY' => ['DECLINE', 'Loss occurred outside the territorial limits', 'REJECTION', null],
    ];

    /** Pack commercial / motorcycle classes => [en, fr, canonical vehicle.vehicle_class or null when no single equivalent]. */
    public const VEHICLE_CLASS_GROUPS = [
        'commercial_vehicle_class' => [
            'LIGHT_COMMERCIAL_VAN' => ['Light commercial van', 'Fourgonnette utilitaire', 'LIGHT_COMMERCIAL'],
            'PICKUP' => ['Pickup', 'Pick-up', 'PICKUP_COMMERCIAL'],
            'MINIBUS' => ['Minibus', 'Minibus', 'MINIBUS_COMMERCIAL'],
            'BUS' => ['Bus', 'Autobus', 'BUS'],
            'COACH' => ['Coach', 'Autocar', 'BUS'],
            'RIGID_TRUCK' => ['Rigid truck', 'Camion porteur', null],
            'TRACTOR_HEAD' => ['Tractor head', 'Tracteur routier', 'TRACTOR_UNIT'],
            'TRAILER' => ['Trailer', 'Remorque', 'TRAILER'],
            'SEMI_TRAILER' => ['Semi-trailer', 'Semi-remorque', 'TRAILER'],
            'TANKER' => ['Tanker', 'Camion-citerne', null],
            'TIPPER' => ['Tipper', 'Camion-benne', null],
            'REFRIGERATED_TRUCK' => ['Refrigerated truck', 'Camion frigorifique', null],
            'CONSTRUCTION_TRUCK' => ['Construction truck', 'Camion de chantier', 'SPECIAL_VEHICLE'],
            'SPECIAL_PURPOSE' => ['Special purpose vehicle', 'Véhicule spécial', 'SPECIAL_VEHICLE'],
            'AMBULANCE' => ['Ambulance', 'Ambulance', 'EMERGENCY_VEHICLE'],
            'FIRE_ENGINE' => ['Fire engine', 'Véhicule de pompiers', 'EMERGENCY_VEHICLE'],
        ],
        'motorcycle_class' => [
            'SCOOTER' => ['Scooter', 'Scooter', null], 'UNDERBONE' => ['Underbone (moped)', 'Cyclomoteur', null],
            'STANDARD' => ['Standard motorcycle', 'Moto standard', null], 'SPORT' => ['Sport motorcycle', 'Moto sportive', null],
            'CRUISER' => ['Cruiser', 'Custom', null], 'DUAL_SPORT' => ['Dual sport', 'Trail', null], 'OFF_ROAD' => ['Off-road', 'Tout-terrain', null],
            'COMMERCIAL_MOTORCYCLE' => ['Commercial motorcycle (moto-taxi)', 'Moto commerciale (moto-taxi)', null],
            'THREE_WHEELER' => ['Three-wheeler', 'Tricycle', null],
        ],
    ];

    /** @return array<string, mixed> */
    public static function pack(): array
    {
        return json_decode((string) file_get_contents(base_path(self::PACK_FILE)), true, flags: JSON_THROW_ON_ERROR);
    }

    /** Line-scoped cause code for a generic pack cause (optionally within a claim category), or null. */
    public static function causeFor(string $generic, ?string $category = null): ?string
    {
        $codes = self::CAUSE_OF_LOSS[strtoupper($generic)] ?? null;
        if ($codes === null) {
            return str_contains($generic, '__') ? strtoupper($generic) : null;
        }
        if ($category === null) {
            return $codes[0];
        }
        foreach ($codes as $c) {
            if (str_starts_with($c, strtoupper($category).'__')) {
                return $c;
            }
        }

        return null;
    }

    /** Stored reason code for a pack/alias code (the code itself when canonical). */
    public static function reasonCode(string $code): string
    {
        $code = strtoupper(trim($code));
        $seen = [];
        while (($alias = self::DECISION_REASONS[$code][3] ?? null) !== null && ! isset($seen[$code])) {
            $seen[$code] = true;
            $code = $alias;
        }

        return $code;
    }

    /** Full catalogue for clients: every taxonomy with its values and data status. */
    public function catalogue(?string $locale = 'en'): array
    {
        $statuses = app(WorkflowDataStatuses::class)->byList();
        $out = [];
        foreach (self::LISTS as $packKey => $list) {
            $values = DB::table('master_data_values')->where(['domain_code' => 'claims', 'list_code' => $list, 'status' => 'ACTIVE'])
                ->whereNull('tenant_id')->whereNull('merged_into_id')->orderBy('sort_order')->get(['code', 'label_en', 'label_fr', 'parent_code', 'attributes']);
            $out[$list] = [
                'pack_key' => $packKey, 'data_status' => $statuses['claims.'.$list]['status'] ?? ($values->isEmpty() ? 'PENDING_SOURCE' : 'PLATFORM_NORMALIZED'),
                'source' => self::SOURCE, 'values' => $values->map(fn ($v) => ['code' => $v->code, 'label' => $locale === 'fr' ? $v->label_fr : $v->label_en,
                    'parent_code' => $v->parent_code, 'generic_code' => json_decode((string) $v->attributes, true)['generic_code'] ?? null])->values()->all(),
            ];
        }
        $out['decision_reason_codes'] = [
            'data_status' => 'PLATFORM_NORMALIZED', 'source' => 'claim_decision_reason_codes',
            'values' => Schema::hasTable('claim_decision_reason_codes') ? DB::table('claim_decision_reason_codes')->where('status', 'ACTIVE')->orderBy('code')->get()
                ->map(fn ($r) => ['code' => $r->code, 'applies_to' => $r->applies_to, 'label' => $r->label, 'reason_group' => $r->reason_group ?? null,
                    'aliases' => json_decode((string) ($r->aliases ?? '[]'), true)])->all() : [],
        ];

        return $out;
    }

    /** @return array<string, list<array<string, mixed>>> */
    public function vehicleClasses(): array
    {
        $out = [];
        foreach (self::VEHICLE_CLASS_GROUPS as $group => $defs) {
            $out[$group] = DB::table('vehicle_reference_values')->where(['group' => $group, 'active' => true])->orderBy('sort_order')->get()
                ->map(fn ($r) => ['code' => $r->code, 'label_en' => $r->label_en, 'label_fr' => $r->label_fr, 'vehicle_class' => $defs[$r->code][2] ?? null,
                    'data_status' => $r->data_status, 'source' => $r->source])->all();
        }

        return $out;
    }
}
