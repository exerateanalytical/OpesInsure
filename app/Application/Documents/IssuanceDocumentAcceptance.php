<?php

declare(strict_types=1);

namespace App\Application\Documents;

use App\Application\Audit\AuditWriter;
use App\Models\Document;
use App\Models\InsuranceProduct;
use App\Models\Proposal;
use App\Models\ProposalDocument;
use Illuminate\Validation\ValidationException;

/**
 * Owner decision 31 (2026-09-25): issuance requires every ISSUANCE_REQUIRED (mandatory) proposal document to be
 * ACCEPTED, i.e. either
 *   - manually accepted: status VERIFIED with a named reviewer (verified_by) — verification_method MANUAL, or
 *   - verified by an APPROVED automated control: verification_method AUTOMATED_CONTROL with a control code listed in
 *     config('proposals.documents.approved_automated_controls') (empty until the owner approves one).
 * A VERIFIED link with neither (e.g. written directly, or by a control that is not / no longer approved) is not
 * accepted: it stays REVIEWING.
 *
 * Submission may accept UPLOADED_NOT_YET_REVIEWED documents where the product permits
 * (insurance_products.submission_accepts_unreviewed_documents, NULL = config default).
 */
final class IssuanceDocumentAcceptance
{
    public const MANUAL = 'MANUAL';

    public const AUTOMATED_CONTROL = 'AUTOMATED_CONTROL';

    public function __construct(private readonly AuditWriter $audit) {}

    /** @return list<string> */
    public static function approvedControls(): array
    {
        return array_values(array_filter((array) config('proposals.documents.approved_automated_controls', [])));
    }

    /** True when a VERIFIED link counts as accepted for issuance. */
    public static function accepted(ProposalDocument $link): bool
    {
        if ($link->status !== 'VERIFIED') {
            return false;
        }
        $method = $link->getAttribute('verification_method');
        if ($method === self::AUTOMATED_CONTROL) {
            return in_array($link->getAttribute('automated_control_code'), self::approvedControls(), true);
        }

        return $link->getAttribute('verified_by') !== null;
    }

    /** Acceptance evidence label for a link (MANUAL / AUTOMATED_CONTROL:<code>), or null. */
    public static function acceptedBy(ProposalDocument $link): ?string
    {
        if (! self::accepted($link)) {
            return null;
        }

        return $link->getAttribute('verification_method') === self::AUTOMATED_CONTROL ? self::AUTOMATED_CONTROL.':'.$link->getAttribute('automated_control_code') : self::MANUAL;
    }

    /** Whether submission may proceed with mandatory documents UPLOADED but not yet reviewed. */
    public static function submissionAcceptsUnreviewed(?InsuranceProduct $product): bool
    {
        $flag = $product?->getAttribute('submission_accepts_unreviewed_documents');

        return $flag === null ? (bool) config('proposals.documents.submission_accepts_unreviewed', true) : (bool) $flag;
    }

    /** Records a verification by an approved automated control (refused for any control the owner has not approved). */
    public function verifyByControl(Proposal $p, Document $d, string $controlCode, array $evidence = []): ProposalDocument
    {
        if (! in_array($controlCode, self::approvedControls(), true)) {
            throw ValidationException::withMessages(['control' => "Automated control {$controlCode} is not approved for document acceptance."]);
        }
        if ($d->scan_status !== 'CLEAN') {
            throw ValidationException::withMessages(['status' => __('wave3.clean_document_required')]);
        }
        $link = ProposalDocument::where(['proposal_id' => $p->id, 'document_id' => $d->id])->firstOrFail();
        ProposalDocument::where(['proposal_id' => $p->id, 'document_id' => $d->id])->update(['status' => 'VERIFIED', 'verification_method' => self::AUTOMATED_CONTROL,
            'automated_control_code' => $controlCode, 'verified_by' => null, 'verified_at' => now(), 'review_notes' => 'Automated control '.$controlCode]);
        $this->audit->record('proposal.document.verified_by_control', 'document', $d->id, ['proposal_id' => $p->id, 'control' => $controlCode, 'evidence' => $evidence, 'previous_status' => $link->status]);

        return ProposalDocument::where(['proposal_id' => $p->id, 'document_id' => $d->id])->firstOrFail();
    }
}
