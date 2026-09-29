<?php

declare(strict_types=1);

namespace App\Application\Documents;

use App\Application\Documents\Scanning\DocumentScanQueue;
use App\Application\Documents\Adapters\SignedUrlAdapter;
use App\Application\Identity\PartyResolver;
use App\Models\Document;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Customer-facing "my documents" — distinct from the existing staff-facing
 * Documents\DocumentController, which has no ownership check at all (only
 * tenant scoping) and leaves signed-URL delivery as an explicit TODO
 * ('delivery_status' => 'SIGNED_URL_ADAPTER_REQUIRED'). Document already
 * has a direct party_id, so ownership mirrors MobileWalletService exactly.
 *
 * Upload is this service's own addition — the spec handed to this batch
 * (OPESINSURE_EXPO_PATCH_2_CUSTOMER_LIFECYCLE_v0.6.0's merge guide) only
 * lists GET /mobile/documents, GET /mobile/documents/{id} and
 * POST /mobile/documents/{id}/access; there is no upload row anywhere in
 * the mobile handoff docs for the *documents* capability specifically.
 * It's included because the batch instructions ask for one "if the
 * existing Document model supports customer uploads at all" (it does —
 * party_id is nullable and staff registration already sets it), and
 * because the whole point of this batch is a malware-scan gate that needs
 * something to gate. See the batch report for the base64-in-JSON transport
 * decision (kept inside the app's existing json.api middleware boundary
 * rather than carving out a multipart exception to it).
 */
final class MobileDocumentService
{
    /** @var array<string, string> declared MIME type => stored file extension */
    private const ALLOWED_MIME_TYPES = [
        'application/pdf' => 'pdf',
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
    ];

    // Matches the staff DocumentController::register() limit exactly, so a
    // file a staff member could register a customer could also upload.
    private const MAX_SIZE_BYTES = 20_971_520;

    private const ACCESS_TTL_SECONDS = 300;

    public function __construct(
        private PartyResolver $parties,
        private SignedUrlAdapter $signedUrls,
        private DocumentScanQueue $scans,
    ) {
    }

    /** Optional owner filter (the app's ?owner_type=POLICY|CLAIM&owner_id=). */
    public function list(User $user, string $tenantId, int $perPage = 20, ?string $ownerType = null, ?string $ownerId = null): LengthAwarePaginator
    {
        $column = ['POLICY' => 'policy_id', 'CLAIM' => 'claim_id'][strtoupper((string) $ownerType)] ?? null;

        return $this->ownedQuery($user, $tenantId)
            ->when($column && $ownerId && Str::isUuid($ownerId), fn ($q) => $q->where($column, $ownerId))
            ->orderByDesc('created_at')->paginate($perPage)
            ->through(fn (Document $d) => $this->present($d));
    }

    public function show(string $documentId, User $user, string $tenantId): Document
    {
        return $this->owned($documentId, $user, $tenantId);
    }

    /**
     * Issues a short-lived signed URL for an owned, CLEAN document and logs
     * the access — the two enforcement requirements the merge guide states
     * explicitly ("issue short-lived signed document URLs only after
     * authorization; record access in the audit log"). Reuses the same
     * document_access_log table and READ/purpose shape the staff
     * DocumentController::access() already writes to, rather than
     * inventing a second logging mechanism.
     *
     * @return array<string, mixed>
     */
    public function requestAccess(string $documentId, string $purpose, User $user, string $tenantId): array
    {
        $document = $this->owned($documentId, $user, $tenantId);

        if ($document->scan_status !== 'CLEAN') {
            throw ValidationException::withMessages(['document' => __('wave12.document_not_ready')]);
        }

        $signed = $this->signedUrls->sign($document, self::ACCESS_TTL_SECONDS);

        DB::table('document_access_log')->insert([
            'document_id' => $document->id,
            'actor_id' => $user->id,
            'action' => 'READ',
            'purpose' => $purpose,
            'request_id' => request()?->header('X-Request-Id') ?: (string) Str::uuid(),
            'occurred_at' => now(),
        ]);

        return array_merge($this->present($document), [
            'id' => $document->id,
            'url' => $signed->url,
            // The app opens signed_url (SecureDocument contract); url kept for older builds.
            'signed_url' => $signed->url,
            'expires_at' => $signed->expiresAt,
        ]);
    }

    /**
     * App-facing row (SecureDocument in the mobile app): the stored document plus owner_type/owner_id,
     * label, status, issued_at, expires_at and share_reference. Raw keys are kept for older builds.
     *
     * @return array<string, mixed>
     */
    public function present(Document $d): array
    {
        [$ownerType, $ownerId] = match (true) {
            $d->claim_id !== null => ['CLAIM', $d->claim_id],
            $d->policy_id !== null => ['POLICY', $d->policy_id],
            default => ['PARTY', $d->party_id],
        };

        return array_merge($d->toArray(), [
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'label' => $d->title ?? Str::headline(strtolower((string) ($d->document_type_code ?? $d->category))),
            'status' => $d->status ?? $d->verification_status,
            'issued_at' => ($d->issued_at ?? $d->created_at)?->toIso8601String(),
            'expires_at' => $d->valid_until?->toIso8601String(),
            'share_reference' => $d->document_number ?? $d->verification_code ?? strtoupper(substr((string) $d->id, -8)),
            'signed_url' => null,
        ]);
    }

    /**
     * Stores a customer-submitted document and runs it through the malware
     * scanner before it can ever be marked usable. The scan result is
     * whatever the bound MalwareScanAdapter genuinely returns — CLEAN only
     * comes from a real scan; with no scanner configured every upload in
     * this environment lands as FAILED (see FailClosedMalwareScanAdapter)
     * and stays inaccessible via requestAccess()/the download route until
     * a human clears it through the existing staff review() endpoint.
     *
     * @param  array{category: string, mime_type: string, file_base64: string}  $data
     * @return array<string, mixed>
     */
    /** $forParty (Q3 launch, agent-assisted capture): store the file as the given client's own document; the caller must have checked the book. */
    public function upload(array $data, User $user, string $tenantId, ?\App\Models\Party $forParty = null): array
    {
        $party = $forParty ?? $this->parties->forUser($user);

        if (! $party) {
            throw ValidationException::withMessages(['party' => __('wave12.document_no_party')]);
        }

        $mimeType = $data['mime_type'];

        if (! array_key_exists($mimeType, self::ALLOWED_MIME_TYPES)) {
            throw ValidationException::withMessages(['mime_type' => __('wave12.document_unsupported_type')]);
        }

        $bytes = base64_decode($data['file_base64'], true);

        if ($bytes === false || $bytes === '') {
            throw ValidationException::withMessages(['file_base64' => __('wave12.document_invalid_file')]);
        }

        if (strlen($bytes) > self::MAX_SIZE_BYTES) {
            throw ValidationException::withMessages(['file_base64' => __('wave12.document_too_large')]);
        }

        if (! \App\Application\Uploads\FileSignature::matches(substr($bytes, 0, 16), $mimeType)) {
            throw ValidationException::withMessages(['file_base64' => __('wave12.document_signature_mismatch')]);
        }

        $sha256 = hash('sha256', $bytes);

        // Scoped to this customer's own documents in this tenant, unlike
        // the staff endpoint's global sha256 uniqueness check — a
        // deliberate narrowing so one customer's upload can never collide
        // with an unrelated party's identical file (see batch report).
        $duplicate = Document::where('tenant_id', $tenantId)->where('party_id', $party->id)->where('sha256', $sha256)->exists();

        abort_if($duplicate, 409, __('wave12.document_duplicate'));

        $storageKey = sprintf('documents/%s/%s.%s', $tenantId, (string) Str::uuid(), self::ALLOWED_MIME_TYPES[$mimeType]);

        Storage::disk(config('filesystems.default'))->put($storageKey, $bytes);

        $disk = (string) config('filesystems.default');
        $document = DB::transaction(function () use ($tenantId, $party, $data, $storageKey, $mimeType, $bytes, $sha256, $user, $disk) {
            $document = Document::create([
                'tenant_id' => $tenantId,
                'party_id' => $party->id,
                'category' => $data['category'],
                'storage_key' => $storageKey,
                'mime_type' => $mimeType,
                'size_bytes' => strlen($bytes),
                'sha256' => $sha256,
                // Q1: always stored first, then scanned by ScanDocumentJob (never CLEAN without a real scan).
                'scan_status' => DocumentScanQueue::PENDING_SCAN,
                'verification_status' => 'UNVERIFIED',
                'ocr_data' => [],
            ]);

            DB::table('document_versions')->insert([
                'id' => (string) Str::uuid(),
                'document_id' => $document->id,
                'version' => 1,
                'storage_key' => $storageKey,
                'sha256' => $sha256,
                'size_bytes' => strlen($bytes),
                'mime_type' => $mimeType,
                'uploaded_by' => $user->id,
                'created_at' => now(),
            ]);

            $this->scans->enqueue($document, $disk, $user->id);

            return $document;
        });
        $document->refresh(); // the scan job has run already on a sync queue

        return [
            'id' => $document->id,
            'category' => $document->category,
            'mime_type' => $document->mime_type,
            'size_bytes' => $document->size_bytes,
            'scan_status' => $document->scan_status,
            'verification_status' => $document->verification_status,
            'usable' => $document->scan_status === 'CLEAN',
            // Held while the security check runs; the client shows this instead of a failure.
            'security_check_pending' => DocumentScanQueue::isHeld($document->scan_status),
            'message' => DocumentScanQueue::isHeld($document->scan_status) ? __('scan_queue.client.in_progress') : null,
            'created_at' => $document->created_at?->toIso8601String(),
        ];
    }

    private function owned(string $documentId, User $user, string $tenantId): Document
    {
        $document = $this->ownedQuery($user, $tenantId)->find($documentId);

        if (! $document) {
            $exists = Document::where('tenant_id', $tenantId)->where('id', $documentId)->exists();

            throw $exists ? new AuthorizationException : new ModelNotFoundException;
        }

        return $document;
    }

    private function ownedQuery(User $user, string $tenantId)
    {
        $party = $this->parties->forUser($user);
        $query = Document::where('tenant_id', $tenantId);

        if (! $party) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('party_id', $party->id);
    }
}
