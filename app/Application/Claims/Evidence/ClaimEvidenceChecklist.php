<?php

declare(strict_types=1);

namespace App\Application\Claims\Evidence;

use App\Models\Claim;
use Illuminate\Support\Facades\DB;

/**
 * REQ-CLM-005 per-claim evidence checklist: each evidence rule (ClaimEvidenceRules) matched against the
 * claim's evidence links (claim_documents joined to canonical documents). A link satisfies a rule when
 * the document's catalogue type (documents.document_type_id, else the link's evidence_type, else
 * documents.document_type_code — resolved through the catalogue) is the rule's type. Documents
 * superseded by a newer version (documents.superseded_by_document_id) no longer count.
 *
 * Item status: MISSING | SUBMITTED (awaiting review) | ACCEPTED | REJECTED | WAIVED.
 */
final class ClaimEvidenceChecklist
{
    public const MISSING = 'MISSING';

    public const SUBMITTED = 'SUBMITTED';

    public const ACCEPTED = 'ACCEPTED';

    public const REJECTED = 'REJECTED';

    public const WAIVED = 'WAIVED';

    /** claim_documents.status (written by ClaimEvidenceService) → checklist status. */
    private const LINK_STATUS = ['SUBMITTED' => self::SUBMITTED, 'VERIFIED' => self::ACCEPTED, 'REJECTED' => self::REJECTED];

    public function __construct(private ClaimEvidenceRules $rules) {}

    /** @return array<string, mixed> */
    public function build(Claim $claim): array
    {
        $spec = $this->rules->forClaim($claim);
        $links = DB::table('claim_documents as l')->join('documents as d', 'd.id', '=', 'l.document_id')
            ->where('l.claim_id', $claim->id)
            ->orderBy('l.submitted_at')
            ->get(['l.id', 'l.document_id', 'l.evidence_type', 'l.status', 'l.submitted_by', 'l.submitted_at', 'l.verified_by', 'l.verified_at', 'l.rejection_reason', 'l.review_reason_code',
                'd.document_type_id', 'd.document_type_code', 'd.document_origin', 'd.mime_type', 'd.sha256', 'd.uploaded_by', 'd.superseded_by_document_id']);

        $byType = [];
        foreach ($links as $l) {
            $l->type_id = $this->rules->typeId($l->document_type_id ?: $l->evidence_type ?: $l->document_type_code);
            $l->current = $l->superseded_by_document_id === null;
            $byType[$l->type_id][] = $l;
        }

        $items = [];
        $matched = [];
        foreach ($spec['rules'] as $rule) {
            $cands = $byType[$rule['document_type_id']] ?? [];
            foreach ($cands as $c) {
                $matched[$c->id ?? $c->document_id] = true;
            }
            $items[] = $rule + ['status' => $this->status($rule, $cands), 'evidence' => array_map(fn ($c) => $this->present($c), $cands)];
        }

        $blocking = array_values(array_map(fn ($i) => ['document_type_id' => $i['document_type_id'], 'status' => $i['status']],
            array_filter($items, fn ($i) => $i['mandatory'] && ! in_array($i['status'], [self::ACCEPTED, self::WAIVED], true))));
        $other = $links->reject(fn ($l) => isset($matched[$l->id ?? $l->document_id]))->map(fn ($l) => $this->present($l))->values()->all();

        $counts = array_count_values(array_column($items, 'status'));

        return [
            'claim_id' => $claim->id,
            'line_code' => $spec['line_code'],
            'class_code' => $spec['class_code'],
            'rule_source' => $spec['source'],
            'rule_versions' => $spec['rule_versions'],
            'complete' => $blocking === [],
            'blocking' => $blocking,
            'counts' => $counts,
            'items' => $items,
            'other_evidence' => $other,
        ];
    }

    /** Mandatory items not yet ACCEPTED/WAIVED (empty = decision may proceed). @return list<array{document_type_id:string,status:string}> */
    public function blocking(Claim $claim): array
    {
        return $this->build($claim)['blocking'];
    }

    private function status(array $rule, array $cands): string
    {
        if ($rule['waived']) {
            return self::WAIVED;
        }
        $current = array_values(array_filter($cands, fn ($c) => $c->current));
        if ($current === []) {
            return self::MISSING;
        }
        $statuses = array_map(fn ($c) => self::LINK_STATUS[$c->status] ?? self::SUBMITTED, $current);
        foreach ([self::ACCEPTED, self::SUBMITTED] as $s) {
            if (in_array($s, $statuses, true)) {
                return $s;
            }
        }

        return self::REJECTED;
    }

    private function present(object $l): array
    {
        return [
            'link_id' => $l->id, 'document_id' => $l->document_id, 'evidence_type' => $l->evidence_type, 'document_type_id' => $l->type_id,
            'status' => self::LINK_STATUS[$l->status] ?? $l->status, 'current' => $l->current, 'document_origin' => $l->document_origin,
            'mime_type' => $l->mime_type, 'sha256' => $l->sha256, 'uploaded_by' => $l->uploaded_by ?? $l->submitted_by,
            'submitted_by' => $l->submitted_by, 'submitted_at' => $l->submitted_at, 'reviewed_by' => $l->verified_by, 'reviewed_at' => $l->verified_at,
            'review_reason_code' => $l->review_reason_code, 'review_notes' => $l->rejection_reason,
        ];
    }
}
