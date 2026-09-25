<?php

declare(strict_types=1);

namespace App\Application\MasterData;

use App\Application\Approvals\ApprovalHandler;
use App\Application\Approvals\ApprovalService;
use App\Models\ApprovalRequest;
use App\Models\MasterData\MasterDataMergeRequest;
use App\Models\MasterData\MasterDataValue;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * REQ-MDM-007 / MDM-010–011 — maker-checker merge of duplicate master-data values (approval action entity.merge).
 * The maker requests; a different checker approves; only then MasterDataReviewService::mergeValues() runs
 * (the losing value goes INACTIVE and redirects; nothing is deleted). Also the ApprovalHandler for the inbox.
 */
final class MasterDataMergeService implements ApprovalHandler
{
    public const APPROVAL_ACTION = 'entity.merge';

    public function __construct(
        private readonly ApprovalService $approvals,
        private readonly MasterDataReviewService $reviews,
    ) {}

    public function request(MasterDataValue $from, MasterDataValue $into, User $maker, ?string $reason = null): MasterDataMergeRequest
    {
        [$from, $into] = [$from->fresh() ?? $from, $into->fresh() ?? $into];
        if ($from->id === $into->id || $from->list_id !== $into->list_id) {
            throw ValidationException::withMessages(['into' => 'Values must be different and in the same list.']);
        }
        if ($from->status !== 'ACTIVE' || $into->status !== 'ACTIVE') {
            throw ValidationException::withMessages(['into' => 'Only active values can be merged.']);
        }
        if (MasterDataMergeRequest::where('status', 'PENDING')->where(fn ($q) => $q->whereIn('from_value_id', [$from->id, $into->id])->orWhereIn('into_value_id', [$from->id]))->exists()) {
            throw ValidationException::withMessages(['into' => 'A merge involving this value is already waiting for approval.']);
        }

        return DB::transaction(function () use ($from, $into, $maker, $reason) {
            $mr = MasterDataMergeRequest::create(['from_value_id' => $from->id, 'into_value_id' => $into->id, 'domain_code' => $from->domain_code,
                'list_code' => $from->list_code, 'status' => 'PENDING', 'reason' => $reason, 'requested_by' => $maker->id]);
            $req = $this->approvals->open($maker, [
                'action_code' => self::APPROVAL_ACTION, 'subject_type' => 'master_data_value', 'subject_id' => $from->id,
                'source_table' => 'master_data_merge_requests', 'source_id' => $mr->id, 'tenant_id' => null,
                'payload' => ['from' => "{$from->list_code}.{$from->code}", 'into' => "{$into->list_code}.{$into->code}"],
                'reason' => $reason ?? "Merge {$from->code} into {$into->code}",
            ]);
            $mr->update(['approval_request_id' => $req->id]);
            if ($this->approvals->isApproved($req)) {
                $this->apply($mr, $maker, null);
            }

            return $mr->refresh();
        });
    }

    public function approveRequest(MasterDataMergeRequest $mr, User $checker, ?string $note = null): MasterDataMergeRequest
    {
        $this->assertPending($mr);

        return DB::transaction(function () use ($mr, $checker, $note) {
            $req = $this->approvals->recordDecision(ApprovalRequest::findOrFail($mr->approval_request_id), $checker, 'APPROVED', $note);
            if ($this->approvals->isApproved($req)) {
                $this->apply($mr, $checker, $note);
            }

            return $mr->refresh();
        });
    }

    public function rejectRequest(MasterDataMergeRequest $mr, User $checker, string $note): MasterDataMergeRequest
    {
        $this->assertPending($mr);

        return DB::transaction(function () use ($mr, $checker, $note) {
            $this->approvals->recordDecision(ApprovalRequest::findOrFail($mr->approval_request_id), $checker, 'REJECTED', $note);
            $mr->update(['status' => 'REJECTED', 'decided_by' => $checker->id, 'decided_at' => now(), 'decision_note' => $note]);

            return $mr->refresh();
        });
    }

    // ApprovalHandler (generic inbox)
    public function approve(ApprovalRequest $request, User $actor, ?string $note): void
    {
        $this->approveRequest(MasterDataMergeRequest::findOrFail($request->source_id), $actor, $note);
    }

    public function reject(ApprovalRequest $request, User $actor, string $note): void
    {
        $this->rejectRequest(MasterDataMergeRequest::findOrFail($request->source_id), $actor, $note);
    }

    /**
     * MDM-010 duplicate detection: active platform values sharing a normalized EN or FR label inside a list.
     *
     * @return list<array{list_id: string, domain_code: string, list_code: string, label: string, values: list<array{id: string, code: string, label_en: string, usage_count: int}>}>
     */
    public function duplicateGroups(int $limit = 200): array
    {
        $groups = [];
        MasterDataValue::where('status', 'ACTIVE')->whereNull('tenant_id')->where('is_other', false)
            ->orderBy('list_id')->get(['id', 'list_id', 'domain_code', 'list_code', 'code', 'label_en', 'label_fr', 'usage_count'])
            ->each(function ($v) use (&$groups) {
                foreach (array_unique([MasterDataNormalizer::normalize($v->label_en), MasterDataNormalizer::normalize($v->label_fr)]) as $n) {
                    if ($n !== '') {
                        $groups[$v->list_id.'|'.$n][$v->id] = $v;
                    }
                }
            });
        $out = [];
        $seen = [];
        foreach ($groups as $key => $values) {
            if (count($values) < 2) {
                continue;
            }
            $ids = array_keys($values);
            sort($ids);
            if (isset($seen[$sig = implode(',', $ids)])) {
                continue;
            }
            $seen[$sig] = true;
            $first = reset($values);
            $out[] = ['list_id' => $first->list_id, 'domain_code' => $first->domain_code, 'list_code' => $first->list_code, 'label' => explode('|', $key, 2)[1],
                'values' => array_values(array_map(fn ($v) => ['id' => $v->id, 'code' => $v->code, 'label_en' => $v->label_en, 'usage_count' => (int) $v->usage_count], $values))];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    private function apply(MasterDataMergeRequest $mr, User $actor, ?string $note): void
    {
        $from = MasterDataValue::findOrFail($mr->from_value_id);
        $into = MasterDataValue::findOrFail($mr->into_value_id);
        if ($from->status !== 'ACTIVE' || $into->status !== 'ACTIVE') {
            throw ValidationException::withMessages(['merge' => 'One of the values is no longer active; reject this request.']);
        }
        $this->reviews->mergeValues($from, $into, $actor->id);
        $mr->update(['status' => 'MERGED', 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => $note]);
    }

    private function assertPending(MasterDataMergeRequest $mr): void
    {
        if ($mr->status !== 'PENDING' || ! $mr->approval_request_id) {
            throw ValidationException::withMessages(['merge' => "This merge request is {$mr->status}."]);
        }
    }
}
