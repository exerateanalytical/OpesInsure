<?php

declare(strict_types=1);

namespace App\Application\Cases\Sla;

use App\Application\Cases\CaseJournal;
use App\Application\Cases\Models\CaseTask;
use App\Application\Cases\Models\CaseType;
use App\Application\Cases\Models\SlaClock;
use App\Application\Cases\Models\WorkCase;
use App\Application\Cases\Models\WorkQueue;
use App\Domain\Shared\Clock\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * REQ-CAS-001 / REQ-CAL-001 SLA clocks (ICE E6 INV-6.3, §6.3 SlaService).
 *
 * Metrics: FIRST_RESPONSE (stops on the first transition out of the initial
 * state), RESOLUTION (stops at RESOLVED or a terminal state; a reopen resumes
 * it without charging the resolved interval), STAGE:<STATE> (runs while the
 * case is in that state). Clocks pause only in states flagged pauses_sla.
 * All durations are business minutes from BusinessHoursCalendar.
 */
final class SlaService
{
    public function __construct(
        private readonly BusinessHoursCalendar $calendar,
        private readonly CaseJournal $journal,
        private readonly Clock $clock,
        private readonly SlaPolicyResolver $policies,
    ) {}

    public function start(WorkCase $case, CaseType $type): void
    {
        $now = $this->clock->now();
        foreach ($this->policies->resolve($case, $type) as $p) {
            $metric = (string) $p['metric'];
            if (str_starts_with($metric, 'STAGE:') && substr($metric, 6) !== $case->status) {
                continue;
            }
            $this->createClock($case, $p, $now);
        }
        $this->syncCaseDue($case);
    }

    public function onTransition(WorkCase $case, CaseType $type, string $from, string $to, bool $leftInitial): void
    {
        $now = $this->clock->now();
        $clocks = SlaClock::where('case_id', $case->id)->lockForUpdate()->get();

        // 1) pause / resume
        if ($type->pausesSla($to) && ! $type->pausesSla($from)) {
            foreach ($clocks as $c) {
                if ($c->stopped_at === null && $c->paused_since === null) {
                    $c->update(['paused_since' => $now]);
                    $this->journal->publish('sla.paused', 'case', $case->id, ['clock_id' => $c->id, 'metric' => $c->metric, 'state' => $to]);
                }
            }
        } elseif ($type->pausesSla($from) && ! $type->pausesSla($to)) {
            foreach ($clocks as $c) {
                if ($c->stopped_at === null && $c->paused_since !== null) {
                    $this->resume($case, $c, $c->paused_since, $now);
                    $this->journal->publish('sla.resumed', 'case', $case->id, ['clock_id' => $c->id, 'metric' => $c->metric, 'due_at' => $c->due_at?->toIso8601String()]);
                }
            }
        }

        // 2) stop / restart
        $stopResolution = $to === 'RESOLVED' || $type->isTerminal($to);
        foreach ($clocks as $c) {
            $c->refresh();
            $stage = str_starts_with($c->metric, 'STAGE:') ? substr($c->metric, 6) : null;
            $stop = match (true) {
                $c->metric === 'FIRST_RESPONSE' => $leftInitial || $type->isTerminal($to),
                $c->metric === 'RESOLUTION' => $stopResolution,
                $stage !== null => $stage === $from && $stage !== $to,
                default => false,
            };
            if ($stop && $c->stopped_at === null) {
                $this->stop($case, $c, $now);
            } elseif ($c->metric === 'RESOLUTION' && $c->stopped_at !== null && $from === 'RESOLVED' && ! $type->isTerminal($to)) {
                // Reopen: the resolved interval is not charged.
                $stoppedAt = $c->stopped_at;
                $c->update(['stopped_at' => null]);
                $this->resume($case, $c, $stoppedAt, $now);
                $this->journal->event($case, 'SLA_RESUMED', ['metric' => $c->metric, 'due_at' => $c->due_at->toIso8601String()]);
            }
        }

        // 3) stage clocks entering $to
        foreach ($this->policies->resolve($case, $type) as $p) {
            if (($p['metric'] ?? null) !== 'STAGE:'.$to || $from === $to) {
                continue;
            }
            $existing = $clocks->firstWhere('metric', $p['metric']);
            if ($existing) {
                $existing->delete();
            }
            $this->createClock($case, $p, $now);
        }
        $this->syncCaseDue($case);
    }

    /**
     * Re-applies the resolved targets to the case's running clocks (case_subtype changed, e.g. a manual quote
     * became COMPLEX/REFERRED). Started-at and paused time are kept; only the target moves.
     *
     * @return list<array{metric: string, from: int, to: int}>
     */
    public function retarget(WorkCase $case, CaseType $type): array
    {
        $changed = [];
        $resolved = $this->policies->resolve($case, $type);
        foreach (SlaClock::where('case_id', $case->id)->whereNull('stopped_at')->lockForUpdate()->get() as $c) {
            $p = $resolved[$c->metric] ?? null;
            if ($p === null) {
                continue;
            }
            $target = $this->targetMinutes($case, $p, $c->started_at);
            if ($target === $c->target_business_minutes && ($p['source'] ?? null) === $c->policy_source) {
                continue;
            }
            $paused = $c->paused_total_business_minutes;
            $changed[] = ['metric' => $c->metric, 'from' => $c->target_business_minutes, 'to' => $target];
            $c->update([
                'target_business_minutes' => $target, 'warn_at_pct' => (int) ($p['warn_at_pct'] ?? $c->warn_at_pct),
                'deadline_label' => $p['label'] ?? 'PLATFORM_SLA', 'legal_basis' => $p['legal_basis'] ?? null, 'policy_source' => $p['source'] ?? null,
                'due_at' => $this->due($case, $c->started_at, $target + $paused),
                'warn_at' => $this->due($case, $c->started_at, (int) ceil($target * (int) ($p['warn_at_pct'] ?? $c->warn_at_pct) / 100) + $paused),
            ]);
            if ($c->due_at > $this->clock->now()) { // a later target re-arms the warning/breach markers
                $c->update(['warned_at' => null, 'breached_at' => null]);
            }
            $this->journal->event($case, 'SLA_RETARGETED', ['metric' => $c->metric, 'target_business_minutes' => $target, 'due_at' => $c->due_at->toIso8601String(), 'source' => $p['source'] ?? null]);
        }
        $this->syncCaseDue($case);

        return $changed;
    }

    /** Stops every running clock (case cancelled/closed). */
    public function stopAll(WorkCase $case): void
    {
        $now = $this->clock->now();
        foreach (SlaClock::where('case_id', $case->id)->whereNull('stopped_at')->lockForUpdate()->get() as $c) {
            $this->stop($case, $c, $now);
        }
    }

    /**
     * Scheduled every minute (cases:sla-tick). Idempotent: every notification
     * is guarded by a conditional UPDATE … WHERE <marker> IS NULL.
     *
     * @return array{warned: int, breached: int, escalated: int, tasks_overdue: int, follow_ups_due: int, orphans_returned: int}
     */
    public function tick(): array
    {
        $now = $this->clock->now();
        $out = ['warned' => 0, 'breached' => 0, 'escalated' => 0, 'tasks_overdue' => 0, 'follow_ups_due' => 0, 'orphans_returned' => 0];

        $running = fn () => SlaClock::query()->whereNull('stopped_at')->whereNull('paused_since');

        foreach ($running()->whereNull('warned_at')->whereNotNull('warn_at')->where('warn_at', '<=', $now)->where('due_at', '>', $now)->limit(500)->get() as $c) {
            if (SlaClock::whereKey($c->id)->whereNull('warned_at')->update(['warned_at' => $now, 'updated_at' => $now]) === 1) {
                DB::transaction(function () use ($c) {
                    $case = $this->lockCase($c->case_id);
                    $this->journal->event($case, 'SLA_WARNED', ['metric' => $c->metric, 'due_at' => $c->due_at->toIso8601String()]);
                    $this->journal->publish('sla.warned', 'case', $case->id, ['clock_id' => $c->id, 'metric' => $c->metric, 'due_at' => $c->due_at->toIso8601String()]);
                });
                $out['warned']++;
            }
        }

        foreach ($running()->whereNull('breached_at')->where('due_at', '<=', $now)->limit(500)->get() as $c) {
            DB::transaction(function () use ($c, $now, &$out) {
                if (SlaClock::whereKey($c->id)->whereNull('breached_at')->update(['breached_at' => $now, 'warned_at' => DB::raw('COALESCE(warned_at, now())'), 'updated_at' => $now]) !== 1) {
                    return;
                }
                $case = $this->lockCase($c->case_id);
                $this->journal->event($case, 'SLA_BREACHED', ['metric' => $c->metric, 'due_at' => $c->due_at->toIso8601String()]);
                $this->journal->publish('sla.breached', 'case', $case->id, ['clock_id' => $c->id, 'metric' => $c->metric, 'due_at' => $c->due_at->toIso8601String(), 'escalate_to' => $c->escalate_to]);
                $this->journal->audit('case.sla.breached', $case, ['metric' => $c->metric]);
                $out['breached']++;
                if ($c->escalate_to && $this->escalate($case, (string) $c->escalate_to, $c->metric)) {
                    $out['escalated']++;
                }
            });
        }

        foreach (CaseTask::query()->whereIn('status', CaseTask::OPEN_STATES)->whereNull('overdue_notified_at')->whereNotNull('due_at')->where('due_at', '<=', $now)->limit(500)->get() as $t) {
            if (CaseTask::whereKey($t->id)->whereNull('overdue_notified_at')->update(['overdue_notified_at' => $now]) === 1) {
                $this->journal->publish('case.task.overdue', 'case_task', $t->id, ['case_id' => $t->case_id, 'assignee_user_id' => $t->assignee_user_id, 'due_at' => $t->due_at->toIso8601String()]);
                $out['tasks_overdue']++;
            }
        }

        foreach (DB::table('diary_entries')->whereNull('follow_up_notified_at')->whereNotNull('follow_up_at')->where('follow_up_at', '<=', $now)->limit(500)->get(['id', 'case_id', 'author_id', 'follow_up_at']) as $d) {
            if (DB::table('diary_entries')->where('id', $d->id)->whereNull('follow_up_notified_at')->update(['follow_up_notified_at' => $now]) === 1) {
                $this->journal->publish('diary.follow_up_due', 'diary_entry', $d->id, ['case_id' => $d->case_id, 'author_id' => $d->author_id]);
                $out['follow_ups_due']++;
            }
        }

        // ICE §6.7: orphaned cases (inactive owner) go back to their queue.
        $orphans = WorkCase::withoutGlobalScopes()->whereNull('closed_at')->whereNotNull('owner_user_id')
            ->whereIn('owner_user_id', DB::table('users')->where('status', '!=', 'ACTIVE')->select('id'))->limit(200)->pluck('id');
        foreach ($orphans as $id) {
            DB::transaction(function () use ($id, &$out) {
                $case = $this->lockCase($id);
                if ($case->owner_user_id === null) {
                    return;
                }
                $previous = $case->owner_user_id;
                $case->update(['owner_user_id' => null, 'version' => $case->version + 1]);
                $this->journal->event($case, 'RETURNED_TO_QUEUE', ['previous_owner_user_id' => $previous, 'queue_id' => $case->queue_id, 'reason' => 'OWNER_INACTIVE']);
                $this->journal->publish('case.assigned', 'case', $case->id, ['owner_user_id' => null, 'queue_id' => $case->queue_id, 'reason' => 'OWNER_INACTIVE']);
                $out['orphans_returned']++;
            });
        }

        return $out;
    }

    /** @param array<string, mixed> $p */
    private function createClock(WorkCase $case, array $p, CarbonImmutable $now): SlaClock
    {
        $target = $this->targetMinutes($case, $p, $now);
        $warnPct = (int) ($p['warn_at_pct'] ?? 80);
        $clock = SlaClock::create([
            'case_id' => $case->id, 'metric' => (string) $p['metric'], 'target_business_minutes' => $target, 'warn_at_pct' => $warnPct,
            'escalate_to' => $p['escalate_to'] ?? null, 'started_at' => $now, 'paused_total_business_minutes' => 0,
            'deadline_label' => $p['label'] ?? 'PLATFORM_SLA', 'legal_basis' => $p['legal_basis'] ?? null, 'policy_source' => $p['source'] ?? null,
            'due_at' => $this->due($case, $now, $target), 'warn_at' => $this->due($case, $now, (int) ceil($target * $warnPct / 100)),
        ]);
        $this->journal->event($case, 'SLA_STARTED', ['metric' => $clock->metric, 'due_at' => $clock->due_at->toIso8601String(), 'label' => $clock->deadline_label, 'source' => $clock->policy_source]);

        return $clock;
    }

    /** Business minutes for a policy; business-day targets are converted from $start on the case's calendar. */
    private function targetMinutes(WorkCase $case, array $p, \DateTimeInterface $start): int
    {
        if (! empty($p['target_business_days'])) {
            return $this->calendar->businessDaysAsMinutes($start, (int) $p['target_business_days'], $case->jurisdiction, $case->branch_id, $this->tz($case));
        }

        return (int) $p['target_business_minutes'];
    }

    private function resume(WorkCase $case, SlaClock $c, \DateTimeInterface $since, CarbonImmutable $now): void
    {
        $paused = $c->paused_total_business_minutes + $this->calendar->businessMinutesBetween($since, $now, $case->jurisdiction, $case->branch_id, $this->tz($case));
        $c->update([
            'paused_since' => null, 'paused_total_business_minutes' => $paused,
            'due_at' => $this->due($case, $c->started_at, $c->target_business_minutes + $paused),
            'warn_at' => $this->due($case, $c->started_at, (int) ceil($c->target_business_minutes * $c->warn_at_pct / 100) + $paused),
        ]);
    }

    private function stop(WorkCase $case, SlaClock $c, CarbonImmutable $now): void
    {
        if ($c->paused_since !== null) {
            $this->resume($case, $c, $c->paused_since, $now);
        }
        $c->update(['stopped_at' => $now]);
        $this->journal->event($case, 'SLA_STOPPED', ['metric' => $c->metric, 'met' => $now <= $c->due_at]);
    }

    private function due(WorkCase $case, \DateTimeInterface $start, int $minutes): CarbonImmutable
    {
        return $this->calendar->addBusinessMinutes($start, $minutes, $case->jurisdiction, $case->branch_id, $this->tz($case));
    }

    private function syncCaseDue(WorkCase $case): void
    {
        $due = SlaClock::where('case_id', $case->id)->where('metric', 'RESOLUTION')->value('due_at');
        if ($due !== null) {
            WorkCase::withoutGlobalScopes()->whereKey($case->id)->update(['due_at' => $due]);
            $case->due_at = $due;
        }
    }

    private function escalate(WorkCase $case, string $target, string $metric): bool
    {
        $queue = WorkQueue::where('tenant_id', $case->tenant_id)->where('code', $target)->where('active', true)->first();
        if (! $queue) {
            // Role targets / notifications arrive with the notification engine; record the unresolved escalation.
            $this->journal->event($case, 'ESCALATION_UNRESOLVED', ['metric' => $metric, 'escalate_to' => $target]);

            return false;
        }
        $from = ['owner_user_id' => $case->owner_user_id, 'queue_id' => $case->queue_id];
        // Gap Closure Pack file 10 escalation reason: an SLA-driven escalation is always SLA_BREACH.
        $case->update(['queue_id' => $queue->id, 'owner_user_id' => null, 'version' => $case->version + 1, 'escalation_reason' => 'SLA_BREACH', 'escalated_at' => now()]);
        $this->journal->event($case, 'ESCALATED', ['metric' => $metric, 'from' => $from, 'queue_id' => $queue->id, 'queue_code' => $queue->code, 'escalation_reason' => 'SLA_BREACH']);
        $this->journal->publish('queue.routed', 'case', $case->id, ['queue_id' => $queue->id, 'reason' => 'SLA_BREACH:'.$metric]);

        return true;
    }

    private function lockCase(string $id): WorkCase
    {
        return WorkCase::withoutGlobalScopes()->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    private function tz(WorkCase $case): string
    {
        return $this->calendar->timezone($case->branch_id, $case->tenant_id);
    }
}
