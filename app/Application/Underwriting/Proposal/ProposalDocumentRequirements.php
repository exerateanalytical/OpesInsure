<?php

declare(strict_types=1);

namespace App\Application\Underwriting\Proposal;

use App\Application\DocumentCatalogue\DocumentCatalogueService;
use App\Application\Documents\IssuanceDocumentAcceptance;
use App\Models\Proposal;
use App\Models\ProposalDocument;

/**
 * REQ-PRP-003 / REQ-DUP-004 — risk-dependent proposal document requirements from the canonical document catalogue:
 * DocumentCatalogueService::requirementsFor(product version, PRE_CONTRACT) (matrix + approved insurer overrides).
 * The legacy document_requirement_versions table is no longer read by the proposal workflow (rows kept for history).
 *
 * A requirement is uploaded by the proposer when its issuer is the customer / a third party; insurer-issued
 * pre-contract documents (quote, information sheet…) are generated, not collected. The proposal form / risk
 * declaration itself is satisfied by the attested digital questionnaire (config proposals.documents).
 *
 * Status per requirement: MISSING | UPLOADED | REVIEWING | ACCEPTED | REJECTED | EXPIRED (PRE §36).
 * Owner decision 31: ACCEPTED only when manually accepted by a named reviewer or verified by an approved automated
 * control (IssuanceDocumentAcceptance); mandatory rows are ISSUANCE_REQUIRED.
 */
final class ProposalDocumentRequirements
{
    public function __construct(private readonly DocumentCatalogueService $catalogue) {}

    /** @return list<array<string,mixed>> */
    public function for(Proposal $p): array
    {
        $product = $p->offer?->product;
        if ($product === null) {
            return [];
        }
        $cfg = config('proposals.documents');
        $rows = $this->catalogue->requirementsFor($product, $cfg['stage']);
        $links = $p->documents()->with('document')->get();
        $out = [];
        foreach ($rows as $r) {
            $level = strtoupper((string) ($r['level'] ?? ''));
            // Collected from the proposer: customer / third-party issued rows, and every T (third-party supplied) row.
            if (! ($level === 'T' || in_array($r['issuer'] ?? null, $cfg['uploader_issuers'], true)) || ! in_array($level, [...$cfg['mandatory_levels'], ...$cfg['optional_levels']], true)) {
                continue;
            }
            $code = (string) ($r['canonical_code'] ?? $r['document_type_id']);
            $form = $this->formSatisfied($code);
            $key = $code.'|'.($r['variant_code'] ?? '');
            if (isset($out[$key])) {
                continue;
            }
            $link = $links->first(fn (ProposalDocument $l) => $l->requirement_code === $code || ($l->document_type_id !== null && $l->document_type_id === $r['document_type_id']));
            $out[$key] = [
                'code' => $code,
                'document_type_id' => $r['document_type_id'],
                'variant_code' => $r['variant_code'] ?? null,
                'label' => $r['matrix_label'] ?? $r['label_en'] ?? $code,
                'name' => array_filter(['en' => $r['label_en'] ?? null, 'fr' => $r['label_fr'] ?? null]),
                'level' => $level,
                'mandatory' => in_array($level, $cfg['mandatory_levels'], true),
                'issuance_required' => in_array($level, $cfg['mandatory_levels'], true),
                'accepted_by' => $form ? ($p->attested_at ? 'PROPOSAL_FORM' : null) : ($link ? IssuanceDocumentAcceptance::acceptedBy($link) : null),
                'satisfied_by' => $form ? 'PROPOSAL_FORM' : 'UPLOAD',
                'status' => $form ? ($p->attested_at ? 'ACCEPTED' : 'MISSING') : $this->status($link),
                'document_id' => $link?->document_id,
                'source' => $r['source'] ?? null,
            ];
        }

        return array_values($out);
    }

    /** Requirement (non form-satisfied) a proposer may upload against, by canonical code or type id. */
    public function uploadable(Proposal $p, string $code): ?array
    {
        $code = strtoupper(trim($code));
        foreach ($this->for($p) as $r) {
            if ($r['satisfied_by'] === 'UPLOAD' && ($r['code'] === $code || $r['document_type_id'] === $code)) {
                return $r;
            }
        }

        return null;
    }

    /**
     * Mandatory requirements not satisfied. Submission to underwriting needs every mandatory document provided
     * (UPLOADED / REVIEWING / ACCEPTED: the underwriter reviews them); issuance ($accepted = true, REQ-PRP-004)
     * needs every one ACCEPTED.
     *
     * @return list<string>
     */
    public function missing(Proposal $p, bool $accepted = false): array
    {
        // Submission accepts UPLOADED_NOT_YET_REVIEWED documents only where the product permits it.
        $ok = $accepted || ! IssuanceDocumentAcceptance::submissionAcceptsUnreviewed($p->offer?->product) ? ['ACCEPTED'] : ['UPLOADED', 'REVIEWING', 'ACCEPTED'];

        return array_values(array_map(fn ($r) => $r['code'], array_filter($this->for($p), fn ($r) => $r['mandatory'] && ! in_array($r['status'], $ok, true))));
    }

    private function status(?ProposalDocument $link): string
    {
        if ($link === null) {
            return 'MISSING';
        }
        $expires = $link->document?->valid_until;
        if ($expires !== null && now()->greaterThan($expires)) {
            return 'EXPIRED';
        }

        return match ($link->status) {
            'VERIFIED' => IssuanceDocumentAcceptance::accepted($link) ? 'ACCEPTED' : 'REVIEWING',
            'REJECTED' => 'REJECTED',
            default => $link->document?->scan_status === 'CLEAN' ? 'REVIEWING' : 'UPLOADED',
        };
    }

    private function formSatisfied(string $code): bool
    {
        foreach (config('proposals.documents.form_satisfied_patterns') as $re) {
            if (preg_match($re, $code)) {
                return true;
            }
        }

        return false;
    }
}
