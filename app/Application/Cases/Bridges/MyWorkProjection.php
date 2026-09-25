<?php

declare(strict_types=1);

namespace App\Application\Cases\Bridges;

use App\Application\Cases\Models\CaseTask;
use App\Application\Cases\Models\WorkCase;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * REQ-CAS-001 "My Work" (ICE ENG-6-001) + REQ-DUP-022 projections.
 *
 * One list over the canonical model (case tasks, owned cases, diary follow-ups)
 * and, read-only, the legacy work-item tables that have not been bridged yet
 * (rows whose case_id / case_task_id is set are shown once, via the case).
 * The legacy tables stay the source of truth for their own states until
 * REQ-CAS-002 moves each flow onto a bridge.
 */
final class MyWorkProjection
{
    /** @return list<array<string, mixed>> */
    public function for(User $user, string $tenantId): array
    {
        $items = [];

        foreach (CaseTask::query()->join('cases', 'cases.id', '=', 'case_tasks.case_id')
            ->whereIn('cases.id', WorkCase::query()->where('tenant_id', $tenantId)->select('id')) // confidentiality scope applies
            ->where('case_tasks.assignee_user_id', $user->id)->whereIn('case_tasks.status', CaseTask::OPEN_STATES)
            ->get(['case_tasks.*', 'cases.case_number']) as $t) {
            $items[] = $this->item('TASK', 'case_tasks', $t->id, $t->title, $t->status, $t->due_at, $t->case_id, ['case_number' => $t->case_number]);
        }

        foreach (WorkCase::query()->where('tenant_id', $tenantId)->where('owner_user_id', $user->id)->whereNull('closed_at')->get() as $c) {
            $items[] = $this->item('CASE', 'cases', $c->id, $c->title, $c->status, $c->due_at, $c->id, ['case_number' => $c->case_number, 'case_type' => $c->case_type_code, 'priority' => $c->priority]);
        }

        foreach (DB::table('diary_entries')->where('tenant_id', $tenantId)->where('author_id', $user->id)->whereNotNull('follow_up_at')
            ->whereIn('case_id', WorkCase::query()->where('tenant_id', $tenantId)->whereNull('closed_at')->select('id'))->get() as $d) {
            $items[] = $this->item('FOLLOW_UP', 'diary_entries', $d->id, mb_strimwidth($d->body, 0, 120, '…'), $d->follow_up_notified_at ? 'DUE' : 'SCHEDULED', $d->follow_up_at, $d->case_id);
        }

        // --- legacy projections (not yet bridged) ---
        foreach (DB::table('underwriting_referral_tasks as r')->join('underwriting_cases as u', 'u.id', '=', 'r.underwriting_case_id')
            ->where('u.tenant_id', $tenantId)->where('r.assigned_to', $user->id)->where('r.status', 'OPEN')->whereNull('r.case_task_id')
            ->get(['r.id', 'r.reason_code', 'r.status', 'r.due_at', 'u.case_id']) as $r) {
            $items[] = $this->item('LEGACY_TASK', 'underwriting_referral_tasks', $r->id, 'Underwriting referral: '.$r->reason_code, $r->status, $r->due_at, $r->case_id);
        }
        foreach (DB::table('underwriting_cases')->where('tenant_id', $tenantId)->where('assigned_to', $user->id)->whereIn('status', ['QUEUED', 'IN_REVIEW', 'AWAITING_INFORMATION'])->whereNull('case_id')->get() as $u) {
            $items[] = $this->item('LEGACY_CASE', 'underwriting_cases', $u->id, 'Underwriting case', $u->status, $u->decision_due_at, null, ['priority' => $u->priority]);
        }
        foreach (DB::table('compliance_cases')->where('tenant_id', $tenantId)->where('owner_id', $user->id)->where('status', '!=', 'CLOSED')->whereNull('case_id')->get() as $c) {
            $items[] = $this->item('LEGACY_CASE', 'compliance_cases', $c->id, 'Compliance case '.$c->case_number, $c->status, $c->review_due_on, null, ['type' => $c->type]);
        }
        foreach (DB::table('support_tickets')->where('tenant_id', $tenantId)->where('assigned_to', $user->id)->whereNotIn('status', ['RESOLVED', 'CLOSED', 'CANCELLED'])->whereNull('case_id')->get() as $s) {
            $items[] = $this->item('LEGACY_CASE', 'support_tickets', $s->id, $s->ticket_number.' '.$s->subject, $s->status, $s->sla_due_at, null, ['priority' => $s->priority]);
        }
        foreach (DB::table('renewal_work_items')->where('tenant_id', $tenantId)->where('assigned_to', $user->id)->whereNull('outcome')->whereNull('case_id')->get() as $w) {
            $items[] = $this->item('LEGACY_TASK', 'renewal_work_items', $w->id, 'Renewal follow-up', $w->status, $w->renewal_due_on, null);
        }

        usort($items, fn ($a, $b) => [$a['due_at'] === null, $a['due_at']] <=> [$b['due_at'] === null, $b['due_at']]);

        return $items;
    }

    private function item(string $kind, string $source, string $id, string $title, string $status, mixed $due, ?string $caseId, array $extra = []): array
    {
        $dueIso = $due === null ? null : \Carbon\CarbonImmutable::parse($due)->utc()->toIso8601ZuluString();

        return ['kind' => $kind, 'source' => $source, 'id' => $id, 'title' => $title, 'status' => $status, 'due_at' => $dueIso, 'case_id' => $caseId] + $extra;
    }
}
