<?php

declare(strict_types=1);

namespace App\Application\Claims\Evidence;

use App\Application\Documents\DocumentOrigin;
use Illuminate\Support\Facades\DB;

/**
 * REQ-CLM-005 evidence metadata, read from the canonical store only (REQ-DUP-021): `documents`
 * (uploader, MIME, sha256, origin, supersedes/superseded_by chain) and `document_versions`
 * (per-version storage hash / MIME / uploader). Nothing is copied onto the claim link.
 */
final class ClaimEvidenceMetadata
{
    private const CHAIN_LIMIT = 25;

    /** @return array<string, mixed>|null */
    public function forDocument(string $documentId): ?array
    {
        $d = DB::table('documents')->where('id', $documentId)->first();
        if (! $d) {
            return null;
        }
        $versions = DB::table('document_versions')->where('document_id', $d->id)->orderBy('version')
            ->get(['version', 'sha256', 'mime_type', 'size_bytes', 'uploaded_by', 'created_at'])
            ->map(fn ($v) => (array) $v)->all();

        return [
            'document_id' => $d->id,
            'document_type_id' => $d->document_type_id,
            'document_type_code' => $d->document_type_code,
            'title' => $d->title,
            'mime_type' => $d->mime_type,
            'size_bytes' => (int) $d->size_bytes,
            'sha256' => $d->sha256,
            'scan_status' => $d->scan_status,
            'document_origin' => $d->document_origin,
            'third_party' => DocumentOrigin::isThirdPartyEvidence((string) $d->document_origin),
            'document_status' => $d->status,
            'uploaded_by' => $d->uploaded_by,
            'uploader_name' => $d->uploaded_by ? DB::table('users')->where('id', $d->uploaded_by)->value('full_name') : null,
            'uploaded_at' => $d->created_at,
            'versions' => $versions,
            'supersedes_document_id' => $d->supersedes_document_id,
            'superseded_by_document_id' => $d->superseded_by_document_id,
            'version_chain' => $this->chain($d),
        ];
    }

    /** Ordered oldest → newest list of document ids linked through supersedes/superseded_by. @return list<string> */
    public function chain(object $d): array
    {
        $back = [];
        $cur = $d->supersedes_document_id;
        while ($cur && count($back) < self::CHAIN_LIMIT && ! in_array($cur, $back, true)) {
            $back[] = $cur;
            $cur = DB::table('documents')->where('id', $cur)->value('supersedes_document_id');
        }
        $fwd = [];
        $cur = $d->superseded_by_document_id;
        while ($cur && count($fwd) < self::CHAIN_LIMIT && ! in_array($cur, $fwd, true) && $cur !== $d->id) {
            $fwd[] = $cur;
            $cur = DB::table('documents')->where('id', $cur)->value('superseded_by_document_id');
        }

        return [...array_reverse($back), $d->id, ...$fwd];
    }
}
