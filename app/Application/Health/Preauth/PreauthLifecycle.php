<?php

declare(strict_types=1);

namespace App\Application\Health\Preauth;

/**
 * REQ-HLT-002 — health preauthorization / guarantee of payment (GOP) lifecycle.
 *
 * REQUESTED ⇄ INFO_REQUESTED; REQUESTED → PENDING_APPROVAL (maker within authority) | REFERRED (maker over authority)
 * → APPROVED | PARTIALLY_APPROVED | DECLINED (checker ≠ maker, checker's own authority limit must cover the amount)
 * or back to REQUESTED (returned). A checker whose own limit does not cover the amount refers it (PENDING_APPROVAL → REFERRED). ADMISSION only: APPROVED/PARTIALLY_APPROVED → ADMITTED → DISCHARGED, with stay
 * extensions decided under the same maker-checker. Any open request can be cancelled.
 *
 * The same states (without ADMITTED/DISCHARGED) back the HEALTH_PREAUTHORIZATION case type, so the case engine runs
 * the SLA clocks. No SLA target is seeded (targets are insurer configuration, per case_subtype = request type).
 */
final class PreauthLifecycle
{
    public const CASE_TYPE = 'HEALTH_PREAUTHORIZATION';

    /**
     * Authority type (authority_types catalogue) checked for the guaranteed amount. No dedicated preauthorization type
     * exists in the catalogue, so the existing CLAIM_SETTLE type is used by default; an insurer that adds its own type
     * (e.g. PREAUTH_APPROVE through the admin catalogue) points health_preauth.authority_type at it.
     */
    public static function authorityType(): string
    {
        return (string) (config('health_preauth.authority_type') ?: 'CLAIM_SETTLE');
    }

    public const TYPES = ['ADMISSION', 'OUTPATIENT', 'PHARMACY', 'LAB'];

    /** Case sub-types: one per request type, plus EXTENSION (admission stay extension). */
    public const CASE_SUBTYPES = ['ADMISSION', 'OUTPATIENT', 'PHARMACY', 'LAB', 'EXTENSION'];

    /** Type-specific fields: field => [required, validation rule]. Stored in type_details. */
    public const TYPE_FIELDS = [
        'ADMISSION' => [
            'admission_date' => [true, 'date'], 'expected_discharge_date' => [true, 'date|after_or_equal:details.admission_date'],
            'diagnosis_code' => [true, 'string|max:32'], 'admission_reason' => [true, 'string|max:2000'],
            'ward_class' => [false, 'string|max:32'], 'attending_physician' => [false, 'string|max:160'], 'emergency' => [false, 'boolean'],
        ],
        'OUTPATIENT' => [
            'consultation_date' => [true, 'date'], 'diagnosis_code' => [true, 'string|max:32'],
            'specialty_code' => [false, 'string|max:48'], 'attending_physician' => [false, 'string|max:160'],
        ],
        'PHARMACY' => [
            'prescription_reference' => [true, 'string|max:64'], 'prescriber' => [true, 'string|max:160'],
            'prescription_date' => [true, 'date'], 'chronic_medication' => [false, 'boolean'], 'diagnosis_code' => [false, 'string|max:32'],
        ],
        'LAB' => [
            'test_order_reference' => [true, 'string|max:64'], 'ordering_physician' => [true, 'string|max:160'],
            'order_date' => [true, 'date'], 'diagnosis_code' => [false, 'string|max:32'], 'sample_date' => [false, 'date'],
        ],
    ];

    /** Field of type_details carrying the service date per type. */
    public const SERVICE_DATE_FIELD = ['ADMISSION' => 'admission_date', 'OUTPATIENT' => 'consultation_date', 'PHARMACY' => 'prescription_date', 'LAB' => 'order_date'];

    public const DECISIONS = ['APPROVED', 'PARTIAL', 'DECLINED'];

    public const STATES = ['REQUESTED', 'INFO_REQUESTED', 'PENDING_APPROVAL', 'REFERRED', 'APPROVED', 'PARTIALLY_APPROVED', 'DECLINED', 'ADMITTED', 'DISCHARGED', 'CANCELLED'];

    public const TERMINAL = ['DECLINED', 'DISCHARGED', 'CANCELLED'];

    /** States in which a guarantee of payment is live. */
    public const GUARANTEED = ['APPROVED', 'PARTIALLY_APPROVED', 'ADMITTED'];

    /** event => [from states, to state] */
    public const TRANSITIONS = [
        'request_info' => [['REQUESTED'], 'INFO_REQUESTED'],
        'provide_info' => [['INFO_REQUESTED'], 'REQUESTED'],
        'propose' => [['REQUESTED'], 'PENDING_APPROVAL'],
        'refer' => [['REQUESTED', 'PENDING_APPROVAL'], 'REFERRED'],
        'return' => [['PENDING_APPROVAL', 'REFERRED'], 'REQUESTED'],
        'approve' => [['PENDING_APPROVAL', 'REFERRED'], 'APPROVED'],
        'approve_partial' => [['PENDING_APPROVAL', 'REFERRED'], 'PARTIALLY_APPROVED'],
        'decline' => [['PENDING_APPROVAL', 'REFERRED'], 'DECLINED'],
        'admit' => [['APPROVED', 'PARTIALLY_APPROVED'], 'ADMITTED'],
        'discharge' => [['ADMITTED'], 'DISCHARGED'],
        'cancel' => [['REQUESTED', 'INFO_REQUESTED', 'PENDING_APPROVAL', 'REFERRED', 'APPROVED', 'PARTIALLY_APPROVED'], 'CANCELLED'],
    ];

    /** Events that need a reason. */
    public const REASON_REQUIRED = ['request_info', 'return', 'cancel'];

    /** Outbox event per lifecycle event. */
    public const DOMAIN_EVENTS = [
        'request' => 'health.preauth.requested',
        'request_info' => 'health.preauth.info_requested',
        'provide_info' => 'health.preauth.info_provided',
        'propose' => 'health.preauth.proposed',
        'refer' => 'health.preauth.referred',
        'return' => 'health.preauth.returned',
        'approve' => 'health.preauth.approved',
        'approve_partial' => 'health.preauth.partially_approved',
        'decline' => 'health.preauth.declined',
        'admit' => 'health.preauth.admitted',
        'discharge' => 'health.preauth.discharged',
        'cancel' => 'health.preauth.cancelled',
        'extension_requested' => 'health.preauth.extension_requested',
        'extension_decided' => 'health.preauth.extension_decided',
    ];

    /** Checker event per proposed decision. */
    public const DECISION_EVENT = ['APPROVED' => 'approve', 'PARTIAL' => 'approve_partial', 'DECLINED' => 'decline'];

    public static function target(string $from, string $event): ?string
    {
        $t = self::TRANSITIONS[$event] ?? null;

        return $t !== null && in_array($from, $t[0], true) ? $t[1] : null;
    }

    /** @return array{states: list<array<string,mixed>>, transitions: list<array<string,mixed>>, sla_policies: list<array<string,mixed>>} */
    public static function caseDefinition(): array
    {
        $caseStates = array_values(array_diff(self::STATES, ['ADMITTED', 'DISCHARGED']));
        $caseTerminal = ['APPROVED', 'PARTIALLY_APPROVED', 'DECLINED', 'CANCELLED'];
        $states = [];
        foreach ($caseStates as $s) {
            $states[] = array_filter(['code' => $s, 'initial' => $s === 'REQUESTED' ?: null, 'terminal' => in_array($s, $caseTerminal, true) ?: null]);
        }
        $transitions = [];
        foreach (self::TRANSITIONS as $event => [$from, $to]) {
            $from = array_values(array_intersect($from, array_diff($caseStates, $caseTerminal)));
            if ($from === [] || ! in_array($to, $caseStates, true)) {
                continue;
            }
            $transitions[] = ['event' => $event, 'from' => $from, 'to' => $to] + (in_array($event, self::REASON_REQUIRED, true) ? ['requires_reason' => true] : []);
        }

        // No SLA targets are seeded: the owner has not supplied preauthorization turnaround targets and the platform never
        // invents SLA data. Insurers configure them per case_subtype (ADMISSION / OUTPATIENT / PHARMACY / LAB / EXTENSION)
        // by versioning the case type.
        return ['states' => $states, 'transitions' => $transitions, 'sla_policies' => []];
    }
}
