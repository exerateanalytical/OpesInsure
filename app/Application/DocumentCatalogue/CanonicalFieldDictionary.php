<?php

declare(strict_types=1);

namespace App\Application\DocumentCatalogue;

/**
 * Field vocabulary of the canonical document spec (document_system.field_groups FG-01..FG-15 and the
 * detailed field specs of the 36 critical documents) mapped onto the canonical keys the document engine
 * resolves from canonical entities (document_implementation_policy §1.3: party, policy, claim, payment ...).
 *
 * Three outcomes per spec bullet:
 *  - a canonical key (e.g. policy.number): enforced at generation when the bullet is not conditional —
 *    an empty value blocks issuance (§1.1, never a blank);
 *  - '@control:<name>': a security control (QR, seal, signature ...) applied by the security profile;
 *  - '@no_source': the platform has no canonical data model for it yet (territory, branch, clause
 *    references ...). Rendered as a marked PENDING_VERIFICATION placeholder (§1.1), never invented,
 *    and listed as a data-model gap in DOCUMENT_SPEC_GAP_AUDIT.md.
 * Bullets not listed here are UNMAPPED (PENDING_VERIFICATION), recorded per document, not enforced.
 */
final class CanonicalFieldDictionary
{
    /** Canonical keys the engine resolves, with bilingual labels. */
    public const KEYS = [
        'document.number' => ['Document number', 'Numéro du document'],
        'document.type_code' => ['Document type', 'Type de document'],
        'document.title' => ['Document title', 'Titre du document'],
        'document.template_version' => ['Template version', 'Version du modèle'],
        'document.issued_at' => ['Issue date', "Date d'émission"],
        'document.status' => ['Status', 'Statut'],
        'document.security_tier' => ['Security tier', 'Niveau de sécurité'],
        'issuer.legal_name' => ['Issuer', 'Émetteur'],
        'party.name' => ['Policyholder / insured', 'Souscripteur / assuré'],
        'policy.number' => ['Policy number', 'N° de police'],
        'policy.insurer' => ['Insurer', 'Assureur'],
        'policy.product' => ['Product', 'Produit'],
        'policy.insurance_class' => ['Insurance class', "Branche d'assurance"],
        'policy.effective_from' => ['Effective from', "Date d'effet"],
        'policy.effective_until' => ['Expiry', "Date d'échéance"],
        'policy.currency' => ['Currency', 'Devise'],
        'policy.version' => ['Policy version', 'Version de police'],
        'risk.summary' => ['Insured risk', 'Risque assuré'],
        'risk.registration_number' => ['Registration number', "N° d'immatriculation"],
        'risk.vin' => ['VIN / chassis number', 'N° de châssis (VIN)'],
        'risk.make' => ['Make', 'Marque'],
        'risk.model' => ['Model', 'Modèle'],
        'risk.usage' => ['Vehicle use', 'Usage du véhicule'],
        'coverage.lines' => ['Coverages', 'Garanties'],
        'premium.gross' => ['Gross premium', 'Prime TTC'],
        'premium.currency' => ['Premium currency', 'Devise de la prime'],
        'premium.taxes' => ['Taxes / levies / fees', 'Taxes / prélèvements / frais'],
        'payment.reference' => ['Payment reference', 'Référence du paiement'],
        'payment.amount' => ['Amount received', 'Montant reçu'],
        'payment.paid_at' => ['Payment date', 'Date du paiement'],
        'payment.method' => ['Payment method', 'Mode de paiement'],
        'payment.status' => ['Payment status', 'Statut du paiement'],
        'claim.number' => ['Claim number', 'N° de sinistre'],
        'claim.loss_date' => ['Loss date', 'Date du sinistre'],
        'claim.status' => ['Claim decision', 'Décision sinistre'],
        'endorsement.number' => ['Endorsement number', "N° d'avenant"],
        'endorsement.effective_at' => ['Endorsement effective date', "Date d'effet de l'avenant"],
        'endorsement.changes' => ['Changes made', 'Modifications'],
        'provider.name' => ['Provider', 'Prestataire'],
        'member.reference' => ['Member', 'Adhérent'],
        'treaty.reference' => ['Treaty / placement', 'Traité / placement'],
        'reinsurer.name' => ['Reinsurer', 'Réassureur'],
        'verification.code' => ['Short verification code', 'Code de vérification'],
        'verification.token' => ['Verification token', 'Jeton de vérification'],
        'template.reference' => ['Template code / version', 'Code / version du modèle'],
        'confidentiality.class' => ['Confidentiality', 'Confidentialité'],
    ];

    /**
     * Minimum enforced keys per universal field group (only fields every document of that group must
     * carry; the rest of each group is rendered when present). FG-12/FG-14 are controls / lifecycle
     * links, applied by the security profile and the status service rather than as data.
     */
    public const GROUP_KEYS = [
        'FG-01' => ['document.number', 'document.type_code', 'document.title', 'document.template_version', 'document.issued_at', 'document.security_tier'],
        'FG-02' => ['issuer.legal_name'],
        'FG-03' => ['party.name'],
        'FG-04' => ['policy.number', 'policy.insurer', 'policy.effective_from', 'policy.effective_until', 'policy.currency'],
        'FG-05' => ['risk.summary'],
        'FG-06' => ['coverage.lines'],
        'FG-07' => ['premium.gross', 'premium.currency'],
        'FG-08' => ['payment.reference', 'payment.amount', 'payment.paid_at'],
        'FG-09' => ['claim.number'],
        'FG-10' => ['provider.name', 'member.reference'],
        'FG-11' => ['treaty.reference', 'reinsurer.name'],
        'FG-12' => [],
        'FG-13' => ['verification.code', 'verification.token'],
        'FG-14' => [],
        'FG-15' => ['template.reference', 'confidentiality.class'],
    ];

    /** Universal groups every issued document carries (zones A, B, F, G of the A4 grid). */
    public const UNIVERSAL_GROUPS = ['FG-01', 'FG-02', 'FG-13', 'FG-15'];

    /**
     * Keyword -> field group, used to derive the applicable groups from a document's
     * "minimum additions" prose when the spec gives no explicit FG list (only DOC-001 and DOC-016 do).
     * Derivation is recorded as DERIVED_KEYWORD; explicit refs as EXPLICIT.
     */
    public const GROUP_KEYWORDS = [
        'FG-04' => '/\bpolic(y|ies)\b/i',
        'FG-03' => '/\b(parties|policyholder|insured\b|customer|claimant|applicant|payer)\b/i',
        'FG-05' => '/\b(risk|vehicle identity|insured object|insured risks?)\b/i',
        'FG-06' => '/\b(coverages?|limits|sums? insured)\b/i',
        'FG-07' => '/\bpremium\b/i',
        'FG-08' => '/\b(payment\/receipt|receipt number|payment reference)\b/i',
        'FG-09' => '/\bclaim (reference|number|identity)|^claim\b/i',
        'FG-10' => '/\b(provider|facility)\b/i',
        'FG-11' => '/\b(reinsur|cedant|treaty|co-insur)/i',
        'FG-12' => '/\b(authori[sz]ation|signature|signatory|approval|authority)\b/i',
        'FG-14' => '/\b(supersed|replac|revoc|duplicate)/i',
    ];

    /** Bullets that are conditional by their own wording are never enforced. */
    public const CONDITIONAL = '/\b(where (applicable|relevant|required|configured|different|appropriate|exposed|permitted|used)|when (applicable|appropriate|configured|verified|used)|if (offered|applicable|any|used|configured)|optional|as applicable)\b/i';

    /** normalized bullet => key | @control:x | @no_source */
    public const BULLETS = [
        // identity
        'quote number' => 'document.number', 'certificate number' => 'document.number', 'attestation number' => 'document.number',
        'receipt number' => 'document.number', 'cover-note number' => 'document.number', 'endorsement number' => 'endorsement.number',
        'invoice number' => 'document.number', 'statement number' => 'document.number', 'card number' => 'document.number',
        'quote status' => 'document.status', 'status' => 'document.status', 'current certificate status' => 'document.status',
        'issue date' => 'document.issued_at', 'issue date/time' => 'document.issued_at', 'schedule version' => 'document.template_version',
        'version/revision' => 'document.template_version',
        // parties
        'policyholder' => 'party.name', 'insured' => 'party.name', 'insured/policyholder' => 'party.name', 'customer' => 'party.name',
        'prospect/customer' => 'party.name', 'payer' => 'party.name', 'applicant' => 'party.name', 'claimant' => 'party.name',
        'insurer' => 'policy.insurer', 'authorized issuer' => 'issuer.legal_name', 'issuer' => 'issuer.legal_name', 'issued-by' => 'issuer.legal_name',
        'broker' => '@no_source', 'broker/agent' => '@no_source', 'agent/adviser' => '@no_source', 'branch' => '@no_source', 'issuing branch' => '@no_source',
        // policy
        'policy number' => 'policy.number', 'policy' => 'policy.number', 'policy/proposal/invoice' => 'policy.number', 'linked policy' => 'policy.number',
        'product' => 'policy.product', 'product/plan' => 'policy.product', 'product/class' => 'policy.product', 'plan/package' => '@no_source',
        'insurance class' => 'policy.insurance_class', 'currency' => 'policy.currency',
        'effective date' => 'policy.effective_from', 'effective date/time' => 'policy.effective_from', 'inception' => 'policy.effective_from',
        'expiry date' => 'policy.effective_until', 'expiry date/time' => 'policy.effective_until', 'expiry' => 'policy.effective_until',
        'effective/expiry dates' => ['policy.effective_from', 'policy.effective_until'], 'policy period' => ['policy.effective_from', 'policy.effective_until'],
        'period' => ['policy.effective_from', 'policy.effective_until'], 'resulting policy version' => 'policy.version', 'prior policy version' => 'policy.version',
        'territory' => '@no_source', 'renewal date/basis' => '@no_source', 'renewal terms' => '@no_source', 'special conditions' => '@no_source',
        'clause references' => '@no_source', 'exclusions' => '@no_source', 'cancellation/termination rules' => '@no_source',
        'linked general/special conditions' => '@no_source', 'limitations/reference to policy terms' => '@no_source', 'coverage period proposed' => '@no_source',
        // risk
        'insured risks' => 'risk.summary', 'insured risk(s)' => 'risk.summary', 'insured object/risk' => 'risk.summary',
        'full risk summary appropriate to the insurance class' => 'risk.summary',
        'registration number' => 'risk.registration_number', 'vin/chassis number' => 'risk.vin', 'vehicle make' => 'risk.make', 'make' => 'risk.make',
        'model' => 'risk.model', 'vehicle category/use' => 'risk.usage',
        // cover
        'coverage schedule' => 'coverage.lines', 'coverages' => 'coverage.lines', 'coverage' => 'coverage.lines', 'concise scope of cover' => 'coverage.lines',
        'relevant motor-cover identification' => 'coverage.lines', 'sums insured' => 'coverage.lines', 'limits' => 'coverage.lines',
        'limit/sum insured' => 'coverage.lines', 'deductibles' => 'coverage.lines', 'deductible' => 'coverage.lines',
        // premium / payment
        'premium' => 'premium.gross', 'gross premium' => 'premium.gross', 'taxes/levies' => 'premium.taxes', 'taxes/charges' => 'premium.taxes',
        'taxes/levies/fees' => 'premium.taxes', 'payment schedule' => '@no_source', 'payment terms' => '@no_source',
        'payment reference' => 'payment.reference', 'amount received' => 'payment.amount', 'payment date/time' => 'payment.paid_at',
        'payment method' => 'payment.method', 'mobile-money/bank/card reference' => 'payment.reference', 'reconciliation status' => 'payment.status',
        'allocation to obligations' => '@no_source', 'balance' => '@no_source', 'cashier/channel' => '@no_source',
        // claim
        'claim number' => 'claim.number', 'loss date' => 'claim.loss_date', 'decision' => 'claim.status',
        // endorsement
        'effective date/time of change' => 'endorsement.effective_at', 'exact change made' => 'endorsement.changes', 'request/reference' => 'endorsement.number',
        // security controls
        'qr' => '@control:qr', 'qr/verification' => '@control:qr', 'qr/hash' => '@control:hash', 'qr/hash/status' => '@control:qr',
        'qr/status' => '@control:qr', 'verification' => '@control:qr', 'short verification code' => 'verification.code',
        'authentication seal' => '@control:seal', 'signature' => '@control:signature', 'signatures' => '@control:signature',
        'issuer/signature' => '@control:signature', 'issuer/signatory' => '@control:signature', 'insurer authorization/signature' => '@control:signature',
        'authorization' => '@control:signature', 'verification/security controls' => '@control:qr',
        '`paid` only when appropriate' => '@control:status_overlay',
    ];

    public static function normalize(string $bullet): string
    {
        return trim(mb_strtolower(preg_replace('/\s+/', ' ', $bullet) ?? $bullet), " \t;.,:");
    }

    /** @return array{target: string|array<int,string>|null, conditional: bool} */
    public static function classify(string $bullet): array
    {
        $n = self::normalize($bullet);
        $conditional = (bool) preg_match(self::CONDITIONAL, $n);
        $base = trim((string) preg_replace(self::CONDITIONAL, '', $n), " \t;.,:");

        return ['target' => self::BULLETS[$n] ?? self::BULLETS[$base] ?? null, 'conditional' => $conditional];
    }

    /**
     * Applicable field groups of a document: explicit refs (with the "FG-01–07" ranges the JSON
     * conversion dropped, re-expanded from the text itself), universal groups, and keyword-derived groups.
     *
     * @param  array<int, string>  $explicit
     * @return array<string, string> group => EXPLICIT | UNIVERSAL | DERIVED_KEYWORD
     */
    public static function groupsFor(array $explicit, ?string $minimumAdditions): array
    {
        $groups = [];
        foreach ($explicit as $g) {
            $groups[$g] = 'EXPLICIT';
        }
        $text = (string) $minimumAdditions;
        if (preg_match_all('/FG-(\d{2})\s*[–-]\s*(?:FG-)?(\d{2})/u', $text, $m, PREG_SET_ORDER)) {
            foreach ($m as $r) {
                for ($i = (int) $r[1]; $i <= (int) $r[2]; $i++) {
                    $groups[sprintf('FG-%02d', $i)] ??= 'EXPLICIT';
                }
            }
        }
        foreach (self::UNIVERSAL_GROUPS as $g) {
            $groups[$g] ??= 'UNIVERSAL';
        }
        if ($explicit === []) {
            foreach (self::GROUP_KEYWORDS as $g => $re) {
                if (preg_match($re, $text)) {
                    $groups[$g] ??= 'DERIVED_KEYWORD';
                }
            }
        }
        ksort($groups);

        return $groups;
    }
}
