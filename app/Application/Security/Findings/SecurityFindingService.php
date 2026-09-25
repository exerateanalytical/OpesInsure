<?php

declare(strict_types=1);

namespace App\Application\Security\Findings;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Models\SecurityFinding;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REQ-SEC-001 security findings register. Extends the Wave 11 security_findings
 * table (release gate keeps reading status != RESOLVED) with a lifecycle:
 *
 *   OPEN → TRIAGED → IN_REMEDIATION → RESOLVED
 *   OPEN|TRIAGED|IN_REMEDIATION → RISK_ACCEPTED (second person, expiry required) | FALSE_POSITIVE
 *   RESOLVED|RISK_ACCEPTED|FALSE_POSITIVE → OPEN (reopen)
 *
 * Every transition is appended to security_finding_events, audited and emitted.
 */
final class SecurityFindingService
{
    public const SEVERITIES = ['CRITICAL', 'HIGH', 'MEDIUM', 'LOW', 'INFO'];

    public const SOURCES = ['PENTEST', 'SAST', 'DAST', 'DEPENDENCY_SCAN', 'BUG_BOUNTY', 'INTERNAL_REVIEW', 'INCIDENT', 'AUDIT', 'MOBILE_MASVS'];

    private const TRANSITIONS = [
        'OPEN' => ['TRIAGED', 'IN_REMEDIATION', 'RISK_ACCEPTED', 'FALSE_POSITIVE'],
        'TRIAGED' => ['IN_REMEDIATION', 'RISK_ACCEPTED', 'FALSE_POSITIVE'],
        'IN_REMEDIATION' => ['RESOLVED', 'RISK_ACCEPTED'],
        'RESOLVED' => ['OPEN'],
        'RISK_ACCEPTED' => ['OPEN'],
        'FALSE_POSITIVE' => ['OPEN'],
    ];

    public function __construct(private readonly AuditWriter $audit, private readonly OutboxWriter $outbox) {}

    public function report(?string $tenantId, array $d, User $actor): SecurityFinding
    {
        return DB::transaction(function () use ($tenantId, $d, $actor) {
            $f = SecurityFinding::query()->create([
                'source' => $d['source'], 'severity' => $d['severity'], 'title' => $d['title'], 'description' => $d['description'],
                'status' => 'OPEN', 'cve' => $d['cve'] ?? null, 'owner_id' => $d['owner_id'] ?? null, 'due_at' => $d['due_at'] ?? null,
                'release_candidate_id' => $d['release_candidate_id'] ?? null,
            ]);
            DB::table('security_findings')->where('id', $f->id)->update([
                'tenant_id' => $tenantId, 'reference' => 'SF-'.strtoupper(Str::random(8)), 'category' => $d['category'] ?? null,
                'affected_asset' => $d['affected_asset'] ?? null, 'reported_by' => $actor->id,
            ]);
            $this->event($f->id, null, 'OPEN', $actor, $d['notes'] ?? null);
            $this->audit->record('security.finding.reported', 'security_finding', $f->id, ['severity' => $f->severity, 'source' => $f->source]);
            $this->outbox->record('security.finding.status_changed', 'security_finding', $f->id, ['finding_id' => $f->id, 'from' => null, 'to' => 'OPEN', 'severity' => $f->severity]);

            return $f->refresh();
        });
    }

    public function transition(SecurityFinding $f, string $to, User $actor, array $d = []): SecurityFinding
    {
        $from = $f->status;
        if (! in_array($to, self::TRANSITIONS[$from] ?? [], true)) {
            throw ValidationException::withMessages(['status' => "A finding cannot move from {$from} to {$to}."]);
        }
        $row = DB::table('security_findings')->where('id', $f->id)->first();
        $update = ['status' => $to, 'updated_at' => now()];
        if ($to === 'RISK_ACCEPTED') {
            if (empty($d['notes']) || empty($d['risk_acceptance_expires_at'])) {
                throw ValidationException::withMessages(['risk_acceptance_expires_at' => 'Risk acceptance needs a justification and an expiry date.']);
            }
            if ($row->reported_by === $actor->id || $f->owner_id === $actor->id) {
                throw ValidationException::withMessages(['status' => __('wave9.maker_checker')]);
            }
            $expires = Carbon::parse($d['risk_acceptance_expires_at']);
            if ($expires <= now()) {
                throw ValidationException::withMessages(['risk_acceptance_expires_at' => 'Risk acceptance expiry must be in the future.']);
            }
            $update += ['risk_accepted_by' => $actor->id, 'risk_acceptance_expires_at' => $expires];
        }
        if ($to === 'IN_REMEDIATION' && ! empty($d['remediation_plan'])) {
            $update['remediation_plan'] = $d['remediation_plan'];
        }
        if (in_array($to, ['RESOLVED', 'FALSE_POSITIVE'], true)) {
            if (empty($d['notes'])) {
                throw ValidationException::withMessages(['notes' => 'Resolution notes are required.']);
            }
            $update += ['resolved_at' => now(), 'resolution_notes' => $d['notes']];
        }
        if ($to === 'OPEN') {
            $update += ['resolved_at' => null, 'risk_accepted_by' => null, 'risk_acceptance_expires_at' => null];
        }

        return DB::transaction(function () use ($f, $from, $to, $update, $actor, $d) {
            $n = DB::table('security_findings')->where('id', $f->id)->where('status', $from)->update($update);
            if ($n !== 1) {
                throw ValidationException::withMessages(['status' => 'The finding changed concurrently; reload and retry.']);
            }
            $this->event($f->id, $from, $to, $actor, $d['notes'] ?? null);
            $this->audit->record('security.finding.transitioned', 'security_finding', $f->id, ['from' => $from, 'to' => $to]);
            $this->outbox->record('security.finding.status_changed', 'security_finding', $f->id, ['finding_id' => $f->id, 'from' => $from, 'to' => $to, 'severity' => $f->severity]);

            return $f->refresh();
        });
    }

    /** Risk acceptances past their expiry go back to OPEN. Returns the count. */
    public function reopenExpiredAcceptances(): int
    {
        $n = 0;
        DB::table('security_findings')->where('status', 'RISK_ACCEPTED')->where('risk_acceptance_expires_at', '<=', now())->pluck('id')->each(function ($id) use (&$n) {
            if (DB::table('security_findings')->where('id', $id)->where('status', 'RISK_ACCEPTED')->update(['status' => 'OPEN', 'updated_at' => now()]) === 1) {
                $this->event($id, 'RISK_ACCEPTED', 'OPEN', null, 'RISK_ACCEPTANCE_EXPIRED');
                $this->outbox->record('security.finding.status_changed', 'security_finding', $id, ['finding_id' => $id, 'from' => 'RISK_ACCEPTED', 'to' => 'OPEN', 'reason' => 'RISK_ACCEPTANCE_EXPIRED']);
                $n++;
            }
        });

        return $n;
    }

    private function event(string $id, ?string $from, string $to, ?User $actor, ?string $notes): void
    {
        DB::table('security_finding_events')->insert(['id' => (string) Str::uuid(), 'security_finding_id' => $id, 'from_status' => $from, 'to_status' => $to, 'actor_id' => $actor?->id, 'notes' => $notes, 'occurred_at' => now()]);
    }
}
