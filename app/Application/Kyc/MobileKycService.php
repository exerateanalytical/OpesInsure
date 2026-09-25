<?php

declare(strict_types=1);

namespace App\Application\Kyc;

use App\Application\Customers\PartyService;
use App\Application\Documents\MobileDocumentService;
use App\Application\Identity\PartyResolver;
use App\Models\KycSubmission;
use App\Models\Party;
use App\Models\User;
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
 * here.
 *
 * REQ-DUP-007 (KYC): this is now a thin customer adapter. Every rule — attach, submit, the KYC_REVIEW
 * case, levels, screening, maker-checker decisions, expiry and remediation — lives in the canonical
 * App\Application\Kyc\KycService; this class only resolves the caller's Party and Document ownership.
 */
final class MobileKycService
{
    public function __construct(
        private PartyResolver $parties,
        private PartyService $partyService,
        private MobileDocumentService $documents,
        private KycService $kyc,
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

        // Ownership (403 vs 404) reused from MobileDocumentService; the attach rules live in KycService.
        $document = $this->documents->show($documentId, $user, $tenantId);
        $submission = $this->kyc->attachDocument($this->kyc->draftFor($party, $tenantId), $document, $purpose, $user);

        return $this->presentSubmission($submission);
    }

    /** @return array<string, mixed> */
    public function submit(?string $notes, User $user, string $tenantId): array
    {
        $party = $this->requireParty($user);
        $submission = KycSubmission::where('tenant_id', $tenantId)->where('party_id', $party->id)->whereIn('status', KycService::EDITABLE)->latest('created_at')->first();
        if (! $submission) {
            throw ValidationException::withMessages(['submission' => [__('wave12.kyc_no_documents')]]);
        }

        return $this->presentSubmission($this->kyc->submit($submission, $notes, $user));
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
        return $this->kyc->present($submission);
    }

    private function requireParty(User $user): Party
    {
        return $this->parties->forUser($user) ?? throw ValidationException::withMessages(['party' => [__('wave12.document_no_party')]]);
    }
}
