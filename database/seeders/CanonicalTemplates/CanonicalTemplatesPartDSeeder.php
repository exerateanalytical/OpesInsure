<?php

declare(strict_types=1);

namespace Database\Seeders\CanonicalTemplates;

/**
 * Canonical PLATFORM templates, part D: DOC-166..DOC-220 (J Claims / assessment / settlement / recovery,
 * K Finance / billing / commission / settlement, L Reinsurance / co-insurance / provider / compliance / regulatory).
 * Contract: docs/spec/canonical/TEMPLATE_CONTENT_CONTRACT.md (lifecycle, publication and idempotency in CanonicalTemplateSeeder).
 *
 * Sources: OpesInsure_220_Documents_and_First_5_Master_Shells_v1 (names EN/FR), OpesInsure_220_Document_Field_Data_Specification_v1
 * (§3 detailed specs for DOC-169/174/177/179/181/186/190/194/197/201/206/208/215/219, §4 field list for the others).
 * Every spec field is a `content.fields` entry: the key is the canonical detailed_field_map target when one exists, otherwise
 * a dotted key in the document's namespace (the engine fills it when an event supplies it, else prints "Not recorded").
 * Intro and confidentiality texts are neutral: no legal clause, rate, limit or deadline beyond the spec. Amounts, tier,
 * verification, letterhead and footer come from the engine and the shell, never from the template.
 */
final class CanonicalTemplatesPartDSeeder extends CanonicalTemplateSeeder
{
    /** Namespace of the dotted key given to a spec field that has no canonical key yet. */
    private const NAMESPACES = [[166, 185, 'claim'], [186, 193, 'billing'], [194, 200, 'statement'], [201, 210, 'reinsurance'], [211, 214, 'coinsurance'],
        [215, 216, 'provider_contract'], [217, 218, 'kyc'], [219, 219, 'regulatory'], [220, 220, 'regulatory_audit']];

    protected function documents(): array
    {
        $out = [];
        foreach (self::definitions() as $specId => $def) {
            $n = (int) substr($specId, 4);
            $ns = 'document';
            foreach (self::NAMESPACES as [$from, $to, $name]) {
                if ($n >= $from && $n <= $to) {
                    $ns = $name;
                }
            }
            $fields = [];
            foreach ($def['groups'] as [, , $items]) {
                foreach ($items as $f) {
                    $key = $f[2] ?? null;
                    if ($key === null) {
                        $key = $ns.'.'.trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower(trim(explode('(', $f[0])[0]))), '_');
                    }
                    $fields[] = [$key, $f[0], $f[1], self::zoneOf($key)];
                }
            }
            $sections = [];
            if (! empty($def['restricted'])) {
                $sections[] = ['heading_en' => 'Confidentiality', 'heading_fr' => 'Confidentialité',
                    'body_en' => 'This document contains restricted information. Access is limited to the recipients authorised by its confidentiality class.',
                    'body_fr' => 'Ce document contient des informations à diffusion restreinte. L’accès est limité aux destinataires autorisés par sa classe de confidentialité.'];
            }
            $out[$specId] = ['intro_en' => $def['intro'][0], 'intro_fr' => $def['intro'][1], 'fields' => $fields, 'sections' => $sections,
                'title_en' => $def['en'], 'title_fr' => $def['fr']];
        }

        return $out;
    }

    /** Public view of the part D definitions in contract form (tests, audits). */
    public function contractDocuments(): array
    {
        return $this->documents();
    }

    private static function zoneOf(string $key): string
    {
        foreach (['party.', 'insured.', 'beneficiary.', 'risk.', 'consent.', 'intermediary.', 'member.', 'provider.', 'reinsurer.', 'payee.'] as $p) {
            if (str_starts_with($key, $p)) {
                return 'C';
            }
        }

        return 'D';
    }

    /**
     * spec id => code, names, tier, intro [en, fr], groups [heading_en, heading_fr, fields [label_en, label_fr, key|null, money?]].
     *
     * @return array<string, array<string, mixed>>
     */
    public static function definitions(): array
    {
        $claim = ['Claim number', 'Numéro de sinistre', 'claim.number'];
        $policy = ['Policy', 'Police', 'policy.number'];
        $lossDate = ['Loss date', 'Date du sinistre', 'claim.loss_date'];
        $claimant = ['Claimant', 'Déclarant', 'party.claimant'];
        $currency = ['Currency', 'Devise', 'policy.currency'];
        $contact = ['Insurer contact', 'Contact de l’assureur', 'issuer.contact'];
        $period = ['Period', 'Période', 'statement.period'];
        $status = ['Status', 'Statut', 'document.status'];
        $approval = ['Preparation / approval', 'Préparation / approbation', 'approval.record'];
        $treaty = ['Treaty', 'Traité', 'treaty.reference'];
        $cedant = ['Cedant', 'Cédante', 'reinsurance.cedant'];
        $reinsurer = ['Reinsurer', 'Réassureur', 'reinsurer.name'];
        $insurer = ['Insurer', 'Assureur', 'policy.insurer'];
        $provider = ['Provider', 'Prestataire', 'provider.name'];

        return [
            // ── J. Claims, assessment, settlement & recovery ──────────────────────────────────────────
            'DOC-166' => ['code' => 'CLAIM_INVESTIGATION_NOTICE', 'en' => 'Claim Investigation Notice', 'fr' => 'Avis d’enquête sinistre', 'tier' => 'S3', 'restricted' => true,
                'intro' => ['This notice informs the recipient that the claim is subject to an investigation.', 'Le présent avis informe le destinataire que le sinistre fait l’objet d’une enquête.'],
                'groups' => [
                    ['Claim', 'Sinistre', [$claim, $policy, $lossDate]],
                    ['Investigation', 'Enquête', [['Investigation scope', 'Périmètre de l’enquête', null], ['Reason / category', 'Motif / catégorie', 'claim.reason_codes'], ['Investigator', 'Enquêteur', 'claim.assigned_to']]],
                    ['Rights and obligations', 'Droits et obligations', [['Rights and obligations of the parties', 'Droits et obligations des parties', null]]],
                    ['Contact', 'Contact', [$contact]],
                ]],
            'DOC-167' => ['code' => 'EXPERT_APPOINTMENT', 'en' => 'Expert / Adjuster Appointment', 'fr' => 'Mandat d’expertise', 'tier' => 'S3',
                'intro' => ['This document appoints an expert or loss adjuster for the claim.', 'Le présent document désigne un expert pour le sinistre.'],
                'groups' => [
                    ['Claim', 'Sinistre', [$claim, $policy, $lossDate]],
                    ['Appointment', 'Mandat', [['Appointed expert', 'Expert désigné', 'claim.assessor'], ['Appointment reference', 'Référence du mandat', 'claim.assessment_number'], ['Scope', 'Étendue de la mission', null], ['Authority', 'Pouvoirs conférés', 'approval.authority']]],
                    ['Deadlines and reporting', 'Délais et rapport', [['Deadlines', 'Délais', 'sla.due_at'], ['Reporting instructions', 'Instructions de rapport', null]]],
                    ['Contact', 'Contact', [$contact]],
                ]],
            'DOC-168' => ['code' => 'INSPECTION_APPOINTMENT', 'en' => 'Inspection Appointment Notice', 'fr' => 'Convocation à expertise', 'tier' => 'S2',
                'intro' => ['This notice sets the inspection appointment for the claim or insured risk.', 'La présente convocation fixe le rendez-vous d’expertise du sinistre ou du risque assuré.'],
                'groups' => [
                    ['Claim / risk', 'Sinistre / risque', [$claim, $policy, ['Insured risk', 'Risque assuré', 'risk.summary']]],
                    ['Inspection', 'Expertise', [['Inspection date and time', 'Date et heure de l’expertise', null], ['Inspection location', 'Lieu de l’expertise', 'claim.loss_location'], ['Parties required to attend', 'Parties convoquées', 'claim.involved_parties']]],
                    ['To present', 'À présenter', [['Documents and items to present', 'Documents et objets à présenter', 'documents.required']]],
                ]],
            'DOC-169' => ['code' => 'CLAIM_ASSESSMENT_REPORT', 'en' => 'Claim Assessment Report', 'fr' => 'Rapport d’expertise sinistre', 'tier' => 'S3', 'restricted' => true,
                'intro' => ['This report records the assessment of the claim by the appointed assessor.', 'Le présent rapport consigne l’expertise du sinistre par l’expert désigné.'],
                'groups' => [
                    ['Claim and appointment', 'Sinistre et mandat', [['Claim', 'Sinistre', 'claim.number'], $policy, ['Assessor / adjuster', 'Expert', 'claim.assessor'], ['Appointment reference', 'Référence du mandat', 'claim.assessment_number'], ['Inspection date', 'Date d’inspection', null], $lossDate]],
                    ['Loss and evidence', 'Sinistre et preuves', [['Affected risk', 'Risque concerné', 'risk.summary'], ['Cause', 'Cause', 'claim.cause'], ['Observed damage', 'Dommages constatés', 'claim.assessment_rationale'], ['Evidence reviewed', 'Pièces examinées', 'claim.evidence'], ['Photographs / attachments', 'Photographies / pièces jointes', 'claim.evidence']]],
                    ['Valuation', 'Évaluation', [['Repair / replacement assessment', 'Évaluation réparation / remplacement', 'claim.assessment_heads'], ['Pre-loss value where applicable', 'Valeur avant sinistre le cas échéant', 'risk.value', true], ['Salvage', 'Sauvetage', 'claim.recoveries'], ['Depreciation where applicable', 'Vétusté le cas échéant', 'settlement.breakdown'], ['Estimated loss', 'Perte estimée', 'claim.estimated_loss', true], ['Deductible', 'Franchise', 'coverage.lines']]],
                    ['Recommendation', 'Recommandation', [['Recommended settlement', 'Règlement recommandé', 'claim.recommended_total', true], ['Coverage observations', 'Observations sur la garantie', 'claim.coverage_check'], ['Reservations', 'Réserves', 'claim.assessment_review_note'], ['Conflicts / limitations', 'Conflits / limites', null]]],
                    ['Declaration', 'Déclaration', [['Assessor declaration', 'Déclaration de l’expert', null], ['Signature / date', 'Signature / date', 'approval.reviewed_at']]],
                ]],
            'DOC-170' => ['code' => 'CLAIM_VALUATION', 'en' => 'Claim Valuation Statement', 'fr' => 'Évaluation du sinistre', 'tier' => 'S3',
                'intro' => ['This statement sets out the valuation of the loss from gross loss to net assessed loss.', 'Le présent état présente l’évaluation du sinistre, de la perte brute à la perte nette évaluée.'],
                'groups' => [
                    ['Claim', 'Sinistre', [$claim, $policy, $lossDate]],
                    ['Damaged items / injuries', 'Biens endommagés / préjudices', [['Damaged items / injuries', 'Biens endommagés / préjudices corporels', 'claim.assessment_heads']]],
                    ['Valuation', 'Évaluation', [['Gross loss', 'Perte brute', 'settlement.gross', true], ['Depreciation', 'Vétusté', 'settlement.breakdown'], ['Salvage', 'Sauvetage', 'claim.recoveries'], ['Deductible', 'Franchise', 'coverage.lines'], ['Net assessed loss', 'Perte nette évaluée', 'claim.recommended_total', true], $currency]],
                ]],
            'DOC-171' => ['code' => 'REPAIR_AUTHORIZATION', 'en' => 'Repair Authorization', 'fr' => 'Autorisation de réparation', 'tier' => 'S4',
                'intro' => ['This document authorises the repairer named below to carry out the stated work within the authorised ceiling.', 'Le présent document autorise le réparateur désigné à exécuter les travaux indiqués dans la limite du plafond autorisé.'],
                'groups' => [
                    ['Claim and object', 'Sinistre et objet', [$claim, $policy, ['Vehicle / property / equipment', 'Véhicule / bien / équipement', 'risk.summary'], ['Registration number', 'Immatriculation', 'risk.registration_number']]],
                    ['Authorised work', 'Travaux autorisés', [['Repairer', 'Réparateur', null], ['Authorised work', 'Travaux autorisés', 'claim.assessment_heads'], ['Authorised ceiling', 'Plafond autorisé', 'decision.approved_amount', true], ['Exclusions', 'Exclusions', 'coverage.exclusions'], ['Validity', 'Validité', 'document.validity']]],
                    ['Authorization', 'Autorisation', [['Authorised by', 'Autorisé par', 'approval.decided_by'], ['Delegated authority reference', 'Référence de délégation', 'approval.authority']]],
                ]],
            'DOC-172' => ['code' => 'MEDICAL_ASSESSMENT_REQUEST', 'en' => 'Medical Assessment Request', 'fr' => 'Demande d’expertise médicale', 'tier' => 'S3', 'restricted' => true,
                'intro' => ['This document requests a medical assessment for the claim or member.', 'Le présent document demande une expertise médicale pour le sinistre ou l’adhérent.'],
                'groups' => [
                    ['Claim / member', 'Sinistre / adhérent', [$claim, ['Member', 'Adhérent', 'member.reference'], $policy]],
                    ['Assessment', 'Expertise', [['Medical question / scope', 'Question médicale / étendue', null], ['Appointed clinician', 'Médecin désigné', 'claim.assessor'], ['Requested reports / examinations', 'Rapports / examens demandés', 'documents.required']]],
                    ['Confidentiality', 'Confidentialité', [['Confidentiality class', 'Classe de confidentialité', 'confidentiality.class']]],
                ]],
            'DOC-173' => ['code' => 'MEDICAL_ASSESSMENT_REPORT', 'en' => 'Medical Assessment Report', 'fr' => 'Rapport d’expertise médicale', 'tier' => 'S4', 'restricted' => true,
                'intro' => ['This report records the medical assessment. It contains restricted medical data.', 'Le présent rapport consigne l’expertise médicale. Il contient des données médicales à diffusion restreinte.'],
                'groups' => [
                    ['Claim / member', 'Sinistre / adhérent', [$claim, ['Member', 'Adhérent', 'member.reference'], $policy]],
                    ['Assessment', 'Expertise', [['Assessor', 'Expert médical', 'claim.assessor'], ['Findings', 'Constatations', 'claim.assessment_rationale'], ['Causation / functional information as permitted', 'Causalité / information fonctionnelle dans les limites autorisées', null], ['Conclusions', 'Conclusions', 'claim.assessment_review_note']]],
                    ['Confidentiality', 'Confidentialité', [['Confidentiality class', 'Classe de confidentialité', 'confidentiality.class']]],
                ]],
            'DOC-174' => ['code' => 'CLAIM_DECISION', 'en' => 'Claim Decision', 'fr' => 'Décision sur sinistre', 'tier' => 'S4',
                'intro' => ['This document records the insurer’s decision on the claim.', 'Le présent document consigne la décision de l’assureur sur le sinistre.'],
                'groups' => [
                    ['Claim', 'Sinistre', [['Decision reference', 'Référence de la décision', 'claim.decision_id'], $claim, $policy, ['Insured / claimant', 'Assuré / déclarant', 'party.claimant'], $lossDate, ['Coverage assessed', 'Garantie examinée', 'claim.coverage_check']]],
                    ['Decision', 'Décision', [['Decision: approved / partially approved / rejected', 'Décision : acceptée / partiellement acceptée / rejetée', 'claim.decision'], ['Gross assessed loss', 'Perte brute évaluée', 'settlement.gross', true], ['Deductible', 'Franchise', 'coverage.lines'], ['Limits / sublimits', 'Plafonds / sous-plafonds', 'coverage.limits'], ['Prior payments', 'Paiements antérieurs', 'settlement.prior_payments', true], ['Recoveries / offsets where applicable', 'Recours / compensations le cas échéant', 'claim.recoveries'], ['Approved amount', 'Montant accepté', 'decision.approved_amount', true], ['Rejected amount', 'Montant rejeté', 'settlement.excluded', true]]],
                    ['Reasons', 'Motifs', [['Reason codes', 'Codes motifs', 'claim.reason_codes'], ['Explanation', 'Explication', 'claim.decision_rationale'], ['Policy clause / coverage references', 'Références des clauses / garanties', 'claim.coverage_check']]],
                    ['Authority', 'Autorité', [['Approving officer', 'Décideur', 'approval.decided_by'], ['Delegated authority reference', 'Référence de délégation', 'approval.authority'], ['Decision timestamp', 'Horodatage de la décision', 'claim.decided_at']]],
                    ['Appeal / review', 'Recours / réexamen', [['Appeal / review mechanism where applicable', 'Voie de recours / réexamen le cas échéant', 'claim.appeal']]],
                ]],
            'DOC-175' => ['code' => 'PARTIAL_APPROVAL_NOTICE', 'en' => 'Partial Approval Notice', 'fr' => 'Notification d’acceptation partielle', 'tier' => 'S4',
                'intro' => ['This notice informs the claimant that the claim is accepted in part.', 'La présente notification informe le déclarant que le sinistre est accepté partiellement.'],
                'groups' => [
                    ['Claim', 'Sinistre', [$claim, $policy, $claimant]],
                    ['Components', 'Composantes', [['Approved component', 'Composante acceptée', 'claim.assessment_heads'], ['Approved amount', 'Montant accepté', 'decision.approved_amount', true], ['Rejected / deferred component', 'Composante rejetée / différée', null], ['Rejected / deferred amount', 'Montant rejeté / différé', 'settlement.excluded', true]]],
                    ['Reasons and next steps', 'Motifs et suites', [['Reasons', 'Motifs', 'claim.decision_rationale'], ['Next steps', 'Prochaines étapes', 'claim.appeal']]],
                ]],
            'DOC-176' => ['code' => 'CLAIM_REJECTION', 'en' => 'Claim Rejection Letter', 'fr' => 'Lettre de rejet', 'tier' => 'S4',
                'intro' => ['This letter informs the claimant that the claim, or the part stated below, is not accepted.', 'La présente lettre informe le déclarant que le sinistre, ou la partie indiquée ci-dessous, n’est pas accepté.'],
                'groups' => [
                    ['Claim', 'Sinistre', [$claim, $policy, $claimant, $lossDate]],
                    ['Rejection', 'Rejet', [['Rejected amount / scope', 'Montant / étendue rejetés', 'settlement.excluded', true], ['Reasons', 'Motifs', 'claim.decision_rationale'], ['Reason codes', 'Codes motifs', 'claim.reason_codes'], ['Policy / coverage references', 'Références police / garantie', 'claim.coverage_check']]],
                    ['Review / appeal route', 'Voie de réexamen / recours', [['Review / appeal route', 'Voie de réexamen / recours', 'claim.appeal']]],
                ]],
            'DOC-177' => ['code' => 'SETTLEMENT_OFFER', 'en' => 'Settlement Offer', 'fr' => 'Offre d’indemnisation', 'tier' => 'S4',
                'intro' => ['This document sets out the insurer’s settlement offer for the claim.', 'Le présent document présente l’offre d’indemnisation de l’assureur pour le sinistre.'],
                'groups' => [
                    ['Offer', 'Offre', [['Settlement reference', 'Référence du règlement', 'settlement.reference'], ['Claim', 'Sinistre', 'claim.number'], ['Claimant / payee', 'Déclarant / bénéficiaire', 'settlement.payee'], ['Offer amount', 'Montant de l’offre', 'settlement.amount', true], $currency]],
                    ['Calculation', 'Calcul', [['Calculation', 'Calcul', 'settlement.breakdown'], ['Deductible', 'Franchise', 'coverage.lines'], ['Prior / interim payments', 'Paiements antérieurs / provisionnels', 'settlement.prior_payments', true], ['Tax / withholding where applicable', 'Taxe / retenue le cas échéant', null]]],
                    ['Conditions and acceptance', 'Conditions et acceptation', [['Release / discharge requirements', 'Exigences de quittance', 'settlement.discharge'], ['Validity period of offer', 'Durée de validité de l’offre', null], ['Acceptance instructions', 'Modalités d’acceptation', 'template.statement.offer_acceptance'], ['Banking / payment requirements', 'Coordonnées bancaires / de paiement requises', 'payee.bank'], ['Conditions', 'Conditions', 'decision.conditions'], $contact]],
                ]],
            'DOC-178' => ['code' => 'SETTLEMENT_ACCEPTANCE', 'en' => 'Settlement Acceptance', 'fr' => 'Acceptation d’indemnisation', 'tier' => 'S4',
                'intro' => ['This document records the claimant’s acceptance of the settlement offer.', 'Le présent document consigne l’acceptation de l’offre d’indemnisation par le déclarant.'],
                'groups' => [
                    ['Claim / offer', 'Sinistre / offre', [$claim, ['Settlement offer reference', 'Référence de l’offre', 'settlement.reference'], $policy]],
                    ['Acceptance', 'Acceptation', [['Accepted amount', 'Montant accepté', 'settlement.amount', true], $currency, ['Accepted conditions', 'Conditions acceptées', 'decision.conditions'], ['Claimant acceptance', 'Acceptation du déclarant', 'settlement.payee']]],
                    ['Payment details', 'Coordonnées de paiement', [['Bank / payment details', 'Coordonnées bancaires / de paiement', 'payee.bank']]],
                    ['Signature', 'Signature', [['Signature / timestamp', 'Signature / horodatage', 'settlement.discharge_signed_at']]],
                ]],
            'DOC-179' => ['code' => 'CLAIM_DISCHARGE', 'en' => 'Claim Discharge', 'fr' => 'Quittance / Décharge de règlement', 'tier' => 'S4',
                'intro' => ['This document records the discharge given on settlement of the claim.', 'Le présent document constate la quittance donnée lors du règlement du sinistre.'],
                'groups' => [
                    ['Claim', 'Sinistre', [$claim, $policy, ['Claimant / payee', 'Déclarant / bénéficiaire', 'settlement.payee']]],
                    ['Settlement', 'Règlement', [['Agreed amount', 'Montant convenu', 'settlement.amount', true], $currency, ['Nature of settlement', 'Nature du règlement', 'settlement.nature'], ['Payment details / reference where appropriate', 'Détails / référence du paiement le cas échéant', 'claim_payment.reference']]],
                    ['Discharge', 'Quittance', [['Scope of discharge / release', 'Étendue de la quittance', null], ['Outstanding / reserved matters if partial', 'Points réservés en cas de règlement partiel', 'claim.reserve_outstanding'], ['Declaration', 'Déclaration', 'template.statement.discharge']]],
                    ['Signatures', 'Signatures', [['Witness / approval if required', 'Témoin / approbation si requis', 'approval.decided_by'], ['Date', 'Date', 'settlement.discharge_signed_at']]],
                ]],
            'DOC-180' => ['code' => 'CLAIM_PAYMENT_ADVICE', 'en' => 'Claim Payment Advice', 'fr' => 'Avis de paiement sinistre', 'tier' => 'S4',
                'intro' => ['This advice confirms a payment made on the claim.', 'Le présent avis confirme un paiement effectué au titre du sinistre.'],
                'groups' => [
                    ['Claim', 'Sinistre', [$claim, $policy]],
                    ['Payment', 'Paiement', [['Payee', 'Bénéficiaire', 'settlement.payee'], ['Payment amount', 'Montant payé', 'settlement.amount', true], $currency, ['Payment date', 'Date de paiement', 'claim_payment.status'], ['Method / reference', 'Mode / référence', 'claim_payment.reference'], ['Settlement type', 'Type de règlement', 'settlement.nature'], ['Payment status', 'Statut du paiement', 'claim_payment.status']]],
                ]],
            'DOC-181' => ['code' => 'CLAIM_SETTLEMENT_STATEMENT', 'en' => 'Claim Settlement Statement', 'fr' => 'Décompte d’indemnisation', 'tier' => 'S4',
                'intro' => ['This statement sets out the claim calculation from the assessed amount to the net amount payable.', 'Le présent décompte présente le calcul du sinistre, du montant évalué au montant net à payer.'],
                'groups' => [
                    ['Claim', 'Sinistre', [$claim, ['Claimant', 'Déclarant', 'party.name'], $policy]],
                    ['Calculation', 'Calcul', [['Assessed amount', 'Montant évalué', 'settlement.gross', true], ['Approved amount', 'Montant accepté', 'decision.approved_amount', true], ['Deductible', 'Franchise', 'coverage.lines'], ['Limit application', 'Application du plafond', 'settlement.limit'], ['Depreciation', 'Vétusté', 'settlement.breakdown'], ['Salvage deduction', 'Déduction du sauvetage', 'settlement.breakdown'], ['Prior payment', 'Paiement antérieur', 'settlement.prior_payments', true], ['Recovery / offset', 'Recours / compensation', 'settlement.adjustments', true], ['Taxes / charges if applicable', 'Taxes / frais le cas échéant', 'premium.taxes', true], ['Net payable', 'Net à payer', 'settlement.amount', true]]],
                    ['Payment', 'Paiement', [['Payee', 'Bénéficiaire', 'settlement.payee'], ['Payment method / reference', 'Mode / référence de paiement', 'claim_payment.reference'], ['Payment date / status', 'Date / statut du paiement', 'claim_payment.status'], ['Remaining reserve or claim status', 'Réserve restante ou statut du sinistre', 'claim.reserve_outstanding']]],
                ]],
            'DOC-182' => ['code' => 'CLAIM_CLOSURE_NOTICE', 'en' => 'Claim Closure Notice', 'fr' => 'Avis de clôture du sinistre', 'tier' => 'S3',
                'intro' => ['This notice informs the recipient that the claim file is closed.', 'Le présent avis informe le destinataire de la clôture du dossier sinistre.'],
                'groups' => [
                    ['Claim', 'Sinistre', [$claim, $policy, $claimant]],
                    ['Closure', 'Clôture', [['Closure date', 'Date de clôture', null], ['Closure reason', 'Motif de clôture', 'claim.status'], ['Final decision', 'Décision finale', 'claim.decision'], ['Final payment', 'Paiement final', 'settlement.amount', true]]],
                    ['Recovery / appeal', 'Recours / réclamation', [['Recovery status where relevant', 'Statut du recours le cas échéant', 'claim.recoveries'], ['Appeal status where relevant', 'Statut de la réclamation le cas échéant', 'claim.appeal']]],
                ]],
            'DOC-183' => ['code' => 'CLAIM_APPEAL', 'en' => 'Claim Appeal', 'fr' => 'Recours contre décision', 'tier' => 'S3',
                'intro' => ['This document records an appeal against a claim decision.', 'Le présent document enregistre un recours contre une décision sur sinistre.'],
                'groups' => [
                    ['Claim / decision', 'Sinistre / décision', [$claim, ['Decision appealed', 'Décision contestée', 'claim.decision_id'], $policy]],
                    ['Appeal', 'Recours', [['Appellant', 'Auteur du recours', 'party.claimant'], ['Grounds', 'Motifs du recours', null], ['Requested remedy', 'Demande formulée', null], ['Evidence', 'Pièces justificatives', 'claim.evidence'], ['Submission date', 'Date de dépôt', null]]],
                ]],
            'DOC-184' => ['code' => 'APPEAL_DECISION', 'en' => 'Appeal Decision', 'fr' => 'Décision sur recours', 'tier' => 'S4',
                'intro' => ['This document records the decision taken on the appeal.', 'Le présent document consigne la décision prise sur le recours.'],
                'groups' => [
                    ['Appeal / claim', 'Recours / sinistre', [['Appeal reference', 'Référence du recours', 'claim.appeal'], $claim, ['Reviewed decision', 'Décision réexaminée', 'claim.decision_id']]],
                    ['Outcome', 'Issue', [['Outcome', 'Issue du recours', 'claim.decision'], ['Reasons', 'Motifs', 'claim.decision_rationale'], ['Amount changes', 'Modification des montants', 'decision.approved_amount', true], ['Final / next-review status', 'Caractère définitif / réexamen suivant', null]]],
                    ['Authority', 'Autorité', [['Decided by', 'Décidé par', 'approval.decided_by'], ['Decision timestamp', 'Horodatage de la décision', 'claim.decided_at']]],
                ]],
            'DOC-185' => ['code' => 'SUBROGATION_RECOVERY_NOTICE', 'en' => 'Subrogation / Recovery Notice', 'fr' => 'Avis de subrogation / recours', 'tier' => 'S4',
                'intro' => ['This notice sets out the recovery pursued by the insurer in respect of the claim.', 'Le présent avis présente le recours exercé par l’assureur au titre du sinistre.'],
                'groups' => [
                    ['Claim', 'Sinistre', [$claim, $policy, $lossDate]],
                    ['Recovery', 'Recours', [['Recovery basis', 'Fondement du recours', null], ['Target party', 'Partie visée', 'claim.involved_parties'], ['Insurer rights', 'Droits de l’assureur', null], ['Amount sought', 'Montant réclamé', 'claim.recoveries', true], ['Evidence / reference', 'Pièces / référence', 'claim.evidence']]],
                    ['Contact', 'Contact', [$contact]],
                ]],

            // ── K. Finance, billing, commission & settlement ─────────────────────────────────────────
            'DOC-186' => ['code' => 'PREMIUM_INVOICE', 'en' => 'Premium Invoice', 'fr' => 'Facture de prime', 'tier' => 'S2',
                'intro' => ['This invoice states the premium and charges due under the policy.', 'La présente facture indique la prime et les frais dus au titre de la police.'],
                'groups' => [
                    ['Invoice', 'Facture', [['Invoice number', 'Numéro de facture', 'document.number'], ['Policy / proposal', 'Police / proposition', 'policy.number'], ['Billed party', 'Partie facturée', 'party.name'], ['Insurer / payee', 'Assureur / bénéficiaire', 'policy.insurer'], ['Billing period', 'Période de facturation', 'policy.period']]],
                    ['Amounts', 'Montants', [['Premium components', 'Composantes de la prime', 'premium.components'], ['Taxes', 'Taxes', 'premium.taxes', true], ['Levies', 'Prélèvements', 'premium.taxes', true], ['Fees', 'Frais', 'premium.fees', true], ['Total', 'Total', 'obligation.amount', true], ['Amount previously paid', 'Montant déjà payé', 'obligation.paid', true], ['Balance', 'Solde', null, true]]],
                    ['Payment', 'Paiement', [['Due date', 'Date d’échéance', 'obligation.due_at'], ['Payment instructions', 'Instructions de paiement', 'payment.instructions'], ['Payment references', 'Références de paiement', 'payment.reference'], ['Branch / contact', 'Agence / contact', 'branch.name']]],
                ]],
            'DOC-187' => ['code' => 'PREMIUM_NOTICE', 'en' => 'Premium Notice', 'fr' => 'Avis de prime', 'tier' => 'S2',
                'intro' => ['This notice informs the policyholder of premium falling due.', 'Le présent avis informe le souscripteur de la prime arrivant à échéance.'],
                'groups' => [
                    ['Policy', 'Police', [$policy, ['Policyholder', 'Souscripteur', 'party.name']]],
                    ['Premium due', 'Prime due', [['Premium due', 'Prime due', 'obligation.amount', true], ['Instalment', 'Échéance fractionnée', 'premium.instalments'], ['Due date', 'Date d’échéance', 'obligation.due_at'], ['Outstanding balance', 'Solde restant dû', 'obligation.outstanding', true]]],
                    ['Payment', 'Paiement', [['Payment instructions', 'Instructions de paiement', 'payment.instructions']]],
                ]],
            'DOC-188' => ['code' => 'DEBIT_NOTE', 'en' => 'Debit Note', 'fr' => 'Note de débit', 'tier' => 'S2',
                'intro' => ['This note records an amount debited to the account or policy.', 'La présente note constate un montant porté au débit du compte ou de la police.'],
                'groups' => [
                    ['Account / policy', 'Compte / police', [['Account / policy', 'Compte / police', 'policy.number'], ['Account holder', 'Titulaire', 'party.name']]],
                    ['Debit', 'Débit', [['Debit reason', 'Motif du débit', 'endorsement.type'], ['Amount', 'Montant', 'obligation.amount', true], ['Tax treatment', 'Traitement fiscal', 'premium.taxes', true], ['Date', 'Date', 'document.issued_at'], ['Resulting balance', 'Solde résultant', 'obligation.outstanding', true]]],
                ]],
            'DOC-189' => ['code' => 'CREDIT_NOTE', 'en' => 'Credit Note', 'fr' => 'Note de crédit', 'tier' => 'S2',
                'intro' => ['This note records an amount credited to the account or policy.', 'La présente note constate un montant porté au crédit du compte ou de la police.'],
                'groups' => [
                    ['Account / policy', 'Compte / police', [['Account / policy', 'Compte / police', 'policy.number'], ['Account holder', 'Titulaire', 'party.name'], ['Original invoice / debit reference', 'Référence de la facture / note de débit d’origine', null]]],
                    ['Credit', 'Crédit', [['Credit reason', 'Motif du crédit', 'cancellation.reason'], ['Amount', 'Montant', 'refund.amount', true], ['Resulting balance', 'Solde résultant', 'obligation.outstanding', true]]],
                ]],
            'DOC-190' => ['code' => 'PREMIUM_RECEIPT', 'en' => 'Premium Receipt', 'fr' => 'Quittance de prime', 'tier' => 'S3', 'shell' => 'TPL-SHELL-PREMIUM-RECEIPT-001',
                'intro' => ['This receipt acknowledges the premium payment received.', 'La présente quittance constate le paiement de prime reçu.'],
                'groups' => [
                    ['Receipt', 'Quittance', [['Receipt number', 'Numéro de quittance', 'document.number'], ['Payment reference', 'Référence du paiement', 'payment.reference'], ['Payer', 'Payeur', 'party.name'], ['Customer', 'Client', 'party.name'], ['Policy / proposal / invoice', 'Police / proposition / facture', 'policy.number']]],
                    ['Payment', 'Paiement', [['Amount received', 'Montant reçu', 'payment.amount', true], $currency, ['Amount in words where configured', 'Montant en lettres si configuré', 'payment.amount_words'], ['Payment date / time', 'Date / heure du paiement', 'payment.paid_at'], ['Payment method', 'Mode de paiement', 'payment.method'], ['Mobile-money / bank / card reference', 'Référence mobile money / banque / carte', 'payment.reference']]],
                    ['Allocation and status', 'Imputation et statut', [['Allocation to obligations', 'Imputation aux échéances', null], ['Balance', 'Solde', null, true], ['Reconciliation status', 'Statut de rapprochement', 'payment.status'], ['Cashier / channel', 'Caissier / canal', null], ['Reversal / refund status where applicable', 'Statut d’annulation / remboursement le cas échéant', 'refund.status']]],
                ]],
            'DOC-191' => ['code' => 'PAYMENT_RECEIPT', 'en' => 'Payment Receipt', 'fr' => 'Reçu de paiement', 'tier' => 'S3',
                'intro' => ['This receipt acknowledges the payment transaction described below.', 'Le présent reçu constate l’opération de paiement décrite ci-dessous.'],
                'groups' => [
                    ['Transaction', 'Opération', [['Payment transaction', 'Opération de paiement', 'payment.reference'], ['Payer', 'Payeur', 'party.name'], ['Payee', 'Bénéficiaire', 'policy.insurer'], ['Purpose', 'Objet du paiement', 'policy.number']]],
                    ['Payment', 'Paiement', [['Amount', 'Montant', 'payment.amount', true], $currency, ['Date', 'Date', 'payment.paid_at'], ['Method', 'Mode', 'payment.method'], ['External reference', 'Référence externe', 'payment.reference'], ['Allocation / status', 'Imputation / statut', 'payment.status']]],
                ]],
            'DOC-192' => ['code' => 'REFUND_ADVICE', 'en' => 'Refund Advice', 'fr' => 'Avis de remboursement', 'tier' => 'S3',
                'intro' => ['This advice informs the beneficiary of a refund.', 'Le présent avis informe le bénéficiaire d’un remboursement.'],
                'groups' => [
                    ['Refund', 'Remboursement', [['Refund number', 'Numéro de remboursement', 'document.number'], ['Original payment', 'Paiement d’origine', 'payment.reference'], ['Beneficiary', 'Bénéficiaire', 'party.name'], $policy, ['Reason', 'Motif', 'cancellation.reason']]],
                    ['Amount and payment', 'Montant et paiement', [['Amount', 'Montant', 'refund.amount', true], $currency, ['Method / reference', 'Mode / référence', 'payment.method'], ['Approval / payment status', 'Statut d’approbation / de paiement', 'refund.status']]],
                ]],
            'DOC-193' => ['code' => 'CUSTOMER_STATEMENT', 'en' => 'Customer Account Statement', 'fr' => 'Relevé de compte client', 'tier' => 'S2',
                'intro' => ['This statement shows the movements on the customer account for the period.', 'Le présent relevé présente les mouvements du compte client sur la période.'],
                'groups' => [
                    ['Account', 'Compte', [['Customer / account', 'Client / compte', 'party.name'], $period]],
                    ['Movements', 'Mouvements', [['Opening balance', 'Solde d’ouverture', 'statement.opening_balance', true], ['Debits', 'Débits', 'statement.items'], ['Credits', 'Crédits', 'statement.items'], ['Payments', 'Paiements', 'statement.collected_premium', true], ['Refunds', 'Remboursements', 'refund.amount', true], ['Closing balance', 'Solde de clôture', 'statement.closing_balance', true], $currency]],
                ]],
            'DOC-194' => ['code' => 'BROKER_STATEMENT', 'en' => 'Broker Statement', 'fr' => 'Relevé courtier', 'tier' => 'S3',
                'intro' => ['This statement sets out the account between the broker and the insurer for the reporting period.', 'Le présent relevé présente le compte entre le courtier et l’assureur pour la période.'],
                'groups' => [
                    ['Statement', 'Relevé', [['Statement number', 'Numéro du relevé', 'document.number'], ['Broker', 'Courtier', null], $insurer, ['Reporting period', 'Période', 'statement.period'], $currency]],
                    ['Premium', 'Primes', [['Opening balance', 'Solde d’ouverture', 'statement.opening_balance', true], ['Policies / transactions', 'Polices / opérations', 'statement.items'], ['Written premium', 'Primes émises', 'statement.written_premium', true], ['Collected premium', 'Primes encaissées', 'statement.collected_premium', true], ['Outstanding premium', 'Primes impayées', 'obligation.outstanding', true], ['Cancellations / refunds', 'Annulations / remboursements', 'statement.items']]],
                    ['Settlement', 'Règlement', [['Commissions', 'Commissions', 'statement.commission', true], ['Taxes / levies', 'Taxes / prélèvements', 'premium.taxes', true], ['Remittances', 'Reversements', 'statement.remittances', true], ['Adjustments', 'Ajustements', 'statement.adjustments', true], ['Closing balance', 'Solde de clôture', 'statement.closing_balance', true], ['Settlement due', 'Règlement dû', 'statement.closing_balance', true], ['Reconciliation status', 'Statut de rapprochement', 'payment.status'], $approval]],
                ]],
            'DOC-195' => ['code' => 'AGENT_COMMISSION_STATEMENT', 'en' => 'Agent Commission Statement', 'fr' => 'Relevé de commission agent', 'tier' => 'S3',
                'intro' => ['This statement sets out the commission of the agent for the period.', 'Le présent relevé présente les commissions de l’agent pour la période.'],
                'groups' => [
                    ['Agent', 'Agent', [['Agent', 'Agent', 'intermediary.name'], $period]],
                    ['Commission', 'Commissions', [['Policies', 'Polices', 'statement.items'], ['Commission basis / rate', 'Assiette / taux de commission', null], ['Earned commission', 'Commissions acquises', 'statement.commission', true], ['Reversals / clawbacks', 'Annulations / reprises', 'statement.adjustments', true], ['Paid', 'Payé', 'statement.remittances', true], ['Outstanding', 'Restant dû', 'statement.closing_balance', true], $currency]],
                ]],
            'DOC-196' => ['code' => 'BROKER_COMMISSION_STATEMENT', 'en' => 'Broker Commission Statement', 'fr' => 'Relevé de commission courtier', 'tier' => 'S3',
                'intro' => ['This statement sets out the commission of the broker for the period.', 'Le présent relevé présente les commissions du courtier pour la période.'],
                'groups' => [
                    ['Broker', 'Courtier', [['Broker', 'Courtier', 'intermediary.name'], $period]],
                    ['Commission', 'Commissions', [['Policy transactions', 'Opérations sur polices', 'statement.items'], ['Commission basis / rate', 'Assiette / taux de commission', null], ['Adjustments', 'Ajustements', 'statement.adjustments', true], ['Tax', 'Taxe', 'premium.taxes', true], ['Payable', 'À payer', 'statement.closing_balance', true], ['Paid', 'Payé', 'statement.remittances', true], $currency]],
                ]],
            'DOC-197' => ['code' => 'CARRIER_SETTLEMENT_STATEMENT', 'en' => 'Carrier Settlement Statement', 'fr' => 'Relevé de règlement assureur', 'tier' => 'S4',
                'intro' => ['This statement sets out the settlement between the broker and the insurer for the period.', 'Le présent relevé présente le règlement entre le courtier et l’assureur pour la période.'],
                'groups' => [
                    ['Settlement', 'Règlement', [['Settlement number', 'Numéro de règlement', 'settlement_batch.number'], $insurer, ['Broker', 'Courtier', null], ['Period', 'Période', 'policy.period'], ['Policies / receipts included', 'Polices / quittances incluses', 'settlement_batch.items']]],
                    ['Amounts', 'Montants', [['Gross premium collected', 'Primes brutes encaissées', 'settlement_batch.gross', true], ['Taxes / levies', 'Taxes / prélèvements', 'premium.taxes', true], ['Commissions retained / payable', 'Commissions retenues / à payer', 'settlement_batch.commission', true], ['Adjustments', 'Ajustements', 'statement.adjustments', true], ['Refunds', 'Remboursements', 'refund.amount', true], ['Prior balance', 'Solde antérieur', null, true], ['Amount due insurer', 'Montant dû à l’assureur', 'settlement_batch.net', true], ['Amount paid', 'Montant payé', 'settlement_batch.paid', true]]],
                    ['Reconciliation', 'Rapprochement', [['Payment reference', 'Référence du paiement', 'payment.reference'], ['Reconciliation exceptions', 'Écarts de rapprochement', 'reconciliation.exceptions'], ['Approval', 'Approbation', 'approval.record'], ['Settlement status', 'Statut du règlement', 'settlement_batch.status']]],
                ]],
            'DOC-198' => ['code' => 'PROVIDER_SETTLEMENT_STATEMENT', 'en' => 'Provider Settlement Statement', 'fr' => 'Relevé de règlement prestataire', 'tier' => 'S4',
                'intro' => ['This statement sets out the settlement of approved provider claims for the period.', 'Le présent relevé présente le règlement des demandes approuvées du prestataire pour la période.'],
                'groups' => [
                    ['Provider', 'Prestataire', [$provider, ['Contract', 'Convention', 'provider_contract.number'], $period]],
                    ['Claims', 'Demandes', [['Approved provider claims', 'Demandes prestataire approuvées', 'settlement_batch.items'], ['Adjustments', 'Ajustements', 'statement.adjustments', true], ['Member shares', 'Quotes-parts adhérents', 'preauth.member_amount', true], ['Deductions', 'Déductions', 'settlement.excluded', true]]],
                    ['Payment', 'Paiement', [['Payments', 'Paiements', 'settlement_batch.paid', true], ['Balance', 'Solde', 'settlement_batch.net', true], $currency, ['Settlement status', 'Statut du règlement', 'settlement_batch.status']]],
                ]],
            'DOC-199' => ['code' => 'TAX_LEVY_BREAKDOWN', 'en' => 'Tax & Levy Breakdown', 'fr' => 'Décompte taxes et prélèvements', 'tier' => 'S2',
                'intro' => ['This breakdown details the taxes and levies of the transaction. Rates are taken only from verified configuration.', 'Le présent décompte détaille les taxes et prélèvements de l’opération. Les taux proviennent uniquement de la configuration vérifiée.'],
                'groups' => [
                    ['Transaction', 'Opération', [['Transaction / policy', 'Opération / police', 'policy.number'], ['Policyholder', 'Souscripteur', 'party.name']]],
                    ['Taxes and levies', 'Taxes et prélèvements', [['Tax / levy types', 'Types de taxes / prélèvements', 'premium.components'], ['Bases', 'Assiettes', 'premium.base', true], ['Rates (verified configuration only)', 'Taux (configuration vérifiée uniquement)', null], ['Amounts', 'Montants', 'premium.taxes', true], ['Totals', 'Totaux', 'premium.gross', true], $currency]],
                ]],
            'DOC-200' => ['code' => 'RECONCILIATION_STATEMENT', 'en' => 'Reconciliation Statement', 'fr' => 'État de rapprochement', 'tier' => 'S4',
                'intro' => ['This statement reconciles the platform records with the external records for the period.', 'Le présent état rapproche les enregistrements de la plateforme et les enregistrements externes pour la période.'],
                'groups' => [
                    ['Scope', 'Périmètre', [['Account / channel', 'Compte / canal', 'payment.method'], $period]],
                    ['Totals', 'Totaux', [['System total', 'Total système', 'settlement_batch.gross', true], ['External total', 'Total externe', null, true], ['Matched items', 'Éléments rapprochés', 'statement.items'], ['Unmatched items', 'Éléments non rapprochés', 'reconciliation.exceptions'], ['Exceptions', 'Anomalies', 'reconciliation.exceptions'], ['Final balance', 'Solde final', 'statement.closing_balance', true], ['Reconciliation status', 'Statut de rapprochement', 'payment.status']]],
                    ['Approval', 'Approbation', [$approval]],
                ]],

            // ── L. Reinsurance, co-insurance, provider, compliance & regulatory ────────────────────
            'DOC-201' => ['code' => 'REINSURANCE_SLIP', 'en' => 'Reinsurance Slip', 'fr' => 'Slip de réassurance', 'tier' => 'S3',
                'intro' => ['This slip sets out the reinsurance terms offered for the risk.', 'Le présent slip présente les conditions de réassurance proposées pour le risque.'],
                'groups' => [
                    ['Parties', 'Parties', [['Slip number', 'Numéro du slip', 'reinsurance.reference'], $cedant, ['Reinsurance broker where applicable', 'Courtier de réassurance le cas échéant', 'reinsurance.broker'], ['Reinsurer / participants', 'Réassureur / participants', 'reinsurer.name']]],
                    ['Risk', 'Risque', [['Insured / risk', 'Assuré / risque', 'risk.summary'], ['Original policy / product', 'Police / produit d’origine', 'policy.number'], ['Period', 'Période', 'policy.period'], ['Sum insured / exposure', 'Capital / engagement', 'reinsurance.sum_insured', true], ['Original premium', 'Prime d’origine', 'reinsurance.original_premium', true]]],
                    ['Cession', 'Cession', [['Retention', 'Rétention', 'reinsurance.retention', true], ['Amount ceded', 'Montant cédé', 'reinsurance.ceded_premium', true], ['Type of reinsurance', 'Type de réassurance', 'reinsurance.type'], ['Participation', 'Participation', 'reinsurance.participation']]],
                    ['Terms', 'Conditions', [['Terms', 'Conditions', 'reinsurance.terms'], ['Exclusions', 'Exclusions', null], ['Commission', 'Commission', 'reinsurance.commission', true], ['Brokerage', 'Courtage', 'reinsurance.brokerage', true], ['Taxes / charges', 'Taxes / frais', 'premium.taxes', true], ['Claims cooperation terms', 'Clause de coopération sinistres', 'reinsurance.claims_cooperation'], ['Governing reference', 'Référence applicable', null], ['Signature / acceptance status', 'Statut de signature / acceptation', 'reinsurance.status']]],
                ]],
            'DOC-202' => ['code' => 'FACULTATIVE_PLACEMENT_REQUEST', 'en' => 'Facultative Placement Request', 'fr' => 'Demande de placement facultatif', 'tier' => 'S3',
                'intro' => ['This request invites reinsurers to provide facultative capacity for the risk.', 'La présente demande sollicite des réassureurs une capacité facultative pour le risque.'],
                'groups' => [
                    ['Risk', 'Risque', [['Risk', 'Risque', 'risk.summary'], ['Original policy / proposal', 'Police / proposition d’origine', 'policy.number'], $cedant, ['Exposure', 'Engagement', 'reinsurance.sum_insured', true]]],
                    ['Request', 'Demande', [['Requested capacity', 'Capacité demandée', 'reinsurance.participation'], ['Retention', 'Rétention', 'reinsurance.retention', true], ['Terms', 'Conditions', 'reinsurance.terms'], ['Documents', 'Documents', 'documents.required'], ['Response deadline', 'Date limite de réponse', 'sla.due_at']]],
                ]],
            'DOC-203' => ['code' => 'FACULTATIVE_QUOTE', 'en' => 'Facultative Quote', 'fr' => 'Cotation facultative', 'tier' => 'S3',
                'intro' => ['This quote records the reinsurer’s facultative offer for the placement.', 'La présente cotation consigne l’offre facultative du réassureur pour le placement.'],
                'groups' => [
                    ['Placement', 'Placement', [['Placement', 'Placement', 'reinsurance.reference'], $reinsurer, $cedant]],
                    ['Offer', 'Offre', [['Offered share', 'Part offerte', 'reinsurance.share'], ['Premium / rate', 'Prime / taux', 'reinsurance.ceded_premium', true], ['Commission', 'Commission', 'reinsurance.commission', true], ['Conditions', 'Conditions', 'reinsurance.terms'], ['Validity', 'Validité', 'document.validity'], ['Exclusions', 'Exclusions', null]]],
                ]],
            'DOC-204' => ['code' => 'REINSURANCE_CONFIRMATION', 'en' => 'Reinsurance Confirmation', 'fr' => 'Confirmation de réassurance', 'tier' => 'S4',
                'intro' => ['This confirmation records the bound reinsurance placement.', 'La présente confirmation constate le placement de réassurance conclu.'],
                'groups' => [
                    ['Placement / treaty', 'Placement / traité', [['Placement / treaty', 'Placement / traité', 'reinsurance.reference'], $cedant, ['Bound participants', 'Participants engagés', 'reinsurer.name'], ['Shares', 'Parts', 'reinsurance.share']]],
                    ['Terms', 'Conditions', [['Effective dates', 'Dates d’effet', 'policy.period'], ['Premium', 'Prime', 'reinsurance.ceded_premium', true], ['Conditions', 'Conditions', 'reinsurance.terms']]],
                    ['Authorization', 'Autorisation', [['Authorised by', 'Autorisé par', 'approval.decided_by'], ['Status', 'Statut', 'reinsurance.status']]],
                ]],
            'DOC-205' => ['code' => 'TREATY_SUMMARY', 'en' => 'Treaty Summary', 'fr' => 'Résumé de traité de réassurance', 'tier' => 'S3',
                'intro' => ['This summary sets out the main terms of the reinsurance treaty.', 'Le présent résumé présente les principales conditions du traité de réassurance.'],
                'groups' => [
                    ['Treaty', 'Traité', [$treaty, $cedant, ['Reinsurers', 'Réassureurs', 'reinsurer.name'], ['Period', 'Période', 'policy.period'], ['Classes', 'Branches', 'policy.insurance_class']]],
                    ['Structure', 'Structure', [['Structure', 'Structure', 'reinsurance.type'], ['Retention', 'Rétention', 'reinsurance.retention', true], ['Capacity / layers', 'Capacité / tranches', 'reinsurance.participation']]],
                    ['Financial terms', 'Conditions financières', [['Premium terms', 'Conditions de prime', 'reinsurance.terms'], ['Commission terms', 'Conditions de commission', 'reinsurance.commission']]],
                ]],
            'DOC-206' => ['code' => 'RISK_BORDEREAU', 'en' => 'Risk Bordereau', 'fr' => 'Bordereau de risques', 'tier' => 'S4',
                'intro' => ['This bordereau lists the risks ceded under the treaty for the period, with reconciled totals.', 'Le présent bordereau liste les risques cédés au titre du traité pour la période, avec totaux rapprochés.'],
                'groups' => [
                    ['Bordereau', 'Bordereau', [['Bordereau number', 'Numéro du bordereau', 'bordereau.number'], $treaty, ['Period', 'Période', 'policy.period'], $cedant, $reinsurer]],
                    ['Risk rows', 'Lignes de risques', [['Policy number', 'Numéro de police', 'policy.number'], ['Insured', 'Assuré', 'party.name'], ['Risk identifier', 'Identifiant du risque', 'risk.identifier'], ['Location', 'Situation', 'claim.loss_location'], ['Insurance class', 'Branche', 'policy.insurance_class'], ['Inception / expiry', 'Effet / échéance', 'policy.period']]],
                    ['Amounts', 'Montants', [['Gross sum insured', 'Capital brut assuré', 'reinsurance.sum_insured', true], ['Retention', 'Rétention', 'reinsurance.retention', true], ['Ceded amount', 'Montant cédé', 'reinsurance.ceded_premium', true], ['Reinsurer share', 'Part du réassureur', 'reinsurance.share'], ['Premium', 'Prime', 'premium.gross', true], $status]],
                ]],
            'DOC-207' => ['code' => 'PREMIUM_BORDEREAU', 'en' => 'Premium Bordereau', 'fr' => 'Bordereau de primes', 'tier' => 'S4',
                'intro' => ['This bordereau lists the premiums ceded under the treaty for the period.', 'Le présent bordereau liste les primes cédées au titre du traité pour la période.'],
                'groups' => [
                    ['Bordereau', 'Bordereau', [['Bordereau number', 'Numéro du bordereau', 'bordereau.number'], $treaty, $period]],
                    ['Premium rows', 'Lignes de primes', [['Policy / risk', 'Police / risque', 'policy.number'], ['Gross premium', 'Prime brute', 'reinsurance.original_premium', true], ['Ceded premium', 'Prime cédée', 'reinsurance.ceded_premium', true], ['Commissions', 'Commissions', 'reinsurance.commission', true], ['Taxes', 'Taxes', 'premium.taxes', true], ['Balances', 'Soldes', 'statement.closing_balance', true], ['Totals', 'Totaux', 'statement.written_premium', true]]],
                ]],
            'DOC-208' => ['code' => 'CLAIMS_BORDEREAU', 'en' => 'Claims Bordereau', 'fr' => 'Bordereau de sinistres', 'tier' => 'S4',
                'intro' => ['This bordereau lists the claims reported under the treaty for the period.', 'Le présent bordereau liste les sinistres déclarés au titre du traité pour la période.'],
                'groups' => [
                    ['Bordereau', 'Bordereau', [['Bordereau number', 'Numéro du bordereau', 'bordereau.number'], $treaty, ['Reporting period', 'Période de déclaration', 'statement.period'], ['Report date', 'Date de déclaration', 'document.issued_at']]],
                    ['Claim rows', 'Lignes de sinistres', [$claim, $policy, ['Date of loss', 'Date du sinistre', 'claim.loss_date'], ['Claim status', 'Statut du sinistre', 'claim.status'], ['Catastrophe / event identifier if relevant', 'Identifiant d’événement / catastrophe le cas échéant', 'claim.catastrophe_event']]],
                    ['Amounts', 'Montants', [['Gross incurred', 'Charge brute', 'reinsurance.gross_incurred', true], ['Paid', 'Payé', 'reinsurance.gross_paid', true], ['Outstanding reserve', 'Réserve restante', 'claim.reserve_outstanding', true], ['Insurer retention', 'Rétention de l’assureur', 'reinsurance.retention', true], ['Recoverable', 'Récupérable', 'reinsurance.recoverable', true], ['Recovery received', 'Recouvrement reçu', 'reinsurance.settled', true]]],
                ]],
            'DOC-209' => ['code' => 'REINSURANCE_RECOVERY_REQUEST', 'en' => 'Reinsurance Recovery Request', 'fr' => 'Demande de recours en réassurance', 'tier' => 'S4',
                'intro' => ['This request asks the reinsurer to pay its share of the claim.', 'La présente demande invite le réassureur à régler sa part du sinistre.'],
                'groups' => [
                    ['Claim / treaty', 'Sinistre / traité', [$claim, $treaty, $reinsurer, ['Loss', 'Sinistre', 'claim.loss_details'], $lossDate]],
                    ['Calculation', 'Calcul', [['Ceded share', 'Part cédée', 'reinsurance.share'], ['Gross claim', 'Sinistre brut', 'reinsurance.gross_incurred', true], ['Retention', 'Rétention', 'reinsurance.retention', true], ['Recoverable calculation', 'Calcul du montant récupérable', 'reinsurance.recoverable', true], ['Evidence', 'Pièces justificatives', 'claim.evidence'], ['Amount requested', 'Montant demandé', 'reinsurance.recoverable', true]]],
                ]],
            'DOC-210' => ['code' => 'REINSURANCE_SETTLEMENT_STATEMENT', 'en' => 'Reinsurance Settlement Statement', 'fr' => 'Relevé de règlement réassurance', 'tier' => 'S4',
                'intro' => ['This statement sets out the account between the reinsurer and the cedant for the period.', 'Le présent relevé présente le compte entre le réassureur et la cédante pour la période.'],
                'groups' => [
                    ['Parties', 'Parties', [$reinsurer, $cedant, $treaty, $period]],
                    ['Account', 'Compte', [['Premiums', 'Primes', 'reinsurance.ceded_premium', true], ['Commissions', 'Commissions', 'reinsurance.commission', true], ['Claims / recoveries', 'Sinistres / recouvrements', 'reinsurance.recoverable', true], ['Adjustments', 'Ajustements', 'statement.adjustments', true], ['Net balance', 'Solde net', 'statement.closing_balance', true], ['Payment status', 'Statut du paiement', 'reinsurance.settled']]],
                ]],
            'DOC-211' => ['code' => 'CO_INSURANCE_PLACEMENT', 'en' => 'Co-insurance Placement', 'fr' => 'Placement en coassurance', 'tier' => 'S3',
                'intro' => ['This document presents the risk for co-insurance participation.', 'Le présent document présente le risque en vue d’une participation en coassurance.'],
                'groups' => [
                    ['Risk / policy', 'Risque / police', [['Risk', 'Risque', 'risk.summary'], $policy, ['Lead insurer', 'Apériteur', 'policy.insurer']]],
                    ['Placement', 'Placement', [['Participants sought', 'Coassureurs sollicités', null], ['Shares', 'Parts', 'reinsurance.share'], ['Terms', 'Conditions', 'reinsurance.terms'], ['Premium', 'Prime', 'premium.gross', true], ['Capacity', 'Capacité', 'coverage.sum_insured', true], ['Conditions', 'Conditions particulières', 'underwriting.conditions']]],
                ]],
            'DOC-212' => ['code' => 'CO_INSURANCE_PARTICIPATION_CONFIRMATION', 'en' => 'Co-insurance Participation Confirmation', 'fr' => 'Confirmation de participation en coassurance', 'tier' => 'S3',
                'intro' => ['This document confirms the participant’s share in the co-insurance arrangement.', 'Le présent document confirme la part du coassureur dans l’arrangement de coassurance.'],
                'groups' => [
                    ['Arrangement', 'Arrangement', [['Arrangement', 'Arrangement', 'reinsurance.reference'], $policy, ['Lead insurer', 'Apériteur', 'policy.insurer'], ['Participant', 'Coassureur', null]]],
                    ['Participation', 'Participation', [['Accepted share', 'Part acceptée', 'reinsurance.share'], ['Premium share', 'Quote-part de prime', 'reinsurance.ceded_premium', true], ['Claim share', 'Quote-part des sinistres', 'reinsurance.share'], ['Effective dates', 'Dates d’effet', 'policy.period'], ['Conditions', 'Conditions', 'reinsurance.terms']]],
                ]],
            'DOC-213' => ['code' => 'COINSURANCE_PREMIUM_ALLOCATION', 'en' => 'Co-insurance Premium Allocation', 'fr' => 'Répartition de prime en coassurance', 'tier' => 'S4',
                'intro' => ['This statement allocates the premium between the co-insurers.', 'Le présent état répartit la prime entre les coassureurs.'],
                'groups' => [
                    ['Policy / arrangement', 'Police / arrangement', [$policy, ['Arrangement', 'Arrangement', 'reinsurance.reference'], ['Gross premium', 'Prime brute', 'premium.gross', true]]],
                    ['Allocation', 'Répartition', [['Participant shares', 'Parts des coassureurs', 'reinsurance.share'], ['Commissions / charges', 'Commissions / frais', 'reinsurance.commission', true], ['Amounts due', 'Montants dus', 'statement.closing_balance', true], ['Amounts paid', 'Montants payés', 'statement.remittances', true], $currency]],
                ]],
            'DOC-214' => ['code' => 'COINSURANCE_CLAIM_ALLOCATION', 'en' => 'Co-insurance Claim Allocation', 'fr' => 'Répartition de sinistre en coassurance', 'tier' => 'S4',
                'intro' => ['This statement allocates the approved claim between the co-insurers.', 'Le présent état répartit le sinistre accepté entre les coassureurs.'],
                'groups' => [
                    ['Claim / arrangement', 'Sinistre / arrangement', [$claim, ['Arrangement', 'Arrangement', 'reinsurance.reference'], ['Gross approved claim', 'Sinistre brut accepté', 'decision.approved_amount', true]]],
                    ['Allocation', 'Répartition', [['Participant shares', 'Parts des coassureurs', 'reinsurance.share'], ['Amounts due', 'Montants dus', 'reinsurance.recoverable', true], ['Amounts paid', 'Montants payés', 'reinsurance.settled', true], ['Recovery allocation', 'Répartition des recours', 'claim.recoveries'], $currency]],
                ]],
            'DOC-215' => ['code' => 'PROVIDER_CONTRACT', 'en' => 'Provider Contract', 'fr' => 'Convention prestataire', 'tier' => 'S4',
                'intro' => ['This document records the agreement between the insurer and the health provider.', 'Le présent document constate la convention entre l’assureur et le prestataire de soins.'],
                'groups' => [
                    ['Parties', 'Parties', [['Contract number', 'Numéro de convention', 'provider_contract.number'], $insurer, ['Provider legal entity', 'Entité juridique du prestataire', 'provider.name'], ['Facilities covered', 'Établissements couverts', 'provider.facilities'], ['Network', 'Réseau', 'provider.network']]],
                    ['Term', 'Durée', [['Effective dates', 'Dates d’effet', 'provider_contract.period'], ['Renewal / termination', 'Renouvellement / résiliation', null]]],
                    ['Services and tariffs', 'Prestations et tarifs', [['Service scope', 'Périmètre des prestations', 'provider.services'], ['Tariff schedule reference', 'Référence de la grille tarifaire', 'provider.tariff'], ['Credentialing requirements', 'Exigences d’agrément', 'provider.credentialing']]],
                    ['Operating rules', 'Règles de fonctionnement', [['Billing rules', 'Règles de facturation', null], ['Eligibility process', 'Procédure de vérification des droits', null], ['Preauthorization rules', 'Règles d’entente préalable', null], ['Claim submission rules', 'Règles de soumission des demandes', null], ['Supporting documents', 'Pièces justificatives', 'provider_contract.document'], ['Payment terms', 'Conditions de paiement', null]]],
                    ['Control and compliance', 'Contrôle et conformité', [['Dispute process', 'Procédure de règlement des litiges', null], ['Audit rights', 'Droits d’audit', null], ['Fraud / abuse obligations', 'Obligations en matière de fraude / abus', null], ['Confidentiality / data protection', 'Confidentialité / protection des données', null]]],
                    ['Annexes', 'Annexes', [['Annexes', 'Annexes', 'provider_contract.document']]],
                ]],
            'DOC-216' => ['code' => 'PROVIDER_TARIFF_SCHEDULE', 'en' => 'Provider Tariff Schedule', 'fr' => 'Grille tarifaire prestataire', 'tier' => 'S3',
                'intro' => ['This schedule sets out the agreed tariffs of the provider under its contract.', 'La présente grille présente les tarifs convenus du prestataire au titre de sa convention.'],
                'groups' => [
                    ['Contract / provider', 'Convention / prestataire', [['Contract', 'Convention', 'provider_contract.number'], $provider, ['Effective dates', 'Dates d’effet', 'provider_contract.period']]],
                    ['Tariffs', 'Tarifs', [['Service code / name', 'Code / libellé de l’acte', 'provider.tariff'], ['Agreed price', 'Prix convenu', 'provider.tariff'], ['Member share', 'Quote-part adhérent', 'benefit.copay'], ['Insurer share', 'Part de l’assureur', 'preauth.insurer_amount', true], ['Limits', 'Plafonds', 'benefit.limits'], $currency]],
                ]],
            'DOC-217' => ['code' => 'KYC_REQUEST', 'en' => 'KYC Information Request', 'fr' => 'Demande d’informations KYC', 'tier' => 'S2', 'restricted' => true,
                'intro' => ['This request asks the party to provide the know-your-customer information listed below.', 'La présente demande invite la partie à fournir les informations de connaissance client indiquées ci-dessous.'],
                'groups' => [
                    ['Party / case', 'Partie / dossier', [['Party', 'Partie', 'party.name'], ['Party identifier', 'Identifiant de la partie', 'party.identifier_masked'], ['Case reference', 'Référence du dossier', null]]],
                    ['Request', 'Demande', [['Required KYC data / documents', 'Données / documents KYC requis', 'documents.required'], ['Reason / category', 'Motif / catégorie', null], ['Deadline', 'Date limite', 'sla.due_at'], ['Secure submission route', 'Canal de transmission sécurisé', null]]],
                ]],
            'DOC-218' => ['code' => 'KYC_APPROVAL_REMEDIATION_NOTICE', 'en' => 'KYC Approval / Remediation Notice', 'fr' => 'Avis d’approbation / régularisation KYC', 'tier' => 'S3', 'restricted' => true,
                'intro' => ['This notice informs the party of the outcome of the know-your-customer review.', 'Le présent avis informe la partie du résultat de l’examen de connaissance client.'],
                'groups' => [
                    ['Party / case', 'Partie / dossier', [['Party', 'Partie', 'party.name'], ['Party identifier', 'Identifiant de la partie', 'party.identifier_masked'], ['Case reference', 'Référence du dossier', null]]],
                    ['Outcome', 'Résultat', [['Status / outcome', 'Statut / résultat', null], ['Remaining actions', 'Actions restantes', 'documents.required'], ['Review date', 'Date de révision', 'sla.due_at'], ['Restrictions if any', 'Restrictions éventuelles', null]]],
                    ['Contact', 'Contact', [$contact]],
                ]],
            'DOC-219' => ['code' => 'REGULATORY_DECLARATION_RETURN', 'en' => 'Regulatory Declaration / Return', 'fr' => 'Déclaration / état réglementaire', 'tier' => 'S4', 'restricted' => true,
                'intro' => ['This return records the regulatory declaration prepared from verified platform data.', 'Le présent état consigne la déclaration réglementaire établie à partir des données vérifiées de la plateforme.'],
                'groups' => [
                    ['Return', 'Déclaration', [['Return type', 'Type de déclaration', 'regulatory.report_type'], ['Regulator', 'Autorité de contrôle', 'regulatory.authority'], ['Reporting entity', 'Entité déclarante', 'issuer.legal_name'], ['Reporting period', 'Période de référence', 'statement.period'], ['Submission period', 'Période de dépôt', 'regulatory.period'], ['Reporting currency', 'Devise de déclaration', 'regulatory.currency']]],
                    ['Content', 'Contenu', [['Required regulatory line items', 'Postes réglementaires requis', null], ['Totals', 'Totaux', 'regulatory.totals'], ['Source-system lineage', 'Traçabilité des sources', 'regulatory.lineage'], ['Reconciliation status', 'Statut de rapprochement', 'payment.status']]],
                    ['Certification and submission', 'Certification et dépôt', [['Preparer', 'Préparé par', 'regulatory.prepared_by'], ['Reviewer', 'Revu par', null], ['Approver', 'Approuvé par', 'approval.decided_by'], ['Certification / declaration', 'Certification / déclaration', null], ['Submission reference', 'Référence de dépôt', 'regulatory.external_reference'], ['Amendment / version status', 'Statut de modification / version', 'regulatory.run_status']]],
                ]],
            'DOC-220' => ['code' => 'REGULATORY_AUDIT_VERIFICATION_REPORT', 'en' => 'Regulatory Audit / Verification Report', 'fr' => 'Rapport d’audit / vérification réglementaire', 'tier' => 'S4', 'restricted' => true,
                'intro' => ['This report records the regulatory audit or verification and its findings.', 'Le présent rapport consigne l’audit ou la vérification réglementaire et ses constatations.'],
                'groups' => [
                    ['Audit', 'Audit', [['Entity', 'Entité', 'issuer.legal_name'], ['Regulator', 'Autorité de contrôle', 'regulatory.authority'], ['Audit scope', 'Périmètre de l’audit', null], ['Audit period', 'Période auditée', 'regulatory.period'], ['Reviewers', 'Contrôleurs', null], ['Evidence', 'Éléments probants', null]]],
                    ['Findings', 'Constatations', [['Findings', 'Constatations', null], ['Severity / classification', 'Gravité / classification', null], ['Required actions', 'Actions requises', null], ['Deadlines', 'Échéances', null], ['Responses', 'Réponses', null], ['Closure status', 'Statut de clôture', null]]],
                ]],
        ];
    }
}
