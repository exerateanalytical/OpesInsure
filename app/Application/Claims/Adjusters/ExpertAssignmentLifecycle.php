<?php

declare(strict_types=1);

namespace App\Application\Claims\Adjusters;

/**
 * REQ-CLM-009 / WF-053 — expert / adjuster assignment lifecycle.
 *
 * ASSIGNMENT_PENDING → ACCEPTED | DECLINED → INSPECTION_SCHEDULED → INSPECTED → REPORT_SUBMITTED → REPORT_ACCEPTED | REPORT_RETURNED
 * (a returned report is resubmitted). The insurer can cancel any open assignment. The same states back the
 * CLAIM_EXPERT_ASSIGNMENT case type so the case engine runs the SLA clocks (targets are insurer-configured; none seeded).
 */
final class ExpertAssignmentLifecycle
{
    public const CASE_TYPE = 'CLAIM_EXPERT_ASSIGNMENT';

    public const STATES = [
        'ASSIGNMENT_PENDING', 'ACCEPTED', 'DECLINED', 'INSPECTION_SCHEDULED', 'INSPECTED',
        'REPORT_SUBMITTED', 'REPORT_ACCEPTED', 'REPORT_RETURNED', 'CANCELLED',
    ];

    public const TERMINAL = ['DECLINED', 'REPORT_ACCEPTED', 'CANCELLED'];

    /** Provider master categories that can be assigned as claim experts. */
    public const PROVIDER_CATEGORIES = ['ADJUSTER', 'EXPERT'];

    /** event => [from states, to state, side that performs it] */
    public const TRANSITIONS = [
        'accept' => [['ASSIGNMENT_PENDING'], 'ACCEPTED', 'ADJUSTER'],
        'decline' => [['ASSIGNMENT_PENDING'], 'DECLINED', 'ADJUSTER'],
        'schedule_inspection' => [['ACCEPTED', 'INSPECTION_SCHEDULED'], 'INSPECTION_SCHEDULED', 'ADJUSTER'],
        'record_inspection' => [['INSPECTION_SCHEDULED'], 'INSPECTED', 'ADJUSTER'],
        'submit_report' => [['INSPECTED', 'REPORT_RETURNED'], 'REPORT_SUBMITTED', 'ADJUSTER'],
        'accept_report' => [['REPORT_SUBMITTED'], 'REPORT_ACCEPTED', 'INSURER'],
        'return_report' => [['REPORT_SUBMITTED'], 'REPORT_RETURNED', 'INSURER'],
        'cancel' => [['ASSIGNMENT_PENDING', 'ACCEPTED', 'INSPECTION_SCHEDULED', 'INSPECTED', 'REPORT_SUBMITTED', 'REPORT_RETURNED'], 'CANCELLED', 'INSURER'],
    ];

    /** Outbox event per lifecycle event. */
    public const DOMAIN_EVENTS = [
        'assign' => 'claim.expert.assigned',
        'accept' => 'claim.expert.accepted',
        'decline' => 'claim.expert.declined',
        'schedule_inspection' => 'claim.expert.inspection_scheduled',
        'record_inspection' => 'claim.expert.inspected',
        'submit_report' => 'claim.expert.report_submitted',
        'accept_report' => 'claim.expert.report_accepted',
        'return_report' => 'claim.expert.report_returned',
        'cancel' => 'claim.expert.cancelled',
    ];

    public static function target(string $from, string $event): ?string
    {
        $t = self::TRANSITIONS[$event] ?? null;

        return $t !== null && in_array($from, $t[0], true) ? $t[1] : null;
    }

    /** Unapproved suggestion (accept/decline 1 business day, report 10, insurer review 2); never seeded. */
    public const SUGGESTED_SLA_POLICIES = [
        ['metric' => 'FIRST_RESPONSE', 'target_business_minutes' => 480, 'label' => 'PLATFORM_SLA'],
        ['metric' => 'RESOLUTION', 'target_business_days' => 10, 'label' => 'PLATFORM_SLA'],
        ['metric' => 'STAGE:REPORT_SUBMITTED', 'target_business_days' => 2, 'label' => 'PLATFORM_SLA'],
    ];

    /** @return array{states: list<array<string,mixed>>, transitions: list<array<string,mixed>>, sla_policies: list<array<string,mixed>>} */
    public static function caseDefinition(): array
    {
        $states = [];
        foreach (self::STATES as $s) {
            $states[] = array_filter(['code' => $s, 'initial' => $s === 'ASSIGNMENT_PENDING' ?: null, 'terminal' => in_array($s, self::TERMINAL, true) ?: null]);
        }
        $transitions = [];
        foreach (self::TRANSITIONS as $event => [$from, $to]) {
            $transitions[] = ['event' => $event, 'from' => $from, 'to' => $to] + ($event === 'cancel' || $event === 'return_report' ? ['requires_reason' => true] : []);
        }

        return [
            'states' => $states,
            'transitions' => $transitions,
            // No SLA targets are seeded: the owner has not supplied expert-assignment targets and the platform
            // never invents SLA data (OWNER_DECISIONS_2026-09-25). Insurers configure them by versioning the
            // case type; SUGGESTED_SLA_POLICIES is a CONFIG_REQUIRED starting point, not a default.
            'sla_policies' => [],
        ];
    }
}
