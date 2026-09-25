<?php

declare(strict_types=1);

namespace App\Application\Underwriting;

use App\Models\UnderwritingCase;
use Illuminate\Validation\ValidationException;

/**
 * REQ-UW-001 — canonical underwriting case states (BP II / WRS WF-018):
 *   REFERRED → ASSIGNED → REVIEWING → DECISION_PENDING → APPROVED | CONDITIONAL | COUNTEROFFERED | DECLINED
 *   with the WF-019 loop REVIEWING/DECISION_PENDING → INFORMATION_REQUESTED → (proposal resubmitted) → ASSIGNED/REFERRED.
 *
 * underwriting_cases.status keeps the stored codes existing readers use (QUEUED, IN_REVIEW, AWAITING_INFORMATION,
 * DECIDED — plus DECISION_PENDING from batch 7B); the canonical name is derived here:
 *   QUEUED (no assignee) → REFERRED, QUEUED (assignee) → ASSIGNED, IN_REVIEW → REVIEWING,
 *   AWAITING_INFORMATION → INFORMATION_REQUESTED, DECISION_PENDING → DECISION_PENDING, DECIDED → outcome.
 * The outcome is always recorded by a human (UnderwritingService::decide); the system only evaluates and recommends.
 */
final class UnderwritingCaseMachine
{
    public const OUTCOMES = ['APPROVED', 'CONDITIONAL', 'COUNTEROFFERED', 'DECLINED'];

    /** event => stored statuses it may start from */
    public const TRANSITIONS = [
        'assign' => ['QUEUED', 'IN_REVIEW', 'DECISION_PENDING'],
        'start_review' => ['QUEUED'],
        'ready_for_decision' => ['IN_REVIEW'],
        'request_information' => ['QUEUED', 'IN_REVIEW', 'DECISION_PENDING'],
        'evaluate' => ['QUEUED', 'IN_REVIEW', 'DECISION_PENDING'],
        'decide' => ['QUEUED', 'IN_REVIEW', 'DECISION_PENDING'],
    ];

    public static function canonicalState(UnderwritingCase $c): string
    {
        return match ($c->status) {
            'QUEUED' => $c->assigned_to ? 'ASSIGNED' : 'REFERRED',
            'IN_REVIEW' => 'REVIEWING',
            'AWAITING_INFORMATION' => 'INFORMATION_REQUESTED',
            'DECISION_PENDING' => 'DECISION_PENDING',
            'DECIDED' => $c->outcome ?? 'DECIDED',
            default => $c->status,
        };
    }

    /** @return list<string> events available from the case's current stored status */
    public static function availableEvents(UnderwritingCase $c): array
    {
        return array_keys(array_filter(self::TRANSITIONS, fn (array $from) => in_array($c->status, $from, true)));
    }

    public static function assertCan(UnderwritingCase $c, string $event): void
    {
        if (! in_array($c->status, self::TRANSITIONS[$event] ?? [], true)) {
            throw ValidationException::withMessages(['status' => "Underwriting case in state ".self::canonicalState($c)." cannot {$event}."]);
        }
    }
}
