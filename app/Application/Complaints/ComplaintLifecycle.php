<?php

declare(strict_types=1);

namespace App\Application\Complaints;

use App\Domain\Shared\StateMachine\GuardResult;
use App\Domain\Shared\StateMachine\StateMachineEngine;
use App\Domain\Shared\StateMachine\TransitionContext;
use Illuminate\Support\Facades\DB;

/**
 * REQ-CPL-001 complaint lifecycle, run by the case engine as COMPLAINT v2 (migration 2026_10_12_780001):
 *
 *   SUBMITTED → ACKNOWLEDGED → CLASSIFIED → ASSIGNED → INVESTIGATING (⇄ WAITING_CUSTOMER, pauses SLA)
 *   → RESOLUTION_PROPOSED (needs a case decision) → COMMUNICATED (needs a dispatched response in the
 *   correspondence register) → CLOSED, or → ESCALATED_NATIONAL → ESCALATED_CIMA → CLOSED.
 *
 * The guards below are evaluated by CaseMachine for every path (the complaint API and the generic
 * /cases/{id}/transitions endpoint alike), so the facts cannot be skipped by using the generic API.
 * Deadlines are NOT modelled here: they are configurable PLATFORM_SLA targets (owner decision; REGULATORY_DEADLINE
 * only with a legal basis) set through sla_policy_overrides / a new type version.
 */
final class ComplaintLifecycle
{
    public const CLASSIFIED_GUARD = 'complaint.classified';

    public const OWNER_GUARD = 'complaint.investigator_assigned';

    public const DISPATCHED_GUARD = 'complaint.response_dispatched';

    public const SUBTYPES = ['STANDARD', 'REGULATORY'];

    /** @return array{states: list<array<string, mixed>>, transitions: list<array<string, mixed>>} */
    public static function definition(): array
    {
        return [
            'states' => [
                ['code' => 'SUBMITTED', 'initial' => true],
                ['code' => 'ACKNOWLEDGED'],
                ['code' => 'CLASSIFIED'],
                ['code' => 'ASSIGNED'],
                ['code' => 'INVESTIGATING'],
                ['code' => 'WAITING_CUSTOMER', 'pauses_sla' => true],
                ['code' => 'RESOLUTION_PROPOSED'],
                ['code' => 'COMMUNICATED'],
                ['code' => 'ESCALATED_NATIONAL'],
                ['code' => 'ESCALATED_CIMA'],
                ['code' => 'CLOSED', 'terminal' => true],
            ],
            'transitions' => [
                ['event' => 'acknowledge', 'from' => ['SUBMITTED'], 'to' => 'ACKNOWLEDGED'],
                ['event' => 'classify', 'from' => ['ACKNOWLEDGED'], 'to' => 'CLASSIFIED', 'guards' => [self::CLASSIFIED_GUARD]],
                ['event' => 'assign_investigator', 'from' => ['CLASSIFIED'], 'to' => 'ASSIGNED', 'guards' => [self::OWNER_GUARD]],
                ['event' => 'investigate', 'from' => ['ASSIGNED'], 'to' => 'INVESTIGATING'],
                ['event' => 'request_info', 'from' => ['INVESTIGATING'], 'to' => 'WAITING_CUSTOMER'],
                ['event' => 'info_received', 'from' => ['WAITING_CUSTOMER'], 'to' => 'INVESTIGATING'],
                ['event' => 'propose_resolution', 'from' => ['INVESTIGATING'], 'to' => 'RESOLUTION_PROPOSED', 'requires_decision' => true],
                ['event' => 'reinvestigate', 'from' => ['RESOLUTION_PROPOSED'], 'to' => 'INVESTIGATING', 'requires_reason' => true],
                ['event' => 'communicate', 'from' => ['RESOLUTION_PROPOSED'], 'to' => 'COMMUNICATED', 'guards' => [self::DISPATCHED_GUARD]],
                ['event' => 'escalate_national', 'from' => ['COMMUNICATED'], 'to' => 'ESCALATED_NATIONAL', 'requires_reason' => true],
                ['event' => 'escalate_cima', 'from' => ['ESCALATED_NATIONAL'], 'to' => 'ESCALATED_CIMA', 'requires_reason' => true],
                ['event' => 'close', 'from' => ['COMMUNICATED', 'ESCALATED_NATIONAL', 'ESCALATED_CIMA'], 'to' => 'CLOSED'],
            ],
        ];
    }

    /** Called by CaseMachine (bridge) so complaint facts gate the engine's transitions. */
    public static function registerGuards(StateMachineEngine $engine): void
    {
        $engine->registerGuard(self::CLASSIFIED_GUARD, function ($t, TransitionContext $c) {
            $ok = DB::table('complaints')->where('case_id', $c->subjectId)->whereNotNull('category')->whereNotNull('severity')->exists();

            return $ok ? GuardResult::pass() : GuardResult::fail('Record the complaint category and severity before classifying.');
        });
        $engine->registerGuard(self::OWNER_GUARD, function ($t, TransitionContext $c) {
            $ok = DB::table('cases')->where('id', $c->subjectId)->whereNotNull('owner_user_id')->exists();

            return $ok ? GuardResult::pass() : GuardResult::fail('Assign an accountable investigator (user owner) first.');
        });
        $engine->registerGuard(self::DISPATCHED_GUARD, function ($t, TransitionContext $c) {
            $ok = DB::table('complaints as p')->join('correspondence_register as r', 'r.id', '=', 'p.response_correspondence_id')
                ->where('p.case_id', $c->subjectId)->whereNotNull('p.outcome')
                ->where('r.direction', 'OUTBOUND')->whereIn('r.status', ['DISPATCHED', 'DELIVERED'])->exists();

            return $ok ? GuardResult::pass() : GuardResult::fail('The resolution must be recorded and its response dispatched (with proof) in the correspondence register.');
        });
    }
}
