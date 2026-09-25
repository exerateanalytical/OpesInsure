<?php

declare(strict_types=1);

namespace App\Application\Compliance\Cases;

use App\Application\Audit\AuditWriter;
use App\Application\Cases\Bridges\LegacyWorkItemBridge;
use App\Application\Cases\CaseService;
use App\Application\Cases\Models\CaseTask;
use App\Application\Cases\Models\WorkCase;
use App\Application\Events\OutboxWriter;
use App\Models\ComplianceCase;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REQ-CMP-001 compliance cases / investigations (CMP-001..020).
 *
 * compliance_cases is the regulatory record (subject, severity, closure, own event log); the workflow
 * container (queue, owner, SLA, tasks) is the COMPLIANCE_INVESTIGATION case on the case engine, linked
 * once through LegacyWorkItemBridge and driven from here in the same transaction — callers never move
 * the work case directly. Findings, corrective action plans (owner, due date, maker-checker
 * verification) and evidence links hang off the compliance case; each corrective action is also a
 * case_task so it shows up in My Work.
 */
final class ComplianceCaseService
{
    public const SEVERITIES = ['LOW', 'MEDIUM', 'HIGH', 'CRITICAL'];

    public const STATUSES = ['OPEN', 'UNDER_REVIEW', 'REMEDIATION', 'CLOSED', 'REOPENED'];

    private const TRANSITIONS = [
        'OPEN' => ['UNDER_REVIEW'], 'UNDER_REVIEW' => ['REMEDIATION', 'CLOSED'], 'REMEDIATION' => ['UNDER_REVIEW', 'CLOSED'],
        'CLOSED' => ['REOPENED'], 'REOPENED' => ['UNDER_REVIEW'],
    ];

    /** compliance status => case-engine events to try (first available wins); none = the work case stays where it is. */
    private const CASE_EVENTS = [
        'UNDER_REVIEW' => ['start', 'info_received'], 'REMEDIATION' => [], 'CLOSED' => ['resolve'], 'REOPENED' => ['reopen'],
    ];

    private const SEVERITY_PRIORITY = ['LOW' => 'LOW', 'MEDIUM' => 'NORMAL', 'HIGH' => 'HIGH', 'CRITICAL' => 'CRITICAL'];

    public function __construct(
        private readonly AuditWriter $audit,
        private readonly OutboxWriter $outbox,
        private readonly CaseService $cases,
        private readonly LegacyWorkItemBridge $bridge,
    ) {}

    public function open(string $tenant, array $d, User $u): ComplianceCase
    {
        $hash = hash('sha256', json_encode($d, JSON_THROW_ON_ERROR));
        if ($x = ComplianceCase::where(['tenant_id' => $tenant, 'idempotency_key' => $d['idempotency_key']])->first()) {
            if (! hash_equals((string) $x->payload_hash, $hash)) {
                throw ValidationException::withMessages(['idempotency_key' => __('wave9.idempotency_conflict')]);
            }

            return $x;
        }
        if (! empty($d['owner_id'])) {
            $this->assertMember($tenant, $d['owner_id'], 'owner_id');
        }

        return DB::transaction(function () use ($tenant, $d, $u, $hash) {
            $findings = $d['findings'] ?? [];
            unset($d['findings']);
            $x = ComplianceCase::create([...$d, 'tenant_id' => $tenant, 'case_number' => 'CMP-'.now()->format('Ym').'-'.strtoupper(Str::random(8)),
                'status' => 'OPEN', 'payload_hash' => $hash, 'opened_by' => $u->id, 'findings' => []]);
            $this->event($x, null, 'OPEN', 'CASE_OPENED', $u);
            $work = $this->workCase($x, $u);
            if (isset(self::SEVERITY_PRIORITY[$x->severity]) && $work->priority !== self::SEVERITY_PRIORITY[$x->severity]) {
                $work->update(['priority' => self::SEVERITY_PRIORITY[$x->severity]]);
            }
            foreach ($this->normaliseFindings($findings) as $f) {
                $this->insertFinding($x, $f, $u);
            }
            $this->audit->record('compliance.case.opened', 'compliance_case', $x->id, ['case_id' => $work->id]);
            $this->outbox->record('compliance.case.opened', 'compliance_case', $x->id, ['case_id' => $x->id, 'work_case_id' => $work->id, 'severity' => $x->severity]);

            return $x->refresh();
        });
    }

    /** @param array<array-key, mixed> $findings legacy payload: new findings to record with this transition */
    public function transition(ComplianceCase $x, string $to, string $reason, array $findings, User $u): ComplianceCase
    {
        return DB::transaction(function () use ($x, $to, $reason, $findings, $u) {
            $x = ComplianceCase::whereKey($x->id)->lockForUpdate()->firstOrFail();
            if (! in_array($to, self::TRANSITIONS[$x->status] ?? [], true) || ($to === 'CLOSED' && $x->opened_by === $u->id)) {
                throw ValidationException::withMessages(['status' => __('wave9.transition_invalid')]);
            }
            foreach ($this->normaliseFindings($findings) as $f) {
                $this->insertFinding($x, $f, $u);
            }
            if ($to === 'CLOSED' && DB::table('compliance_findings')->where('compliance_case_id', $x->id)->whereIn('status', ['OPEN', 'REMEDIATING'])->exists()) {
                throw ValidationException::withMessages(['status' => 'Close or withdraw every finding before closing the case.']);
            }
            $from = $x->status;
            $x->update(['status' => $to, 'closed_at' => $to === 'CLOSED' ? now() : null, 'closed_by' => $to === 'CLOSED' ? $u->id : null,
                'closure_code' => $to === 'CLOSED' ? $reason : null, 'version' => $x->version + 1]);
            $this->event($x, $from, $to, $reason, $u);
            $this->drive($this->workCase($x, $u), self::CASE_EVENTS[$to] ?? [], $u, $reason);
            $this->audit->record('compliance.case.transitioned', 'compliance_case', $x->id, ['from' => $from, 'to' => $to], $reason);
            $this->outbox->record('compliance.case.transitioned', 'compliance_case', $x->id, ['case_id' => $x->id, 'from' => $from, 'to' => $to]);

            return $x->refresh();
        });
    }

    /** @param array{title: string, description?: ?string, severity: string, category?: ?string} $d */
    public function addFinding(ComplianceCase $x, array $d, User $u): object
    {
        return DB::transaction(function () use ($x, $d, $u) {
            $x = ComplianceCase::whereKey($x->id)->lockForUpdate()->firstOrFail();
            if ($x->status === 'CLOSED') {
                throw ValidationException::withMessages(['status' => 'Reopen the compliance case before adding findings.']);
            }
            $id = $this->insertFinding($x, $d, $u);
            $this->audit->record('compliance.finding.recorded', 'compliance_finding', $id, ['compliance_case_id' => $x->id, 'severity' => $d['severity']]);
            $this->outbox->record('compliance.finding.recorded', 'compliance_finding', $id, ['compliance_case_id' => $x->id, 'severity' => $d['severity']]);

            return DB::table('compliance_findings')->where('id', $id)->first();
        });
    }

    public function withdrawFinding(string $findingId, string $tenant, string $reason, User $u): object
    {
        return DB::transaction(function () use ($findingId, $tenant, $reason, $u) {
            $f = $this->lockFinding($findingId, $tenant);
            if ($f->status === 'CLOSED' || $f->status === 'WITHDRAWN') {
                throw ValidationException::withMessages(['status' => __('wave9.transition_invalid')]);
            }
            DB::table('compliance_corrective_actions')->where('finding_id', $f->id)->whereIn('status', ['PLANNED', 'IN_PROGRESS', 'COMPLETED'])
                ->update(['status' => 'CANCELLED', 'updated_at' => now()]);
            DB::table('compliance_findings')->where('id', $f->id)->update(['status' => 'WITHDRAWN', 'closed_at' => now(), 'version' => $f->version + 1, 'updated_at' => now()]);
            $this->audit->record('compliance.finding.withdrawn', 'compliance_finding', $f->id, [], $reason);

            return DB::table('compliance_findings')->where('id', $f->id)->first();
        });
    }

    /** @param array{description: string, owner_user_id: string, due_on: string} $d */
    public function planAction(string $findingId, string $tenant, array $d, User $u): object
    {
        $this->assertMember($tenant, $d['owner_user_id'], 'owner_user_id');

        return DB::transaction(function () use ($findingId, $tenant, $d, $u) {
            $f = $this->lockFinding($findingId, $tenant);
            if (! in_array($f->status, ['OPEN', 'REMEDIATING'], true)) {
                throw ValidationException::withMessages(['finding' => 'Corrective actions can only be planned for an open finding.']);
            }
            $case = ComplianceCase::findOrFail($f->compliance_case_id);
            $work = $this->workCase($case, $u);
            $task = $work->closed_at === null ? $this->cases->addTask($work, [
                'title' => 'Corrective action: '.Str::limit($d['description'], 120), 'template_code' => 'COMPLIANCE_CORRECTIVE_ACTION',
                'assignee_user_id' => $d['owner_user_id'], 'due_at' => $d['due_on'].' 23:59:59',
            ], $u) : null;
            $id = (string) Str::uuid();
            DB::table('compliance_corrective_actions')->insert([
                'id' => $id, 'tenant_id' => $tenant, 'compliance_case_id' => $f->compliance_case_id, 'finding_id' => $f->id,
                'description' => $d['description'], 'owner_user_id' => $d['owner_user_id'], 'due_on' => $d['due_on'], 'status' => 'PLANNED',
                'case_task_id' => $task?->id, 'created_by' => $u->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            if ($f->status === 'OPEN') {
                DB::table('compliance_findings')->where('id', $f->id)->update(['status' => 'REMEDIATING', 'version' => $f->version + 1, 'updated_at' => now()]);
            }
            $this->audit->record('compliance.corrective_action.planned', 'compliance_corrective_action', $id, ['finding_id' => $f->id, 'owner_user_id' => $d['owner_user_id'], 'due_on' => $d['due_on']]);
            $this->outbox->record('compliance.corrective_action.planned', 'compliance_corrective_action', $id, ['finding_id' => $f->id, 'owner_user_id' => $d['owner_user_id'], 'due_on' => $d['due_on']]);

            return DB::table('compliance_corrective_actions')->where('id', $id)->first();
        });
    }

    /**
     * start (owner) → complete (owner, notes) → verify (someone other than the completer; accepted → VERIFIED and the
     * finding closes once all its live actions are verified; rejected → back to IN_PROGRESS). cancel needs a reason.
     */
    public function actOnAction(string $actionId, string $tenant, string $event, array $d, User $u): object
    {
        return DB::transaction(function () use ($actionId, $tenant, $event, $d, $u) {
            $a = DB::table('compliance_corrective_actions')->where('id', $actionId)->where('tenant_id', $tenant)->lockForUpdate()->first();
            if (! $a) {
                abort(404);
            }
            $bad = fn () => throw ValidationException::withMessages(['status' => __('wave9.transition_invalid')]);
            $upd = ['version' => $a->version + 1, 'updated_at' => now()];
            $task = null;
            switch ($event) {
                case 'start':
                    $a->status === 'PLANNED' || $bad();
                    $upd['status'] = 'IN_PROGRESS';
                    $task = 'IN_PROGRESS';
                    break;
                case 'complete':
                    in_array($a->status, ['PLANNED', 'IN_PROGRESS'], true) || $bad();
                    $upd += ['status' => 'COMPLETED', 'completed_by' => $u->id, 'completed_at' => now(), 'completion_notes' => $d['notes'] ?? null];
                    break;
                case 'verify':
                    $a->status === 'COMPLETED' || $bad();
                    if ($a->completed_by === $u->id) {
                        throw ValidationException::withMessages(['status' => __('wave9.maker_checker')]);
                    }
                    if (! empty($d['accepted'])) {
                        $upd += ['status' => 'VERIFIED', 'verified_by' => $u->id, 'verified_at' => now(), 'verification_notes' => $d['notes'] ?? null];
                        $task = 'DONE';
                    } else {
                        $upd += ['status' => 'IN_PROGRESS', 'verification_notes' => $d['notes'] ?? null, 'completed_by' => null, 'completed_at' => null];
                    }
                    break;
                case 'cancel':
                    in_array($a->status, ['PLANNED', 'IN_PROGRESS', 'COMPLETED'], true) || $bad();
                    trim((string) ($d['notes'] ?? '')) !== '' || throw ValidationException::withMessages(['notes' => 'A reason is required to cancel a corrective action.']);
                    $upd['status'] = 'CANCELLED';
                    $task = 'CANCELLED';
                    break;
                default:
                    $bad();
            }
            DB::table('compliance_corrective_actions')->where('id', $a->id)->update($upd);
            if ($task && $a->case_task_id && ($t = CaseTask::find($a->case_task_id)) && in_array($t->status, CaseTask::OPEN_STATES, true) && $t->status !== $task) {
                $this->cases->transitionTask($t, $task, $u, ['corrective_action_id' => $a->id]);
            }
            $this->closeFindingIfDone($a->finding_id);
            $status = $upd['status'];
            $this->audit->record('compliance.corrective_action.'.strtolower($event), 'compliance_corrective_action', $a->id, ['from' => $a->status, 'to' => $status], $d['notes'] ?? null);
            if ($status === 'VERIFIED') {
                $this->outbox->record('compliance.corrective_action.verified', 'compliance_corrective_action', $a->id, ['finding_id' => $a->finding_id, 'verified_by' => $u->id]);
            }

            return DB::table('compliance_corrective_actions')->where('id', $a->id)->first();
        });
    }

    /** @param array{description: string, document_id?: ?string, external_reference?: ?string, finding_id?: ?string, corrective_action_id?: ?string} $d */
    public function linkEvidence(ComplianceCase $x, array $d, User $u): object
    {
        $sha = null;
        if (! empty($d['document_id'])) {
            $doc = DB::table('documents')->where('id', $d['document_id'])->where('tenant_id', $x->tenant_id)->first();
            if (! $doc) {
                throw ValidationException::withMessages(['document_id' => 'Document not found in this tenant.']);
            }
            $sha = $doc->sha256;
        } elseif (empty($d['external_reference'])) {
            throw ValidationException::withMessages(['document_id' => 'Either a document or an external reference is required.']);
        }
        foreach (['finding_id' => 'compliance_findings', 'corrective_action_id' => 'compliance_corrective_actions'] as $key => $table) {
            if (! empty($d[$key]) && ! DB::table($table)->where('id', $d[$key])->where('compliance_case_id', $x->id)->exists()) {
                throw ValidationException::withMessages([$key => 'Not part of this compliance case.']);
            }
        }
        $id = (string) Str::uuid();
        DB::table('compliance_evidence_links')->insert([
            'id' => $id, 'tenant_id' => $x->tenant_id, 'compliance_case_id' => $x->id, 'finding_id' => $d['finding_id'] ?? null,
            'corrective_action_id' => $d['corrective_action_id'] ?? null, 'document_id' => $d['document_id'] ?? null,
            'external_reference' => $d['external_reference'] ?? null, 'sha256' => $sha, 'description' => $d['description'],
            'linked_by' => $u->id, 'linked_at' => now(),
        ]);
        $this->audit->record('compliance.evidence.linked', 'compliance_case', $x->id, ['evidence_link_id' => $id, 'document_id' => $d['document_id'] ?? null]);

        return DB::table('compliance_evidence_links')->where('id', $id)->first();
    }

    /** @return array<string, mixed> */
    public function show(ComplianceCase $x): array
    {
        $work = $x->case_id ? WorkCase::withoutGlobalScopes()->find($x->case_id) : null;

        return [
            'case' => $x->toArray(),
            'work_case' => $work ? ['id' => $work->id, 'case_number' => $work->case_number, 'status' => $work->status, 'owner_user_id' => $work->owner_user_id, 'queue_id' => $work->queue_id] : null,
            'findings' => DB::table('compliance_findings')->where('compliance_case_id', $x->id)->orderBy('created_at')->get(),
            'corrective_actions' => DB::table('compliance_corrective_actions')->where('compliance_case_id', $x->id)->orderBy('due_on')->get(),
            'evidence' => DB::table('compliance_evidence_links')->where('compliance_case_id', $x->id)->orderBy('linked_at')->get(),
            'events' => DB::table('compliance_case_events')->where('compliance_case_id', $x->id)->orderBy('occurred_at')->get(),
        ];
    }

    private function workCase(ComplianceCase $x, User $u): WorkCase
    {
        return $this->bridge->link('compliance_cases', $x->id, (string) $x->tenant_id, $u)['case'];
    }

    /** @param list<string> $events */
    private function drive(WorkCase $work, array $events, User $u, string $reason): void
    {
        if ($events === []) {
            return;
        }
        $available = $this->cases->availableEvents($work, $u);
        foreach ($events as $e) {
            if (in_array($e, $available, true)) {
                $this->cases->transition($work, $e, $u, $reason, ['source' => 'compliance_case']);

                return;
            }
        }
    }

    private function closeFindingIfDone(string $findingId): void
    {
        $live = DB::table('compliance_corrective_actions')->where('finding_id', $findingId)->where('status', '!=', 'CANCELLED');
        if ((clone $live)->exists() && ! (clone $live)->where('status', '!=', 'VERIFIED')->exists()) {
            DB::table('compliance_findings')->where('id', $findingId)->where('status', 'REMEDIATING')->update(['status' => 'CLOSED', 'closed_at' => now(), 'updated_at' => now()]);
        }
    }

    private function lockFinding(string $id, string $tenant): object
    {
        $f = DB::table('compliance_findings')->where('id', $id)->where('tenant_id', $tenant)->lockForUpdate()->first();
        if (! $f) {
            abort(404);
        }

        return $f;
    }

    private function insertFinding(ComplianceCase $x, array $f, User $u): string
    {
        if (! in_array($f['severity'] ?? null, self::SEVERITIES, true)) {
            throw ValidationException::withMessages(['severity' => 'Finding severity must be one of '.implode(', ', self::SEVERITIES).'.']);
        }
        $id = (string) Str::uuid();
        $n = DB::table('compliance_findings')->where('compliance_case_id', $x->id)->count() + 1;
        DB::table('compliance_findings')->insert([
            'id' => $id, 'tenant_id' => $x->tenant_id, 'compliance_case_id' => $x->id, 'finding_number' => $x->case_number.'-F'.$n,
            'title' => Str::limit((string) $f['title'], 250), 'description' => $f['description'] ?? null, 'severity' => $f['severity'],
            'category' => $f['category'] ?? null, 'status' => 'OPEN', 'raised_by' => $u->id, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * Legacy `findings` payloads were free-form (a list of structured findings, or Filament key => value pairs).
     *
     * @return list<array<string, mixed>>
     */
    private function normaliseFindings(array $findings): array
    {
        $out = [];
        foreach ($findings as $k => $v) {
            if (is_array($v) && isset($v['title'])) {
                $out[] = $v + ['severity' => 'MEDIUM'];
            } elseif ($v !== null && $v !== '') {
                $out[] = ['title' => is_string($k) ? $k.': '.(is_scalar($v) ? $v : json_encode($v)) : (is_scalar($v) ? (string) $v : json_encode($v)), 'severity' => 'MEDIUM', 'category' => 'UNCLASSIFIED'];
            }
        }

        return $out;
    }

    private function assertMember(string $tenant, string $userId, string $field): void
    {
        if (! DB::table('tenant_memberships')->where('tenant_id', $tenant)->where('user_id', $userId)->where('status', 'ACTIVE')->exists()) {
            throw ValidationException::withMessages([$field => 'The user is not an active member of this tenant.']);
        }
    }

    private function event(ComplianceCase $x, ?string $f, string $t, string $r, User $u): void
    {
        DB::table('compliance_case_events')->insert(['id' => (string) Str::uuid(), 'compliance_case_id' => $x->id, 'from_status' => $f, 'to_status' => $t,
            'reason_code' => $r, 'actor_id' => $u->id, 'metadata' => '{}', 'occurred_at' => now()]);
    }
}
