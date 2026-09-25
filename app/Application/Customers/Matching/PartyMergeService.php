<?php

declare(strict_types=1);

namespace App\Application\Customers\Matching;

use App\Application\Approvals\ApprovalHandler;
use App\Application\Approvals\ApprovalService;
use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Models\ApprovalRequest;
use App\Models\Parties\EntityMatchCandidate;
use App\Models\Parties\PartyMerge;
use App\Models\Party;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * REQ-PTY-004 — human-approved, non-destructive party merge (BRK-017 survivorship log) through the one approval
 * engine (action entity.merge, source party_merges). Nothing is deleted:
 *  - the merged party stays, status MERGED + merged_into_id (callers resolve() to the survivor);
 *  - roles, relationships and ownership interests are re-pointed to the survivor and their ids recorded;
 *  - survivor fields changed by survivorship are snapshotted; unmerge() restores fields and moves rows back.
 * Contacts, identifiers, tenant_customers, policies and claims keep pointing at the merged party (redirect).
 */
final class PartyMergeService implements ApprovalHandler
{
    public const APPROVAL_ACTION = 'entity.merge';

    public const SOURCE_TABLE = 'party_merges';

    /** Survivorship fields: display_name and legal_identity keys. Rule SURVIVOR | MERGED; default SURVIVOR, blanks filled from MERGED. */
    public const FIELDS = ['display_name', 'legal_identity.date_of_birth', 'legal_identity.registration_number', 'legal_identity.gender', 'legal_identity.nationality'];

    /** table => columns re-pointed from the merged party to the survivor */
    private const MOVABLE = [
        'party_roles' => ['party_id'],
        'party_relationships' => ['from_party_id', 'to_party_id'],
        'ownership_interests' => ['owner_party_id', 'owned_party_id'],
    ];

    public function __construct(
        private readonly ApprovalService $approvals,
        private readonly AuditWriter $audit,
        private readonly OutboxWriter $outbox,
    ) {}

    /** @param array<string, string> $rules field => SURVIVOR|MERGED */
    public function request(Party $survivor, Party $merged, User $maker, array $rules = [], ?string $reason = null, ?EntityMatchCandidate $candidate = null): PartyMerge
    {
        [$survivor, $merged] = [$survivor->fresh() ?? $survivor, $merged->fresh() ?? $merged];
        if ($survivor->id === $merged->id || $survivor->type !== $merged->type) {
            throw ValidationException::withMessages(['survivor_party_id' => 'Parties must be different and of the same type.']);
        }
        if ($survivor->merged_into_id || $merged->merged_into_id) {
            throw ValidationException::withMessages(['survivor_party_id' => 'One of the parties is already merged.']);
        }
        foreach ($rules as $field => $rule) {
            if (! in_array($field, self::FIELDS, true) || ! in_array($rule, ['SURVIVOR', 'MERGED'], true)) {
                throw ValidationException::withMessages(['survivorship' => "Invalid survivorship rule for {$field}."]);
            }
        }
        if ($candidate && ! in_array($candidate->status, ['OPEN'], true)) {
            throw ValidationException::withMessages(['candidate' => "This candidate is {$candidate->status}."]);
        }
        if ($candidate && array_diff([$candidate->party_a_id, $candidate->party_b_id], [$survivor->id, $merged->id])) {
            throw ValidationException::withMessages(['candidate' => 'The candidate does not match these parties.']);
        }
        if (PartyMerge::where('status', 'PENDING')->where(fn ($q) => $q->whereIn('survivor_party_id', [$survivor->id, $merged->id])->orWhereIn('merged_party_id', [$survivor->id, $merged->id]))->exists()) {
            throw ValidationException::withMessages(['merge' => 'A merge involving one of these parties is already waiting for approval.']);
        }

        return DB::transaction(function () use ($survivor, $merged, $maker, $rules, $reason, $candidate) {
            $m = PartyMerge::create(['match_candidate_id' => $candidate?->id, 'survivor_party_id' => $survivor->id, 'merged_party_id' => $merged->id,
                'status' => 'PENDING', 'survivorship_rules' => (object) $rules, 'reason' => $reason, 'requested_by' => $maker->id]);
            $candidate?->update(['status' => 'MERGE_REQUESTED']);
            $req = $this->approvals->open($maker, [
                'action_code' => self::APPROVAL_ACTION, 'subject_type' => 'party', 'subject_id' => $merged->id,
                'source_table' => self::SOURCE_TABLE, 'source_id' => $m->id,
                'payload' => ['survivor' => $survivor->display_name, 'merged' => $merged->display_name, 'score' => $candidate?->score],
                'reason' => $reason ?? "Merge party {$merged->display_name} into {$survivor->display_name}",
            ]);
            $m->update(['approval_request_id' => $req->id]);
            $this->audit->record('party.merge_requested', 'party', $merged->id, ['merge_id' => $m->id, 'survivor_party_id' => $survivor->id, 'approval_id' => $req->id], $reason);
            if ($this->approvals->isApproved($req)) {
                $this->apply($m, $maker, null);
            }

            return $m->refresh();
        });
    }

    public function approveMerge(PartyMerge $m, User $checker, ?string $note = null): PartyMerge
    {
        $this->assertPending($m);

        return DB::transaction(function () use ($m, $checker, $note) {
            $req = $this->approvals->recordDecision(ApprovalRequest::findOrFail($m->approval_request_id), $checker, 'APPROVED', $note);
            if ($this->approvals->isApproved($req)) {
                $this->apply($m, $checker, $note);
            }

            return $m->refresh();
        });
    }

    public function rejectMerge(PartyMerge $m, User $checker, string $note): PartyMerge
    {
        $this->assertPending($m);

        return DB::transaction(function () use ($m, $checker, $note) {
            $this->approvals->recordDecision(ApprovalRequest::findOrFail($m->approval_request_id), $checker, 'REJECTED', $note);
            $m->update(['status' => 'REJECTED', 'decided_by' => $checker->id, 'decided_at' => now(), 'decision_note' => mb_substr($note, 0, 500)]);
            if ($m->match_candidate_id) {
                EntityMatchCandidate::whereKey($m->match_candidate_id)->update(['status' => 'OPEN']);
            }

            return $m->refresh();
        });
    }

    // ApprovalHandler (generic approval inbox) — dispatched by EntityMergeApprovalRouter.
    public function approve(ApprovalRequest $request, User $actor, ?string $note): void
    {
        $this->approveMerge(PartyMerge::findOrFail($request->source_id), $actor, $note);
    }

    public function reject(ApprovalRequest $request, User $actor, string $note): void
    {
        $this->rejectMerge(PartyMerge::findOrFail($request->source_id), $actor, $note);
    }

    /** Reverses an applied merge: moved rows go back, survivor fields are restored, the merged party is live again. */
    public function unmerge(PartyMerge $m, User $actor, string $reason): PartyMerge
    {
        if (mb_strlen(trim($reason)) < 3) {
            throw ValidationException::withMessages(['reason' => 'An unmerge needs a reason.']);
        }

        return DB::transaction(function () use ($m, $actor, $reason) {
            $m = PartyMerge::whereKey($m->id)->lockForUpdate()->firstOrFail();
            if ($m->status !== 'MERGED') {
                throw ValidationException::withMessages(['merge' => "Only an applied merge can be reversed (this one is {$m->status})."]);
            }
            $survivor = Party::whereKey($m->survivor_party_id)->lockForUpdate()->firstOrFail();
            $merged = Party::whereKey($m->merged_party_id)->lockForUpdate()->firstOrFail();
            if ($survivor->merged_into_id) {
                throw ValidationException::withMessages(['merge' => 'The survivor was itself merged later; reverse that merge first.']);
            }
            foreach ($m->moved_rows ?? [] as $table => $cols) {
                foreach ($cols as $col => $ids) {
                    if ($ids) {
                        DB::table($table)->whereIn('id', $ids)->where($col, $survivor->id)->update([$col => $merged->id, 'updated_at' => now()]);
                    }
                }
            }
            $snap = $m->before_snapshot;
            $survivor->forceFill(['display_name' => $snap['survivor']['display_name'], 'legal_identity' => $snap['survivor']['legal_identity']])->save();
            $merged->forceFill(['status' => $snap['merged']['status'], 'merged_into_id' => null, 'merged_at' => null])->save();
            $m->update(['status' => 'UNMERGED', 'unmerged_by' => $actor->id, 'unmerged_at' => now(), 'unmerge_reason' => mb_substr($reason, 0, 500)]);
            if ($m->match_candidate_id) {
                EntityMatchCandidate::whereKey($m->match_candidate_id)->update(['status' => 'DISMISSED', 'review_note' => 'Unmerged: '.mb_substr($reason, 0, 480), 'reviewed_by' => $actor->id, 'reviewed_at' => now()]);
            }
            $this->audit->record('party.unmerged', 'party', $merged->id, ['merge_id' => $m->id, 'survivor_party_id' => $survivor->id], $reason);
            $this->outbox->record('party.unmerged', 'party', $merged->id, ['merge_id' => $m->id, 'survivor_party_id' => $survivor->id, 'merged_party_id' => $merged->id]);

            return $m->refresh();
        });
    }

    /** Follows merge redirects to the live golden record (bounded). */
    public function resolve(string $partyId): ?Party
    {
        $p = Party::find($partyId);
        for ($i = 0; $p && $p->merged_into_id && $i < 10; $i++) {
            $p = Party::find($p->merged_into_id);
        }

        return $p;
    }

    private function apply(PartyMerge $m, User $actor, ?string $note): void
    {
        $survivor = Party::whereKey($m->survivor_party_id)->lockForUpdate()->firstOrFail();
        $merged = Party::whereKey($m->merged_party_id)->lockForUpdate()->firstOrFail();
        if ($survivor->merged_into_id || $merged->merged_into_id) {
            throw ValidationException::withMessages(['merge' => 'One of the parties was merged meanwhile; reject this request.']);
        }
        $snapshot = ['survivor' => ['display_name' => $survivor->display_name, 'legal_identity' => $survivor->legal_identity ?? []], 'merged' => ['status' => $merged->status]];

        // Survivorship
        $rules = (array) ($m->survivorship_rules ?? []);
        $log = [];
        $identity = $survivor->legal_identity ?? [];
        foreach (self::FIELDS as $field) {
            $sv = $this->get($survivor, $field);
            $mv = $this->get($merged, $field);
            $rule = $rules[$field] ?? null;
            $from = $rule === 'MERGED' ? 'MERGED' : ($rule === null && blank($sv) && ! blank($mv) ? 'MERGED' : 'SURVIVOR');
            $value = $from === 'MERGED' ? $mv : $sv;
            if ($field === 'display_name') {
                $survivor->display_name = $value;
            } elseif (! blank($value)) {
                $identity[substr($field, strlen('legal_identity.'))] = $value;
            }
            if (! blank($sv) || ! blank($mv)) {
                $log[] = ['field' => $field, 'rule' => $rule ?? 'DEFAULT', 'chosen_from' => $from, 'survivor_value' => $sv, 'merged_value' => $mv, 'result' => $value];
            }
        }
        $survivor->forceFill(['legal_identity' => $identity])->save();

        // Re-point golden-record links (skip links between the two parties: they would become self-links).
        $moved = [];
        foreach (self::MOVABLE as $table => $cols) {
            foreach ($cols as $col) {
                $q = DB::table($table)->where($col, $merged->id);
                foreach ($cols as $other) {
                    if ($other !== $col) {
                        $q->where($other, '!=', $survivor->id);
                    }
                }
                $ids = $q->pluck('id')->all();
                if ($ids) {
                    DB::table($table)->whereIn('id', $ids)->update([$col => $survivor->id, 'updated_at' => now()]);
                }
                $moved[$table][$col] = $ids;
            }
        }

        $merged->forceFill(['status' => 'MERGED', 'merged_into_id' => $survivor->id, 'merged_at' => now()])->save();
        $m->update(['status' => 'MERGED', 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => $note === null ? null : mb_substr($note, 0, 500),
            'survivorship_log' => $log, 'before_snapshot' => $snapshot, 'moved_rows' => $moved]);
        if ($m->match_candidate_id) {
            EntityMatchCandidate::whereKey($m->match_candidate_id)->update(['status' => 'MERGED', 'reviewed_by' => $actor->id, 'reviewed_at' => now()]);
        }
        $this->audit->record('party.merged', 'party', $merged->id, ['merge_id' => $m->id, 'survivor_party_id' => $survivor->id, 'survivorship' => $log, 'moved' => array_map(fn ($c) => array_map('count', $c), $moved)], $note);
        $this->outbox->record('party.merged', 'party', $merged->id, ['merge_id' => $m->id, 'survivor_party_id' => $survivor->id, 'merged_party_id' => $merged->id]);
    }

    private function get(Party $p, string $field): mixed
    {
        return $field === 'display_name' ? $p->display_name : (($p->legal_identity ?? [])[substr($field, strlen('legal_identity.'))] ?? null);
    }

    private function assertPending(PartyMerge $m): void
    {
        if ($m->status !== 'PENDING' || ! $m->approval_request_id) {
            throw ValidationException::withMessages(['merge' => "This merge request is {$m->status}."]);
        }
    }
}
