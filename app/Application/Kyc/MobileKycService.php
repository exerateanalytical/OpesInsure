<?php

declare(strict_types=1);

namespace App\Application\Kyc;

use App\Application\Audit\AuditWriter;
use App\Application\Customers\PartyService;
use App\Application\Documents\MobileDocumentService;
use App\Application\Events\OutboxWriter;
use App\Application\Identity\PartyResolver;
use App\Models\KycSubmission;
use App\Models\Party;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Customer-facing KYC verification. Backend contract came from
 * OPESINSURE_EXPO_CUSTOMER_CORE_PATCH_v0.5.0's CLAUDE_MERGE_GUIDE.md:
 * `GET/PATCH /mobile/kyc/profile`, `POST /mobile/kyc/documents`,
 * `POST /mobile/kyc/submission`. That guide's own merge notes assumed a
 * dedicated multipart upload under /mobile/kyc/documents, but a real,
 * malware-scanned, ownership-scoped upload endpoint already exists
 * (POST /mobile/documents — MobileDocumentService) from the prior Documents
 * batch, so this service deliberately does NOT re-implement upload: the
 * mobile client uploads through that endpoint first, then references the
 * resulting Document id here. This mirrors exactly how MobileWalletService
 * / MobileDocumentService already scope everything to the caller's Party.
 *
 * There is no OCR/identity-verification provider anywhere in this codebase
 * (confirmed by grep before writing this batch — see OcrAdapter's
 * docblock). A submission therefore only ever reaches DRAFT or SUBMITTED
 * here; APPROVED/REJECTED are reserved columns for a future staff decision
 * endpoint that does not exist yet — see the batch report.
 */
final class MobileKycService
{
    public function __construct(
        private PartyResolver $parties,
        private PartyService $partyService,
        private MobileDocumentService $documents,
        private AuditWriter $audit,
        private OutboxWriter $outbox,
    ) {
    }

    /** @return array<string, mixed> */
    public function profile(User $user, string $tenantId): array
    {
        $party = $this->parties->forUser($user);

        if (! $party) {
            return ['party_id' => null, 'display_name' => null, 'identifiers' => [], 'submission' => null];
        }

        $submission = KycSubmission::where('tenant_id', $tenantId)->where('party_id', $party->id)->latest('created_at')->first();

        return [
            'party_id' => $party->id,
            'display_name' => $party->display_name,
            'identifiers' => $this->identifiers($party),
            'submission' => $submission ? $this->presentSubmission($submission) : null,
        ];
    }

    /**
     * @param  array{identifier_type: string, identifier_value: string, identifier_country?: string}  $data
     * @return array<string, mixed>
     */
    public function updateProfile(array $data, User $user, string $tenantId): array
    {
        $party = $this->requireParty($user);

        // Reuses PartyService::addIdentifier verbatim — the same
        // encrypt/hash/mask/duplicate-check logic PartyService::create()
        // already uses for registration (see tests/Feature/Wave1/PartyIdentityTest.php).
        $this->partyService->addIdentifier($party, $data['identifier_type'], $data['identifier_value'], $data['identifier_country'] ?? 'CM');

        return $this->profile($user, $tenantId);
    }

    /** @return array<string, mixed> */
    public function attachDocument(string $documentId, string $purpose, User $user, string $tenantId): array
    {
        $party = $this->requireParty($user);

        // Ownership (403 vs 404) reused from MobileDocumentService rather
        // than re-implementing the owned()/ownedQuery() pair a third time.
        $document = $this->documents->show($documentId, $user, $tenantId);

        if ($document->scan_status === 'INFECTED') {
            throw ValidationException::withMessages(['document_id' => [__('wave12.kyc_document_infected')]]);
        }

        $submission = $this->draftSubmission($party, $tenantId);

        // Pre-check rather than catching the pivot's unique violation as
        // flow control: on Postgres a constraint violation aborts the whole
        // surrounding transaction (SQLSTATE 25P02), so every later statement
        // fails until rollback — which would silently break any caller that
        // composes this inside a DB::transaction(). Same explicit-duplicate-
        // check shape MobileDocumentService::upload() already uses.
        // 409, not the default 422: a conflict with the submission's current
        // state, matching that duplicate-upload check and
        // EnforcesOptimisticConcurrency's stale-write status.
        if ($submission->documents()->where('documents.id', $document->id)->exists()) {
            throw ValidationException::withMessages(['document_id' => [__('wave12.kyc_document_already_attached')]])->status(409);
        }

        try {
            // Wrapped so that if a concurrent request wins the race between
            // the check above and this insert, the violation rolls back to
            // this statement's own savepoint instead of poisoning an
            // enclosing transaction.
            DB::transaction(fn () => $submission->documents()->attach($document->id, ['purpose' => $purpose]));
        } catch (QueryException $e) {
            if (! self::isUniqueViolation($e)) {
                throw $e;
            }

            throw ValidationException::withMessages(['document_id' => [__('wave12.kyc_document_already_attached')]])->status(409);
        }

        $this->audit->record('kyc_submission.document_attached', 'kyc_submission', $submission->id, ['document_id' => $document->id, 'purpose' => $purpose]);

        return $this->presentSubmission($submission->fresh());
    }

    /** @return array<string, mixed> */
    public function submit(?string $notes, User $user, string $tenantId): array
    {
        $party = $this->requireParty($user);

        $submission = KycSubmission::where('tenant_id', $tenantId)->where('party_id', $party->id)->where('status', 'DRAFT')->latest('created_at')->first();

        // Validate everything before the transaction opens — throwing
        // inside DB::transaction() would roll back writes made earlier in
        // the same closure (see the batch instructions' DB::transaction
        // hazard note); there is nothing to roll back here because nothing
        // has been mutated yet.
        if (! $submission || $submission->documents()->count() === 0) {
            throw ValidationException::withMessages(['submission' => [__('wave12.kyc_no_documents')]]);
        }

        if ($submission->documents()->where('scan_status', '!=', 'CLEAN')->exists()) {
            throw ValidationException::withMessages(['submission' => [__('wave12.kyc_documents_not_ready')]]);
        }

        DB::transaction(function () use ($submission, $notes, $party, $tenantId) {
            $submission->update(['status' => 'SUBMITTED', 'submitted_at' => now(), 'notes' => $notes]);
            $this->audit->record('kyc_submission.submitted', 'kyc_submission', $submission->id, ['party_id' => $party->id]);
            $this->outbox->record('kyc_submission.submitted', 'kyc_submission', $submission->id, ['kyc_submission_id' => $submission->id, 'tenant_id' => $tenantId]);
        });

        return $this->presentSubmission($submission->fresh());
    }

    private function draftSubmission(Party $party, string $tenantId): KycSubmission
    {
        return KycSubmission::where('tenant_id', $tenantId)->where('party_id', $party->id)->where('status', 'DRAFT')->latest('created_at')->first()
            ?? KycSubmission::create(['tenant_id' => $tenantId, 'party_id' => $party->id, 'status' => 'DRAFT']);
    }

    /** @return array<int, array<string, mixed>> */
    private function identifiers(Party $party): array
    {
        return $party->identifiers()->get()->map(fn ($identifier) => [
            'type' => $identifier->type,
            'country_code' => $identifier->country_code,
            'masked_value' => $identifier->masked_value,
            'verified_at' => $identifier->verified_at?->toIso8601String(),
        ])->all();
    }

    /** @return array<string, mixed> */
    private function presentSubmission(KycSubmission $submission): array
    {
        return [
            'id' => $submission->id,
            'status' => $submission->status,
            'notes' => $submission->notes,
            'submitted_at' => $submission->submitted_at?->toIso8601String(),
            'reviewed_at' => $submission->reviewed_at?->toIso8601String(),
            'documents' => $submission->documents()->get()->map(fn ($document) => [
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

    private static function isUniqueViolation(QueryException $e): bool
    {
        return ($e->errorInfo[0] ?? null) === '23505';
    }
}
