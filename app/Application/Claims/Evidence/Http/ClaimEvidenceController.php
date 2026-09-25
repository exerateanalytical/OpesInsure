<?php

declare(strict_types=1);

namespace App\Application\Claims\Evidence\Http;

use App\Application\Claims\Evidence\ClaimEvidenceChecklist;
use App\Application\Claims\Evidence\ClaimEvidenceMetadata;
use App\Application\Claims\Evidence\ClaimEvidenceReviewService;
use App\Application\Claims\Evidence\ClaimEvidenceRules;
use App\Domain\Tenancy\TenantContext;
use App\Models\Claim;
use App\Models\Document;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** REQ-CLM-005 staff API: evidence rules, checklist, metadata and WF-051/052 review. */
final class ClaimEvidenceController
{
    public function rules(string $id, ClaimEvidenceRules $rules): JsonResponse
    {
        return response()->json(['data' => $rules->forClaim($this->claim($id))]);
    }

    public function checklist(string $id, ClaimEvidenceChecklist $checklist): JsonResponse
    {
        return response()->json(['data' => $checklist->build($this->claim($id))]);
    }

    public function show(string $id, string $document, ClaimEvidenceMetadata $metadata): JsonResponse
    {
        $claim = $this->claim($id);
        $link = DB::table('claim_documents')->where(['claim_id' => $claim->id, 'document_id' => $document])->first();
        abort_if(! $link, 404);
        $meta = $metadata->forDocument($document);
        abort_if(! $meta, 404);
        $custody = DB::table('claim_evidence_custody_events')->where(['claim_id' => $claim->id, 'document_id' => $document])->orderBy('occurred_at')
            ->get(['event_type', 'from_actor_id', 'to_actor_id', 'content_hash', 'purpose', 'occurred_at']);

        return response()->json(['data' => $meta + [
            'link' => ['id' => $link->id, 'evidence_type' => $link->evidence_type, 'status' => $link->status, 'submitted_by' => $link->submitted_by, 'submitted_at' => $link->submitted_at,
                'reviewed_by' => $link->verified_by, 'reviewed_at' => $link->verified_at, 'review_reason_code' => $link->review_reason_code, 'review_notes' => $link->rejection_reason,
                'evidence_hash' => $link->evidence_hash, 'hash_matches' => hash_equals((string) $link->evidence_hash, (string) $meta['sha256'])],
            'custody' => $custody,
        ]]);
    }

    public function review(Request $r, string $id, string $document, ClaimEvidenceReviewService $reviews): JsonResponse
    {
        $d = $r->validate(['decision' => 'required|string|in:ACCEPT,REJECT,accept,reject', 'reason_code' => 'nullable|string|max:64', 'notes' => 'nullable|string|max:2000']);
        $claim = $this->claim($id);
        $doc = Document::where(['id' => $document, 'tenant_id' => $claim->tenant_id])->firstOrFail();

        return response()->json(['data' => $reviews->review($claim, $doc, $d['decision'], $d['reason_code'] ?? null, $d['notes'] ?? null, $r->user())]);
    }

    private function claim(string $id): Claim
    {
        return Claim::where(['id' => $id, 'tenant_id' => app(TenantContext::class)->id()])->firstOrFail();
    }
}
