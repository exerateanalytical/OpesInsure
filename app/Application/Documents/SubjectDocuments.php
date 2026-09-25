<?php

declare(strict_types=1);

namespace App\Application\Documents;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * REQ-DUP-021 read surface: documents of a subject (claim, proposal, KYC
 * submission, risk asset). The document data always comes from the canonical
 * `documents` row; the subject link tables contribute only the link's role
 * (evidence type / requirement / purpose) and review status, through the
 * document_subject_links view. Readers use this instead of joining a link
 * table themselves.
 */
final class SubjectDocuments
{
    public const SUBJECTS = ['CLAIM', 'PROPOSAL', 'KYC_SUBMISSION', 'RISK_ASSET'];

    /** Base query: one row per (subject, document) link, canonical document columns prefixed documents.* */
    public function query(string $subjectType, string $subjectId): Builder
    {
        if (! in_array($subjectType, self::SUBJECTS, true)) {
            throw new \InvalidArgumentException("Unknown document subject {$subjectType}.");
        }

        return DB::table('document_subject_links as links')
            ->join('documents', 'documents.id', '=', 'links.document_id')
            ->where('links.subject_type', $subjectType)
            ->where('links.subject_id', $subjectId);
    }

    /** @return Collection<int, object> link role/status + canonical document fields */
    public function forSubject(string $subjectType, string $subjectId): Collection
    {
        return $this->query($subjectType, $subjectId)
            ->orderByDesc('links.linked_at')->orderByDesc('documents.created_at')
            ->get($this->columns());
    }

    /** @return array<int, string> */
    public function columns(): array
    {
        return [
            'links.link_id as id', 'links.document_id', 'links.role', 'links.link_status as status', 'links.linked_at as submitted_at', 'links.verified_at',
            'documents.category', 'documents.mime_type', 'documents.size_bytes', 'documents.scan_status', 'documents.sha256',
            'documents.document_type_code', 'documents.document_origin', 'documents.document_stage', 'documents.created_at',
        ];
    }
}
