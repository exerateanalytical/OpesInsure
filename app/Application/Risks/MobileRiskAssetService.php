<?php

declare(strict_types=1);

namespace App\Application\Risks;

use App\Application\Documents\Adapters\OcrAdapter;
use App\Application\Documents\MobileDocumentService;
use App\Application\Identity\PartyResolver;
use App\Models\Party;
use App\Models\RiskAsset;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Customer-facing "my insured assets" — distinct from the existing
 * staff-facing RiskAssetController, which has no ownership check at all
 * (only tenant scoping, and it trusts a client-supplied party_id). Backend
 * contract came from OPESINSURE_EXPO_CUSTOMER_CORE_PATCH_v0.5.0's
 * CLAUDE_MERGE_GUIDE.md: `GET/POST /mobile/assets`, `GET /mobile/assets/{id}`,
 * `POST /mobile/assets/{id}/documents`, `POST /mobile/assets/{id}/scan`,
 * `POST /mobile/assets/{id}/scan/{documentId}/confirm`.
 *
 * Deliberately reuses the existing RiskAssetService for create/update
 * rather than duplicating its business logic (customer-of-tenant check,
 * facts_hash, version/event bookkeeping, audit, outbox) — this service only
 * adds ownership scoping and the document-evidence/OCR layer on top.
 *
 * "scan"/"confirm" exist because ocr_data already exists on Document but
 * nothing in this codebase populates it (confirmed by grep — see
 * OcrAdapter). With no real OCR provider, scan() always comes back
 * MANUAL_REVIEW_REQUIRED and confirm() is the honest substitute: the
 * customer types in the facts themselves after reading their own document,
 * exactly the way an unconfigured malware scanner holds a document for a
 * human instead of pretending to have scanned it.
 */
final class MobileRiskAssetService
{
    public function __construct(
        private PartyResolver $parties,
        private RiskAssetService $assets,
        private MobileDocumentService $documents,
        private OcrAdapter $ocr,
    ) {
    }

    public function list(User $user, string $tenantId, int $perPage = 20): LengthAwarePaginator
    {
        return $this->ownedQuery($user, $tenantId)->with('documents')->orderByDesc('created_at')->paginate($perPage);
    }

    public function show(string $assetId, User $user, string $tenantId): RiskAsset
    {
        return $this->owned($assetId, $user, $tenantId)->load('documents');
    }

    /** @param array{type: string, external_reference?: ?string, display_name: string, facts: array} $data */
    public function create(array $data, User $user, string $tenantId): RiskAsset
    {
        $party = $this->requireParty($user);
        $tenant = Tenant::findOrFail($tenantId);

        try {
            return $this->assets->create($tenant, $party, $data, $user);
        } catch (QueryException $e) {
            if (! self::isUniqueViolation($e)) {
                throw $e;
            }

            // tenant_id+type+external_reference is unique at the DB level
            // (risk_assets table) — translate the raw constraint violation
            // into the same mobile-friendly 409 shape used elsewhere
            // (e.g. MobileDocumentService's duplicate-upload check) instead
            // of letting a Postgres error surface to the app.
            throw ValidationException::withMessages(['external_reference' => [__('wave12.asset_duplicate_reference')]])->status(409);
        }
    }

    /** @return array<string, mixed> */
    public function attachDocument(string $assetId, string $documentId, string $purpose, User $user, string $tenantId): array
    {
        $asset = $this->owned($assetId, $user, $tenantId);
        $document = $this->documents->show($documentId, $user, $tenantId);

        if ($document->scan_status === 'INFECTED') {
            throw ValidationException::withMessages(['document_id' => [__('wave12.kyc_document_infected')]]);
        }

        // Pre-check instead of catching the pivot's unique violation: on
        // Postgres a constraint violation aborts the surrounding transaction
        // (SQLSTATE 25P02) until rollback. See MobileKycService::attachDocument
        // for the same reasoning.
        if ($asset->documents()->where('documents.id', $document->id)->exists()) {
            throw ValidationException::withMessages(['document_id' => [__('wave12.asset_document_already_attached')]])->status(409);
        }

        try {
            DB::transaction(fn () => $asset->documents()->attach($document->id, ['purpose' => $purpose]));
        } catch (QueryException $e) {
            if (! self::isUniqueViolation($e)) {
                throw $e;
            }

            throw ValidationException::withMessages(['document_id' => [__('wave12.asset_document_already_attached')]])->status(409);
        }

        return $this->present($asset->fresh()->load('documents'));
    }

    /**
     * Requests OCR extraction for one attached document (documentId given)
     * or every attached document that hasn't been scanned yet. See the
     * class docblock: with no real provider bound, this always comes back
     * MANUAL_REVIEW_REQUIRED — it never fabricates extracted fields.
     *
     * @return array<string, mixed>
     */
    public function requestScan(string $assetId, ?string $documentId, User $user, string $tenantId): array
    {
        $asset = $this->owned($assetId, $user, $tenantId)->load('documents');

        $targets = $documentId ? $asset->documents->where('id', $documentId) : $asset->documents;

        if ($targets->isEmpty()) {
            throw ValidationException::withMessages(['document_id' => [__('wave12.asset_document_not_attached')]]);
        }

        foreach ($targets as $document) {
            if ($document->scan_status !== 'CLEAN') {
                throw ValidationException::withMessages(['document_id' => [__('wave12.asset_document_not_clean')]]);
            }

            $result = $this->ocr->extract($document);

            $document->update(['ocr_data' => [
                'status' => $result->status,
                'provider' => $result->provider,
                'fields' => $result->fields,
                'requested_at' => now()->toIso8601String(),
            ]]);
        }

        return $this->present($asset->fresh()->load('documents'));
    }

    /**
     * The manual-confirmation counterpart to requestScan(): the customer
     * supplies the facts themselves (e.g. having read their own vehicle
     * registration), merged into the asset's existing facts via the
     * existing RiskAssetService::update() — reusing its optimistic
     * concurrency (version) and audit/outbox bookkeeping rather than
     * building a second update path.
     *
     * @param  array<string, mixed>  $facts
     * @return array<string, mixed>
     */
    public function confirmScan(string $assetId, string $documentId, int $expectedVersion, array $facts, User $user, string $tenantId): array
    {
        $asset = $this->owned($assetId, $user, $tenantId)->load('documents');
        $document = $asset->documents->firstWhere('id', $documentId);

        if (! $document) {
            throw ValidationException::withMessages(['document_id' => [__('wave12.asset_document_not_attached')]]);
        }

        $merged = array_merge($asset->facts ?? [], $facts);
        $updated = $this->assets->update($asset, $expectedVersion, ['facts' => $merged], $user);

        // Not auto-VERIFIED: a customer confirming what they read off their
        // own document is not the same as a staff member independently
        // verifying it (see the staff DocumentController::review() gate).
        $document->update([
            'ocr_data' => array_merge($document->ocr_data ?? [], [
                'status' => 'CUSTOMER_CONFIRMED',
                'confirmed_fields' => array_keys($facts),
                'confirmed_at' => now()->toIso8601String(),
            ]),
            'verification_status' => 'NEEDS_REVIEW',
        ]);

        return $this->present($updated->load('documents'));
    }

    /** @return array<string, mixed> */
    private function present(RiskAsset $asset): array
    {
        return [
            'id' => $asset->id,
            'type' => $asset->type,
            'display_name' => $asset->display_name,
            'external_reference' => $asset->external_reference,
            'facts' => $asset->facts,
            'status' => $asset->status,
            'version' => $asset->version,
            'documents' => $asset->documents->map(fn ($document) => [
                'id' => $document->id,
                'purpose' => $document->pivot->purpose,
                'category' => $document->category,
                'scan_status' => $document->scan_status,
                'verification_status' => $document->verification_status,
                'ocr_data' => $document->ocr_data,
            ])->all(),
        ];
    }

    private function requireParty(User $user): Party
    {
        return $this->parties->forUser($user) ?? throw ValidationException::withMessages(['party' => [__('wave12.document_no_party')]]);
    }

    private function owned(string $assetId, User $user, string $tenantId): RiskAsset
    {
        $asset = $this->ownedQuery($user, $tenantId)->find($assetId);

        if (! $asset) {
            $exists = RiskAsset::where('tenant_id', $tenantId)->where('id', $assetId)->exists();

            throw $exists ? new AuthorizationException : new ModelNotFoundException;
        }

        return $asset;
    }

    private function ownedQuery(User $user, string $tenantId)
    {
        $party = $this->parties->forUser($user);
        $query = RiskAsset::where('tenant_id', $tenantId);

        if (! $party) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('party_id', $party->id);
    }

    private static function isUniqueViolation(QueryException $e): bool
    {
        return ($e->errorInfo[0] ?? null) === '23505';
    }
}
