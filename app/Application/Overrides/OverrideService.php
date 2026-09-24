<?php

declare(strict_types=1);

namespace App\Application\Overrides;

use App\Application\Audit\AuditWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REQ-OVR-001: the single controlled human-override path (engine_overrides; ICE §0.4, AOM "controlled override").
 * Captures previous/new value, reason, requester, authority, time; maker-checker approval unless the
 * requester acts inside a recorded delegated-authority grant. Every step is written to the audit chain.
 * Callers must apply the new value only once isEffective() is true — no silent edits.
 */
final class OverrideService
{
    public function __construct(private readonly AuditWriter $audit) {}

    /**
     * @param  array{override_type: string, subject_type: string, subject_id?: string|null, field?: string|null,
     *               engine_evaluation_id?: string|null, previous_outcome?: string|null, new_outcome?: string|null,
     *               previous_value?: mixed, new_value?: mixed, reason_code: string, justification: string,
     *               authority_grant_id?: string|null, tenant_id?: string|null}  $input
     */
    public function request(string $requestedBy, array $input): object
    {
        foreach (['override_type', 'subject_type', 'reason_code', 'justification'] as $k) {
            if (blank($input[$k] ?? null)) {
                throw ValidationException::withMessages([$k => "{$k} is required for an override."]);
            }
        }
        if (mb_strlen(trim($input['justification'])) < 10) {
            throw ValidationException::withMessages(['justification' => 'Justification must be at least 10 characters.']);
        }
        $hasOutcome = array_key_exists('new_outcome', $input) && $input['new_outcome'] !== null;
        $hasValue = array_key_exists('new_value', $input);
        if (! $hasOutcome && ! $hasValue) {
            throw ValidationException::withMessages(['new_value' => 'An override must state the new outcome or value.']);
        }

        $id = (string) Str::uuid();
        $selfAuthorised = ! blank($input['authority_grant_id'] ?? null);
        $now = now();

        DB::transaction(function () use ($id, $requestedBy, $input, $selfAuthorised, $now) {
            if (! empty($input['engine_evaluation_id'])) {
                $eval = DB::table('engine_evaluations')->where('id', $input['engine_evaluation_id'])->first();
                if (! $eval) {
                    throw ValidationException::withMessages(['engine_evaluation_id' => 'Unknown engine evaluation.']);
                }
                $input['previous_outcome'] ??= $eval->outcome;
            }
            DB::table('engine_overrides')->insert([
                'id' => $id,
                'tenant_id' => $input['tenant_id'] ?? null,
                'engine_evaluation_id' => $input['engine_evaluation_id'] ?? null,
                'override_type' => $input['override_type'],
                'subject_type' => $input['subject_type'],
                'subject_id' => $input['subject_id'] ?? null,
                'field' => $input['field'] ?? null,
                'previous_outcome' => $input['previous_outcome'] ?? null,
                'new_outcome' => $input['new_outcome'] ?? null,
                'previous_value' => array_key_exists('previous_value', $input) ? json_encode($input['previous_value'], JSON_THROW_ON_ERROR) : null,
                'new_value' => array_key_exists('new_value', $input) ? json_encode($input['new_value'], JSON_THROW_ON_ERROR) : null,
                'reason_code' => $input['reason_code'],
                'justification' => trim($input['justification']),
                'requested_by' => $requestedBy,
                'authority_grant_id' => $input['authority_grant_id'] ?? null,
                'status' => $selfAuthorised ? 'AUTO_APPROVED' : 'REQUESTED',
                'approved_at' => $selfAuthorised ? $now : null,
                'correlation_id' => substr((string) (rescue(fn () => request()->header('X-Request-Id'), null, false) ?: Str::uuid()), 0, 64),
                'created_at' => $now,
            ]);
            $this->audit->record($selfAuthorised ? 'override.auto_approved' : 'override.requested', $input['subject_type'], $input['subject_id'] ?? null, [
                'override_id' => $id, 'override_type' => $input['override_type'], 'field' => $input['field'] ?? null,
                'authority_grant_id' => $input['authority_grant_id'] ?? null, 'requested_by' => $requestedBy,
            ], $input['reason_code'], [
                'old' => ['outcome' => $input['previous_outcome'] ?? null, 'value' => $input['previous_value'] ?? null],
                'new' => ['outcome' => $input['new_outcome'] ?? null, 'value' => $input['new_value'] ?? null],
                'approval_id' => $id,
            ]);
        });

        return $this->find($id);
    }

    public function approve(string $overrideId, string $approverId, ?string $note = null): object
    {
        return $this->decide($overrideId, $approverId, 'APPROVED', $note);
    }

    public function reject(string $overrideId, string $approverId, string $note): object
    {
        if (blank($note)) {
            throw ValidationException::withMessages(['decision_note' => 'A rejection needs a note.']);
        }

        return $this->decide($overrideId, $approverId, 'REJECTED', $note);
    }

    public function isEffective(object $override): bool
    {
        return in_array($override->status, ['APPROVED', 'AUTO_APPROVED'], true);
    }

    public function find(string $id): object
    {
        return DB::table('engine_overrides')->where('id', $id)->first() ?? throw ValidationException::withMessages(['override' => 'Unknown override.']);
    }

    /** Effective overrides for a subject (read side for callers applying overridden values). */
    public function effectiveFor(string $subjectType, string $subjectId, ?string $field = null): array
    {
        return DB::table('engine_overrides')->where('subject_type', $subjectType)->where('subject_id', $subjectId)
            ->whereIn('status', ['APPROVED', 'AUTO_APPROVED'])->when($field, fn ($q) => $q->where('field', $field))
            ->orderBy('approved_at')->get()->all();
    }

    private function decide(string $overrideId, string $approverId, string $status, ?string $note): object
    {
        DB::transaction(function () use ($overrideId, $approverId, $status, $note) {
            $o = DB::table('engine_overrides')->where('id', $overrideId)->lockForUpdate()->first()
                ?? throw ValidationException::withMessages(['override' => 'Unknown override.']);
            if ($o->status !== 'REQUESTED') {
                throw ValidationException::withMessages(['override' => "Override is already {$o->status}."]);
            }
            if ($o->requested_by === $approverId) {
                throw ValidationException::withMessages(['override' => 'Maker-checker: the requester cannot decide their own override.']);
            }
            $now = now();
            DB::table('engine_overrides')->where('id', $overrideId)->update($status === 'APPROVED'
                ? ['status' => 'APPROVED', 'approved_by' => $approverId, 'approved_at' => $now, 'decision_note' => $note]
                : ['status' => 'REJECTED', 'rejected_by' => $approverId, 'rejected_at' => $now, 'decision_note' => $note]);
            $this->audit->record('override.'.strtolower($status), $o->subject_type, $o->subject_id, [
                'override_id' => $o->id, 'override_type' => $o->override_type, 'field' => $o->field, 'requested_by' => $o->requested_by, 'decided_by' => $approverId, 'note' => $note,
            ], $o->reason_code, ['approval_id' => $o->id]);
        });

        return $this->find($overrideId);
    }
}
