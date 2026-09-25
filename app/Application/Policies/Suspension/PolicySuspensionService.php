<?php

declare(strict_types=1);

namespace App\Application\Policies\Suspension;

use App\Application\Audit\AuditWriter;
use App\Application\Authority\AuthorityTypeCatalogue;
use App\Application\Cases\CaseService;
use App\Application\Cases\Models\WorkCase;
use App\Application\Events\OutboxWriter;
use App\Application\Policies\Chronology\PolicyChronologyWriter;
use App\Domain\Policies\PolicyStateMachine;
use App\Models\Policy;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REQ-POL-006 (WF-046 suspension, WF-047 reinstatement, BRK-063 reinstatement queue).
 *
 *  suspend()               ACTIVE → SUSPENDED. Opens a policy_suspensions episode, writes a SUSPENSION chronology version.
 *                          Idempotent: suspending an already-suspended policy returns the open episode.
 *                          Callable by other engines (premium-to-cover SUSPEND_ON_DEFAULT passes source PREMIUM_DEFAULT,
 *                          actor null = system) — no premium rule lives here.
 *  requestReinstatement()  maker: opens a POLICY_REINSTATEMENT case (the queue) and marks the episode REINSTATEMENT_REQUESTED.
 *  reinstate()             checker: SUSPENDED → ACTIVE, REINSTATEMENT chronology version, case resolved + closed.
 *                          Maker-checker: the requester cannot reinstate. REINSTATE authority type must be active.
 *                          A system call (actor null) may reinstate directly (e.g. premium received).
 *  rejectReinstatement()   checker: case cancelled, episode back to SUSPENDED.
 */
final class PolicySuspensionService
{
    public const SOURCES = ['MANUAL', 'PREMIUM_DEFAULT', 'COMPLIANCE', 'SYSTEM'];

    public const CASE_TYPE = 'POLICY_REINSTATEMENT';

    public function __construct(
        private readonly PolicyStateMachine $machine,
        private readonly PolicyChronologyWriter $chronology,
        private readonly CaseService $cases,
        private readonly AuthorityTypeCatalogue $authorityTypes,
        private readonly AuditWriter $audit,
        private readonly OutboxWriter $outbox,
    ) {}

    /** @param array{source?: string, notes?: ?string, effective_at?: \DateTimeInterface|string|null} $options */
    public function suspend(Policy $policy, string $reasonCode, ?User $actor, array $options = []): PolicySuspension
    {
        $source = $options['source'] ?? ($actor ? 'MANUAL' : 'SYSTEM');
        if (! in_array($source, self::SOURCES, true)) {
            throw ValidationException::withMessages(['source' => "Unknown suspension source {$source}."]);
        }
        $this->assertReason($reasonCode);

        return DB::transaction(function () use ($policy, $reasonCode, $actor, $source, $options): PolicySuspension {
            $policy = Policy::whereKey($policy->id)->lockForUpdate()->firstOrFail();
            if ($policy->status === 'SUSPENDED' && ($open = $this->open($policy->id))) {
                return $open;
            }
            try {
                $this->machine->assert($policy->status, 'SUSPENDED');
            } catch (\DomainException) {
                throw ValidationException::withMessages(['status' => "A {$policy->status} policy cannot be suspended."]);
            }

            $at = isset($options['effective_at']) ? \Carbon\CarbonImmutable::parse($options['effective_at']) : now()->toImmutable();
            $from = $policy->status;
            $policy->update(['status' => 'SUSPENDED', 'version' => $policy->version + 1]);
            $this->history($policy, $from, 'SUSPENDED', $reasonCode, $actor, ['source' => $source]);

            $suspension = PolicySuspension::create([
                'tenant_id' => $policy->tenant_id, 'policy_id' => $policy->id, 'status' => 'SUSPENDED', 'source' => $source,
                'reason_code' => mb_substr($reasonCode, 0, 64), 'notes' => $options['notes'] ?? null,
                'suspended_at' => $at, 'suspended_by' => $actor?->id,
            ]);
            $versionId = $this->chronology->record($policy->refresh(), 'SUSPENSION', $at, [
                'source_type' => 'policy_suspension', 'source_id' => $suspension->id, 'actor_id' => $actor?->id,
            ]);
            $suspension->update(['suspension_version_id' => $versionId]);

            $this->audit->record('policy.suspended', 'policy', $policy->id, ['suspension_id' => $suspension->id, 'source' => $source], $reasonCode);
            $this->outbox->record('policy.suspended', 'policy', $policy->id, [
                'policy_id' => $policy->id, 'suspension_id' => $suspension->id, 'source' => $source, 'reason_code' => $reasonCode,
                'effective_at' => $at->toIso8601String(),
            ]);

            return $suspension->refresh();
        });
    }

    /** Maker step: puts the suspended policy on the reinstatement queue (a POLICY_REINSTATEMENT case). */
    public function requestReinstatement(Policy $policy, string $reasonCode, User $actor, ?string $notes = null): PolicySuspension
    {
        $this->assertReason($reasonCode);

        return DB::transaction(function () use ($policy, $reasonCode, $actor, $notes): PolicySuspension {
            $policy = Policy::whereKey($policy->id)->lockForUpdate()->firstOrFail();
            $suspension = $this->openOrFail($policy);
            if ($suspension->status === 'REINSTATEMENT_REQUESTED') {
                throw ValidationException::withMessages(['status' => 'A reinstatement request is already pending for this policy.']);
            }

            $case = $this->cases->open($policy->tenant_id, self::CASE_TYPE, [
                'title' => 'Reinstatement — '.($policy->policy_number ?? $policy->id),
                'subject_type' => 'policy', 'subject_id' => $policy->id,
                'source_type' => 'policy_suspension', 'source_id' => $suspension->id,
                'carrier_id' => $policy->carrier_id, 'priority' => 'HIGH',
            ], $actor);

            $suspension->update([
                'status' => 'REINSTATEMENT_REQUESTED', 'reinstatement_reason_code' => mb_substr($reasonCode, 0, 64),
                'reinstatement_requested_by' => $actor->id, 'reinstatement_requested_at' => now(),
                'reinstatement_case_id' => $case->id, 'rejection_reason' => null,
                'notes' => $notes !== null ? trim(($suspension->notes ? $suspension->notes."\n" : '').$notes) : $suspension->notes,
            ]);

            $this->audit->record('policy.reinstatement.requested', 'policy', $policy->id, ['suspension_id' => $suspension->id, 'case_id' => $case->id], $reasonCode);
            $this->outbox->record('policy.reinstatement.requested', 'policy', $policy->id, [
                'policy_id' => $policy->id, 'suspension_id' => $suspension->id, 'case_id' => $case->id, 'reason_code' => $reasonCode,
            ]);

            return $suspension->refresh();
        });
    }

    /** Checker step (or system reinstatement when $actor is null): SUSPENDED → ACTIVE. */
    public function reinstate(Policy $policy, string $reasonCode, ?User $actor, ?\DateTimeInterface $effectiveAt = null): Policy
    {
        $this->assertReason($reasonCode);
        $this->authorityTypes->assertActive('REINSTATE');

        return DB::transaction(function () use ($policy, $reasonCode, $actor, $effectiveAt): Policy {
            $policy = Policy::whereKey($policy->id)->lockForUpdate()->firstOrFail();
            $suspension = $this->openOrFail($policy);
            if ($actor !== null && $suspension->reinstatement_requested_by === null) {
                throw ValidationException::withMessages(['status' => 'A manual reinstatement must be requested first (maker-checker).']);
            }
            if ($actor !== null && $suspension->reinstatement_requested_by === $actor->id) {
                throw ValidationException::withMessages(['actor' => __('wave5.maker_checker')]);
            }
            $this->machine->assert($policy->status, 'ACTIVE');

            $at = $effectiveAt ? \Carbon\CarbonImmutable::instance($effectiveAt) : now()->toImmutable();
            $policy->update(['status' => 'ACTIVE', 'version' => $policy->version + 1]);
            $this->history($policy, 'SUSPENDED', 'ACTIVE', $reasonCode, $actor, ['suspension_id' => $suspension->id]);
            $versionId = $this->chronology->record($policy->refresh(), 'REINSTATEMENT', $at, [
                'source_type' => 'policy_suspension', 'source_id' => $suspension->id, 'actor_id' => $actor?->id,
            ]);
            $suspension->update([
                'status' => 'REINSTATED', 'reinstated_by' => $actor?->id, 'reinstated_at' => $at, 'ended_at' => now(),
                'reinstatement_version_id' => $versionId,
                'reinstatement_reason_code' => $suspension->reinstatement_reason_code ?? mb_substr($reasonCode, 0, 64),
            ]);
            if ($suspension->reinstatement_case_id) {
                $this->finishCase($suspension->reinstatement_case_id, $actor, $reasonCode, true);
            }

            $this->audit->record('policy.reinstated', 'policy', $policy->id, ['suspension_id' => $suspension->id], $reasonCode);
            $this->outbox->record('policy.reinstated', 'policy', $policy->id, [
                'policy_id' => $policy->id, 'suspension_id' => $suspension->id, 'reason_code' => $reasonCode,
                'effective_at' => $at->toIso8601String(), 'system' => $actor === null,
            ]);

            return $policy->refresh();
        });
    }

    public function rejectReinstatement(Policy $policy, string $reason, User $actor): PolicySuspension
    {
        $this->assertReason($reason);

        return DB::transaction(function () use ($policy, $reason, $actor): PolicySuspension {
            $policy = Policy::whereKey($policy->id)->lockForUpdate()->firstOrFail();
            $suspension = $this->openOrFail($policy);
            if ($suspension->status !== 'REINSTATEMENT_REQUESTED') {
                throw ValidationException::withMessages(['status' => 'No reinstatement request is pending for this policy.']);
            }
            if ($suspension->reinstatement_requested_by === $actor->id) {
                throw ValidationException::withMessages(['actor' => __('wave5.maker_checker')]);
            }
            $this->finishCase($suspension->reinstatement_case_id, $actor, $reason, false);
            $suspension->update(['status' => 'SUSPENDED', 'rejection_reason' => $reason, 'reinstatement_case_id' => null,
                'reinstatement_requested_by' => null, 'reinstatement_requested_at' => null]);

            $this->audit->record('policy.reinstatement.rejected', 'policy', $policy->id, ['suspension_id' => $suspension->id], $reason);
            $this->outbox->record('policy.reinstatement.rejected', 'policy', $policy->id, ['policy_id' => $policy->id, 'suspension_id' => $suspension->id]);

            return $suspension->refresh();
        });
    }

    /** Closes the open episode when a suspended policy leaves SUSPENDED another way (e.g. cancellation). */
    public function end(Policy $policy, string $reasonCode, ?User $actor): void
    {
        $suspension = $this->open($policy->id);
        if (! $suspension) {
            return;
        }
        if ($suspension->reinstatement_case_id) {
            $this->finishCase($suspension->reinstatement_case_id, $actor, $reasonCode, false);
        }
        $suspension->update(['status' => 'ENDED', 'ended_at' => now()]);
        $this->audit->record('policy.suspension.ended', 'policy', $policy->id, ['suspension_id' => $suspension->id], $reasonCode);
    }

    /** The reinstatement queue: open episodes for a tenant, oldest first. @return Collection<int, PolicySuspension> */
    public function queue(string $tenantId, ?string $status = null): Collection
    {
        return PolicySuspension::where('tenant_id', $tenantId)
            ->whereIn('status', $status ? [$status] : PolicySuspension::OPEN_STATES)
            ->orderBy('suspended_at')->get();
    }

    public function open(string $policyId): ?PolicySuspension
    {
        return PolicySuspension::where('policy_id', $policyId)->whereIn('status', PolicySuspension::OPEN_STATES)->lockForUpdate()->first();
    }

    private function openOrFail(Policy $policy): PolicySuspension
    {
        if ($policy->status !== 'SUSPENDED') {
            throw ValidationException::withMessages(['status' => 'The policy is not suspended.']);
        }

        return $this->open($policy->id)
            ?? throw ValidationException::withMessages(['status' => 'The suspended policy has no open suspension record.']);
    }

    private function finishCase(string $caseId, ?User $actor, string $reason, bool $resolved): void
    {
        $case = WorkCase::withoutGlobalScopes()->find($caseId);
        if (! $case || $case->closed_at !== null) {
            return;
        }
        if (! $resolved) {
            $this->cases->transition($case, 'cancel', $actor, $reason, ['outcome' => 'REINSTATEMENT_REJECTED']);

            return;
        }
        if ($case->status === 'OPEN') {
            $case = $this->cases->transition($case, 'start', $actor);
        }
        $case = $this->cases->transition($case, 'resolve', $actor, $reason);
        $this->cases->transition($case, 'close', $actor, null, ['outcome' => 'REINSTATED']);
    }

    private function assertReason(string $reason): void
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason_code' => 'A reason is required.']);
        }
    }

    private function history(Policy $policy, string $from, string $to, string $reason, ?User $actor, array $meta): void
    {
        DB::table('policy_status_history')->insert([
            'id' => (string) Str::uuid(), 'policy_id' => $policy->id, 'from_status' => $from, 'to_status' => $to,
            'reason_code' => mb_substr($reason, 0, 64), 'actor_id' => $actor?->id, 'metadata' => json_encode($meta), 'occurred_at' => now(),
        ]);
    }
}
