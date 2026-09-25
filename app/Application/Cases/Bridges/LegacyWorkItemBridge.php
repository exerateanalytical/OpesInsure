<?php

declare(strict_types=1);

namespace App\Application\Cases\Bridges;

use App\Application\Cases\CaseProblem;
use App\Application\Cases\CaseService;
use App\Application\Cases\Models\CaseTask;
use App\Application\Cases\Models\WorkCase;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * REQ-DUP-022 strangler link (ICE §6.2 "Links"): attaches an existing work item
 * to the canonical cases/case_tasks model without moving or dropping it.
 *
 *   underwriting_cases           → UW_REFERRAL case
 *   underwriting_referral_tasks  → case_task on its underwriting case's UW_REFERRAL case
 *   compliance_cases             → COMPLIANCE_INVESTIGATION case
 *   support_tickets (COMPLAINT / REGULATORY_COMPLAINT) → COMPLAINT case
 *
 * Idempotent (cases.source_type/source_id is unique). The legacy row keeps
 * owning its own status; state mirroring is REQ-CAS-002. claim_disputes and
 * renewal_work_items get the case_id column but no automatic type mapping:
 * which of the owner's case types they belong to is OQ-6.4.
 */
final class LegacyWorkItemBridge
{
    public const SOURCES = ['underwriting_cases', 'underwriting_referral_tasks', 'compliance_cases', 'support_tickets'];

    public function __construct(private readonly CaseService $cases) {}

    /** @return array{case: WorkCase, task: ?CaseTask, created: bool} */
    public function link(string $source, string $id, string $tenantId, ?User $actor): array
    {
        return match ($source) {
            'underwriting_cases' => $this->underwritingCase($id, $tenantId, $actor),
            'underwriting_referral_tasks' => $this->referralTask($id, $tenantId, $actor),
            'compliance_cases' => $this->simple('compliance_cases', $id, $tenantId, $actor, 'COMPLIANCE_INVESTIGATION', fn ($r) => [
                'title' => 'Compliance case '.$r->case_number, 'subject_type' => $r->subject_type, 'subject_id' => $r->subject_id, 'owner_user_id' => $r->owner_id,
            ]),
            'support_tickets' => $this->simple('support_tickets', $id, $tenantId, $actor, 'COMPLAINT', function ($r) {
                if (! in_array($r->type, ['COMPLAINT', 'REGULATORY_COMPLAINT'], true)) {
                    throw CaseProblem::make('SOURCE_NOT_BRIDGEABLE', 422, 'Only complaint tickets are bridged to COMPLAINT cases.');
                }

                return ['title' => $r->ticket_number.' '.$r->subject, 'subject_type' => 'support_ticket', 'subject_id' => $r->id, 'owner_user_id' => $r->assigned_to, 'priority' => in_array($r->priority, \App\Application\Cases\CaseTypeCatalogue::PRIORITIES, true) ? $r->priority : 'NORMAL'];
            }),
            default => throw CaseProblem::make('SOURCE_NOT_BRIDGEABLE', 422, "No bridge for {$source}.", ['allowed' => self::SOURCES]),
        };
    }

    /** @return array{case: WorkCase, task: ?CaseTask, created: bool} */
    private function underwritingCase(string $id, string $tenantId, ?User $actor): array
    {
        return $this->simple('underwriting_cases', $id, $tenantId, $actor, 'UW_REFERRAL', fn ($r) => [
            'title' => 'Underwriting referral', 'subject_type' => 'proposal', 'subject_id' => $r->proposal_id, 'owner_user_id' => $r->assigned_to,
            'carrier_id' => $r->carrier_id, 'priority' => in_array($r->priority, \App\Application\Cases\CaseTypeCatalogue::PRIORITIES, true) ? $r->priority : 'NORMAL',
        ]);
    }

    /** @return array{case: WorkCase, task: ?CaseTask, created: bool} */
    private function referralTask(string $id, string $tenantId, ?User $actor): array
    {
        return DB::transaction(function () use ($id, $tenantId, $actor) {
            $r = DB::table('underwriting_referral_tasks as r')->join('underwriting_cases as u', 'u.id', '=', 'r.underwriting_case_id')
                ->where('r.id', $id)->where('u.tenant_id', $tenantId)->lockForUpdate()->first(['r.*']);
            if (! $r) {
                throw CaseProblem::make('SOURCE_NOT_FOUND', 404, 'Referral task not found in this tenant.');
            }
            $parent = $this->underwritingCase($r->underwriting_case_id, $tenantId, $actor)['case'];
            if ($r->case_task_id) {
                return ['case' => $parent, 'task' => CaseTask::find($r->case_task_id), 'created' => false];
            }
            $task = $this->cases->addTask($parent, [
                'title' => 'Referral: '.$r->reason_code, 'template_code' => 'UW_REFERRAL_TASK',
                'assignee_user_id' => $r->assigned_to && $this->isMember($tenantId, $r->assigned_to) ? $r->assigned_to : null, 'due_at' => $r->due_at,
            ], $actor);
            DB::table('underwriting_referral_tasks')->where('id', $r->id)->update(['case_task_id' => $task->id]);

            return ['case' => $parent, 'task' => $task, 'created' => true];
        });
    }

    /** @return array{case: WorkCase, task: null, created: bool} */
    private function simple(string $table, string $id, string $tenantId, ?User $actor, string $type, \Closure $attrs): array
    {
        return DB::transaction(function () use ($table, $id, $tenantId, $actor, $type, $attrs) {
            $row = DB::table($table)->where('id', $id)->where('tenant_id', $tenantId)->lockForUpdate()->first();
            if (! $row) {
                throw CaseProblem::make('SOURCE_NOT_FOUND', 404, "{$table} row not found in this tenant.");
            }
            if ($row->case_id) {
                return ['case' => WorkCase::withoutGlobalScopes()->findOrFail($row->case_id), 'task' => null, 'created' => false];
            }
            $a = $attrs($row);
            if (! empty($a['owner_user_id']) && ! $this->isMember($tenantId, $a['owner_user_id'])) {
                $a['owner_user_id'] = null; // e.g. a carrier underwriter outside this tenant: route via queues instead
            }
            $case = $this->cases->open($tenantId, $type, $a + ['source_type' => $table, 'source_id' => $id], $actor);
            DB::table($table)->where('id', $id)->update(['case_id' => $case->id]);

            return ['case' => $case, 'task' => null, 'created' => true];
        });
    }

    private function isMember(string $tenantId, string $userId): bool
    {
        return DB::table('tenant_memberships')->where('tenant_id', $tenantId)->where('user_id', $userId)->where('status', 'ACTIVE')->exists();
    }
}
