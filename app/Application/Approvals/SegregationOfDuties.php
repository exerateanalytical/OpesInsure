<?php

declare(strict_types=1);

namespace App\Application\Approvals;

use App\Models\ApprovalRequest;
use Illuminate\Support\Facades\DB;

/**
 * REQ-RBAC-006 segregation of duties. Returns the reasons a user may not act as checker on a request:
 * maker = checker, a named subject party (e.g. the grantee of privileged access), an earlier checker of the
 * same request (multi-level needs distinct people), and sod_conflict_rules pairs on the same subject.
 */
final class SegregationOfDuties
{
    /** @return list<string> */
    public function violations(ApprovalRequest $request, string $userId): array
    {
        $v = [];
        if ($request->requested_by === $userId) {
            $v[] = 'Maker-checker: the requester cannot decide their own request.';
        }
        if (in_array($userId, $request->excluded_user_ids ?? [], true)) {
            $v[] = 'Segregation of duties: a party to the subject cannot decide this request.';
        }
        if (DB::table('approval_decisions')->where('approval_request_id', $request->id)->where('decided_by', $userId)->exists()) {
            $v[] = 'Segregation of duties: each approval level needs a different checker.';
        }
        if ($request->subject_id !== null) {
            foreach ($this->conflictingActions($request->action_code) as $first) {
                if ($this->actedOn($userId, $first, $request->subject_type, $request->subject_id, $request->id)) {
                    $v[] = "Segregation of duties: you already acted on {$first} for this subject.";
                }
            }
        }

        return $v;
    }

    /** Standalone SoD check for domain code that does not (yet) route through approval_requests. */
    public function assertMayPerform(string $userId, string $action, string $subjectType, string $subjectId): void
    {
        foreach ($this->conflictingActions($action) as $first) {
            if ($this->actedOn($userId, $first, $subjectType, $subjectId, null)) {
                throw \Illuminate\Validation\ValidationException::withMessages(['actor' => "Segregation of duties: you already acted on {$first} for this subject."]);
            }
        }
    }

    /** @return list<string> */
    private function conflictingActions(string $action): array
    {
        return DB::table('sod_conflict_rules')->where('status', 'ACTIVE')->where('second_action', $action)->pluck('first_action')->all();
    }

    private function actedOn(string $userId, string $action, string $subjectType, string $subjectId, ?string $exceptRequest): bool
    {
        $ids = DB::table('approval_requests')->where('action_code', $action)->where('subject_type', $subjectType)->where('subject_id', $subjectId)
            ->when($exceptRequest, fn ($q) => $q->where('id', '<>', $exceptRequest));

        return (clone $ids)->where('requested_by', $userId)->exists()
            || DB::table('approval_decisions')->whereIn('approval_request_id', (clone $ids)->select('id'))->where('decided_by', $userId)->exists();
    }
}
