<?php

declare(strict_types=1);

namespace App\Application\Documents\Scanning;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * S4: uploads that are not (yet) usable because of the malware scan, for display next to the documents / evidence
 * they will join. A held file (PENDING_SCAN / SCAN_UNAVAILABLE) shows "Security check in progress"; an INFECTED one
 * shows as quarantined and is never downloadable. Read-only: callers have already authorised access to the subject.
 *
 * Row shape (additive "pending" arrays in API responses):
 *   document_id, filename, uploaded_at (ISO-8601), status, status_label, message, evidence_type, downloadable (false)
 */
final class PendingDocuments
{
    /** Scan statuses shown as pending items (FAILED = legacy upload still awaiting a real scan). */
    public const SHOWN = [DocumentScanQueue::PENDING_SCAN, DocumentScanQueue::SCAN_UNAVAILABLE, DocumentScanQueue::INFECTED, DocumentScanQueue::LEGACY_FAILED];

    /**
     * Evidence the uploader attached to the claim while the file was held (document_pending_attachments), plus
     * documents filed against the claim (documents.claim_id) that are held or infected and not linked yet.
     *
     * @return list<array<string, mixed>>
     */
    public function forClaim(string $claimId): array
    {
        $attachments = DB::table('document_pending_attachments')
            ->where('target_type', DocumentScanQueue::TARGET_CLAIM_EVIDENCE)->where('target_id', $claimId)
            ->where(fn ($q) => $q->where('status', 'PENDING')->orWhere(fn ($w) => $w->where('status', 'CANCELLED')->where('error', DocumentScanQueue::INFECTED)))
            ->orderBy('created_at')->get(['document_id', 'payload']);
        $types = [];
        foreach ($attachments as $a) {
            $types[(string) $a->document_id] = (json_decode((string) $a->payload, true) ?: [])['evidence_type'] ?? null;
        }

        $linked = DB::table('claim_documents')->where('claim_id', $claimId)->pluck('document_id')->map(fn ($id) => (string) $id)->all();
        $filed = DB::table('documents')->where('claim_id', $claimId)->whereIn('scan_status', self::SHOWN)
            ->whereNotIn('id', $linked ?: ['00000000-0000-0000-0000-000000000000'])->pluck('id')->map(fn ($id) => (string) $id)->all();

        return $this->rows(array_values(array_unique([...array_keys($types), ...$filed])), $types);
    }

    /**
     * The held / infected ones among the given documents (KYC, proposal documents…).
     *
     * @param  iterable<string|null>  $documentIds
     * @return list<array<string, mixed>>
     */
    public function forDocumentIds(iterable $documentIds): array
    {
        $ids = [];
        foreach ($documentIds as $id) {
            if ($id) {
                $ids[] = (string) $id;
            }
        }

        return $this->rows(array_values(array_unique($ids)));
    }

    /**
     * @param  list<string>  $ids
     * @param  array<string, ?string>  $types  document_id => evidence type
     * @return list<array<string, mixed>>
     */
    private function rows(array $ids, array $types = []): array
    {
        if ($ids === []) {
            return [];
        }
        $docs = DB::table('documents')->whereIn('id', $ids)->whereIn('scan_status', self::SHOWN)->orderBy('created_at')
            ->get(['id', 'title', 'storage_key', 'category', 'mime_type', 'scan_status', 'created_at']);
        if ($docs->isEmpty()) {
            return [];
        }
        $names = DB::table('document_intake_items')->whereIn('document_id', $docs->pluck('id')->all())->whereNotNull('original_filename')
            ->pluck('original_filename', 'document_id');

        return $docs->map(function ($d) use ($names, $types) {
            $status = $d->scan_status === DocumentScanQueue::LEGACY_FAILED ? DocumentScanQueue::SCAN_UNAVAILABLE : (string) $d->scan_status;
            $infected = $status === DocumentScanQueue::INFECTED;

            return [
                'document_id' => (string) $d->id,
                'filename' => (string) ($names[$d->id] ?? ($d->title ?: basename((string) $d->storage_key))),
                'uploaded_at' => $d->created_at ? Carbon::parse($d->created_at)->toIso8601String() : null,
                'status' => $status,
                'status_label' => __('scan_queue.pending.status.'.$status),
                'message' => __($infected ? 'scan_queue.pending.quarantined' : 'scan_queue.pending.in_progress'),
                'evidence_type' => $types[(string) $d->id] ?? null,
                'category' => $d->category,
                'mime_type' => $d->mime_type,
                'downloadable' => false,
            ];
        })->values()->all();
    }
}
