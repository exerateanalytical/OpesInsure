<?php

declare(strict_types=1);

namespace App\Application\Claims;

use App\Application\Documents\Adapters\MalwareScanAdapter;
use App\Application\Documents\Adapters\ScanResult;
use App\Application\Identity\PartyResolver;
use App\Models\Document;
use App\Models\Party;
use App\Models\UploadSession;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Attaches customer-owned evidence to a customer-owned claim, on top of
 * ClaimEvidenceService::attach() (which already owns the actual "is this
 * evidence usable" business rule — malware-clean + integrity hash — and the
 * custody-event audit trail). This service's only job is the mobile-specific
 * ownership front door plus turning a *source* of bytes into the Document
 * ClaimEvidenceService::attach() expects.
 *
 * Two sources are accepted, matching the two upload paths this effort's
 * batches already built:
 *  - document_id: a document already uploaded via POST /mobile/documents
 *    (small photos, base64-in-JSON, scanned at upload time).
 *  - upload_session_id: a COMPLETED chunked/resumable upload started via
 *    POST /mobile/uploads (larger incident photos/video) — this is the path
 *    the Offline-Sync batch's report recommended Claims adopt directly for
 *    large media rather than re-implementing chunked transport. Registering
 *    it here means scanning it and creating the Document row from the
 *    session's already-assembled bytes; no second upload of the same bytes.
 */
final class MobileClaimEvidenceService
{
    /** Mirrors ResumableUploadService::ALLOWED_MIME — evidence registered from a session can only ever be one of those types anyway. */
    private const ALLOWED_UPLOAD_MIME = ['application/pdf', 'image/jpeg', 'image/png', 'video/mp4'];

    public function __construct(
        private PartyResolver $parties,
        private MobileClaimService $claims,
        private ClaimEvidenceService $evidence,
        private MalwareScanAdapter $scanner,
    ) {
    }

    public function list(string $claimId, User $user, string $tenantId, int $perPage = 20): LengthAwarePaginator
    {
        $claim = $this->claims->owned($claimId, $user, $tenantId);

        // REQ-DUP-021: canonical documents + the claim link's role/status (SubjectDocuments).
        return app(\App\Application\Documents\SubjectDocuments::class)->query('CLAIM', $claim->id)
            ->orderByDesc('links.linked_at')
            ->select([
                'links.link_id as id', 'links.document_id', 'links.role as evidence_type',
                'links.link_status as status', 'links.linked_at as submitted_at', 'links.verified_at',
                'documents.category', 'documents.mime_type', 'documents.size_bytes', 'documents.scan_status',
            ])
            ->paginate($perPage);
    }

    /**
     * @param  array{document_id?: string, upload_session_id?: string, evidence_type: string, purpose: string}  $data
     * @return array<string, mixed>
     */
    public function attach(string $claimId, array $data, User $user, string $tenantId): array
    {
        $claim = $this->claims->owned($claimId, $user, $tenantId);
        $party = $this->parties->forUser($user);

        if (! $party) {
            throw ValidationException::withMessages(['party' => __('wave12.claim_no_party')]);
        }

        $document = isset($data['document_id'])
            ? $this->ownedDocument($data['document_id'], $party, $tenantId)
            : $this->registerFromUploadSession($data['upload_session_id'], $user, $party, $tenantId);

        return $this->evidence->attach($claim, $document, $data['evidence_type'], $data['purpose'], $user);
    }

    private function ownedDocument(string $documentId, Party $party, string $tenantId): Document
    {
        $document = Document::where('tenant_id', $tenantId)->where('party_id', $party->id)->find($documentId);

        if (! $document) {
            $exists = Document::where('tenant_id', $tenantId)->where('id', $documentId)->exists();

            throw $exists ? new AuthorizationException : new ModelNotFoundException;
        }

        return $document;
    }

    /** Converts a completed resumable-upload session's already-assembled bytes into a scanned Document, without re-uploading them. Idempotent: calling this twice for the same session returns the same Document. */
    private function registerFromUploadSession(string $uploadId, User $user, Party $party, string $tenantId): Document
    {
        $session = UploadSession::where('tenant_id', $tenantId)->where('user_id', $user->id)->find($uploadId);

        if (! $session) {
            $exists = UploadSession::where('tenant_id', $tenantId)->where('id', $uploadId)->exists();

            throw $exists ? new AuthorizationException : new ModelNotFoundException;
        }

        if ($session->status !== 'COMPLETED') {
            throw ValidationException::withMessages(['upload_session_id' => __('wave12.claim_evidence_upload_not_ready')]);
        }
        if (! in_array($session->mime_type, self::ALLOWED_UPLOAD_MIME, true)) {
            throw ValidationException::withMessages(['upload_session_id' => __('wave12.claim_evidence_upload_not_ready')]);
        }

        if ($existing = Document::where('tenant_id', $tenantId)->where('storage_key', $session->storage_key)->first()) {
            return $existing;
        }

        $disk = Storage::disk('local');

        if (! $disk->exists($session->storage_key)) {
            throw ValidationException::withMessages(['upload_session_id' => __('wave12.claim_evidence_upload_unreadable')]);
        }

        $bytes = $disk->get($session->storage_key);
        $sha256 = hash('sha256', $bytes);

        $duplicate = Document::where('tenant_id', $tenantId)->where('party_id', $party->id)->where('sha256', $sha256)->exists();

        abort_if($duplicate, 409, __('wave12.document_duplicate'));

        $scan = $this->scan($bytes, $session->mime_type);

        return DB::transaction(function () use ($tenantId, $party, $session, $bytes, $sha256, $scan, $user) {
            $document = Document::create([
                'tenant_id' => $tenantId,
                'party_id' => $party->id,
                'category' => 'CLAIM_EVIDENCE',
                'storage_key' => $session->storage_key,
                'mime_type' => $session->mime_type,
                'size_bytes' => strlen($bytes),
                'sha256' => $sha256,
                'scan_status' => $scan->status,
                'verification_status' => 'UNVERIFIED',
                'ocr_data' => [],
            ]);

            DB::table('document_versions')->insert([
                'id' => (string) Str::uuid(),
                'document_id' => $document->id,
                'version' => 1,
                'storage_key' => $session->storage_key,
                'sha256' => $sha256,
                'size_bytes' => strlen($bytes),
                'mime_type' => $session->mime_type,
                'uploaded_by' => $user->id,
                'created_at' => now(),
            ]);

            return $document;
        });
    }

    private function scan(string $bytes, string $mimeType): ScanResult
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'claim-evidence-scan-');
        file_put_contents($tempPath, $bytes);

        try {
            return $this->scanner->scan($tempPath, $mimeType);
        } finally {
            @unlink($tempPath);
        }
    }
}
