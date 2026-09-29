<?php

declare(strict_types=1);

namespace Database\Seeders\CanonicalTemplates;

/**
 * Canonical document templates, part B: DOC-056..DOC-110 (220-document register categories
 * D Health & Medical, E Life / Savings / Beneficiaries, F Group & Corporate).
 * Contract: docs/spec/canonical/TEMPLATE_CONTENT_CONTRACT.md (lifecycle, content shape, wording rules).
 *
 * Sources: OpesInsure_220_Documents_and_First_5_Master_Shells_v1 (names EN/FR, tiers, zones A–G; none of these
 * documents uses one of the 5 master shells, they render in the generic zoned shell), OpesInsure_220_Document_Field_
 * Data_Specification_v1 §4 D/E/F (minimum additions) and §3 (detailed specs DOC-057, 060, 066, 069, 076, 082, 096).
 * Tier / confidentiality / controls come from the catalogue security profile (Security Matrix), not from here.
 *
 * Each field is [canonical key, label EN, label FR, group]; the group orders the fields by subject (member, parties,
 * policy and plan → Zone C; details, amounts, decision, declarations → Zone D). Universal groups FG-01/02/12/13/14/15
 * (number, issuer, signature, verification, lifecycle, footer) are printed by the shell zones and not repeated.
 * Life documents (category E) are LIFE-scoped: life policies only accept LIFE templates.
 * DOC-064..072 supersede the minimal REVIEW seeds of ProviderDocumentTemplateSeeder (same lineage, new version).
 */
final class CanonicalTemplatesPartBSeeder extends CanonicalTemplateSeeder
{
    /** Field group => shell zone. */
    public const GROUP_ZONES = ['M' => 'C', 'P' => 'C', 'K' => 'C', 'D' => 'D', 'F' => 'D', 'A' => 'D', 'S' => 'D'];

    /** @return array<string, array<string, mixed>> contract definitions */
    protected function documents(): array
    {
        $out = [];
        foreach (self::definitions() as $specId => $def) {
            // Field order = spec order (identifiers first); the shell splits the rows by zone.
            $fields = $def['fields'];
            $out[$specId] = array_filter([
                'intro_en' => $def['purpose'][0], 'intro_fr' => $def['purpose'][1],
                'title_en' => $def['en'], 'title_fr' => $def['fr'],
                'fields' => array_values(array_map(fn ($f) => [$f[0], $f[1], $f[2], self::GROUP_ZONES[$f[3]]], $fields)),
                'sections' => array_map(fn ($s) => ['heading_en' => $s[2], 'heading_fr' => $s[3], 'body_en' => $s[0], 'body_fr' => $s[1]], $def['statements']),
                'insurance_class' => $def['class'],
            ], fn ($v) => $v !== null && $v !== []);
        }

        return $out;
    }

    /** Public view of the contract definitions (tests, specimen tooling). */
    public function definitionsForContract(): array
    {
        return $this->documents();
    }

    // ----------------------------------------------------------------------------------------------------------
    // Neutral statements (no legal clause invented; wording limited to what the spec names for the document).
    // ----------------------------------------------------------------------------------------------------------

    private const H_DECL = ['Declaration', 'Déclaration'];

    private const H_CONSENT = ['Consent', 'Consentement'];

    private const H_PRIVACY = ['Confidentiality', 'Confidentialité'];

    private const H_SCOPE = ['Scope', 'Portée'];

    private const H_NOTICE = ['Notice', 'Avis'];

    private static function s(array $h, string $en, string $fr): array
    {
        return [$en, $fr, $h[0], $h[1]];
    }

    private static function decl(string $who = 'applicant'): array
    {
        $fr = ['applicant' => 'Le demandeur', 'policyholder' => 'Le souscripteur', 'member' => "L'adhérent", 'insured' => "L'assuré", 'employer' => "L'employeur / le souscripteur du groupe"][$who];

        return self::s(self::H_DECL, 'The '.str_replace('_', ' ', $who).' declares that the information given in this document is accurate and complete to the best of their knowledge.',
            $fr.' déclare que les informations fournies dans ce document sont exactes et complètes à sa connaissance.');
    }

    private static function consent(): array
    {
        return self::s(self::H_CONSENT, 'Personal data in this document is processed only for the administration of this insurance relationship, within the consent recorded in this document.',
            'Les données personnelles de ce document sont traitées uniquement pour la gestion de cette relation d’assurance, dans les limites du consentement enregistré dans ce document.');
    }

    private static function medical(): array
    {
        return self::s(self::H_PRIVACY, 'Restricted medical information (MEDICAL_RESTRICTED). Access is limited to authorized medical and claims personnel.',
            'Information médicale restreinte (MEDICAL_RESTRICTED). L’accès est limité au personnel médical et sinistres habilité.');
    }

    private static function financial(): array
    {
        return self::s(self::H_PRIVACY, 'Restricted financial information (FINANCIAL_RESTRICTED). Access is limited to the policyholder and authorized personnel.',
            'Information financière restreinte (FINANCIAL_RESTRICTED). L’accès est limité au souscripteur et au personnel habilité.');
    }

    private static function noDiagnosis(): array
    {
        return self::s(self::H_PRIVACY, 'This document carries no diagnosis or medical history.', 'Ce document ne comporte aucun diagnostic ni antécédent médical.');
    }

    private static function subjectToTerms(string $en, string $fr): array
    {
        return self::s(self::H_SCOPE, $en.' It remains subject to the policy terms and conditions.', $fr.' Il reste soumis aux conditions de la police.');
    }

    private static function def(string $code, string $en, string $fr, string $cat, string $tier, ?string $class, array $purpose, array $fields, array $statements): array
    {
        return compact('code', 'en', 'fr', 'tier', 'class', 'purpose', 'fields', 'statements') + ['category' => $cat];
    }

    // Common field tuples [key, label EN, label FR, group].
    private const F_MEMBER = ['member.name', 'Member name', 'Nom de l’adhérent', 'M'];

    private const F_MEMBER_NO = ['member.reference', 'Member number', 'N° d’adhérent', 'M'];

    private const F_DOB = ['party.date_of_birth', 'Date of birth', 'Date de naissance', 'M'];

    private const F_POLICY = ['policy.number', 'Policy / group number', 'N° de police / groupe', 'K'];

    private const F_INSURER = ['policy.insurer', 'Insurer', 'Assureur', 'K'];

    private const F_PLAN = ['policy.product', 'Plan', 'Formule', 'K'];

    private const F_NETWORK = ['member.network', 'Network', 'Réseau de soins', 'K'];

    private const F_PROVIDER = ['provider.name', 'Provider / facility', 'Prestataire / établissement', 'P'];

    private const F_HOLDER = ['party.name', 'Policyholder', 'Souscripteur', 'P'];

    private const F_INSURED = ['party.insured', 'Insured person', 'Assuré', 'P'];

    private const F_LIFE_POLICY = ['policy.number', 'Policy number', 'N° de police', 'K'];

    private const F_GROUP = ['group.sponsor', 'Group / employer', 'Groupe / employeur', 'P'];

    private const F_GROUP_POLICY = ['policy.number', 'Group policy number', 'N° de police groupe', 'K'];

    private const F_EMPLOYEE = ['member.name', 'Employee / member', 'Employé / adhérent', 'M'];

    private const F_PREAUTH_NO = ['preauth.number', 'Authorization number', 'N° d’autorisation', 'A'];

    private const F_DECIDED_BY = ['preauth.decided_by', 'Approving officer / engine', 'Agent / moteur d’approbation', 'A'];

    private const F_DECIDED_AT = ['preauth.decided_at', 'Decision timestamp', 'Horodatage de la décision', 'A'];

    private const F_SIGNED_AT = ['proposal.attested_at', 'Date signed', 'Date de signature', 'S'];

    private const F_SIGNATURE = ['proposal.attestation', 'Signature', 'Signature', 'S'];

    /** @return array<string, array<string, mixed>> spec id => definition */
    public static function definitions(): array
    {
        $D = 'D'; $E = 'E'; $F = 'F'; $LIFE = 'LIFE';

        return [
            // ================================================ D. Health & Medical
            'DOC-056' => self::def('HEALTH_ENROLLMENT_FORM', 'Health Enrollment Form', 'Formulaire d’adhésion santé', $D, 'S2', null,
                ['Application for enrollment of the applicant and, where applicable, their dependants in a health insurance plan.', 'Demande d’adhésion du demandeur et, le cas échéant, de ses ayants droit à une formule d’assurance santé.'],
                [['party.name', 'Applicant / member', 'Demandeur / adhérent', 'M'], self::F_DOB, ['party.identifier_masked', 'Identity document reference', 'Référence de la pièce d’identité', 'M'],
                    ['party.contact', 'Contact details', 'Coordonnées', 'M'], ['group.sponsor', 'Employer / group (if applicable)', 'Employeur / groupe (le cas échéant)', 'P'],
                    ['member.dependants', 'Dependants', 'Ayants droit', 'P'], self::F_INSURER, self::F_PLAN, self::F_NETWORK,
                    ['proposal.requested_effective_date', 'Requested effective date', 'Date d’effet demandée', 'K'], ['proposal.number', 'Application reference', 'Référence de la demande', 'K'],
                    ['proposal.declarations', 'Declarations', 'Déclarations', 'S'], ['consent.record', 'Consent', 'Consentement', 'S'], self::F_SIGNED_AT, self::F_SIGNATURE],
                [self::decl('applicant'), self::consent()]),

            'DOC-057' => self::def('MEDICAL_DECLARATION', 'Medical Declaration', 'Déclaration médicale', $D, 'S3', null,
                ['Health questionnaire completed by or for the person to be insured, supporting the underwriting of the proposal referenced below.', 'Questionnaire de santé rempli par ou pour la personne à assurer, à l’appui de la sélection médicale de la proposition référencée ci-dessous.'],
                [['medical.declaration_number', 'Declaration number', 'N° de déclaration', 'D'], ['party.insured', 'Applicant / insured', 'Demandeur / assuré', 'M'], self::F_DOB,
                    ['proposal.number', 'Policy / proposal reference', 'Référence de police / proposition', 'K'], self::F_INSURER,
                    ['medical.questions', 'Relevant medical questions', 'Questions médicales', 'D'], ['medical.conditions', 'Current / past conditions', 'Affections actuelles / antérieures', 'D'],
                    ['medical.treatment', 'Treatment / medication', 'Traitements / médicaments', 'D'], ['medical.hospitalization', 'Hospitalization / surgery', 'Hospitalisations / interventions chirurgicales', 'D'],
                    ['medical.disability', 'Disability (where applicable)', 'Invalidité (le cas échéant)', 'D'], ['medical.lifestyle', 'Tobacco / alcohol (where permitted)', 'Tabac / alcool (si autorisé)', 'D'],
                    ['proposal.declarations', 'Declarations', 'Déclarations', 'S'], ['consent.record', 'Consent / authorization', 'Consentement / autorisation', 'S'],
                    ['proposal.attested_at', 'Date completed', 'Date de remplissage', 'S'], self::F_SIGNATURE, ['confidentiality.class', 'Confidentiality classification', 'Classification de confidentialité', 'S']],
                [self::decl('insured'), self::consent(), self::medical()]),

            'DOC-058' => self::def('DEPENDANT_ENROLLMENT_FORM', 'Dependant Enrollment Form', 'Formulaire d’adhésion des ayants droit', $D, 'S2', null,
                ['Request to enroll a dependant under the health cover of the primary member.', 'Demande d’inscription d’un ayant droit sous la couverture santé de l’adhérent principal.'],
                [['member.name', 'Primary member', 'Adhérent principal', 'M'], self::F_MEMBER_NO, ['dependant.name', 'Dependant identity', 'Identité de l’ayant droit', 'P'],
                    ['dependant.relationship', 'Relationship', 'Lien de parenté', 'P'], ['dependant.date_of_birth', 'Dependant date of birth', 'Date de naissance de l’ayant droit', 'P'],
                    self::F_POLICY, self::F_PLAN, ['proposal.requested_effective_date', 'Requested effective date', 'Date d’effet demandée', 'K'],
                    ['dependant.eligibility_evidence', 'Eligibility evidence', 'Justificatifs d’éligibilité', 'D'], ['consent.record', 'Consent', 'Consentement', 'S'], self::F_SIGNED_AT, self::F_SIGNATURE],
                [self::decl('member'), self::consent()]),

            'DOC-059' => self::def('MEMBER_CERTIFICATE', 'Member Certificate', 'Certificat d’adhésion', $D, 'S3', null,
                ['Certifies that the member named below is enrolled under the health policy and plan stated, for the membership period stated.', 'Certifie que l’adhérent désigné ci-dessous est inscrit au titre de la police et de la formule santé indiquées, pour la période d’adhésion indiquée.'],
                [self::F_MEMBER, self::F_MEMBER_NO, ['member.relationship', 'Relationship to primary member', 'Lien avec l’adhérent principal', 'M'], self::F_POLICY, ['group.sponsor', 'Group', 'Groupe', 'K'],
                    self::F_INSURER, self::F_PLAN, ['member.validity', 'Membership period', 'Période d’adhésion', 'K'], ['member.benefit_category', 'Benefit category', 'Catégorie de prestations', 'K'],
                    self::F_NETWORK, ['member.card_status', 'Membership status', 'Statut d’adhésion', 'A']],
                [self::subjectToTerms('This certificate evidences membership only.', 'Ce certificat atteste uniquement de l’adhésion.'), self::noDiagnosis()]),

            'DOC-060' => self::def('HEALTH_MEMBERSHIP_CARD', 'Health Membership Card', 'Carte d’assuré santé', $D, 'S4', null,
                ['Health membership card identifying the member to network providers.', 'Carte d’assuré santé identifiant l’adhérent auprès des prestataires du réseau.'],
                [self::F_INSURER, self::F_NETWORK, self::F_PLAN, self::F_MEMBER, self::F_MEMBER_NO, self::F_POLICY,
                    ['member.relationship', 'Dependant relationship (where relevant)', 'Lien de l’ayant droit (le cas échéant)', 'M'], ['member.validity', 'Validity dates', 'Dates de validité', 'K'],
                    ['member.emergency_contact', 'Emergency / provider contact', 'Contact urgence / prestataires', 'D'], ['benefit.copay', 'Copay / network indication (optional)', 'Ticket modérateur / réseau (facultatif)', 'D'],
                    ['member.card_status', 'Card status', 'Statut de la carte', 'A']],
                [self::noDiagnosis(), self::s(self::H_NOTICE, 'Card status is confirmed by scanning the verification code; the card alone does not guarantee payment.', 'Le statut de la carte est confirmé par le code de vérification ; la carte seule ne vaut pas garantie de paiement.')]),

            'DOC-061' => self::def('DIGITAL_HEALTH_CARD', 'Digital Health Card', 'Carte santé numérique', $D, 'S3', null,
                ['Digital health membership card identifying the member to network providers.', 'Carte santé numérique identifiant l’adhérent auprès des prestataires du réseau.'],
                [self::F_INSURER, self::F_NETWORK, self::F_PLAN, self::F_MEMBER, self::F_MEMBER_NO, self::F_POLICY,
                    ['member.relationship', 'Dependant relationship (where relevant)', 'Lien de l’ayant droit (le cas échéant)', 'M'], ['member.validity', 'Validity dates', 'Dates de validité', 'K'],
                    ['member.emergency_contact', 'Emergency / provider contact', 'Contact urgence / prestataires', 'D'], ['member.digital_token', 'Digital card token (device-safe)', 'Jeton de carte numérique (sans donnée sensible)', 'A'],
                    ['member.card_status', 'Card status', 'Statut de la carte', 'A']],
                [self::noDiagnosis(), self::s(self::H_NOTICE, 'Card status is confirmed by scanning the verification code; a screenshot does not prove current validity.', 'Le statut de la carte est confirmé par le code de vérification ; une capture d’écran ne prouve pas la validité actuelle.')]),

            'DOC-062' => self::def('HEALTH_BENEFIT_SCHEDULE', 'Health Benefit Schedule', 'Tableau des prestations santé', $D, 'S2', null,
                ['Schedule of the benefits, limits and member contributions of the health plan below.', 'Tableau des prestations, plafonds et participations de l’adhérent de la formule santé ci-dessous.'],
                [self::F_POLICY, self::F_INSURER, self::F_PLAN, ['schedule.categories', 'Service categories', 'Catégories de soins', 'D'], ['benefit.limits', 'Annual / per-event limits', 'Plafonds annuels / par événement', 'D'],
                    ['benefit.copay', 'Copays', 'Tickets modérateurs', 'D'], ['benefit.coinsurance', 'Coinsurance', 'Coassurance / quote-part', 'D'], ['benefit.waiting_periods', 'Waiting periods', 'Délais de carence', 'D'],
                    ['coverage.exclusions', 'Exclusions', 'Exclusions', 'D']],
                [self::subjectToTerms('This schedule summarizes the benefits of the plan.', 'Ce tableau résume les prestations de la formule.')]),

            'DOC-063' => self::def('PROVIDER_NETWORK_DIRECTORY', 'Provider Network Directory', 'Répertoire du réseau de soins', $D, 'S1', null,
                ['Directory of the providers and facilities in the care network below, as at the effective date stated.', 'Répertoire des prestataires et établissements du réseau de soins ci-dessous, à la date d’effet indiquée.'],
                [['provider.network', 'Network', 'Réseau', 'K'], self::F_INSURER, ['provider.name', 'Provider / facility', 'Prestataire / établissement', 'D'], ['provider.specialty', 'Specialty', 'Spécialité', 'D'],
                    ['provider.location', 'Location / contact', 'Adresse / contact', 'D'], ['provider.network_status', 'Network status', 'Statut dans le réseau', 'D'], ['provider.network_effective_date', 'Effective date', 'Date d’effet', 'D']],
                [self::s(self::H_NOTICE, 'Network membership may change; the current status of a provider is confirmed by the insurer or the online verification.', 'L’appartenance au réseau peut évoluer ; le statut actuel d’un prestataire est confirmé par l’assureur ou la vérification en ligne.')]),

            'DOC-064' => self::def('ELIGIBILITY_CONFIRMATION', 'Eligibility Confirmation', 'Confirmation d’éligibilité', $D, 'S2', null,
                ['Result of the member eligibility check performed for the provider below at the time stated.', 'Résultat du contrôle d’éligibilité de l’adhérent effectué pour le prestataire ci-dessous à l’heure indiquée.'],
                [self::F_MEMBER, self::F_MEMBER_NO, self::F_POLICY, self::F_INSURER, self::F_PLAN, self::F_NETWORK, self::F_PROVIDER,
                    ['eligibility.checked_at', 'Check timestamp', 'Horodatage du contrôle', 'A'], ['eligibility.result', 'Eligibility result', 'Résultat d’éligibilité', 'A'],
                    ['benefit.remaining', 'Remaining benefit (where appropriate)', 'Solde de garantie (le cas échéant)', 'F']],
                [self::subjectToTerms('Eligibility is confirmed at the time of the check only and is not a guarantee of payment.', 'L’éligibilité est confirmée à l’heure du contrôle uniquement et ne vaut pas garantie de paiement.'), self::medical()]),

            'DOC-065' => self::def('PREAUTHORIZATION_REQUEST', 'Preauthorization Request', 'Demande de prise en charge préalable', $D, 'S3', null,
                ['Request by the provider for prior authorization of the service below.', 'Demande d’autorisation préalable du prestataire pour le service ci-dessous.'],
                [['preauth.request_reference', 'Request reference', 'Référence de la demande', 'D'], self::F_MEMBER, self::F_MEMBER_NO, self::F_POLICY, self::F_INSURER, self::F_PROVIDER,
                    ['preauth.services', 'Requested service', 'Service demandé', 'D'], ['preauth.clinical_justification', 'Diagnosis / clinical justification (where permitted)', 'Diagnostic / justification clinique (si autorisé)', 'D'],
                    ['preauth.requested_amount', 'Requested amount', 'Montant demandé', 'F'], ['preauth.service_date', 'Planned service date', 'Date prévue des soins', 'D'],
                    ['preauth.attachments', 'Attachments', 'Pièces jointes', 'D'], ['preauth.submitted_at', 'Request date', 'Date de la demande', 'A']],
                [self::s(self::H_NOTICE, 'A request is not an authorization; only an approval document confirms cover.', 'Une demande ne vaut pas autorisation ; seul un document d’approbation confirme la prise en charge.'), self::medical()]),

            'DOC-066' => self::def('PREAUTHORIZATION_APPROVAL', 'Preauthorization Approval', 'Autorisation de prise en charge', $D, 'S4', null,
                ['Authorization of the services below for the member and provider named.', 'Autorisation des services ci-dessous pour l’adhérent et le prestataire désignés.'],
                [self::F_PREAUTH_NO, ['preauth.request_reference', 'Request reference', 'Référence de la demande', 'D'], self::F_MEMBER, self::F_MEMBER_NO, self::F_POLICY, self::F_INSURER, self::F_PROVIDER,
                    ['preauth.services', 'Approved service / procedure', 'Service / acte autorisé', 'D'], ['preauth.service_code', 'Service code', 'Code de l’acte', 'D'],
                    ['preauth.requested_amount', 'Requested amount', 'Montant demandé', 'F'], ['decision.approved_amount', 'Approved amount', 'Montant autorisé', 'F'],
                    ['preauth.member_amount', 'Member responsibility', 'Part de l’adhérent', 'F'], ['preauth.insurer_amount', 'Insurer responsibility', 'Part de l’assureur', 'F'],
                    ['preauth.approved_quantity', 'Approved quantity / days', 'Quantité / jours autorisés', 'D'], ['preauth.validity', 'Validity period', 'Période de validité', 'D'],
                    ['decision.conditions', 'Conditions', 'Conditions', 'D'], ['preauth.declined_lines', 'Exclusions / not-covered items', 'Exclusions / éléments non couverts', 'D'],
                    ['preauth.status', 'Authorization status', 'Statut de l’autorisation', 'A'], self::F_DECIDED_BY, ['preauth.decided_at', 'Approval timestamp', 'Horodatage de l’approbation', 'A']],
                [self::subjectToTerms('This approval is limited to the services, amount and period specified.', 'Cette autorisation est limitée aux services, au montant et à la période indiqués.'), self::medical()]),

            'DOC-067' => self::def('PARTIAL_PREAUTHORIZATION_APPROVAL', 'Partial Preauthorization Approval', 'Autorisation partielle de prise en charge', $D, 'S4', null,
                ['Partial authorization of the services requested for the member and provider named: approved scope and items not approved.', 'Autorisation partielle des services demandés pour l’adhérent et le prestataire désignés : périmètre autorisé et éléments non autorisés.'],
                [self::F_PREAUTH_NO, ['preauth.request_reference', 'Request reference', 'Référence de la demande', 'D'], self::F_MEMBER, self::F_MEMBER_NO, self::F_POLICY, self::F_INSURER, self::F_PROVIDER,
                    ['preauth.requested_scope', 'Requested scope', 'Périmètre demandé', 'D'], ['preauth.services', 'Approved scope', 'Périmètre autorisé', 'D'], ['preauth.service_code', 'Service code', 'Code de l’acte', 'D'],
                    ['preauth.declined_lines', 'Rejected / deferred items and reasons', 'Éléments refusés / différés et motifs', 'D'],
                    ['preauth.requested_amount', 'Requested amount', 'Montant demandé', 'F'], ['decision.approved_amount', 'Approved amount', 'Montant autorisé', 'F'],
                    ['preauth.member_amount', 'Member responsibility', 'Part de l’adhérent', 'F'], ['preauth.insurer_amount', 'Insurer responsibility', 'Part de l’assureur', 'F'],
                    ['preauth.approved_quantity', 'Approved quantity / days', 'Quantité / jours autorisés', 'D'], ['preauth.validity', 'Validity period', 'Période de validité', 'D'],
                    ['decision.conditions', 'Conditions', 'Conditions', 'D'], ['preauth.status', 'Authorization status', 'Statut de l’autorisation', 'A'], self::F_DECIDED_BY, ['preauth.decided_at', 'Approval timestamp', 'Horodatage de l’approbation', 'A']],
                [self::subjectToTerms('This approval is limited to the approved scope, amount and period specified; items not approved are not covered by this document.', 'Cette autorisation est limitée au périmètre, au montant et à la période autorisés ; les éléments non autorisés ne sont pas couverts par ce document.'), self::medical()]),

            'DOC-068' => self::def('PREAUTHORIZATION_REJECTION', 'Preauthorization Rejection', 'Refus de prise en charge', $D, 'S3', null,
                ['Decision not to authorize the service requested for the member and provider named.', 'Décision de ne pas autoriser le service demandé pour l’adhérent et le prestataire désignés.'],
                [['preauth.request_reference', 'Request reference', 'Référence de la demande', 'D'], self::F_MEMBER, self::F_MEMBER_NO, self::F_POLICY, self::F_INSURER, self::F_PROVIDER,
                    ['preauth.services', 'Rejected service', 'Service refusé', 'D'], ['preauth.requested_amount', 'Rejected amount', 'Montant refusé', 'F'],
                    ['decision.reason', 'Reason', 'Motif', 'A'], ['decision.review_contact', 'Review / escalation information', 'Recours / escalade', 'A'], self::F_DECIDED_BY, self::F_DECIDED_AT],
                [self::s(self::H_NOTICE, 'The member or provider may request a review of this decision through the contact indicated.', 'L’adhérent ou le prestataire peut demander le réexamen de cette décision auprès du contact indiqué.'), self::medical()]),

            'DOC-069' => self::def('GUARANTEE_OF_PAYMENT', 'Guarantee of Payment', 'Lettre de garantie / Prise en charge', $D, 'S4', null,
                ['Guarantee by the insurer to the provider named of payment for the authorized services, within the ceiling and period stated.', 'Garantie de paiement de l’assureur au prestataire désigné pour les services autorisés, dans la limite du plafond et de la période indiqués.'],
                [['guarantee.number', 'Guarantee number', 'N° de garantie', 'A'], self::F_MEMBER, self::F_MEMBER_NO, self::F_INSURER, self::F_POLICY, self::F_PROVIDER,
                    ['preauth.services', 'Authorized treatment / service', 'Traitement / service autorisé', 'D'], ['guarantee.ceiling', 'Guarantee ceiling', 'Plafond de garantie', 'F'],
                    ['preauth.member_amount', 'Member share', 'Part de l’adhérent', 'F'], ['preauth.insurer_amount', 'Insurer share', 'Part de l’assureur', 'F'],
                    ['preauth.valid_from', 'Effective time', 'Heure d’effet', 'D'], ['preauth.valid_until', 'Expiry time', 'Heure d’expiration', 'D'], ['decision.conditions', 'Conditions', 'Conditions', 'D'],
                    ['preauth.declined_lines', 'Non-guaranteed services', 'Services non garantis', 'D'], ['product.claims_requirements', 'Claim submission instructions', 'Modalités de facturation', 'D'],
                    ['issuer.contact', 'Contact / escalation point', 'Contact / escalade', 'A'], ['approval.signatory', 'Authorized signatory', 'Signataire autorisé', 'A']],
                [self::subjectToTerms('The guarantee is limited to the services, ceiling and period stated; services outside it are not guaranteed.', 'La garantie est limitée aux services, au plafond et à la période indiqués ; les services hors de ce périmètre ne sont pas garantis.'), self::medical()]),

            'DOC-070' => self::def('HOSPITAL_ADMISSION_AUTHORIZATION', 'Hospital Admission Authorization', 'Autorisation d’hospitalisation', $D, 'S4', null,
                ['Authorization of the hospital admission of the member named.', 'Autorisation de l’hospitalisation de l’adhérent désigné.'],
                [self::F_PREAUTH_NO, self::F_MEMBER, self::F_MEMBER_NO, self::F_POLICY, self::F_INSURER, ['provider.name', 'Hospital', 'Établissement hospitalier', 'P'],
                    ['admission.date', 'Admission date', 'Date d’admission', 'D'], ['admission.ward', 'Service / ward (if appropriate)', 'Service / unité (le cas échéant)', 'D'],
                    ['preauth.validity', 'Approved period', 'Période autorisée', 'D'], ['decision.approved_amount', 'Approved amount', 'Montant autorisé', 'F'],
                    ['preauth.member_amount', 'Member share', 'Part de l’adhérent', 'F'], ['preauth.insurer_amount', 'Insurer share', 'Part de l’assureur', 'F'],
                    ['decision.conditions', 'Conditions', 'Conditions', 'D'], self::F_DECIDED_BY, self::F_DECIDED_AT],
                [self::subjectToTerms('This authorization is limited to the period and amount stated.', 'Cette autorisation est limitée à la période et au montant indiqués.'), self::medical()]),

            'DOC-071' => self::def('HOSPITAL_STAY_EXTENSION_AUTHORIZATION', 'Hospital Stay Extension Authorization', 'Autorisation de prolongation d’hospitalisation', $D, 'S4', null,
                ['Authorization to extend the hospital stay covered by the original authorization referenced below.', 'Autorisation de prolonger le séjour hospitalier couvert par l’autorisation initiale référencée ci-dessous.'],
                [self::F_PREAUTH_NO, ['preauth.original_reference', 'Original authorization', 'Autorisation initiale', 'D'], self::F_MEMBER, self::F_MEMBER_NO, self::F_POLICY, self::F_INSURER,
                    ['provider.name', 'Hospital', 'Établissement hospitalier', 'P'], ['extension.dates', 'Extension dates', 'Dates de prolongation', 'D'],
                    ['extension.services', 'Additional approved services', 'Services supplémentaires autorisés', 'D'], ['extension.amount', 'Additional approved amount', 'Montant supplémentaire autorisé', 'F'],
                    ['extension.reason', 'Reason', 'Motif', 'D'], ['decision.conditions', 'Conditions', 'Conditions', 'D'], self::F_DECIDED_BY, ['preauth.decided_at', 'Approval timestamp', 'Horodatage de l’approbation', 'A']],
                [self::subjectToTerms('The extension is limited to the dates and amounts stated; the original authorization otherwise applies unchanged.', 'La prolongation est limitée aux dates et montants indiqués ; l’autorisation initiale s’applique pour le reste sans changement.'), self::medical()]),

            'DOC-072' => self::def('EXPLANATION_OF_BENEFITS', 'Explanation of Benefits', 'Relevé des prestations', $D, 'S2', null,
                ['Statement of how the claim below was processed: amounts billed, allowed, paid by the insurer and payable by the member.', 'Relevé du traitement de la demande ci-dessous : montants facturés, admis, payés par l’assureur et à la charge de l’adhérent.'],
                [self::F_MEMBER, self::F_MEMBER_NO, self::F_POLICY, self::F_INSURER, self::F_PROVIDER, ['provider_claim.number', 'Claim reference', 'Référence de la demande', 'D'],
                    ['claim.service_date', 'Service date', 'Date des soins', 'D'], ['eob.billed_amount', 'Billed amount', 'Montant facturé', 'F'], ['eob.allowed_amount', 'Allowed amount', 'Montant admis', 'F'],
                    ['eob.insurer_paid', 'Insurer paid', 'Payé par l’assureur', 'F'], ['eob.member_responsibility', 'Member responsibility', 'Reste à charge de l’adhérent', 'F'],
                    ['claim.reason_codes', 'Denial reasons', 'Motifs de rejet', 'A']],
                [self::s(self::H_NOTICE, 'This statement is not an invoice.', 'Ce relevé n’est pas une facture.'), self::medical()]),

            'DOC-073' => self::def('MEMBER_REIMBURSEMENT_STATEMENT', 'Member Reimbursement Statement', 'Décompte de remboursement', $D, 'S3', null,
                ['Statement of the reimbursement of health expenses submitted by the member.', 'Décompte du remboursement des frais de santé présentés par l’adhérent.'],
                [self::F_MEMBER, self::F_MEMBER_NO, self::F_POLICY, self::F_INSURER, ['claim.number', 'Claim reference', 'Référence de la demande', 'D'],
                    ['reimbursement.expenses_submitted', 'Expenses submitted', 'Frais présentés', 'F'], ['reimbursement.eligible_amount', 'Eligible amounts', 'Montants admis', 'F'],
                    ['reimbursement.deductible_copay', 'Deductible / copay', 'Franchise / ticket modérateur', 'F'], ['reimbursement.approved_amount', 'Approved reimbursement', 'Remboursement accordé', 'F'],
                    ['payment.reference', 'Payment reference', 'Référence du paiement', 'F']],
                [self::medical()]),

            'DOC-074' => self::def('BENEFIT_EXHAUSTION_NOTICE', 'Benefit Exhaustion Notice', 'Avis d’épuisement de garantie', $D, 'S2', null,
                ['Notice that the benefit category below has been consumed in full or in part.', 'Avis de consommation totale ou partielle de la catégorie de garantie ci-dessous.'],
                [self::F_MEMBER, self::F_MEMBER_NO, self::F_POLICY, self::F_INSURER, ['benefit.category', 'Benefit category', 'Catégorie de garantie', 'D'],
                    ['benefit.original_limit', 'Original limit', 'Plafond initial', 'F'], ['benefit.consumed', 'Consumed amount', 'Montant consommé', 'F'],
                    ['benefit.remaining', 'Remaining / exhausted amount', 'Solde / montant épuisé', 'F'], ['benefit.exhausted_on', 'Date', 'Date', 'D'], ['benefit.implications', 'Implications', 'Conséquences', 'D']],
                [self::s(self::H_NOTICE, 'Services under an exhausted benefit are not payable by the insurer for the remainder of the benefit period.', 'Les soins relevant d’une garantie épuisée ne sont plus pris en charge par l’assureur pour le reste de la période de garantie.'), self::medical()]),

            'DOC-075' => self::def('HEALTH_COVERAGE_TERMINATION_CERTIFICATE', 'Health Coverage Termination Certificate', 'Certificat de cessation de couverture santé', $D, 'S3', null,
                ['Certifies the end of the health cover of the member named, from the termination date stated.', 'Certifie la fin de la couverture santé de l’adhérent désigné, à compter de la date de cessation indiquée.'],
                [self::F_MEMBER, self::F_MEMBER_NO, self::F_POLICY, self::F_INSURER, self::F_PLAN, ['termination.effective_at', 'Termination date', 'Date de cessation', 'D'],
                    ['termination.reason', 'Reason / category', 'Motif / catégorie', 'D'], ['member.card_status', 'Coverage status', 'Statut de la couverture', 'A'],
                    ['termination.continuation', 'Continuation information (where applicable)', 'Maintien des garanties (le cas échéant)', 'D']],
                [self::s(self::H_NOTICE, 'Services received after the termination date are not covered under this membership.', 'Les soins reçus après la date de cessation ne sont pas couverts au titre de cette adhésion.'), self::noDiagnosis()]),

            // ================================================ E. Life, Savings, Retirement & Beneficiaries
            'DOC-076' => self::def('LIFE_INSURANCE_ILLUSTRATION', 'Life Insurance Illustration', 'Illustration d’assurance vie', $E, 'S1', $LIFE,
                ['Illustration of the benefits and values of the life insurance contract proposed below, on the stated assumptions.', 'Illustration des prestations et valeurs du contrat d’assurance vie proposé ci-dessous, selon les hypothèses indiquées.'],
                [['party.name', 'Applicant', 'Demandeur', 'P'], ['party.insured', 'Proposed insured', 'Assuré proposé', 'P'], ['party.age', 'Age / date of birth', 'Âge / date de naissance', 'P'],
                    ['policy.product', 'Product', 'Produit', 'K'], self::F_INSURER, ['policy.term', 'Term', 'Durée', 'K'], ['coverage.sum_insured', 'Sum assured', 'Capital assuré', 'F'],
                    ['premium.gross', 'Premium', 'Prime', 'F'], ['premium.frequency', 'Payment frequency', 'Périodicité', 'F'], ['life.benefits', 'Benefits', 'Prestations', 'D'],
                    ['life.guaranteed_values', 'Guaranteed values', 'Valeurs garanties', 'F'], ['life.projected_values', 'Projected values (non-guaranteed)', 'Valeurs projetées (non garanties)', 'F'],
                    ['life.surrender_value', 'Surrender values', 'Valeurs de rachat', 'F'], ['life.maturity_values', 'Maturity values', 'Valeurs à l’échéance', 'F'],
                    ['quote.assumptions', 'Assumptions', 'Hypothèses', 'D'], ['life.charges', 'Charges', 'Frais', 'F'], ['illustration.date', 'Illustration date', 'Date de l’illustration', 'D'],
                    ['illustration.validity', 'Validity / assumption basis', 'Validité / base des hypothèses', 'D'], ['intermediary.name', 'Adviser', 'Conseiller', 'P']],
                [self::s(['Warning', 'Avertissement'], 'Projected values are not guaranteed: they depend on the stated assumptions and may be higher or lower. Only the values marked guaranteed are guaranteed. This illustration is not a contract and not proof of cover.',
                    'Les valeurs projetées ne sont pas garanties : elles dépendent des hypothèses indiquées et peuvent être supérieures ou inférieures. Seules les valeurs indiquées comme garanties le sont. Cette illustration n’est ni un contrat ni une preuve d’assurance.')]),

            'DOC-077' => self::def('LIFE_CONTRACT_SUMMARY', 'Life Contract Summary', 'Encadré récapitulatif du contrat vie', $E, 'S2', $LIFE,
                ['Summary of the key features of the life contract below.', 'Récapitulatif des caractéristiques essentielles du contrat vie ci-dessous.'],
                [self::F_LIFE_POLICY, self::F_INSURER, self::F_HOLDER, self::F_INSURED, ['policy.product', 'Product', 'Produit', 'K'], ['life.benefits', 'Key benefits', 'Garanties principales', 'D'],
                    ['policy.term', 'Term', 'Durée', 'K'], ['premium.gross', 'Premium', 'Prime', 'F'], ['premium.frequency', 'Payment frequency', 'Périodicité', 'F'], ['life.charges', 'Charges', 'Frais', 'F'],
                    ['coverage.exclusions', 'Exclusions', 'Exclusions', 'D'], ['life.surrender_summary', 'Surrender summary', 'Conditions de rachat', 'D'], ['life.maturity_summary', 'Maturity summary', 'Prestation à l’échéance', 'D'],
                    ['beneficiary.designation', 'Beneficiary rules', 'Règles de désignation des bénéficiaires', 'D']],
                [self::subjectToTerms('This summary does not replace the contract.', 'Ce récapitulatif ne remplace pas le contrat.')]),

            'DOC-078' => self::def('LIFE_PROPOSAL', 'Life Proposal', 'Proposition d’assurance vie', $E, 'S2', $LIFE,
                ['Proposal for life insurance submitted by the applicant for the cover described below.', 'Proposition d’assurance vie présentée par le demandeur pour la garantie décrite ci-dessous.'],
                [['proposal.number', 'Proposal number', 'N° de proposition', 'K'], ['party.name', 'Applicant', 'Demandeur', 'P'], ['party.insured', 'Person to be insured', 'Personne à assurer', 'P'], self::F_DOB,
                    ['party.contact', 'Contact details', 'Coordonnées', 'P'], ['beneficiary.list', 'Beneficiary details', 'Bénéficiaires', 'P'], ['policy.product', 'Product', 'Produit', 'K'], self::F_INSURER,
                    ['policy.term', 'Term', 'Durée', 'K'], ['coverage.sum_insured', 'Sum assured', 'Capital assuré', 'F'], ['premium.gross', 'Premium', 'Prime', 'F'], ['premium.frequency', 'Payment frequency', 'Périodicité', 'F'],
                    ['proposal.medical_declarations', 'Medical declarations', 'Déclarations médicales', 'S'], ['proposal.financial_declarations', 'Financial declarations', 'Déclarations financières', 'S'],
                    ['consent.record', 'Consent', 'Consentement', 'S'], self::F_SIGNED_AT, ['proposal.attestation', 'Applicant signature', 'Signature du demandeur', 'S']],
                [self::decl('applicant'), self::consent(), self::s(self::H_NOTICE, 'A proposal is not proof of cover; cover starts only when the insurer accepts it.', 'Une proposition ne vaut pas preuve d’assurance ; la garantie ne prend effet qu’après acceptation par l’assureur.')]),

            'DOC-079' => self::def('LIFE_MEDICAL_QUESTIONNAIRE', 'Life Medical Questionnaire', 'Questionnaire médical vie', $E, 'S3', $LIFE,
                ['Medical questionnaire of the person to be insured under the life proposal referenced below.', 'Questionnaire médical de la personne à assurer au titre de la proposition vie référencée ci-dessous.'],
                [['party.insured', 'Insured', 'Assuré', 'M'], self::F_DOB, ['proposal.number', 'Proposal reference', 'Référence de la proposition', 'K'], self::F_INSURER,
                    ['medical.questions', 'Medical questions and answers', 'Questions et réponses médicales', 'D'], ['proposal.declarations', 'Declarations', 'Déclarations', 'S'],
                    ['consent.record', 'Consent', 'Consentement', 'S'], self::F_SIGNED_AT, self::F_SIGNATURE, ['confidentiality.class', 'Confidentiality classification', 'Classification de confidentialité', 'S']],
                [self::decl('insured'), self::consent(), self::medical()]),

            'DOC-080' => self::def('MEDICAL_EXAMINATION_REQUEST', 'Medical Examination Request', 'Demande d’examen médical', $E, 'S2', $LIFE,
                ['Request that the person to be insured undergo the examinations below for the underwriting of the proposal referenced.', 'Demande de réalisation des examens ci-dessous par la personne à assurer, pour la sélection médicale de la proposition référencée.'],
                [['party.insured', 'Insured', 'Assuré', 'M'], ['proposal.number', 'Proposal reference', 'Référence de la proposition', 'K'], self::F_INSURER,
                    ['medical.examinations', 'Requested examinations / tests', 'Examens / analyses demandés', 'D'], ['provider.name', 'Examining provider', 'Prestataire chargé de l’examen', 'P'],
                    ['medical.deadline', 'Deadline', 'Date limite', 'D'], ['medical.instructions', 'Instructions', 'Instructions', 'D']],
                [self::s(self::H_PRIVACY, 'Examination results are sent only to the insurer’s medical adviser and are processed as restricted medical information.', 'Les résultats des examens sont transmis uniquement au médecin-conseil de l’assureur et traités comme information médicale restreinte.')]),

            'DOC-081' => self::def('FINANCIAL_NEEDS_DECLARATION', 'Financial Needs Declaration', 'Déclaration des besoins financiers', $E, 'S2', $LIFE,
                ['Declaration of the applicant’s financial situation and protection objective supporting the life proposal.', 'Déclaration de la situation financière et de l’objectif de protection du demandeur à l’appui de la proposition vie.'],
                [['party.name', 'Applicant', 'Demandeur', 'P'], ['proposal.number', 'Proposal reference', 'Référence de la proposition', 'K'], ['financial.income', 'Income (where required)', 'Revenus (si requis)', 'F'],
                    ['financial.assets', 'Assets (where required)', 'Patrimoine (si requis)', 'F'], ['financial.liabilities', 'Liabilities (where required)', 'Engagements (si requis)', 'F'],
                    ['financial.objective', 'Coverage objective', 'Objectif de couverture', 'D'], ['coverage.sum_insured', 'Requested sum assured', 'Capital demandé', 'F'],
                    ['proposal.declarations', 'Declaration', 'Déclaration', 'S'], self::F_SIGNED_AT, self::F_SIGNATURE],
                [self::decl('applicant'), self::financial()]),

            'DOC-082' => self::def('BENEFICIARY_NOMINATION', 'Beneficiary Nomination', 'Désignation de bénéficiaire', $E, 'S3', $LIFE,
                ['Nomination by the policyholder of the beneficiaries of the life contract below.', 'Désignation par le souscripteur des bénéficiaires du contrat vie ci-dessous.'],
                [['policy.number', 'Policy / proposal', 'Police / proposition', 'K'], self::F_INSURER, self::F_HOLDER, self::F_INSURED, ['beneficiary.name', 'Beneficiary full name', 'Nom complet du bénéficiaire', 'D'],
                    ['beneficiary.identifier', 'Beneficiary ID / reference (where permitted)', 'Pièce / référence du bénéficiaire (si autorisé)', 'D'], ['beneficiary.relationship', 'Relationship', 'Lien avec l’assuré', 'D'],
                    ['beneficiary.date_of_birth', 'Date of birth (where needed)', 'Date de naissance (si nécessaire)', 'D'], ['beneficiary.contact', 'Contact (where permitted)', 'Contact (si autorisé)', 'D'],
                    ['beneficiary.allocation', 'Allocation percentage', 'Pourcentage attribué', 'D'], ['beneficiary.type', 'Beneficiary type', 'Type de bénéficiaire', 'D'],
                    ['beneficiary.contingent', 'Contingent beneficiary (where supported)', 'Bénéficiaire subsidiaire (si prévu)', 'D'], ['beneficiary.allocation_total', 'Total allocation', 'Total des attributions', 'D'],
                    ['beneficiary.effective_date', 'Effective date', 'Date d’effet', 'D'], ['proposal.declarations', 'Policyholder declaration', 'Déclaration du souscripteur', 'S'], self::F_SIGNED_AT, self::F_SIGNATURE,
                    ['beneficiary.witness', 'Witness / notarization (if required)', 'Témoin / légalisation (si requis)', 'S']],
                [self::decl('policyholder'), self::s(self::H_NOTICE, 'Allocations must total 100%; a nomination whose total differs is not accepted.', 'Le total des attributions doit être de 100 % ; une désignation dont le total diffère n’est pas acceptée.')]),

            'DOC-083' => self::def('BENEFICIARY_ALLOCATION_SCHEDULE', 'Beneficiary Allocation Schedule', 'Répartition des bénéficiaires', $E, 'S3', $LIFE,
                ['Schedule of the beneficiaries of the life contract below and their shares.', 'État des bénéficiaires du contrat vie ci-dessous et de leurs parts.'],
                [self::F_LIFE_POLICY, self::F_INSURER, self::F_HOLDER, ['beneficiary.list', 'Beneficiary list', 'Liste des bénéficiaires', 'D'], ['beneficiary.allocation', 'Percentages / shares', 'Pourcentages / parts', 'D'],
                    ['beneficiary.contingent', 'Contingent status', 'Statut subsidiaire', 'D'], ['beneficiary.effective_date', 'Effective dates', 'Dates d’effet', 'D'], ['beneficiary.allocation_total', 'Total validation', 'Contrôle du total', 'D']],
                [self::s(self::H_NOTICE, 'Allocations total 100%. This schedule replaces any earlier allocation schedule of the policy.', 'Le total des attributions est de 100 %. Cet état remplace tout état de répartition antérieur de la police.')]),

            'DOC-084' => self::def('BENEFICIARY_CHANGE_REQUEST', 'Beneficiary Change Request', 'Demande de modification de bénéficiaire', $E, 'S3', $LIFE,
                ['Request by the policyholder to change the beneficiaries of the life contract below.', 'Demande du souscripteur de modifier les bénéficiaires du contrat vie ci-dessous.'],
                [self::F_LIFE_POLICY, self::F_INSURER, self::F_HOLDER, ['beneficiary.current', 'Current beneficiaries', 'Bénéficiaires actuels', 'D'], ['beneficiary.list', 'New beneficiaries', 'Nouveaux bénéficiaires', 'D'],
                    ['beneficiary.requested_changes', 'Requested changes', 'Modifications demandées', 'D'], ['beneficiary.allocation_total', 'Total allocation', 'Total des attributions', 'D'],
                    ['beneficiary.requested_effective_date', 'Requested effective date', 'Date d’effet demandée', 'D'], ['beneficiary.authorization', 'Policyholder authorization', 'Autorisation du souscripteur', 'S'], self::F_SIGNED_AT, self::F_SIGNATURE],
                [self::decl('policyholder'), self::s(self::H_NOTICE, 'The change takes effect only once confirmed by the insurer.', 'La modification ne prend effet qu’après confirmation par l’assureur.')]),

            'DOC-085' => self::def('BENEFICIARY_CHANGE_CONFIRMATION', 'Beneficiary Change Confirmation', 'Confirmation de modification de bénéficiaire', $E, 'S3', $LIFE,
                ['Confirmation that the beneficiary schedule below has been accepted for the life contract.', 'Confirmation de l’acceptation de l’état des bénéficiaires ci-dessous pour le contrat vie.'],
                [self::F_LIFE_POLICY, self::F_INSURER, self::F_HOLDER, ['beneficiary.list', 'Accepted beneficiary schedule', 'État des bénéficiaires accepté', 'D'], ['beneficiary.allocation', 'Shares', 'Parts', 'D'],
                    ['beneficiary.effective_date', 'Effective date', 'Date d’effet', 'D'], ['beneficiary.previous_version', 'Previous version', 'Version précédente', 'D'], ['approval.decided_by', 'Confirmed by', 'Confirmé par', 'A']],
                [self::s(self::H_NOTICE, 'This confirmation replaces the previous beneficiary schedule from the effective date.', 'Cette confirmation remplace l’état des bénéficiaires précédent à compter de la date d’effet.')]),

            'DOC-086' => self::def('LIFE_POLICY_SCHEDULE', 'Life Policy Schedule', 'Conditions particulières vie', $E, 'S3', $LIFE,
                ['Particular conditions of the life contract below.', 'Conditions particulières du contrat vie ci-dessous.'],
                [self::F_LIFE_POLICY, self::F_INSURER, self::F_HOLDER, self::F_INSURED, ['policy.product', 'Product', 'Produit', 'K'], ['coverage.sum_insured', 'Sum assured', 'Capital assuré', 'F'],
                    ['life.benefits', 'Benefits', 'Garanties', 'D'], ['premium.gross', 'Premium', 'Prime', 'F'], ['premium.frequency', 'Payment frequency', 'Périodicité', 'F'], ['policy.term', 'Term', 'Durée', 'K'],
                    ['policy.period', 'Dates', 'Dates', 'K'], ['beneficiary.designation', 'Beneficiaries reference', 'Référence des bénéficiaires', 'D'], ['underwriting.conditions', 'Special terms', 'Conditions spéciales', 'D']],
                [self::subjectToTerms('These particular conditions form part of the contract with its general conditions.', 'Ces conditions particulières forment le contrat avec ses conditions générales.')]),

            'DOC-087' => self::def('LIFE_BENEFIT_SCHEDULE', 'Life Benefit Schedule', 'Tableau des prestations vie', $E, 'S2', $LIFE,
                ['Schedule of the benefits payable under the life contract below.', 'Tableau des prestations payables au titre du contrat vie ci-dessous.'],
                [self::F_LIFE_POLICY, self::F_INSURER, ['policy.product', 'Plan', 'Formule', 'K'], ['life.benefit_events', 'Benefit events', 'Événements garantis', 'D'], ['life.benefit_amounts', 'Amounts / formulas', 'Montants / formules', 'F'],
                    ['benefit.waiting_periods', 'Waiting periods', 'Délais de carence', 'D'], ['coverage.exclusions', 'Exclusions', 'Exclusions', 'D'], ['life.maturity_benefit', 'Maturity benefit', 'Prestation à l’échéance', 'F'],
                    ['life.death_benefit', 'Death benefit', 'Prestation en cas de décès', 'F'], ['life.disability_benefit', 'Disability benefit', 'Prestation en cas d’invalidité', 'F']],
                [self::subjectToTerms('This schedule summarizes the benefits of the contract.', 'Ce tableau résume les prestations du contrat.')]),

            'DOC-088' => self::def('CONTRIBUTION_SCHEDULE', 'Contribution Schedule', 'Échéancier des cotisations', $E, 'S2', $LIFE,
                ['Schedule of the contributions due under the life contract below.', 'Échéancier des cotisations dues au titre du contrat vie ci-dessous.'],
                [self::F_LIFE_POLICY, self::F_INSURER, self::F_HOLDER, ['premium.frequency', 'Contribution frequency', 'Périodicité des cotisations', 'F'], ['premium.instalments', 'Due dates and amounts', 'Échéances et montants', 'F'],
                    ['premium.gross', 'Total contribution', 'Cotisation totale', 'F'], ['premium.indexation', 'Escalation / indexation rules (where applicable)', 'Règles de revalorisation / indexation (le cas échéant)', 'D']],
                [self::financial()]),

            'DOC-089' => self::def('ANNUAL_LIFE_STATEMENT', 'Annual Life Statement', 'Relevé annuel assurance vie', $E, 'S3', $LIFE,
                ['Annual statement of the values of the life contract below for the period stated.', 'Relevé annuel des valeurs du contrat vie ci-dessous pour la période indiquée.'],
                [self::F_LIFE_POLICY, self::F_INSURER, self::F_HOLDER, ['statement.period', 'Statement period', 'Période du relevé', 'K'], ['statement.opening_balance', 'Opening value', 'Valeur d’ouverture', 'F'],
                    ['life.contributions', 'Contributions', 'Cotisations', 'F'], ['life.charges', 'Charges', 'Frais', 'F'], ['life.interest', 'Interest / investment performance (where applicable)', 'Intérêts / performance (le cas échéant)', 'F'],
                    ['life.benefits_paid', 'Benefits', 'Prestations', 'F'], ['life.surrender_value', 'Surrender value', 'Valeur de rachat', 'F'], ['statement.closing_balance', 'Closing value', 'Valeur de clôture', 'F']],
                [self::financial()]),

            'DOC-090' => self::def('SURRENDER_REQUEST', 'Surrender Request', 'Demande de rachat', $E, 'S3', $LIFE,
                ['Request by the policyholder to surrender the life contract below, in full or in part.', 'Demande du souscripteur de racheter le contrat vie ci-dessous, totalement ou partiellement.'],
                [self::F_LIFE_POLICY, self::F_INSURER, self::F_HOLDER, ['surrender.type', 'Surrender type (full / partial)', 'Type de rachat (total / partiel)', 'D'], ['surrender.requested_amount', 'Requested amount', 'Montant demandé', 'F'],
                    ['payee.bank', 'Bank / payment details', 'Coordonnées bancaires / de paiement', 'F'], ['proposal.declarations', 'Declaration', 'Déclaration', 'S'], self::F_SIGNED_AT, self::F_SIGNATURE],
                [self::decl('policyholder'), self::s(self::H_NOTICE, 'The amount paid is the surrender value calculated by the insurer at the processing date.', 'Le montant versé est la valeur de rachat calculée par l’assureur à la date de traitement.'), self::financial()]),

            'DOC-091' => self::def('SURRENDER_VALUE_STATEMENT', 'Surrender Value Statement', 'Relevé de valeur de rachat', $E, 'S4', $LIFE,
                ['Statement of the surrender value of the life contract below at the calculation date.', 'Relevé de la valeur de rachat du contrat vie ci-dessous à la date de calcul.'],
                [self::F_LIFE_POLICY, self::F_INSURER, self::F_HOLDER, ['surrender.calculated_at', 'Calculation date', 'Date de calcul', 'D'], ['surrender.gross_value', 'Gross value', 'Valeur brute', 'F'],
                    ['surrender.charges', 'Charges / adjustments', 'Frais / ajustements', 'F'], ['surrender.advances', 'Loans / advances', 'Avances en cours', 'F'], ['surrender.tax', 'Tax (where applicable)', 'Impôt (le cas échéant)', 'F'],
                    ['life.surrender_value', 'Net surrender value', 'Valeur de rachat nette', 'F']],
                [self::s(self::H_NOTICE, 'The value is stated at the calculation date and changes afterwards.', 'La valeur est indiquée à la date de calcul et évolue ensuite.'), self::financial()]),

            'DOC-092' => self::def('POLICY_ADVANCE_APPLICATION', 'Policy Advance Application', 'Demande d’avance sur police', $E, 'S3', $LIFE,
                ['Application by the policyholder for an advance on the life contract below.', 'Demande d’avance du souscripteur sur le contrat vie ci-dessous.'],
                [self::F_LIFE_POLICY, self::F_INSURER, self::F_HOLDER, ['advance.available_value', 'Available value', 'Valeur disponible', 'F'], ['advance.requested_amount', 'Requested advance', 'Avance demandée', 'F'],
                    ['advance.purpose', 'Purpose (if required)', 'Objet (si requis)', 'D'], ['advance.repayment_preference', 'Repayment preference', 'Mode de remboursement souhaité', 'D'], self::F_SIGNED_AT, self::F_SIGNATURE],
                [self::decl('policyholder'), self::financial()]),

            'DOC-093' => self::def('POLICY_ADVANCE_AGREEMENT', 'Policy Advance Agreement', 'Convention d’avance sur police', $E, 'S4', $LIFE,
                ['Agreement for the advance granted on the life contract below.', 'Convention relative à l’avance consentie sur le contrat vie ci-dessous.'],
                [self::F_LIFE_POLICY, self::F_INSURER, self::F_HOLDER, ['advance.amount', 'Advance amount', 'Montant de l’avance', 'F'], ['advance.rate', 'Rate / charges (if applicable)', 'Taux / frais (le cas échéant)', 'F'],
                    ['advance.repayment_terms', 'Repayment terms', 'Modalités de remboursement', 'D'], ['advance.security', 'Security against the policy', 'Garantie sur la police', 'D'],
                    ['advance.consequences', 'Consequences of non-repayment', 'Conséquences du non-remboursement', 'D'], ['proposal.attestation', 'Policyholder signature', 'Signature du souscripteur', 'S'],
                    ['approval.signatory', 'Insurer signatory', 'Signataire pour l’assureur', 'A']],
                [self::s(self::H_NOTICE, 'Any advance outstanding is deducted from the benefits or surrender value paid under the policy.', 'Toute avance en cours est déduite des prestations ou de la valeur de rachat versées au titre de la police.'), self::financial()]),

            'DOC-094' => self::def('MATURITY_NOTICE', 'Maturity Notice', 'Avis d’échéance du contrat vie', $E, 'S3', $LIFE,
                ['Notice that the life contract below reaches maturity, with the requirements for payment of the maturity benefit.', 'Avis d’arrivée à échéance du contrat vie ci-dessous et des pièces requises pour le paiement de la prestation.'],
                [self::F_LIFE_POLICY, self::F_INSURER, self::F_HOLDER, ['maturity.date', 'Maturity date', 'Date d’échéance', 'D'], ['maturity.expected_benefit', 'Expected benefit', 'Prestation prévue', 'F'],
                    ['documents.required', 'Requirements', 'Pièces requises', 'D'], ['maturity.payee', 'Beneficiary / payee', 'Bénéficiaire / payé', 'P'], ['payment.instructions', 'Payment instructions', 'Instructions de paiement', 'D']],
                [self::s(self::H_NOTICE, 'The expected benefit is indicative; the amount paid is confirmed in the maturity benefit statement.', 'La prestation prévue est indicative ; le montant versé est confirmé dans le décompte de prestation à l’échéance.')]),

            'DOC-095' => self::def('MATURITY_BENEFIT_STATEMENT', 'Maturity Benefit Statement', 'Décompte de prestation à l’échéance', $E, 'S4', $LIFE,
                ['Statement of the maturity benefit of the life contract below.', 'Décompte de la prestation à l’échéance du contrat vie ci-dessous.'],
                [self::F_LIFE_POLICY, self::F_INSURER, self::F_HOLDER, ['maturity.calculation', 'Maturity benefit calculation', 'Calcul de la prestation', 'F'], ['maturity.deductions', 'Deductions / advances', 'Déductions / avances', 'F'],
                    ['maturity.net_benefit', 'Net benefit', 'Prestation nette', 'F'], ['maturity.payee', 'Payee', 'Bénéficiaire du paiement', 'P'], ['payment.status', 'Payment status', 'Statut du paiement', 'F'],
                    ['payment.reference', 'Payment reference', 'Référence du paiement', 'F']],
                [self::financial()]),

            // ================================================ F. Group & Corporate
            'DOC-096' => self::def('MASTER_GROUP_POLICY', 'Master Group Policy', 'Police groupe', $F, 'S3', null,
                ['Master policy between the insurer and the sponsoring organization for the group cover described below.', 'Police cadre entre l’assureur et l’organisation souscriptrice pour la couverture de groupe décrite ci-dessous.'],
                [self::F_GROUP_POLICY, self::F_INSURER, ['group.sponsor', 'Sponsoring employer / organization', 'Employeur / organisation souscriptrice', 'P'], ['policy.period', 'Policy period', 'Période de la police', 'K'],
                    ['group.eligible_members', 'Eligible member definition', 'Définition des membres éligibles', 'D'], ['group.member_categories', 'Covered member categories', 'Catégories de membres couvertes', 'D'],
                    ['coverage.lines', 'Coverages', 'Garanties', 'D'], ['benefit.limits', 'Benefit limits', 'Plafonds de garantie', 'D'], ['group.contribution_basis', 'Contribution / premium basis', 'Base de cotisation / prime', 'F'],
                    ['benefit.waiting_periods', 'Waiting periods', 'Délais de carence', 'D'], ['product.eligibility_rules', 'Eligibility rules', 'Règles d’éligibilité', 'D'], ['group.enrollment_rules', 'Enrollment rules', 'Règles d’adhésion', 'D'],
                    ['group.termination_rules', 'Termination rules', 'Règles de cessation', 'D'], ['group.dependant_rules', 'Dependant rules', 'Règles relatives aux ayants droit', 'D'], ['product.claims_requirements', 'Claims rules', 'Règles de déclaration des sinistres', 'D'],
                    ['group.member_certificate_rules', 'Member certificate rules', 'Règles des certificats d’adhésion', 'D'], ['group.corporate_contacts', 'Corporate contacts', 'Contacts de l’entreprise', 'P'],
                    ['approval.signatory', 'Insurer signatory', 'Signataire pour l’assureur', 'A'], ['group.sponsor_signatory', 'Sponsor signatory', 'Signataire pour le souscripteur', 'A']],
                [self::subjectToTerms('Members’ rights derive from this master policy; member certificates evidence individual membership.', 'Les droits des membres découlent de cette police cadre ; les certificats d’adhésion attestent l’adhésion individuelle.')]),

            'DOC-097' => self::def('GROUP_MEMBERSHIP_FORM', 'Group Membership Form', 'Bulletin d’adhésion groupe', $F, 'S2', null,
                ['Enrollment of the employee / member below in the group policy.', 'Adhésion de l’employé / membre ci-dessous à la police groupe.'],
                [self::F_GROUP, self::F_GROUP_POLICY, self::F_EMPLOYEE, ['member.employee_number', 'Employee number', 'Matricule', 'M'], self::F_DOB, ['member.dependants', 'Dependants', 'Ayants droit', 'M'],
                    ['group.benefit_selection', 'Benefit selection', 'Garanties choisies', 'D'], ['proposal.requested_effective_date', 'Effective date', 'Date d’effet', 'K'],
                    ['consent.record', 'Consent', 'Consentement', 'S'], self::F_SIGNED_AT, self::F_SIGNATURE],
                [self::decl('member'), self::consent()]),

            'DOC-098' => self::def('EMPLOYEE_CENSUS', 'Employee Census', 'État nominatif des employés', $F, 'S2', null,
                ['Census of the employees of the group below, for enrollment and rating of the group cover.', 'État nominatif des employés du groupe ci-dessous, pour l’adhésion et la tarification de la couverture groupe.'],
                [self::F_GROUP, self::F_GROUP_POLICY, ['census.as_at', 'Census date', 'Date de l’état', 'K'], ['census.employee_identifiers', 'Employee identifiers', 'Identifiants des employés', 'D'],
                    ['census.categories', 'Categories', 'Catégories', 'D'], ['census.dates', 'Dates (birth / hire / cover)', 'Dates (naissance / embauche / couverture)', 'D'],
                    ['census.salary_basis', 'Salary / benefit basis (where applicable)', 'Base salariale / de garantie (le cas échéant)', 'F'], ['census.coverage_status', 'Coverage status', 'Statut de couverture', 'D'],
                    ['census.headcount', 'Headcount', 'Effectif', 'D']],
                [self::decl('employer'), self::consent()]),

            'DOC-099' => self::def('DEPENDANT_CENSUS', 'Dependant Census', 'État des ayants droit', $F, 'S2', null,
                ['Census of the dependants of the members of the group below.', 'État des ayants droit des membres du groupe ci-dessous.'],
                [self::F_GROUP, self::F_GROUP_POLICY, ['member.name', 'Primary member', 'Membre principal', 'M'], ['dependant.name', 'Dependant', 'Ayant droit', 'D'], ['dependant.relationship', 'Relationship', 'Lien de parenté', 'D'],
                    ['dependant.date_of_birth', 'Date of birth', 'Date de naissance', 'D'], ['dependant.eligibility', 'Eligibility', 'Éligibilité', 'D'], ['dependant.effective_dates', 'Effective dates', 'Dates d’effet', 'D']],
                [self::decl('employer'), self::consent()]),

            'DOC-100' => self::def('GROUP_MEMBER_CERTIFICATE', 'Group Member Certificate', 'Certificat d’adhésion groupe', $F, 'S3', null,
                ['Certifies that the member named below is covered under the group policy stated, for the coverage period stated.', 'Certifie que le membre désigné ci-dessous est couvert au titre de la police groupe indiquée, pour la période indiquée.'],
                [self::F_EMPLOYEE, self::F_MEMBER_NO, self::F_GROUP, self::F_GROUP_POLICY, self::F_INSURER, ['member.benefit_category', 'Benefit category', 'Catégorie de garanties', 'K'],
                    ['member.validity', 'Coverage period', 'Période de couverture', 'K'], ['group.key_benefits', 'Key benefits', 'Garanties principales', 'D'], ['member.card_status', 'Membership status', 'Statut d’adhésion', 'A']],
                [self::subjectToTerms('This certificate evidences membership under the master group policy.', 'Ce certificat atteste l’adhésion au titre de la police groupe.')]),

            'DOC-101' => self::def('MEMBER_BENEFIT_CERTIFICATE', 'Individual Benefit Certificate', 'Certificat individuel de prestations', $F, 'S3', null,
                ['Certifies the individual benefits selected by the member under the group policy stated.', 'Certifie les garanties individuelles choisies par le membre au titre de la police groupe indiquée.'],
                [self::F_EMPLOYEE, self::F_MEMBER_NO, self::F_GROUP_POLICY, self::F_INSURER, ['group.benefit_selection', 'Selected individual benefits', 'Garanties individuelles choisies', 'D'],
                    ['benefit.limits', 'Limits / sums assured', 'Plafonds / capitaux assurés', 'F'], ['member.validity', 'Effective dates', 'Dates d’effet', 'K']],
                [self::subjectToTerms('This certificate states the member’s individual benefits.', 'Ce certificat indique les garanties individuelles du membre.')]),

            'DOC-102' => self::def('EMPLOYEE_ADDITION_NOTICE', 'Employee Addition Notice', 'Avis d’ajout d’employé', $F, 'S2', null,
                ['Notice of the addition of the employee below to the group cover.', 'Avis d’ajout de l’employé ci-dessous à la couverture groupe.'],
                [self::F_GROUP, self::F_GROUP_POLICY, self::F_EMPLOYEE, ['member.employee_number', 'Employee number', 'Matricule', 'M'], ['movement.effective_at', 'Effective date', 'Date d’effet', 'D'],
                    ['member.benefit_category', 'Category', 'Catégorie', 'D'], ['group.benefit_selection', 'Benefits', 'Garanties', 'D'], ['endorsement.premium_delta', 'Additional premium', 'Prime additionnelle', 'F']],
                [self::subjectToTerms('The addition takes effect on the date stated.', 'L’ajout prend effet à la date indiquée.')]),

            'DOC-103' => self::def('EMPLOYEE_REMOVAL_NOTICE', 'Employee Removal Notice', 'Avis de retrait d’employé', $F, 'S2', null,
                ['Notice of the removal of the employee below from the group cover.', 'Avis de retrait de l’employé ci-dessous de la couverture groupe.'],
                [self::F_GROUP, self::F_GROUP_POLICY, self::F_EMPLOYEE, ['member.employee_number', 'Employee number', 'Matricule', 'M'], ['movement.effective_at', 'Termination date', 'Date de cessation', 'D'],
                    ['movement.reason', 'Reason', 'Motif', 'D'], ['endorsement.premium_delta', 'Premium adjustment', 'Ajustement de prime', 'F']],
                [self::s(self::H_NOTICE, 'Cover for the employee ends on the termination date stated.', 'La couverture de l’employé prend fin à la date de cessation indiquée.')]),

            'DOC-104' => self::def('GROUP_MOVEMENT_SCHEDULE', 'Group Movement Schedule', 'État des mouvements du groupe', $F, 'S2', null,
                ['Schedule of the member movements of the group below for the period stated.', 'État des mouvements de membres du groupe ci-dessous pour la période indiquée.'],
                [self::F_GROUP, self::F_GROUP_POLICY, ['statement.period', 'Period', 'Période', 'K'], ['movement.additions', 'Additions', 'Entrées', 'D'], ['movement.removals', 'Removals', 'Sorties', 'D'],
                    ['movement.changes', 'Changes', 'Modifications', 'D'], ['movement.effective_dates', 'Effective dates', 'Dates d’effet', 'D'], ['movement.premium_impacts', 'Premium impacts', 'Incidences sur la prime', 'F'],
                    ['movement.totals', 'Totals', 'Totaux', 'F']],
                []),

            'DOC-105' => self::def('GROUP_PREMIUM_STATEMENT', 'Group Premium Statement', 'État des primes groupe', $F, 'S3', null,
                ['Statement of the premium of the group below for the period stated.', 'État des primes du groupe ci-dessous pour la période indiquée.'],
                [self::F_GROUP, self::F_GROUP_POLICY, self::F_INSURER, ['statement.period', 'Period', 'Période', 'K'], ['census.headcount', 'Headcount / basis', 'Effectif / assiette', 'D'],
                    ['group.premium_by_category', 'Premium by category', 'Prime par catégorie', 'F'], ['statement.taxes', 'Taxes', 'Taxes', 'F'], ['statement.adjustments', 'Adjustments', 'Ajustements', 'F'],
                    ['obligation.amount', 'Total due', 'Total dû', 'F'], ['obligation.paid', 'Total paid', 'Total payé', 'F'], ['obligation.outstanding', 'Balance outstanding', 'Solde restant dû', 'F']],
                [self::financial()]),

            'DOC-106' => self::def('GROUP_RENEWAL_CENSUS', 'Group Renewal Census', 'État de renouvellement du groupe', $F, 'S2', null,
                ['Census of the group below for the renewal of the group cover.', 'État du groupe ci-dessous pour le renouvellement de la couverture groupe.'],
                [self::F_GROUP, self::F_GROUP_POLICY, ['renewal.due_on', 'Renewal date', 'Date de renouvellement', 'K'], ['census.active_members', 'Active members', 'Membres actifs', 'D'],
                    ['census.active_dependants', 'Active dependants', 'Ayants droit actifs', 'D'], ['renewal.changes', 'Changes', 'Modifications', 'D'], ['census.categories', 'Benefit category', 'Catégorie de garanties', 'D'],
                    ['renewal.basis', 'Renewal basis', 'Base de renouvellement', 'D']],
                [self::decl('employer')]),

            'DOC-107' => self::def('CORPORATE_BENEFIT_SCHEDULE', 'Corporate Benefit Schedule', 'Tableau des garanties entreprise', $F, 'S2', null,
                ['Schedule of the benefits of the corporate cover below by benefit category.', 'Tableau des garanties de la couverture entreprise ci-dessous par catégorie.'],
                [['group.sponsor', 'Group / company', 'Groupe / entreprise', 'P'], self::F_GROUP_POLICY, self::F_INSURER, ['group.member_categories', 'Benefit categories', 'Catégories de garanties', 'D'],
                    ['product.eligibility_rules', 'Eligibility', 'Éligibilité', 'D'], ['benefit.limits', 'Limits', 'Plafonds', 'D'], ['benefit.copay', 'Deductibles / copays', 'Franchises / tickets modérateurs', 'D'],
                    ['benefit.waiting_periods', 'Waiting periods', 'Délais de carence', 'D']],
                [self::subjectToTerms('This schedule summarizes the benefits of the corporate cover.', 'Ce tableau résume les garanties de la couverture entreprise.')]),

            'DOC-108' => self::def('CORPORATE_POLICY_SUMMARY', 'Corporate Policy Summary', 'Résumé de police entreprise', $F, 'S2', null,
                ['Summary of the corporate policy below.', 'Résumé de la police entreprise ci-dessous.'],
                [['group.sponsor', 'Company', 'Entreprise', 'P'], ['policy.number', 'Policy number', 'N° de police', 'K'], self::F_INSURER, ['policy.product', 'Product', 'Produit', 'K'], ['policy.period', 'Period', 'Période', 'K'],
                    ['group.key_benefits', 'Key benefits', 'Garanties principales', 'D'], ['premium.gross', 'Premium', 'Prime', 'F'], ['group.corporate_contacts', 'Contacts', 'Contacts', 'P'],
                    ['group.obligations', 'Obligations', 'Obligations', 'D']],
                [self::subjectToTerms('This summary does not replace the policy.', 'Ce résumé ne remplace pas la police.')]),

            'DOC-109' => self::def('CORPORATE_INSURANCE_CERTIFICATE', 'Corporate Insurance Certificate', 'Certificat d’assurance entreprise', $F, 'S3', null,
                ['Certifies that the company named below holds the insurance stated, for the period stated.', 'Certifie que l’entreprise désignée ci-dessous est titulaire de l’assurance indiquée, pour la période indiquée.'],
                [['group.sponsor', 'Company', 'Entreprise', 'P'], ['policy.number', 'Policy number', 'N° de police', 'K'], self::F_INSURER, ['policy.product', 'Product', 'Produit', 'K'], ['policy.insurance_class', 'Class of insurance', 'Branche', 'K'],
                    ['policy.period', 'Period', 'Période', 'K'], ['risk.summary', 'Covered operations / assets summary', 'Activités / biens couverts', 'D']],
                [self::subjectToTerms('This certificate evidences the existence of the policy at the date of issue; the online verification status is authoritative.', 'Ce certificat atteste l’existence de la police à la date d’émission ; le statut de vérification en ligne fait foi.')]),

            'DOC-110' => self::def('GROUP_COVERAGE_TERMINATION_NOTICE', 'Group Coverage Termination Notice', 'Avis de cessation de couverture groupe', $F, 'S3', null,
                ['Notice of the end of the group cover for the group or members below.', 'Avis de fin de la couverture groupe pour le groupe ou les membres ci-dessous.'],
                [self::F_GROUP, self::F_GROUP_POLICY, self::F_INSURER, ['termination.members', 'Member(s) concerned', 'Membre(s) concerné(s)', 'M'], ['termination.effective_at', 'Termination date', 'Date de cessation', 'D'],
                    ['termination.reason', 'Reason', 'Motif', 'D'], ['termination.affected_cover', 'Affected cover', 'Garanties concernées', 'D'], ['termination.final_premium', 'Final premium / status', 'Prime finale / statut', 'F']],
                [self::s(self::H_NOTICE, 'Cover ends on the termination date stated for the group or members concerned.', 'La couverture prend fin à la date de cessation indiquée pour le groupe ou les membres concernés.')]),
        ];
    }
}
