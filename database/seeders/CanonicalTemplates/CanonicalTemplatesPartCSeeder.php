<?php

declare(strict_types=1);

namespace Database\Seeders\CanonicalTemplates;

/**
 * Canonical templates, part C: DOC-111..DOC-165 (OpesInsure_220_Documents_and_First_5_Master_Shells_v1 categories G
 * Property/Liability/Engineering/Cyber, H Marine/Travel/Agriculture/Specialty, I Servicing/Endorsements/Renewal and
 * J Claims up to DOC-165). Contract: docs/spec/canonical/TEMPLATE_CONTENT_CONTRACT.md (lifecycle, owner approval,
 * idempotency and languages come from CanonicalTemplateSeeder).
 *
 * Content: every field of OpesInsure_220_Document_Field_Data_Specification_v1 for the document as `fields`
 * (engine key, EN/FR label, zone C parties/risk or D transaction content), grouped as in the spec prose; neutral
 * statements (declaration for customer forms, certification for certificates, request status for requests) as
 * sections and a contact notice. No insurer wording, rates, limits, exclusions, legal references or clauses are
 * invented: values come from the platform at issuance, and a key without a value prints "Not recorded".
 */
final class CanonicalTemplatesPartCSeeder extends CanonicalTemplateSeeder
{
    /** Keys printed by the fixed zones of the shell: listed for completeness, zone C. */
    private const SHELL_KEYS = ['policy.number', 'party.name', 'policy.insurer', 'policy.product', 'policy.effective_from', 'policy.effective_until', 'claim.number'];

    protected function documents(): array
    {
        $out = [];
        foreach (self::definitions() as $specId => $def) {
            $out[$specId] = self::toContract($def);
        }

        return $out;
    }

    /**
     * One definition (field groups + statements) in the contract shape: fields [[key, en, fr, zone]], sections, notices.
     *
     * @param  array<string, mixed>  $def
     * @return array<string, mixed>
     */
    public static function toContract(array $def): array
    {
        $fields = [];
        $seen = [];
        foreach ($def['groups'] as [, , $zone, $rows]) {
            foreach ($rows as $key => [$lEn, $lFr]) {
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $fields[] = [$key, $lEn, $lFr, in_array($key, self::SHELL_KEYS, true) || $zone === 'C' ? 'C' : 'D'];
            }
        }
        $sections = [];
        $notices = [];
        foreach ($def['statements'] ?? [] as [$kind]) {
            if ($kind === 'contact') {
                $notices[] = ['en' => self::STATEMENTS['contact']['body_en'], 'fr' => self::STATEMENTS['contact']['body_fr']];

                continue;
            }
            $sections[] = ['heading_en' => self::STATEMENT_HEADINGS[$kind][0], 'heading_fr' => self::STATEMENT_HEADINGS[$kind][1]] + self::STATEMENTS[$kind];
        }

        return ['title_en' => $def['en'], 'title_fr' => $def['fr'], 'fields' => $fields, 'sections' => $sections, 'notices' => $notices];
    }

    private const STATEMENT_HEADINGS = [
        'declaration' => ['Declaration', 'Déclaration'],
        'certificate' => ['Certification', 'Certification'],
        'request' => ['Request status', 'Statut de la demande'],
        'contact' => ['Contact', 'Contact'],
    ];

    /** Neutral statements only (no clause, obligation or legal reference is created here). */
    private const STATEMENTS = [
        'declaration' => [
            'body_en' => 'The declarant confirms that the information given in this document is accurate and complete to the best of their knowledge.',
            'body_fr' => 'Le déclarant certifie que les informations fournies dans ce document sont exactes et complètes à sa connaissance.'],
        'certificate' => [
            'body_en' => 'This certificate confirms the cover stated above for the period shown, subject to the terms and conditions of policy {policy_number}.',
            'body_fr' => 'Le présent certificat atteste la couverture indiquée ci-dessus pour la période mentionnée, sous réserve des conditions de la police {policy_number}.'],
        'request' => [
            'body_en' => 'This document records a request; it does not change the policy until the insurer confirms it in a separate document.',
            'body_fr' => 'Ce document enregistre une demande ; il ne modifie pas la police tant que l’assureur ne l’a pas confirmée par un document distinct.'],
        'contact' => [
            'body_en' => 'For any question about this document, contact the issuer quoting the document number shown above.',
            'body_fr' => 'Pour toute question sur ce document, contactez l’émetteur en rappelant le numéro de document indiqué ci-dessus.'],
    ];

    // ---------------------------------------------------------------- shared field blocks
    private static function policyBlock(): array
    {
        return ['Policy', 'Police', 'B', [
            'policy.number' => ['Policy number', 'N° de police'], 'party.name' => ['Policyholder / insured', 'Souscripteur / assuré'],
            'policy.insurer' => ['Insurer', 'Assureur'], 'policy.product' => ['Product', 'Produit'],
            'policy.effective_from' => ['Effective from', 'Date d’effet'], 'policy.effective_until' => ['Expiry', 'Date d’échéance'],
        ]];
    }

    private static function signBlock(string $who = 'Declarant', string $whoFr = 'Déclarant'): array
    {
        return ['Signature', 'Signature', 'E', [
            'declaration.signatory_name' => [$who.' name', 'Nom du '.mb_strtolower($whoFr)],
            'declaration.signatory_capacity' => ['Capacity', 'Qualité'],
            'declaration.signed_at' => ['Date signed', 'Date de signature'],
            'declaration.signature_reference' => ['Signature reference', 'Référence de signature'],
        ]];
    }

    private static function coverBlock(array $extra = []): array
    {
        return ['Cover', 'Garanties', 'D', [
            'coverage.lines' => ['Coverages', 'Garanties'], 'coverage.limits' => ['Limits / sums insured', 'Limites / capitaux assurés'],
            'coverage.deductibles' => ['Deductibles', 'Franchises'],
        ] + $extra];
    }

    private static function premiumBlock(): array
    {
        return ['Premium', 'Prime', 'D', [
            'premium.net' => ['Net premium', 'Prime nette'], 'premium.taxes' => ['Taxes / levies / fees', 'Taxes / prélèvements / frais'],
            'premium.gross' => ['Gross premium', 'Prime TTC'], 'premium.currency' => ['Premium currency', 'Devise de la prime'],
        ]];
    }

    private static function surveyorBlock(string $en = 'Surveyor', string $fr = 'Expert'): array
    {
        return ['Authorship', 'Auteur', 'E', [
            'survey.surveyor_name' => [$en, $fr], 'survey.surveyor_organization' => ['Organization', 'Organisme'],
            'survey.surveyor_licence' => ['Licence / accreditation reference', 'Référence d’agrément'],
            'survey.report_signed_at' => ['Report date', 'Date du rapport'],
        ]];
    }

    private static function claimBlock(): array
    {
        return ['Claim', 'Sinistre', 'B', [
            'claim.number' => ['Claim number', 'N° de sinistre'], 'policy.number' => ['Policy number', 'N° de police'],
            'party.name' => ['Policyholder / insured', 'Souscripteur / assuré'], 'policy.insurer' => ['Insurer', 'Assureur'],
        ]];
    }

    /** @return array<string, array<string, mixed>> spec id => definition */
    public static function definitions(): array
    {
        $P = self::policyBlock();
        $sign = self::signBlock();
        $cover = self::coverBlock();
        $prem = self::premiumBlock();
        $surv = self::surveyorBlock();
        $claim = self::claimBlock();
        $period = ['Period', 'Période', 'D', ['cover.period_start' => ['Period start', 'Début de période'], 'cover.period_end' => ['Period end', 'Fin de période']]];
        $territory = ['cover.territory' => ['Territory', 'Territorialité']];
        $cert = [['certificate'], ['contact']];
        $decl = [['declaration', null, 'D']];

        return [
            // ================= G. Property, Liability, Engineering & Cyber
            'DOC-111' => ['code' => 'PROPERTY_RISK_QUESTIONNAIRE', 'en' => 'Property Risk Questionnaire', 'fr' => 'Questionnaire de risque immobilier', 'tier' => 'S2', 'cat' => 'G', 'groups' => [
                ['Property / business identity', 'Identité du bien / de l’entreprise', 'C', ['party.name' => ['Proposer', 'Proposant'], 'property.business_name' => ['Business name', 'Raison sociale'], 'property.business_activity' => ['Business activity', 'Activité'], 'property.contact' => ['Contact', 'Contact']]],
                ['Location', 'Situation', 'C', ['property.address' => ['Risk address', 'Adresse du risque'], 'property.city' => ['City', 'Ville'], 'property.gps' => ['GPS coordinates', 'Coordonnées GPS']]],
                ['Occupancy and construction', 'Occupation et construction', 'D', ['property.occupancy' => ['Occupancy', 'Occupation'], 'property.construction_walls' => ['Walls', 'Murs'], 'property.construction_roof' => ['Roof', 'Toiture'], 'property.floors' => ['Number of floors', 'Nombre de niveaux'], 'property.year_built' => ['Year built', 'Année de construction']]],
                ['Values', 'Valeurs', 'D', ['property.building_value' => ['Building value', 'Valeur du bâtiment'], 'property.contents_value' => ['Contents value', 'Valeur du contenu'], 'property.stock_value' => ['Stock value', 'Valeur des stocks'], 'property.value_basis' => ['Value basis', 'Base d’évaluation']]],
                ['Fire and security controls', 'Protection incendie et sûreté', 'D', ['property.fire_protection' => ['Fire protection', 'Protection incendie'], 'property.security_measures' => ['Security measures', 'Mesures de sûreté'], 'property.alarm' => ['Alarm', 'Alarme']]],
                ['History', 'Antécédents', 'D', ['property.loss_history' => ['Loss history', 'Historique des sinistres'], 'property.previous_insurer' => ['Previous insurer', 'Assureur précédent']]],
                $sign], 'statements' => $decl],
            'DOC-112' => ['code' => 'PROPERTY_SCHEDULE', 'en' => 'Property Schedule', 'fr' => 'État des biens immobiliers', 'tier' => 'S2', 'cat' => 'G', 'shell' => 'TPL-SHELL-POLICY-SCHEDULE-001', 'groups' => [$P,
                ['Property locations', 'Situations des biens', 'C', ['property.locations' => ['Locations', 'Situations'], 'property.occupancy' => ['Occupancy', 'Occupation'], 'property.construction' => ['Construction', 'Construction']]],
                ['Values', 'Valeurs', 'D', ['property.building_value' => ['Building value', 'Valeur du bâtiment'], 'property.contents_value' => ['Contents value', 'Valeur du contenu'], 'property.total_sum_insured' => ['Total sum insured', 'Capital total assuré']]],
                $cover, $prem], 'statements' => [['contact']]],
            'DOC-113' => ['code' => 'CONTENTS_SCHEDULE', 'en' => 'Contents Schedule', 'fr' => 'État du contenu assuré', 'tier' => 'S2', 'cat' => 'G', 'shell' => 'TPL-SHELL-POLICY-SCHEDULE-001', 'groups' => [$P,
                ['Location', 'Situation', 'C', ['property.address' => ['Location', 'Situation']]],
                ['Contents', 'Contenu', 'D', ['contents.categories' => ['Contents categories', 'Catégories de contenu'], 'contents.items' => ['Items', 'Objets'], 'contents.item_values' => ['Item values', 'Valeurs des objets'], 'contents.sum_insured' => ['Sums insured', 'Capitaux assurés'], 'contents.special_limits' => ['Special limits', 'Limites particulières']]]],
                'statements' => [['contact']]],
            'DOC-114' => ['code' => 'STOCK_SCHEDULE', 'en' => 'Stock Schedule', 'fr' => 'État des stocks', 'tier' => 'S2', 'cat' => 'G', 'shell' => 'TPL-SHELL-POLICY-SCHEDULE-001', 'groups' => [$P,
                ['Location', 'Situation', 'C', ['property.address' => ['Location', 'Situation']]],
                ['Stock', 'Stocks', 'D', ['stock.type' => ['Stock type', 'Nature des stocks'], 'stock.average_value' => ['Average value', 'Valeur moyenne'], 'stock.maximum_value' => ['Maximum value', 'Valeur maximale'], 'stock.seasonality' => ['Seasonality', 'Saisonnalité']]],
                $cover], 'statements' => [['contact']]],
            'DOC-115' => ['code' => 'PROPERTY_VALUATION', 'en' => 'Property Valuation Report', 'fr' => 'Rapport d’évaluation immobilière', 'tier' => 'S3', 'cat' => 'G', 'groups' => [$P,
                ['Property', 'Bien', 'C', ['property.address' => ['Property address', 'Adresse du bien'], 'property.description' => ['Description', 'Description']]],
                ['Valuation', 'Évaluation', 'D', ['valuation.date' => ['Valuation date', 'Date d’évaluation'], 'valuation.basis' => ['Valuation basis', 'Base d’évaluation'], 'valuation.building_value' => ['Building value', 'Valeur du bâtiment'], 'valuation.contents_value' => ['Contents value', 'Valeur du contenu'], 'valuation.methodology' => ['Methodology', 'Méthodologie'], 'valuation.assumptions' => ['Assumptions', 'Hypothèses']]],
                self::surveyorBlock('Valuer', 'Évaluateur')]],
            'DOC-116' => ['code' => 'PROPERTY_RISK_SURVEY', 'en' => 'Property Risk Survey', 'fr' => 'Rapport de visite de risque', 'tier' => 'S3', 'cat' => 'G', 'groups' => [$P,
                ['Location', 'Situation', 'C', ['property.address' => ['Location', 'Situation'], 'survey.date' => ['Survey date', 'Date de visite']]],
                ['Findings', 'Constatations', 'D', ['survey.hazards' => ['Hazards', 'Risques identifiés'], 'property.construction' => ['Construction', 'Construction'], 'property.occupancy' => ['Occupancy', 'Occupation'], 'survey.protections' => ['Protections', 'Moyens de protection'], 'survey.deficiencies' => ['Deficiencies', 'Insuffisances'], 'survey.recommendations' => ['Recommendations', 'Recommandations'], 'survey.photo_references' => ['Photo references', 'Références des photos']]],
                $surv]],
            'DOC-117' => ['code' => 'FIRE_SAFETY_SURVEY', 'en' => 'Fire Safety Survey', 'fr' => 'Rapport de sécurité incendie', 'tier' => 'S3', 'cat' => 'G', 'groups' => [$P,
                ['Location', 'Situation', 'C', ['property.address' => ['Location', 'Situation'], 'survey.date' => ['Survey date', 'Date de visite']]],
                ['Fire protection', 'Protection incendie', 'D', ['fire.protection_systems' => ['Fire protection systems', 'Systèmes de protection incendie'], 'fire.alarms' => ['Alarms', 'Alarmes'], 'fire.extinguishers' => ['Extinguishers', 'Extincteurs'], 'fire.hydrants' => ['Hydrants', 'Poteaux / bouches d’incendie'], 'fire.exits' => ['Exits', 'Issues de secours'], 'fire.housekeeping' => ['Housekeeping', 'Tenue des locaux']]],
                ['Assessment', 'Appréciation', 'D', ['survey.hazards' => ['Hazards', 'Risques identifiés'], 'survey.recommendations' => ['Recommendations', 'Recommandations']]],
                $surv]],
            'DOC-118' => ['code' => 'RISK_IMPROVEMENT_NOTICE', 'en' => 'Risk Improvement Notice', 'fr' => 'Recommandations d’amélioration du risque', 'tier' => 'S2', 'cat' => 'G', 'groups' => [$P,
                ['Risk', 'Risque', 'C', ['risk.summary' => ['Insured risk', 'Risque assuré']]],
                ['Improvement requirement', 'Amélioration demandée', 'D', ['improvement.requirement' => ['Requirement', 'Mesure demandée'], 'improvement.severity' => ['Severity', 'Gravité'], 'improvement.due_date' => ['Due date', 'Date limite'], 'improvement.evidence_required' => ['Evidence required', 'Justificatifs demandés'], 'improvement.consequences' => ['Consequences if not completed', 'Conséquences en cas de non-réalisation']]]],
                'statements' => [['contact']]],
            'DOC-119' => ['code' => 'BUILDING_INSURANCE_CERTIFICATE', 'en' => 'Building Insurance Certificate', 'fr' => 'Certificat d’assurance bâtiment', 'tier' => 'S3', 'cat' => 'G', 'shell' => 'TPL-SHELL-POLICY-CERTIFICATE-001', 'groups' => [$P,
                ['Building', 'Bâtiment', 'C', ['property.owner' => ['Owner', 'Propriétaire'], 'property.address' => ['Address', 'Adresse']]],
                ['Cover', 'Garanties', 'D', ['coverage.lines' => ['Coverages', 'Garanties'], 'property.building_value' => ['Value / limit', 'Valeur / limite']]], $period],
                'statements' => $cert],
            'DOC-120' => ['code' => 'BUSINESS_MULTIRISK_SCHEDULE', 'en' => 'Business Multirisk Schedule', 'fr' => 'État multirisque entreprise', 'tier' => 'S2', 'cat' => 'G', 'shell' => 'TPL-SHELL-POLICY-SCHEDULE-001', 'groups' => [$P,
                ['Business', 'Entreprise', 'C', ['property.business_name' => ['Business', 'Entreprise'], 'property.business_activity' => ['Activity', 'Activité'], 'property.locations' => ['Locations', 'Situations']]],
                ['Cover sections', 'Sections de garantie', 'D', ['multirisk.property_cover' => ['Property', 'Dommages aux biens'], 'multirisk.business_interruption_cover' => ['Business interruption', 'Pertes d’exploitation'], 'multirisk.liability_cover' => ['Liability', 'Responsabilité civile'], 'multirisk.other_covers' => ['Other sections', 'Autres sections']]],
                $cover, $prem], 'statements' => [['contact']]],
            'DOC-121' => ['code' => 'BUSINESS_INTERRUPTION_SCHEDULE', 'en' => 'Business Interruption Schedule', 'fr' => 'Tableau pertes d’exploitation', 'tier' => 'S2', 'cat' => 'G', 'shell' => 'TPL-SHELL-POLICY-SCHEDULE-001', 'groups' => [$P,
                ['Business', 'Entreprise', 'C', ['property.business_name' => ['Business', 'Entreprise'], 'property.business_activity' => ['Activity', 'Activité']]],
                ['Basis of cover', 'Base de garantie', 'D', ['bi.basis' => ['Gross profit / revenue basis', 'Base marge brute / chiffre d’affaires'], 'bi.declared_amount' => ['Declared amount', 'Montant déclaré'], 'bi.indemnity_period' => ['Indemnity period', 'Période d’indemnisation'], 'bi.waiting_period' => ['Waiting period', 'Délai de carence'], 'bi.limit' => ['Limit', 'Limite'], 'bi.dependencies' => ['Dependencies', 'Dépendances']]]],
                'statements' => [['contact']]],
            'DOC-122' => ['code' => 'MACHINERY_SCHEDULE', 'en' => 'Machinery Schedule', 'fr' => 'État des machines', 'tier' => 'S2', 'cat' => 'G', 'shell' => 'TPL-SHELL-POLICY-SCHEDULE-001', 'groups' => [$P,
                ['Machinery / equipment', 'Machines / équipements', 'C', ['machine.identifier' => ['Machine / equipment ID', 'Identifiant machine / équipement'], 'machine.description' => ['Description', 'Description'], 'machine.serial_number' => ['Serial number', 'N° de série'], 'machine.location' => ['Location', 'Situation'], 'machine.year' => ['Year', 'Année'], 'machine.value' => ['Value', 'Valeur']]],
                ['Cover', 'Garanties', 'D', ['coverage.lines' => ['Coverages', 'Garanties'], 'coverage.limits' => ['Limit', 'Limite']]]],
                'statements' => [['contact']]],
            'DOC-123' => ['code' => 'ENGINEERING_SURVEY_REPORT', 'en' => 'Engineering Survey Report', 'fr' => 'Rapport d’expertise technique', 'tier' => 'S3', 'cat' => 'G', 'groups' => [$P,
                ['Project / equipment', 'Projet / équipement', 'C', ['engineering.project' => ['Project / equipment', 'Projet / équipement'], 'engineering.location' => ['Location', 'Situation'], 'survey.date' => ['Survey date', 'Date de visite']]],
                ['Survey', 'Expertise', 'D', ['survey.scope' => ['Scope', 'Étendue de la mission'], 'survey.technical_findings' => ['Technical findings', 'Constatations techniques'], 'survey.hazards' => ['Hazards', 'Risques identifiés'], 'engineering.values' => ['Values', 'Valeurs'], 'survey.recommendations' => ['Recommendations', 'Recommandations']]],
                self::surveyorBlock('Engineer / surveyor', 'Ingénieur / expert')]],
            'DOC-124' => ['code' => 'CONSTRUCTION_LIABILITY_CERTIFICATE', 'en' => 'Construction Liability Certificate', 'fr' => 'Certificat RC chantier', 'tier' => 'S3', 'cat' => 'G', 'shell' => 'TPL-SHELL-POLICY-CERTIFICATE-001', 'groups' => [$P,
                ['Project', 'Chantier', 'C', ['engineering.project' => ['Project', 'Chantier'], 'liability.contractor' => ['Contractor', 'Entrepreneur'], 'engineering.work_scope' => ['Work scope', 'Nature des travaux'], 'engineering.location' => ['Location', 'Situation']]],
                ['Liability cover', 'Garantie responsabilité', 'D', ['liability.limits' => ['Liability limits', 'Limites de responsabilité'], 'liability.principals' => ['Principals / additional insureds where applicable', 'Maîtres d’ouvrage / assurés additionnels le cas échéant']]], $period],
                'statements' => $cert],
            'DOC-125' => ['code' => 'PROFESSIONAL_LIABILITY_CERTIFICATE', 'en' => 'Professional Liability Certificate', 'fr' => 'Attestation RC professionnelle', 'tier' => 'S3', 'cat' => 'G', 'shell' => 'TPL-SHELL-POLICY-CERTIFICATE-001', 'groups' => [$P,
                ['Professional / entity', 'Professionnel / entité', 'C', ['liability.professional_activity' => ['Professional activity', 'Activité professionnelle']]],
                ['Liability cover', 'Garantie responsabilité', 'D', ['liability.limits' => ['Limits', 'Limites'], 'coverage.deductibles' => ['Deductible', 'Franchise'], 'liability.retroactive_date' => ['Retroactive date where applicable', 'Date de rétroactivité le cas échéant']] + $territory], $period],
                'statements' => $cert],
            'DOC-126' => ['code' => 'PUBLIC_LIABILITY_CERTIFICATE', 'en' => 'Public Liability Certificate', 'fr' => 'Attestation responsabilité civile exploitation', 'tier' => 'S3', 'cat' => 'G', 'shell' => 'TPL-SHELL-POLICY-CERTIFICATE-001', 'groups' => [$P,
                ['Operations', 'Activités', 'C', ['liability.operations' => ['Insured operations', 'Activités assurées']]],
                ['Liability cover', 'Garantie responsabilité', 'D', ['liability.limits' => ['Liability limit', 'Limite de responsabilité'], 'coverage.deductibles' => ['Deductible', 'Franchise']] + $territory], $period],
                'statements' => $cert],
            'DOC-127' => ['code' => 'EMPLOYER_LIABILITY_CERTIFICATE', 'en' => 'Employer Liability Certificate', 'fr' => 'Attestation responsabilité employeur', 'tier' => 'S3', 'cat' => 'G', 'shell' => 'TPL-SHELL-POLICY-CERTIFICATE-001', 'groups' => [$P,
                ['Employer', 'Employeur', 'C', ['liability.employee_scope' => ['Employee scope', 'Personnel couvert'], 'liability.occupation_activity' => ['Occupation / activity', 'Profession / activité']]],
                ['Liability cover', 'Garantie responsabilité', 'D', ['liability.limits' => ['Limits', 'Limites']] + $territory], $period],
                'statements' => $cert],
            'DOC-128' => ['code' => 'CYBER_RISK_QUESTIONNAIRE', 'en' => 'Cyber Risk Questionnaire', 'fr' => 'Questionnaire cyber-risque', 'tier' => 'S2', 'cat' => 'G', 'groups' => [
                ['Organization', 'Organisation', 'C', ['party.name' => ['Organization', 'Organisation'], 'cyber.activity' => ['Activity', 'Activité'], 'cyber.revenue' => ['Annual revenue', 'Chiffre d’affaires annuel']]],
                ['Systems and data', 'Systèmes et données', 'D', ['cyber.systems' => ['Systems', 'Systèmes'], 'cyber.user_count' => ['Number of users', 'Nombre d’utilisateurs'], 'cyber.data_types' => ['Data held', 'Données détenues'], 'cyber.record_count' => ['Number of records', 'Nombre d’enregistrements']]],
                ['Controls', 'Mesures de sécurité', 'D', ['cyber.controls' => ['Security controls', 'Mesures de sécurité'], 'cyber.backups' => ['Backups', 'Sauvegardes'], 'cyber.mfa' => ['Multi-factor authentication', 'Authentification multifacteur'], 'cyber.vendors' => ['Critical vendors', 'Prestataires critiques']]],
                ['History', 'Antécédents', 'D', ['cyber.incident_history' => ['Incident history', 'Historique des incidents']]],
                $sign], 'statements' => $decl],
            'DOC-129' => ['code' => 'CYBERSECURITY_CONTROLS_DECLARATION', 'en' => 'Cybersecurity Controls Declaration', 'fr' => 'Déclaration des mesures cybersécurité', 'tier' => 'S2', 'cat' => 'G', 'groups' => [
                ['Organization', 'Organisation', 'C', ['party.name' => ['Organization', 'Organisation'], 'policy.number' => ['Policy / quote reference', 'Référence police / devis']]],
                ['Control attestations', 'Attestation des mesures', 'D', ['cyber.control_attestations' => ['Security control attestations', 'Mesures de sécurité attestées'], 'cyber.control_owner' => ['Control owner', 'Responsable des mesures'], 'cyber.evidence_references' => ['Evidence references', 'Références des justificatifs'], 'cyber.attestation_date' => ['Attestation date', 'Date de l’attestation']]],
                $sign], 'statements' => $decl],
            'DOC-130' => ['code' => 'CYBER_INSURANCE_CERTIFICATE', 'en' => 'Cyber Insurance Certificate', 'fr' => 'Certificat d’assurance cyber', 'tier' => 'S3', 'cat' => 'G', 'shell' => 'TPL-SHELL-POLICY-CERTIFICATE-001', 'groups' => [$P,
                ['Cyber cover', 'Garantie cyber', 'D', ['cyber.cover_categories' => ['Cover categories', 'Catégories de garantie'], 'coverage.limits' => ['Limits', 'Limites'], 'cyber.retention' => ['Retention', 'Rétention']] + $territory], $period],
                'statements' => $cert],

            // ================= H. Marine, Travel, Agriculture & Specialty
            'DOC-131' => ['code' => 'CARGO_INSURANCE_DECLARATION', 'en' => 'Cargo Insurance Declaration', 'fr' => 'Déclaration d’assurance marchandises', 'tier' => 'S2', 'cat' => 'H', 'groups' => [
                ['Open policy', 'Police d’abonnement', 'B', ['marine.open_policy_reference' => ['Open-policy reference', 'Référence de la police d’abonnement'], 'party.name' => ['Insured', 'Assuré']]],
                ['Shipment', 'Expédition', 'D', ['marine.goods' => ['Goods', 'Marchandises'], 'marine.value' => ['Value', 'Valeur'], 'marine.origin' => ['Origin', 'Origine'], 'marine.destination' => ['Destination', 'Destination'], 'marine.conveyance' => ['Conveyance', 'Moyen de transport'], 'marine.shipment_date' => ['Shipment date', 'Date d’expédition'], 'coverage.lines' => ['Coverage', 'Garanties']]],
                $sign], 'statements' => $decl],
            'DOC-132' => ['code' => 'MARINE_CARGO_POLICY', 'en' => 'Marine Cargo Policy', 'fr' => 'Police facultés', 'tier' => 'S3', 'cat' => 'H', 'shell' => 'TPL-SHELL-POLICY-SCHEDULE-001', 'groups' => [$P,
                ['Interest', 'Intérêt assuré', 'C', ['marine.goods' => ['Goods / interest', 'Marchandises / intérêt']]],
                ['Voyages and valuation', 'Voyages et évaluation', 'D', ['marine.voyages' => ['Voyages / territory', 'Voyages / territorialité'], 'marine.valuation_basis' => ['Valuation basis', 'Base d’évaluation'], 'marine.clauses' => ['Clauses', 'Clauses'], 'coverage.limits' => ['Limits', 'Limites'], 'marine.declaration_terms' => ['Declarations', 'Déclarations']]],
                $prem], 'statements' => [['contact']]],
            'DOC-133' => ['code' => 'MARINE_CARGO_CERTIFICATE', 'en' => 'Marine Cargo Certificate', 'fr' => 'Certificat d’assurance marchandises', 'tier' => 'S3', 'cat' => 'H', 'shell' => 'TPL-SHELL-POLICY-CERTIFICATE-001', 'groups' => [$P,
                ['Shipment', 'Expédition', 'C', ['marine.certificate_number' => ['Certificate number', 'N° de certificat'], 'marine.shipment_reference' => ['Shipment', 'Expédition'], 'marine.goods' => ['Goods', 'Marchandises'], 'marine.value' => ['Value', 'Valeur']]],
                ['Voyage and cover', 'Voyage et garanties', 'D', ['marine.voyage' => ['Voyage', 'Voyage'], 'marine.conveyance' => ['Conveyance', 'Moyen de transport'], 'coverage.lines' => ['Cover', 'Garanties'], 'marine.loss_payee' => ['Beneficiary / loss payee', 'Bénéficiaire']]]],
                'statements' => $cert],
            'DOC-134' => ['code' => 'SHIPMENT_INSURANCE_CERTIFICATE', 'en' => 'Shipment Insurance Certificate', 'fr' => 'Certificat d’assurance expédition', 'tier' => 'S3', 'cat' => 'H', 'shell' => 'TPL-SHELL-POLICY-CERTIFICATE-001', 'groups' => [$P,
                ['Shipment', 'Expédition', 'C', ['marine.shipment_reference' => ['Shipment ID', 'Identifiant de l’expédition'], 'marine.sender' => ['Sender / insured', 'Expéditeur / assuré'], 'marine.goods' => ['Goods', 'Marchandises'], 'marine.value' => ['Value', 'Valeur']]],
                ['Route and cover', 'Trajet et garanties', 'D', ['marine.origin' => ['Origin', 'Origine'], 'marine.destination' => ['Destination', 'Destination'], 'marine.shipment_date' => ['Shipment date', 'Date d’expédition'], 'marine.arrival_date' => ['Expected arrival', 'Arrivée prévue'], 'marine.carrier' => ['Carrier', 'Transporteur'], 'coverage.lines' => ['Coverage', 'Garanties']]]],
                'statements' => $cert],
            'DOC-135' => ['code' => 'SHIPMENT_DECLARATION', 'en' => 'Shipment Declaration', 'fr' => 'Déclaration d’expédition', 'tier' => 'S2', 'cat' => 'H', 'groups' => [
                ['Open cover', 'Police d’abonnement', 'B', ['marine.open_policy_reference' => ['Open cover', 'Police d’abonnement'], 'party.name' => ['Insured', 'Assuré']]],
                ['Shipment', 'Expédition', 'D', ['marine.shipment_reference' => ['Shipment details', 'Détails de l’expédition'], 'marine.goods' => ['Goods', 'Marchandises'], 'marine.value' => ['Value', 'Valeur'], 'marine.route' => ['Route', 'Trajet'], 'marine.carrier' => ['Carrier', 'Transporteur'], 'marine.shipment_date' => ['Date', 'Date']]],
                $sign], 'statements' => $decl],
            'DOC-136' => ['code' => 'GOODS_SCHEDULE', 'en' => 'Goods Schedule', 'fr' => 'État des marchandises', 'tier' => 'S2', 'cat' => 'H', 'groups' => [$P,
                ['Goods', 'Marchandises', 'D', ['goods.items' => ['Goods / items', 'Marchandises / articles'], 'goods.quantity' => ['Quantity', 'Quantité'], 'goods.invoice_reference' => ['Invoice / reference', 'Facture / référence'], 'goods.value' => ['Value', 'Valeur'], 'goods.packaging' => ['Packaging', 'Emballage'], 'goods.location_shipment' => ['Location / shipment', 'Situation / expédition']]]],
                'statements' => [['contact']]],
            'DOC-137' => ['code' => 'MARINE_SURVEY_REPORT', 'en' => 'Marine Survey Report', 'fr' => 'Rapport d’expertise maritime', 'tier' => 'S3', 'cat' => 'H', 'groups' => [$P,
                ['Subject', 'Objet', 'C', ['marine.survey_subject' => ['Shipment / vessel / cargo', 'Expédition / navire / cargaison'], 'survey.date' => ['Inspection date', 'Date d’inspection'], 'survey.location' => ['Inspection place', 'Lieu d’inspection']]],
                ['Findings', 'Constatations', 'D', ['survey.inspection_details' => ['Inspection details', 'Détails de l’inspection'], 'survey.condition' => ['Condition', 'État constaté'], 'survey.loss_damage' => ['Loss / damage', 'Pertes / dommages'], 'survey.cause_observations' => ['Cause observations', 'Observations sur la cause'], 'survey.valuation' => ['Valuation', 'Évaluation'], 'survey.evidence_references' => ['Evidence', 'Pièces justificatives']]],
                $surv]],
            'DOC-138' => ['code' => 'TRAVEL_INSURANCE_CERTIFICATE', 'en' => 'Travel Insurance Certificate', 'fr' => 'Certificat d’assurance voyage', 'tier' => 'S3', 'cat' => 'H', 'shell' => 'TPL-SHELL-POLICY-CERTIFICATE-001', 'groups' => [$P,
                ['Traveler', 'Voyageur', 'C', ['travel.traveler_name' => ['Traveler', 'Voyageur'], 'travel.destination' => ['Destination', 'Destination']]],
                ['Trip and cover', 'Voyage et garanties', 'D', ['travel.departure_date' => ['Departure date', 'Date de départ'], 'travel.return_date' => ['Return date', 'Date de retour'], 'coverage.limits' => ['Key limits', 'Principales limites'], 'travel.assistance_contacts' => ['Assistance contacts', 'Contacts d’assistance']]]],
                'statements' => $cert],
            'DOC-139' => ['code' => 'VISA_TRAVEL_COVERAGE_CERTIFICATE', 'en' => 'Visa/Travel Coverage Certificate', 'fr' => 'Certificat de couverture voyage/visa', 'tier' => 'S3', 'cat' => 'H', 'shell' => 'TPL-SHELL-POLICY-CERTIFICATE-001', 'groups' => [$P,
                ['Traveler', 'Voyageur', 'C', ['travel.traveler_name' => ['Traveler', 'Voyageur'], 'travel.passport_reference' => ['Passport / reference', 'Passeport / référence']]],
                ['Cover', 'Couverture', 'D', ['cover.territory' => ['Territory', 'Territorialité'], 'travel.departure_date' => ['Start date', 'Date de début'], 'travel.return_date' => ['End date', 'Date de fin'], 'travel.medical_cover' => ['Medical cover', 'Frais médicaux'], 'travel.repatriation_cover' => ['Repatriation cover', 'Rapatriement'], 'travel.certificate_wording' => ['Certificate wording', 'Libellé du certificat']]]],
                'statements' => $cert],
            'DOC-140' => ['code' => 'TRAVEL_ASSISTANCE_INFORMATION', 'en' => 'Travel Assistance Information', 'fr' => 'Informations d’assistance voyage', 'tier' => 'S1', 'cat' => 'H', 'groups' => [$P,
                ['Certificate', 'Certificat', 'B', ['travel.certificate_reference' => ['Policy / certificate', 'Police / certificat']]],
                ['Assistance', 'Assistance', 'D', ['travel.emergency_contacts' => ['Emergency contacts', 'Contacts d’urgence'], 'travel.services' => ['Services', 'Services'], 'cover.territory' => ['Territory', 'Territorialité'], 'travel.procedures' => ['Procedures', 'Procédures'], 'travel.exclusions_limitations' => ['Exclusions / limitations', 'Exclusions / limitations']]]],
                'statements' => [['contact']]],
            'DOC-141' => ['code' => 'FARM_RISK_DECLARATION', 'en' => 'Farm Risk Declaration', 'fr' => 'Déclaration de risque agricole', 'tier' => 'S2', 'cat' => 'H', 'groups' => [
                ['Farmer / entity', 'Exploitant / entité', 'C', ['party.name' => ['Farmer / entity', 'Exploitant / entité'], 'agri.farm_location' => ['Farm location', 'Situation de l’exploitation'], 'agri.acreage' => ['Acreage', 'Superficie']]],
                ['Farm', 'Exploitation', 'D', ['agri.crops_livestock' => ['Crops / livestock', 'Cultures / cheptel'], 'agri.practices' => ['Practices', 'Pratiques'], 'agri.values' => ['Values', 'Valeurs'], 'agri.hazards' => ['Hazards', 'Risques'], 'agri.history' => ['History', 'Antécédents']]],
                $sign], 'statements' => $decl],
            'DOC-142' => ['code' => 'CROP_SCHEDULE', 'en' => 'Crop Schedule', 'fr' => 'État des cultures', 'tier' => 'S2', 'cat' => 'H', 'shell' => 'TPL-SHELL-POLICY-SCHEDULE-001', 'groups' => [$P,
                ['Farm / field', 'Exploitation / parcelle', 'C', ['agri.farm_location' => ['Farm / field', 'Exploitation / parcelle']]],
                ['Crop', 'Culture', 'D', ['agri.crop' => ['Crop', 'Culture'], 'agri.variety' => ['Variety', 'Variété'], 'agri.area' => ['Area', 'Surface'], 'agri.planting_date' => ['Planting date', 'Date de semis'], 'agri.harvest_date' => ['Harvest date', 'Date de récolte'], 'agri.expected_yield' => ['Expected yield / value', 'Rendement / valeur attendus'], 'agri.sum_insured' => ['Sum insured', 'Capital assuré']]]],
                'statements' => [['contact']]],
            'DOC-143' => ['code' => 'CROP_INSURANCE_CERTIFICATE', 'en' => 'Crop Insurance Certificate', 'fr' => 'Certificat d’assurance récolte', 'tier' => 'S3', 'cat' => 'H', 'shell' => 'TPL-SHELL-POLICY-CERTIFICATE-001', 'groups' => [$P,
                ['Farm / crop', 'Exploitation / culture', 'C', ['agri.farm_location' => ['Farm', 'Exploitation'], 'agri.crop' => ['Crop', 'Culture'], 'agri.area' => ['Area', 'Surface']]],
                ['Cover', 'Garanties', 'D', ['agri.insured_perils' => ['Insured perils', 'Périls assurés'], 'agri.sum_insured' => ['Sum insured', 'Capital assuré'], 'agri.season' => ['Period / season', 'Période / campagne']]]],
                'statements' => $cert],
            'DOC-144' => ['code' => 'LIVESTOCK_SCHEDULE', 'en' => 'Livestock Schedule', 'fr' => 'État du cheptel', 'tier' => 'S2', 'cat' => 'H', 'shell' => 'TPL-SHELL-POLICY-SCHEDULE-001', 'groups' => [$P,
                ['Farm', 'Exploitation', 'C', ['agri.farm_location' => ['Farm', 'Exploitation']]],
                ['Livestock', 'Cheptel', 'D', ['livestock.category' => ['Animal category', 'Catégorie d’animaux'], 'livestock.count' => ['Count', 'Effectif'], 'livestock.identifiers' => ['Identifiers where applicable', 'Identifiants le cas échéant'], 'livestock.age' => ['Age', 'Âge'], 'livestock.value' => ['Value', 'Valeur'], 'livestock.sum_insured' => ['Sum insured', 'Capital assuré']]]],
                'statements' => [['contact']]],
            'DOC-145' => ['code' => 'LIVESTOCK_INSURANCE_CERTIFICATE', 'en' => 'Livestock Insurance Certificate', 'fr' => 'Certificat d’assurance bétail', 'tier' => 'S3', 'cat' => 'H', 'shell' => 'TPL-SHELL-POLICY-CERTIFICATE-001', 'groups' => [$P,
                ['Livestock', 'Cheptel', 'C', ['livestock.category' => ['Livestock class', 'Catégorie de bétail'], 'livestock.count' => ['Count', 'Effectif'], 'agri.farm_location' => ['Location', 'Situation']]],
                ['Cover', 'Garanties', 'D', ['coverage.lines' => ['Cover', 'Garanties'], 'livestock.sum_insured' => ['Sum insured', 'Capital assuré']]], $period],
                'statements' => $cert],

            // ================= I. Servicing, Endorsements & Renewal
            'DOC-146' => ['code' => 'ENDORSEMENT_REQUEST', 'en' => 'Endorsement Request', 'fr' => 'Demande d’avenant', 'tier' => 'S2', 'cat' => 'I', 'groups' => [$P,
                ['Request', 'Demande', 'D', ['request.requester' => ['Requester', 'Demandeur'], 'endorsement.requested_change' => ['Requested change', 'Modification demandée'], 'request.reason' => ['Reason', 'Motif'], 'request.requested_effective_date' => ['Requested effective date', 'Date d’effet souhaitée'], 'request.supporting_evidence' => ['Supporting evidence', 'Pièces justificatives']]],
                self::signBlock('Requester', 'Demandeur')], 'statements' => [['request'], ['declaration']]],
            'DOC-147' => ['code' => 'POLICY_ENDORSEMENT', 'en' => 'Policy Endorsement', 'fr' => 'Avenant', 'tier' => 'S3', 'cat' => 'I', 'groups' => [$P,
                ['Endorsement', 'Avenant', 'D', ['endorsement.number' => ['Endorsement number', 'N° d’avenant'], 'policy.version' => ['Policy version', 'Version de police'], 'endorsement.changes' => ['Exact changes', 'Modifications'], 'endorsement.premium_delta' => ['Premium adjustment', 'Ajustement de prime'], 'endorsement.effective_at' => ['Effective date', 'Date d’effet'], 'endorsement.resulting_version' => ['Resulting policy version', 'Version de police résultante']]],
                ['Authorization', 'Autorisation', 'E', ['endorsement.authorized_by' => ['Authorized by', 'Autorisé par'], 'endorsement.authorized_at' => ['Authorized at', 'Autorisé le']]]],
                'statements' => [['contact']]],
            'DOC-148' => ['code' => 'REVISED_POLICY_SCHEDULE', 'en' => 'Revised Policy Schedule', 'fr' => 'Conditions particulières révisées', 'tier' => 'S3', 'cat' => 'I', 'shell' => 'TPL-SHELL-POLICY-SCHEDULE-001', 'groups' => [$P,
                ['Revision', 'Révision', 'D', ['policy.version' => ['New version', 'Nouvelle version'], 'endorsement.changes' => ['Changed fields', 'Éléments modifiés'], 'endorsement.effective_at' => ['Revision effective date', 'Date d’effet de la révision']]],
                ['Current schedule', 'Conditions particulières en vigueur', 'D', ['risk.summary' => ['Insured risk', 'Risque assuré'], 'coverage.lines' => ['Coverages', 'Garanties'], 'coverage.limits' => ['Limits / sums insured', 'Limites / capitaux assurés'], 'coverage.deductibles' => ['Deductibles', 'Franchises']]],
                $prem], 'statements' => [['contact']]],
            'DOC-149' => ['code' => 'ADDITIONAL_PREMIUM_NOTICE', 'en' => 'Additional Premium Notice', 'fr' => 'Avis de prime complémentaire', 'tier' => 'S2', 'cat' => 'I', 'groups' => [$P,
                ['Endorsement', 'Avenant', 'B', ['endorsement.number' => ['Endorsement', 'Avenant'], 'premium.adjustment_reason' => ['Reason', 'Motif']]],
                ['Amount due', 'Montant dû', 'D', ['premium.additional' => ['Additional premium', 'Prime complémentaire'], 'premium.taxes' => ['Taxes / fees', 'Taxes / frais'], 'premium.amount_due' => ['Total due', 'Total dû'], 'premium.due_date' => ['Due date', 'Date d’échéance'], 'payment.instructions' => ['Payment instructions', 'Modalités de paiement']]]],
                'statements' => [['contact']]],
            'DOC-150' => ['code' => 'PREMIUM_REDUCTION_NOTICE', 'en' => 'Premium Reduction Notice', 'fr' => 'Avis de réduction de prime', 'tier' => 'S2', 'cat' => 'I', 'groups' => [$P,
                ['Endorsement', 'Avenant', 'B', ['endorsement.number' => ['Endorsement', 'Avenant'], 'premium.adjustment_reason' => ['Reason', 'Motif']]],
                ['Reduction', 'Réduction', 'D', ['premium.reduction' => ['Reduction amount', 'Montant de la réduction'], 'premium.credit_treatment' => ['Credit / refund treatment', 'Traitement : avoir / remboursement'], 'endorsement.effective_at' => ['Effective date', 'Date d’effet']]]],
                'statements' => [['contact']]],
            'DOC-151' => ['code' => 'RENEWAL_NOTICE', 'en' => 'Renewal Notice', 'fr' => 'Avis d’échéance', 'tier' => 'S2', 'cat' => 'I', 'groups' => [$P,
                ['Renewal', 'Renouvellement', 'D', ['renewal.expiry_date' => ['Expiry', 'Échéance'], 'renewal.period' => ['Renewal period', 'Période de renouvellement'], 'renewal.premium' => ['Renewal premium', 'Prime de renouvellement'], 'renewal.changed_terms' => ['Changed terms', 'Conditions modifiées'], 'renewal.payment_deadline' => ['Payment deadline', 'Date limite de paiement'], 'renewal.action_required' => ['Action required', 'Action requise']]]],
                'statements' => [['contact']]],
            'DOC-152' => ['code' => 'RENEWAL_QUOTE', 'en' => 'Renewal Quote', 'fr' => 'Devis de renouvellement', 'tier' => 'S1', 'cat' => 'I', 'shell' => 'TPL-SHELL-QUOTE-001', 'groups' => [$P,
                ['Renewal', 'Renouvellement', 'B', ['renewal.reference' => ['Renewal reference', 'Référence du renouvellement'], 'renewal.expiring_policy' => ['Expiring policy', 'Police arrivant à échéance']]],
                ['Proposed terms', 'Conditions proposées', 'D', ['coverage.lines' => ['Proposed cover', 'Garanties proposées'], 'coverage.limits' => ['Limits', 'Limites'], 'renewal.premium' => ['Premium', 'Prime'], 'renewal.changed_terms' => ['Changes', 'Modifications'], 'renewal.quote_valid_until' => ['Validity', 'Validité']]]],
                'statements' => [['contact']]],
            'DOC-153' => ['code' => 'RENEWAL_CONFIRMATION', 'en' => 'Renewal Confirmation', 'fr' => 'Confirmation de renouvellement', 'tier' => 'S2', 'cat' => 'I', 'groups' => [$P,
                ['Renewal', 'Renouvellement', 'D', ['renewal.expiring_policy' => ['Previous policy', 'Police précédente'], 'renewal.new_policy' => ['New policy', 'Nouvelle police'], 'renewal.period' => ['Renewal period', 'Période de renouvellement'], 'renewal.accepted_terms' => ['Accepted terms', 'Conditions acceptées'], 'payment.status' => ['Payment / status', 'Paiement / statut'], 'renewal.documents_issued' => ['Documents issued', 'Documents émis']]]],
                'statements' => [['contact']]],
            'DOC-154' => ['code' => 'NON_RENEWAL_NOTICE', 'en' => 'Non-Renewal Notice', 'fr' => 'Avis de non-renouvellement', 'tier' => 'S3', 'cat' => 'I', 'groups' => [$P,
                ['Non-renewal', 'Non-renouvellement', 'D', ['renewal.expiry_date' => ['Expiry', 'Échéance'], 'renewal.non_renewal_effective_date' => ['Non-renewal effective date', 'Date d’effet du non-renouvellement'], 'renewal.non_renewal_reason' => ['Reason / category where appropriate', 'Motif / catégorie le cas échéant'], 'renewal.customer_action' => ['Customer action / contact', 'Action du client / contact']]]],
                'statements' => [['contact']]],
            'DOC-155' => ['code' => 'SUSPENSION_NOTICE', 'en' => 'Suspension Notice', 'fr' => 'Avis de suspension des garanties', 'tier' => 'S3', 'cat' => 'I', 'groups' => [$P,
                ['Suspension', 'Suspension', 'D', ['suspension.reason' => ['Suspension reason', 'Motif de la suspension'], 'suspension.effective_at' => ['Effective time', 'Date et heure d’effet'], 'suspension.affected_coverage' => ['Affected coverage', 'Garanties concernées'], 'suspension.reinstatement_requirements' => ['Reinstatement requirements', 'Conditions de remise en vigueur']]]],
                'statements' => [['contact']]],
            'DOC-156' => ['code' => 'REINSTATEMENT_NOTICE', 'en' => 'Reinstatement Notice', 'fr' => 'Avis de remise en vigueur', 'tier' => 'S3', 'cat' => 'I', 'groups' => [$P,
                ['Reinstatement', 'Remise en vigueur', 'D', ['reinstatement.effective_at' => ['Reinstatement date / time', 'Date et heure de remise en vigueur'], 'reinstatement.conditions_satisfied' => ['Conditions satisfied', 'Conditions remplies'], 'payment.status' => ['Premium / payment status', 'Statut de la prime / du paiement'], 'coverage.lines' => ['Current cover', 'Garanties en vigueur']]]],
                'statements' => [['contact']]],
            'DOC-157' => ['code' => 'CANCELLATION_REQUEST', 'en' => 'Cancellation Request', 'fr' => 'Demande de résiliation', 'tier' => 'S2', 'cat' => 'I', 'groups' => [$P,
                ['Request', 'Demande', 'D', ['request.requester' => ['Requester', 'Demandeur'], 'request.reason' => ['Reason', 'Motif'], 'request.requested_effective_date' => ['Requested date', 'Date souhaitée'], 'cancellation.refund_instructions' => ['Refund / payment instructions', 'Instructions de remboursement / paiement']]],
                self::signBlock('Requester', 'Demandeur')], 'statements' => [['request'], ['declaration']]],
            'DOC-158' => ['code' => 'CANCELLATION_TERMINATION_NOTICE', 'en' => 'Cancellation / Termination Notice', 'fr' => 'Avis de résiliation', 'tier' => 'S3', 'cat' => 'I', 'groups' => [$P,
                ['Cancellation', 'Résiliation', 'D', ['cancellation.reason' => ['Reason', 'Motif'], 'cancellation.effective_at' => ['Effective date', 'Date d’effet'], 'cancellation.premium_balance' => ['Premium / refund balance', 'Solde de prime / remboursement'], 'cancellation.coverage_status' => ['Coverage status', 'Statut de la couverture']]]],
                'statements' => [['contact']]],
            'DOC-159' => ['code' => 'POLICY_EXPIRY_NOTICE', 'en' => 'Policy Expiry Notice', 'fr' => 'Avis d’expiration', 'tier' => 'S2', 'cat' => 'I', 'groups' => [$P,
                ['Expiry', 'Expiration', 'D', ['renewal.expiry_date' => ['Expiry date / time', 'Date et heure d’expiration'], 'expiry.coverage_ending' => ['Coverage ending', 'Garanties prenant fin'], 'renewal.status' => ['Renewal status / options', 'Statut / options de renouvellement']]]],
                'statements' => [['contact']]],
            'DOC-160' => ['code' => 'POLICY_STATUS_CONFIRMATION', 'en' => 'Policy Status Confirmation', 'fr' => 'Attestation de situation du contrat', 'tier' => 'S3', 'cat' => 'I', 'groups' => [$P,
                ['Status', 'Situation', 'D', ['policy.status' => ['Current status', 'Statut actuel'], 'policy.current_effective_dates' => ['Current effective dates', 'Dates d’effet en vigueur'], 'policy.status_as_of' => ['Status as of', 'Situation au']]]],
                'statements' => [['contact']]],

            // ================= J. Claims (DOC-161..DOC-165)
            'DOC-161' => ['code' => 'CLAIM_NOTIFICATION_FORM', 'en' => 'Claim Notification Form', 'fr' => 'Déclaration de sinistre', 'tier' => 'S2', 'cat' => 'J', 'groups' => [$claim,
                ['Loss', 'Sinistre', 'D', ['claim.loss_date' => ['Loss date', 'Date du sinistre'], 'claim.loss_place' => ['Place of loss', 'Lieu du sinistre'], 'claim.loss_description' => ['Loss details', 'Circonstances'], 'claim.damage_injury' => ['Damage / injury', 'Dommages / blessures']]],
                ['Claimant and parties', 'Déclarant et parties', 'C', ['claim.claimant' => ['Claimant', 'Déclarant'], 'claim.third_parties' => ['Other parties', 'Autres parties']]],
                ['Evidence', 'Pièces', 'D', ['claim.evidence' => ['Evidence provided', 'Pièces fournies']]],
                $sign], 'statements' => $decl],
            'DOC-162' => ['code' => 'CLAIM_ACKNOWLEDGEMENT', 'en' => 'Claim Acknowledgement', 'fr' => 'Accusé de réception de sinistre', 'tier' => 'S2', 'cat' => 'J', 'groups' => [$claim,
                ['Receipt', 'Réception', 'D', ['claim.claimant' => ['Claimant', 'Déclarant'], 'claim.received_at' => ['Receipt date', 'Date de réception'], 'claim.handler_contact' => ['Contact', 'Interlocuteur'], 'claim.next_steps' => ['Next steps', 'Prochaines étapes'], 'claim.requirements' => ['Requirements', 'Pièces demandées']]]],
                'statements' => [['contact']]],
            'DOC-163' => ['code' => 'CLAIM_REFERENCE_CONFIRMATION', 'en' => 'Claim Reference Confirmation', 'fr' => 'Confirmation du numéro de sinistre', 'tier' => 'S2', 'cat' => 'J', 'groups' => [$claim,
                ['Claim reference', 'Référence du sinistre', 'D', ['claim.claimant' => ['Insured / claimant', 'Assuré / déclarant'], 'claim.loss_date' => ['Loss date', 'Date du sinistre'], 'claim.reported_at' => ['Report date', 'Date de déclaration'], 'claim.handler_contact' => ['Assigned contact', 'Interlocuteur désigné']]]],
                'statements' => [['contact']]],
            'DOC-164' => ['code' => 'CLAIM_REQUIREMENTS_LIST', 'en' => 'Claim Requirements List', 'fr' => 'Liste des pièces à fournir', 'tier' => 'S1', 'cat' => 'J', 'groups' => [$claim,
                ['Requirements', 'Pièces à fournir', 'D', ['claim.requirements' => ['Required evidence / documents', 'Pièces / documents requis'], 'claim.conditional_requirements' => ['Conditional requirements', 'Pièces conditionnelles'], 'claim.requirements_status' => ['Status', 'Statut'], 'claim.requirements_due_dates' => ['Due dates', 'Dates limites'], 'claim.submission_method' => ['Submission method', 'Mode de transmission']]]],
                'statements' => [['contact']]],
            'DOC-165' => ['code' => 'ADDITIONAL_EVIDENCE_REQUEST', 'en' => 'Additional Evidence Request', 'fr' => 'Demande de pièces complémentaires', 'tier' => 'S2', 'cat' => 'J', 'groups' => [$claim,
                ['Evidence requested', 'Pièces demandées', 'D', ['claim.missing_evidence' => ['Missing / insufficient evidence', 'Pièces manquantes / insuffisantes'], 'claim.evidence_request_reason' => ['Reason', 'Motif'], 'claim.evidence_deadline' => ['Deadline', 'Date limite'], 'claim.submission_method' => ['Submission instructions', 'Modalités de transmission']]]],
                'statements' => [['contact']]],
        ];
    }
}
