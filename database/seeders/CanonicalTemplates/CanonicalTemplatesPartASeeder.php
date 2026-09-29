<?php

declare(strict_types=1);

namespace Database\Seeders\CanonicalTemplates;

/**
 * Canonical templates DOC-001..DOC-055 (categories A pre-contract, B core policy, C motor).
 *
 * Fields: OpesInsure_220_Document_Field_Data_Specification_v1 (detailed specs of DOC-001/003/016/017/021/022/036 and the
 * per-document lists of §4). Each field is mapped to the canonical key the engine resolves (CanonicalFieldDictionary::KEYS,
 * MappedFieldValues, or the event's ctx['fields']); a field without a value prints "Not recorded", never an invented value.
 * Wording is neutral and restates only the spec text. Security (tier, shell, QR, seal …) comes from the catalogue.
 */
final class CanonicalTemplatesPartASeeder extends CanonicalTemplateSeeder
{
    protected function documents(): array
    {
        // Reusable field sets.
        $policy = ['policy.number', 'Policy number', 'N° de police', 'D'];
        $holder = ['party.name', 'Policyholder / insured', 'Souscripteur / assuré', 'C'];
        $insurer = ['policy.insurer', 'Insurer', 'Assureur', 'C'];
        $product = ['policy.product', 'Product', 'Produit', 'D'];
        $class = ['policy.insurance_class', 'Insurance class', "Branche d'assurance", 'D'];
        $currency = ['policy.currency', 'Currency', 'Devise', 'D'];
        $from = ['policy.effective_from', 'Effective date', "Date d'effet", 'D'];
        $until = ['policy.effective_until', 'Expiry date', "Date d'échéance", 'D'];
        $risk = ['risk.summary', 'Insured risk', 'Risque assuré', 'C'];
        $cover = ['coverage.lines', 'Coverages, limits and deductibles', 'Garanties, limites et franchises', 'D'];
        $premium = ['premium.gross', 'Gross premium', 'Prime TTC', 'D'];
        $taxes = ['premium.taxes', 'Taxes / levies / fees', 'Taxes / prélèvements / frais', 'D'];
        $broker = ['intermediary.name', 'Broker / agent', 'Courtier / agent', 'C'];
        $territory = ['policy.territory', 'Territory', 'Territorialité', 'D'];
        $plate = ['risk.registration_number', 'Registration number', "N° d'immatriculation", 'C'];
        $vin = ['risk.vin', 'VIN / chassis number', 'N° de châssis (VIN)', 'C'];
        $make = ['risk.make', 'Make', 'Marque', 'C'];
        $model = ['risk.model', 'Model', 'Modèle', 'C'];
        $year = ['risk.model_year', 'Model year', 'Année modèle', 'C'];
        $use = ['risk.usage', 'Vehicle category / use', 'Catégorie / usage du véhicule', 'C'];
        $vehicle = [$plate, $vin, $make, $model, $year, $use];
        $status = ['policy.status', 'Policy status', 'Statut de la police', 'D'];
        $proposal = ['proposal.reference', 'Proposal reference', 'Référence de la proposition', 'D'];
        $quote = ['quote.reference', 'Quote reference', 'Référence du devis', 'D'];
        $reason = ['transaction.reason', 'Reason', 'Motif', 'D'];
        $conditions = ['policy.special_conditions', 'Conditions', 'Conditions', 'D'];
        $signature = ['consent.signature', 'Signature / acceptance', 'Signature / acceptation', 'D'];
        $declaredAt = ['consent.timestamp', 'Declaration date and time', 'Date et heure de la déclaration', 'D'];

        return [
            // ---------------- A. Pre-contract, sales & disclosure ----------------
            'DOC-001' => [
                'intro_en' => 'Quotation for the product and risk described below, prepared for the prospect named in this document.',
                'intro_fr' => 'Devis établi pour le produit et le risque décrits ci-dessous, au nom du prospect désigné dans ce document.',
                'fields' => [
                    ['quote.status', 'Quote status', 'Statut du devis', 'D'], ['quote.revision', 'Version / revision', 'Version / révision', 'D'],
                    ['quote.valid_until', 'Valid until', "Valable jusqu'au", 'D'], $currency,
                    ['party.name', 'Prospect / customer', 'Prospect / client', 'C'], $insurer, $broker, ['intermediary.adviser', 'Agent / adviser', 'Agent / conseiller', 'C'],
                    $class, $product, ['policy.plan', 'Plan / package', 'Formule', 'D'], ['quote.coverage_period', 'Coverage period proposed', 'Période de couverture proposée', 'D'],
                    $risk, $cover, ['coverage.mandatory_flags', 'Optional / mandatory', 'Facultative / obligatoire', 'D'],
                    ['premium.base', 'Base premium', 'Prime de base', 'D'], ['premium.loadings', 'Loadings', 'Majorations', 'D'], ['premium.discounts', 'Discounts', 'Réductions', 'D'],
                    ['premium.net', 'Net premium', 'Prime nette', 'D'], $taxes, $premium, ['premium.installment_option', 'Installment option', 'Option de paiement fractionné', 'D'],
                    ['underwriting.assumptions', 'Underwriting assumptions', 'Hypothèses de souscription', 'D'],
                    ['underwriting.outstanding_requirements', 'Outstanding requirements', 'Exigences en attente', 'D'],
                    ['underwriting.referral_status', 'Referral / conditional status', 'Statut de référé / conditionnel', 'D'],
                    ['policy.exclusions_reference', 'Material exclusions / conditions', 'Exclusions / conditions importantes', 'D'],
                ],
                'notices' => [['en' => 'This quotation is valid until the date stated above and is subject to underwriting.', 'fr' => "Ce devis est valable jusqu'à la date indiquée ci-dessus et reste soumis à la souscription."]],
            ],
            'DOC-002' => [
                'intro_en' => 'Comparison of the quotations obtained for the customer named below.',
                'intro_fr' => 'Comparatif des devis obtenus pour le client désigné ci-dessous.',
                'fields' => [
                    ['comparison.reference', 'Comparison ID', 'Référence du comparatif', 'D'], ['party.name', 'Customer', 'Client', 'C'],
                    ['comparison.insurers_products', 'Insurers / products compared', 'Assureurs / produits comparés', 'D'],
                    ['comparison.coverages', 'Comparable coverages', 'Garanties comparables', 'D'], ['comparison.limits', 'Limits', 'Limites', 'D'],
                    ['comparison.deductibles', 'Deductibles', 'Franchises', 'D'], ['comparison.premiums', 'Premiums', 'Primes', 'D'],
                    ['comparison.taxes_fees', 'Taxes / fees', 'Taxes / frais', 'D'], ['comparison.key_exclusions', 'Key exclusions', 'Principales exclusions', 'D'],
                    ['comparison.validity_dates', 'Quote validity dates', 'Dates de validité des devis', 'D'],
                    ['comparison.recommendation', 'Recommendation (adviser)', 'Recommandation (conseiller)', 'D'],
                ],
                'notices' => [['en' => 'A recommendation is shown only when the customer or an authorized adviser provided one.', 'fr' => "Une recommandation n'apparaît que si le client ou un conseiller habilité l'a fournie."]],
            ],
            'DOC-003' => [
                'intro_en' => 'Insurance proposal submitted by the applicant named below.',
                'intro_fr' => 'Proposition d’assurance soumise par le proposant désigné ci-dessous.',
                'fields' => [
                    ['proposal.reference', 'Proposal number', 'N° de proposition', 'D'], $quote, ['party.name', 'Applicant', 'Proposant', 'C'],
                    ['proposal.policyholder', 'Proposed policyholder', 'Souscripteur proposé', 'C'], ['proposal.insured', 'Proposed insured(s)', 'Assuré(s) proposé(s)', 'C'],
                    ['beneficiary.name', 'Beneficiary', 'Bénéficiaire', 'C'], $product, $class,
                    ['proposal.requested_effective_date', 'Requested effective date', "Date d'effet demandée", 'D'], $risk,
                    ['proposal.underwriting_answers', 'Underwriting answers', 'Réponses au questionnaire', 'D'], ['proposal.disclosures', 'Disclosures', 'Déclarations', 'D'],
                    ['proposal.previous_insurance', 'Previous insurance', 'Assurance antérieure', 'D'], ['proposal.loss_history', 'Claims / loss history', 'Antécédents de sinistres', 'D'],
                    $cover, ['proposal.premium_estimate', 'Premium estimate', 'Estimation de prime', 'D'], ['proposal.payment_preference', 'Payment preference', 'Mode de paiement souhaité', 'D'],
                    ['proposal.required_documents', 'Required documents', 'Pièces requises', 'D'], ['consent.declaration', 'Declaration of truth / completeness', 'Déclaration de sincérité / exhaustivité', 'D'],
                    ['consent.reference', 'Consent', 'Consentement', 'D'], ['consent.privacy', 'Privacy / data-processing acknowledgement', 'Accusé données personnelles', 'D'],
                    $signature, ['proposal.submitted_at', 'Submission date and time', 'Date et heure de soumission', 'D'],
                ],
            ],
            'DOC-004' => [
                'intro_en' => 'Insurance application form of the applicant named below.',
                'intro_fr' => 'Formulaire de souscription du proposant désigné ci-dessous.',
                'fields' => [
                    ['party.name', 'Applicant', 'Proposant', 'C'], ['party.identification', 'Identity', 'Identité', 'C'], ['party.contact', 'Contact', 'Coordonnées', 'C'],
                    $product, $risk, ['proposal.requested_cover', 'Requested cover', 'Garanties demandées', 'D'],
                    ['proposal.previous_insurance', 'Prior insurance', 'Assurance antérieure', 'D'], ['proposal.loss_history', 'Loss history', 'Antécédents de sinistres', 'D'],
                    ['consent.declaration', 'Declarations', 'Déclarations', 'D'], ['proposal.required_documents', 'Supporting documents', 'Pièces justificatives', 'D'],
                    ['consent.reference', 'Consent', 'Consentement', 'D'], $signature,
                ],
            ],
            'DOC-005' => [
                'intro_en' => 'Information sheet of the insurance product described below.',
                'intro_fr' => 'Fiche d’information du produit d’assurance décrit ci-dessous.',
                'fields' => [
                    $product, ['product.code', 'Product code', 'Code produit', 'D'], $insurer, ['product.intended_customer', 'Intended customer', 'Clientèle visée', 'D'],
                    ['product.cover_summary', 'Cover summary', 'Résumé des garanties', 'D'], ['product.exclusions_summary', 'Exclusions summary', 'Résumé des exclusions', 'D'],
                    ['product.eligibility', 'Eligibility', 'Conditions d’éligibilité', 'D'], ['product.premium_basis', 'Premium basis', 'Base de calcul de la prime', 'D'],
                    ['product.key_obligations', 'Key obligations', 'Principales obligations', 'D'], ['product.claims_contact', 'Claims / contact process', 'Déclaration de sinistre / contact', 'D'],
                    ['product.version', 'Version / effective date', "Version / date d'effet", 'D'],
                ],
            ],
            'DOC-006' => [
                'intro_en' => 'Summary of the coverages of the product or policy referenced below.',
                'intro_fr' => 'Résumé des garanties du produit ou de la police référencé(e) ci-dessous.',
                'fields' => [
                    $product, $policy, $cover, ['coverage.waiting_periods', 'Waiting periods', 'Délais de carence', 'D'], $territory,
                    ['policy.exclusions_reference', 'Exclusions reference', 'Référence des exclusions', 'D'], $from, $until,
                ],
            ],
            'DOC-007' => [
                'intro_en' => 'Premium illustration for the applicant and product below, based on the assumptions stated.',
                'intro_fr' => 'Illustration de prime pour le proposant et le produit ci-dessous, sur la base des hypothèses indiquées.',
                'fields' => [
                    ['party.name', 'Insured / applicant', 'Assuré / proposant', 'C'], $product, ['illustration.assumptions', 'Assumptions', 'Hypothèses', 'D'],
                    ['illustration.premium_schedule', 'Premium schedule', 'Échéancier de prime', 'D'], ['illustration.benefits_values', 'Benefits / values', 'Prestations / valeurs', 'D'],
                    ['illustration.guaranteed_split', 'Guaranteed / non-guaranteed', 'Garanti / non garanti', 'D'], ['illustration.charges', 'Charges', 'Frais', 'D'],
                    ['quote.valid_until', 'Validity date', 'Date de validité', 'D'],
                ],
            ],
            'DOC-008' => [
                'intro_en' => 'Declaration of the risk made by the declarant named below.',
                'intro_fr' => 'Déclaration du risque faite par le déclarant désigné ci-dessous.',
                'fields' => [
                    ['party.name', 'Declarant', 'Déclarant', 'C'], ['risk.summary', 'Risk object / person', 'Objet / personne du risque', 'C'],
                    ['risk.material_facts', 'Material risk facts', 'Faits essentiels du risque', 'D'], ['proposal.loss_history', 'Previous losses', 'Sinistres antérieurs', 'D'],
                    ['risk.conditions', 'Risk conditions', 'Conditions du risque', 'D'], $declaredAt,
                    ['consent.declaration', 'Truth declaration', 'Déclaration de sincérité', 'D'], $signature,
                ],
            ],
            'DOC-009' => [
                'intro_en' => 'Risk questionnaire completed for the product and risk below.',
                'intro_fr' => 'Questionnaire de risque rempli pour le produit et le risque ci-dessous.',
                'fields' => [
                    $product, $risk, ['questionnaire.answers', 'Questions and answers', 'Questions et réponses', 'D'],
                    ['questionnaire.follow_up', 'Follow-up answers', 'Réponses complémentaires', 'D'], ['questionnaire.attachments', 'Attachments', 'Pièces jointes', 'D'],
                    ['party.name', 'Respondent', 'Répondant', 'C'], ['questionnaire.completed_at', 'Completion timestamp', 'Date et heure de réponse', 'D'],
                    ['consent.declaration', 'Signature / declaration', 'Signature / déclaration', 'D'],
                ],
            ],
            'DOC-010' => [
                'intro_en' => 'Request for the additional information or documents listed below.',
                'intro_fr' => 'Demande des informations ou pièces complémentaires énumérées ci-dessous.',
                'fields' => [
                    ['request.transaction_reference', 'Reference transaction', 'Opération concernée', 'D'], ['party.name', 'Recipient', 'Destinataire', 'C'],
                    ['request.missing_items', 'Missing information / documents', 'Informations / pièces manquantes', 'D'], $reason,
                    ['request.response_deadline', 'Response deadline', 'Date limite de réponse', 'D'], ['request.submission_method', 'Submission method', 'Mode de transmission', 'D'],
                    ['request.case_contact', 'Case contact', 'Interlocuteur', 'D'],
                ],
            ],
            'DOC-011' => [
                'intro_en' => 'Underwriting requirements for the proposal or quotation referenced below.',
                'intro_fr' => 'Exigences de souscription relatives à la proposition ou au devis référencé ci-dessous.',
                'fields' => [
                    $proposal, $quote, ['underwriting.requirements', 'Requirements', 'Exigences', 'D'], ['underwriting.requirement_status', 'Status of each requirement', 'Statut de chaque exigence', 'D'],
                    ['underwriting.due_date', 'Due date', "Date d'échéance", 'D'], ['underwriting.non_completion_consequence', 'Consequence of non-completion', 'Conséquence en cas de non-réalisation', 'D'],
                    ['underwriting.contact', 'Underwriter / contact', 'Souscripteur / contact', 'D'],
                ],
            ],
            'DOC-012' => [
                'intro_en' => 'Offer made on the conditions stated below.',
                'intro_fr' => 'Offre faite aux conditions indiquées ci-dessous.',
                'fields' => [
                    ['offer.reference', 'Offer reference', "Référence de l'offre", 'D'], $proposal, ['offer.conditions_precedent', 'Conditions precedent', 'Conditions préalables', 'D'],
                    ['offer.modified_terms', 'Modified terms', 'Conditions modifiées', 'D'], $premium, $cover,
                    ['offer.expiry', 'Offer expiry', "Expiration de l'offre", 'D'], ['offer.acceptance_mechanism', 'Acceptance mechanism', "Modalités d'acceptation", 'D'],
                ],
            ],
            'DOC-013' => [
                'intro_en' => 'Revised offer replacing the prior offer referenced below.',
                'intro_fr' => "Offre révisée remplaçant l'offre antérieure référencée ci-dessous.",
                'fields' => [
                    ['offer.prior_reference', 'Prior offer reference', "Référence de l'offre antérieure", 'D'], ['offer.revision', 'Revision number', 'N° de révision', 'D'],
                    ['offer.changed_terms', 'Changed terms', 'Conditions modifiées', 'D'], ['offer.previous_premium', 'Previous premium', 'Prime antérieure', 'D'], $premium,
                    ['coverage.lines', 'Revised cover', 'Garanties révisées', 'D'], ['offer.expiry', 'Validity', 'Validité', 'D'], $reason,
                ],
            ],
            'DOC-014' => [
                'intro_en' => 'Confirmation that the customer named below accepted the offer referenced in this document.',
                'intro_fr' => "Confirmation de l'acceptation, par le client désigné ci-dessous, de l'offre référencée dans ce document.",
                'fields' => [
                    ['offer.reference', 'Offer / proposal reference', "Référence de l'offre / proposition", 'D'], ['offer.accepted_terms', 'Accepted terms', 'Conditions acceptées', 'D'],
                    ['party.name', 'Customer', 'Client', 'C'], ['consent.channel', 'Acceptance channel', "Canal d'acceptation", 'D'], ['consent.timestamp', 'Acceptance timestamp', "Date et heure d'acceptation", 'D'],
                    ['consent.evidence', 'Consent / signature evidence', 'Preuve du consentement / de la signature', 'D'], ['offer.next_steps', 'Next steps', 'Étapes suivantes', 'D'],
                ],
            ],
            'DOC-015' => [
                'intro_en' => 'Record of the electronic consent given by the party named below.',
                'intro_fr' => 'Preuve du consentement électronique donné par la personne désignée ci-dessous.',
                'fields' => [
                    ['party.name', 'Party', 'Personne', 'C'], ['consent.subject', 'Transaction / document consented to', 'Opération / document concerné', 'D'],
                    ['consent.text_version', 'Consent text and version', 'Texte et version du consentement', 'D'], ['consent.channel', 'Channel', 'Canal', 'D'],
                    ['consent.session_evidence', 'Device / session evidence', 'Preuve appareil / session', 'D'], ['consent.timestamp', 'Timestamp', 'Date et heure', 'D'],
                    ['consent.authentication_method', 'Authentication method', "Méthode d'authentification", 'D'],
                ],
            ],

            // ---------------- B. Core policy & contract ----------------
            'DOC-016' => [
                'intro_en' => 'Insurance policy {policy_number} issued by {carrier_name} to the policyholder named below. The components listed in this document together constitute the contract.',
                'intro_fr' => 'Police d’assurance {policy_number} émise par {carrier_name} au profit du souscripteur désigné ci-dessous. Les éléments énumérés dans ce document constituent ensemble le contrat.',
                'fields' => [
                    $insurer, $holder, ['insured.name', 'Insured', 'Assuré', 'C'], ['beneficiary.name', 'Beneficiary roles', 'Bénéficiaires', 'C'], $policy, $product, $class,
                    $from, $until, $territory, $risk, $cover, ['policy.exclusions_reference', 'Exclusions', 'Exclusions', 'D'], ['policy.special_conditions', 'Special conditions', 'Conditions spéciales', 'D'],
                    $premium, $taxes, ['policy.payment_terms', 'Payment terms', 'Modalités de paiement', 'D'], ['policy.renewal_terms', 'Renewal terms', 'Conditions de renouvellement', 'D'],
                    ['policy.cancellation_rules', 'Cancellation / termination rules', 'Règles de résiliation', 'D'], ['policy.contact', 'Notification / contact information', 'Notifications / contact', 'D'],
                    ['policy.conditions_reference', 'Linked general / special conditions', 'Conditions générales / spéciales liées', 'D'],
                    ['policy.endorsements', 'Endorsements incorporated', 'Avenants intégrés', 'D'], ['policy.components', 'Contract components', 'Éléments constitutifs du contrat', 'D'],
                ],
            ],
            'DOC-017' => [
                'intro_en' => 'Schedule of policy {policy_number}.',
                'intro_fr' => 'Conditions particulières de la police {policy_number}.',
                'fields' => [
                    $policy, ['policy.version', 'Schedule version', 'Version des conditions particulières', 'D'], $holder, ['insured.name', 'Insured', 'Assuré', 'C'], $product,
                    ['policy.branch', 'Branch', 'Agence', 'D'], $broker, $from, $until, ['policy.renewal_date', 'Renewal date / basis', 'Date / base de renouvellement', 'D'], $territory,
                    $risk, $cover, $premium, $taxes, ['policy.payment_schedule', 'Payment schedule', 'Échéancier de paiement', 'D'], ['policy.special_conditions', 'Special conditions', 'Conditions spéciales', 'D'],
                    ['policy.clause_references', 'Clause references', 'Références des clauses', 'D'], ['policy.endorsements', 'Endorsement references', 'Références des avenants', 'D'],
                ],
            ],
            'DOC-018' => [
                'intro_en' => 'General conditions of the product referenced below, in the version stated.',
                'intro_fr' => 'Conditions générales du produit référencé ci-dessous, dans la version indiquée.',
                'fields' => [
                    $insurer, $product, ['product.version', 'Product version', 'Version du produit', 'D'], ['conditions.definitions', 'Definitions', 'Définitions', 'D'],
                    ['conditions.coverage_framework', 'Coverage framework', 'Cadre des garanties', 'D'], ['conditions.exclusions', 'Exclusions', 'Exclusions', 'D'],
                    ['conditions.duties', 'Duties', 'Obligations', 'D'], ['conditions.claims', 'Claims conditions', 'Conditions en cas de sinistre', 'D'],
                    ['conditions.premium_obligations', 'Premium obligations', 'Obligations relatives à la prime', 'D'], ['conditions.cancellation_renewal', 'Cancellation / renewal rules', 'Résiliation / renouvellement', 'D'],
                    ['conditions.disputes_complaints', 'Dispute / complaint process', 'Litiges / réclamations', 'D'], ['conditions.effective_version', 'Effective version', 'Version en vigueur', 'D'],
                ],
            ],
            'DOC-019' => [
                'intro_en' => 'Special conditions applying to the policy or product referenced below.',
                'intro_fr' => 'Conditions spéciales applicables à la police ou au produit référencé(e) ci-dessous.',
                'fields' => [
                    $policy, $product, ['policy.special_conditions', 'Special conditions', 'Conditions spéciales', 'D'],
                    ['conditions.deviations', 'Deviations / additions to general conditions', 'Dérogations / ajouts aux conditions générales', 'D'],
                    ['conditions.applicability', 'Applicability', 'Champ d’application', 'D'], ['conditions.precedence', 'Precedence rule', 'Règle de préséance', 'D'],
                    ['conditions.effective_version', 'Effective version', 'Version en vigueur', 'D'],
                ],
            ],
            'DOC-020' => [
                'intro_en' => 'Schedule of the specific clauses attached to policy {policy_number}.',
                'intro_fr' => 'Tableau des clauses particulières attachées à la police {policy_number}.',
                'fields' => [
                    $policy, ['clauses.codes_titles', 'Clause codes / titles', 'Codes / intitulés des clauses', 'D'], ['clauses.text_reference', 'Clause text / reference', 'Texte / référence des clauses', 'D'],
                    ['clauses.affected_cover', 'Affected coverage / risk', 'Garantie / risque concerné(e)', 'D'], $from, $until,
                ],
            ],
            'DOC-021' => [
                'intro_en' => 'Cover note evidencing temporary cover for the risk described below, for the period stated.',
                'intro_fr' => 'Note de couverture attestant une couverture temporaire du risque décrit ci-dessous, pour la période indiquée.',
                'fields' => [
                    ['cover_note.linked_reference', 'Linked proposal / quote / policy', 'Proposition / devis / police lié(e)', 'D'], $insurer, $holder, $risk,
                    ['coverage.lines', 'Coverage temporarily evidenced', 'Garanties temporairement attestées', 'D'], ['cover_note.starts_at', 'Inception date and time', "Date et heure d'effet", 'D'],
                    ['cover_note.ends_at', 'Expiration date and time', "Date et heure d'expiration", 'D'], ['offer.conditions_precedent', 'Conditions precedent', 'Conditions préalables', 'D'],
                    ['underwriting.outstanding_requirements', 'Outstanding requirements', 'Exigences en attente', 'D'], ['payment.status', 'Premium / payment status', 'Statut de la prime / du paiement', 'D'],
                ],
                'notices' => [['en' => 'This cover note is temporary and provisional; it is valid only for the period stated.', 'fr' => 'Cette note de couverture est temporaire et provisoire ; elle ne vaut que pour la période indiquée.']],
            ],
            'DOC-022' => [
                'fields' => [
                    $policy, $insurer, $holder, $class, $risk, ['coverage.lines', 'Scope of cover', 'Étendue de la couverture', 'D'], $from, $until, $territory,
                    ['policy.terms_reference', 'Limitations / reference to policy terms', 'Limitations / renvoi aux conditions de la police', 'D'],
                ],
            ],
            'DOC-023' => [
                'intro_en' => 'Proof that the cover described below is recorded under policy {policy_number}.',
                'intro_fr' => 'Justificatif de la couverture décrite ci-dessous, enregistrée au titre de la police {policy_number}.',
                'fields' => [$policy, $holder, $risk, ['coverage.status', 'Coverage status', 'Statut de la couverture', 'D'], $from, $until,
                    ['coverage.verified_at', 'Verification timestamp / status', 'Date / statut de vérification', 'D']],
            ],
            'DOC-024' => [
                'intro_en' => 'Summary of policy {policy_number}.',
                'intro_fr' => 'Résumé de la police {policy_number}.',
                'fields' => [$policy, $holder, $product, $from, $until, ['coverage.lines', 'Key coverages', 'Principales garanties', 'D'],
                    ['policy.exclusions_reference', 'Key exclusions', 'Principales exclusions', 'D'], $premium, ['policy.contact', 'Contact / claims instructions', 'Contact / déclaration de sinistre', 'D']],
            ],
            'DOC-025' => [
                'intro_en' => 'Schedule of the benefits of the policy or product referenced below.',
                'intro_fr' => 'Tableau des prestations de la police ou du produit référencé(e) ci-dessous.',
                'fields' => [$policy, $product, ['benefits.codes', 'Benefit codes', 'Codes des prestations', 'D'], ['benefits.descriptions', 'Descriptions', 'Descriptions', 'D'],
                    ['benefits.limits', 'Limits', 'Plafonds', 'D'], ['benefits.frequency', 'Frequency', 'Fréquence', 'D'], ['coverage.waiting_periods', 'Waiting periods', 'Délais de carence', 'D'],
                    ['benefits.member_shares', 'Member shares', "Participation de l'adhérent", 'D'], ['benefits.conditions', 'Conditions', 'Conditions', 'D']],
            ],
            'DOC-026' => [
                'intro_en' => 'Schedule of the coverages of policy {policy_number}.',
                'intro_fr' => 'Tableau des garanties de la police {policy_number}.',
                'fields' => [$policy, $risk, $cover, $premium, $from, $until],
            ],
            'DOC-027' => [
                'intro_en' => 'Schedule of the exclusions of the policy or product referenced below.',
                'intro_fr' => 'Tableau des exclusions de la police ou du produit référencé(e) ci-dessous.',
                'fields' => [$policy, $product, ['exclusions.code_text', 'Exclusion code / text / reference', 'Code / texte / référence de l’exclusion', 'D'],
                    ['exclusions.coverage_affected', 'Coverage affected', 'Garantie concernée', 'D'], ['exclusions.exceptions', 'Conditions / exceptions', 'Conditions / exceptions', 'D']],
            ],
            'DOC-028' => [
                'intro_en' => 'Schedule of the deductibles of policy {policy_number}.',
                'intro_fr' => 'Tableau des franchises de la police {policy_number}.',
                'fields' => [$policy, ['coverage.lines', 'Coverage / risk', 'Garantie / risque', 'D'], ['deductibles.type', 'Deductible type', 'Type de franchise', 'D'],
                    ['deductibles.amount', 'Amount / percentage', 'Montant / pourcentage', 'D'], ['deductibles.min_max', 'Minimum / maximum', 'Minimum / maximum', 'D'],
                    ['deductibles.basis', 'Application basis', "Base d'application", 'D']],
            ],
            'DOC-029' => [
                'intro_en' => 'Schedule of the assets insured under policy {policy_number}.',
                'intro_fr' => 'État des biens assurés au titre de la police {policy_number}.',
                'fields' => [$policy, ['assets.schedule', 'Insured assets', 'Biens assurés', 'C', 'table', [
                    ['id', 'Asset ID', 'Identifiant du bien'], ['description', 'Description', 'Description'], ['location', 'Location', 'Situation'],
                    ['value', 'Value', 'Valeur', 'money'], ['sum_insured', 'Sum insured', 'Capital assuré', 'money'], ['coverage', 'Coverage', 'Garanties'],
                    ['effective_from', 'Effective from', "Date d'effet", 'date'], ['effective_until', 'Effective until', "Date d'échéance", 'date']]],
                    ['coverage.lines', 'Coverage', 'Garanties', 'D'], $from, $until],
            ],
            'DOC-030' => [
                'intro_en' => 'Schedule of the persons insured under policy {policy_number}.',
                'intro_fr' => 'État des personnes assurées au titre de la police {policy_number}.',
                'fields' => [$policy, ['insured.persons', 'Insured persons', 'Personnes assurées', 'C', 'table', [
                    ['member_id', 'Member / person ID', 'Identifiant adhérent / personne'], ['name', 'Name', 'Nom'], ['relationship', 'Relationship / category', 'Lien / catégorie'],
                    ['benefit_level', 'Benefit level', 'Niveau de garanties'], ['effective_from', 'Effective from', "Date d'effet", 'date'],
                    ['effective_until', 'Effective until', "Date d'échéance", 'date'], ['status', 'Status', 'Statut']]], $from, $until],
            ],
            'DOC-031' => [
                'intro_en' => 'Premium schedule of policy {policy_number}.',
                'intro_fr' => 'Échéancier de prime de la police {policy_number}.',
                'fields' => [$policy, ['installments.schedule', 'Installments', 'Échéances', 'D', 'table', [
                    ['number', 'Installment number', "N° d'échéance"], ['due_date', 'Due date', "Date d'échéance", 'date'], ['premium_minor', 'Premium', 'Prime', 'money'],
                    ['taxes_fees_minor', 'Taxes / fees', 'Taxes / frais', 'money'], ['total_minor', 'Total', 'Total', 'money'],
                    ['outstanding_minor', 'Paid / outstanding', 'Payé / restant dû', 'money'], ['status', 'Status', 'Statut']]]],
            ],
            'DOC-032' => [
                'intro_en' => 'Payment schedule of the policy or account referenced below.',
                'intro_fr' => 'Échéancier de paiement de la police ou du compte référencé(e) ci-dessous.',
                'fields' => [$policy, ['account.reference', 'Account', 'Compte', 'D'], ['installments.due_dates', 'Due dates', "Dates d'échéance", 'D'],
                    ['installments.amounts', 'Required amounts', 'Montants dus', 'D'], ['payment.instructions', 'Payment method / instructions', 'Mode / instructions de paiement', 'D'],
                    ['installments.status', 'Status', 'Statut', 'D']],
            ],
            'DOC-033' => [
                'intro_en' => 'Confirmation that policy {policy_number} has been issued.',
                'intro_fr' => "Confirmation de l'émission de la police {policy_number}.",
                'fields' => [$policy, $proposal, ['policy.issued_at', 'Issue date', "Date d'émission", 'D'], ['policy.effective_from', 'Inception', "Date d'effet", 'D'],
                    ['policy.documents_issued', 'Documents issued', 'Documents émis', 'D'], ['payment.status', 'Payment status', 'Statut du paiement', 'D'], ['offer.next_steps', 'Next steps', 'Étapes suivantes', 'D']],
            ],
            'DOC-034' => [
                'intro_en' => 'Duplicate of the original policy document referenced below.',
                'intro_fr' => 'Duplicata du document de police original référencé ci-dessous.',
                'fields' => [['lifecycle.original_reference', 'Original policy / document reference', 'Référence de la police / du document original', 'D'], $policy,
                    ['lifecycle.duplicate_number', 'Duplicate number', 'N° de duplicata', 'D'], $reason, ['lifecycle.current_status', 'Current validity / status', 'Validité / statut actuel', 'D']],
            ],
            'DOC-035' => [
                'intro_en' => 'Notice that the policy document referenced below is replaced.',
                'intro_fr' => 'Avis de remplacement du document de police référencé ci-dessous.',
                'fields' => [['lifecycle.original_reference', 'Old policy / document', 'Ancienne police / ancien document', 'D'], ['lifecycle.replacement_reference', 'Replacement document', 'Document de remplacement', 'D'],
                    $reason, ['lifecycle.effective_at', 'Effective date', "Date d'effet", 'D'], ['lifecycle.validity_impact', 'Validity impact', 'Incidence sur la validité', 'D'], $policy],
            ],

            // ---------------- C. Motor ----------------
            'DOC-036' => [
                'fields' => array_merge([$policy, $insurer, $holder, ['insured.name', 'Insured (where different)', 'Assuré (si différent)', 'C']], $vehicle, [
                    ['policy.effective_from', 'Effective date and time', "Date et heure d'effet", 'D'], ['policy.effective_until', 'Expiry date and time', "Date et heure d'échéance", 'D'],
                    $territory, ['coverage.lines', 'Motor cover', 'Garanties automobile', 'D'], ['policy.branch', 'Issuing branch', 'Agence émettrice', 'D'],
                    ['security.stock_serial', 'Serial / security stock', 'Série / imprimé sécurisé', 'D'],
                ]),
            ],
            'DOC-037' => [
                'fields' => array_merge([$policy, $holder], $vehicle, [['coverage.lines', 'Cover scope', 'Étendue de la couverture', 'D'], $from, $until, $territory, $insurer]),
            ],
            'DOC-038' => [
                'intro_en' => 'Provisional motor attestation for the vehicle described below, valid for the temporary period stated.',
                'intro_fr' => 'Attestation provisoire pour le véhicule décrit ci-dessous, valable pour la période temporaire indiquée.',
                'fields' => array_merge([['cover_note.linked_reference', 'Proposal / policy reference', 'Référence de la proposition / police', 'D'], $holder], $vehicle, [
                    ['cover_note.starts_at', 'Temporary cover start', 'Début de la couverture temporaire', 'D'], ['cover_note.ends_at', 'Temporary cover end', 'Fin de la couverture temporaire', 'D'],
                    ['offer.conditions_precedent', 'Conditions', 'Conditions', 'D'], ['underwriting.outstanding_requirements', 'Outstanding requirements', 'Exigences en attente', 'D'],
                ]),
                'notices' => [['en' => 'Provisional attestation: valid only for the temporary period stated.', 'fr' => 'Attestation provisoire : valable uniquement pour la période temporaire indiquée.']],
            ],
            'DOC-039' => [
                'intro_en' => 'Schedule of the vehicles insured under policy {policy_number}.',
                'intro_fr' => 'État des véhicules assurés au titre de la police {policy_number}.',
                'fields' => array_merge([$policy, ['vehicle.id', 'Vehicle ID', 'Identifiant du véhicule', 'C']], $vehicle, [
                    ['vehicle.value', 'Value', 'Valeur', 'D'], $cover, $premium, ['vehicle.status', 'Status', 'Statut', 'D'],
                ]),
            ],
            'DOC-040' => [
                'intro_en' => 'Schedule of the fleet insured under policy {policy_number}.',
                'intro_fr' => 'État du parc automobile assuré au titre de la police {policy_number}.',
                'fields' => [$policy, ['fleet.vehicles', 'Vehicles', 'Véhicules', 'C', 'table', [
                    ['registration_number', 'Registration number', "N° d'immatriculation"], ['make', 'Make', 'Marque'], ['model', 'Model', 'Modèle'],
                    ['location', 'Branch / location', 'Agence / lieu'], ['usage', 'Driver / use category', 'Catégorie conducteur / usage'], ['value', 'Value', 'Valeur', 'money'],
                    ['cover', 'Cover', 'Garanties'], ['premium_minor', 'Premium', 'Prime', 'money']]],
                    $cover, $premium, $from, $until, ['fleet.totals', 'Totals', 'Totaux', 'D']],
            ],
            'DOC-041' => [
                'intro_en' => 'Motor risk questionnaire for the vehicle described below.',
                'intro_fr' => 'Questionnaire de risque automobile pour le véhicule décrit ci-dessous.',
                'fields' => array_merge($vehicle, [['party.name', 'Owner / user', 'Propriétaire / utilisateur', 'C'], ['driver.profile', 'Driver profile', 'Profil du conducteur', 'C'],
                    ['vehicle.parking_security', 'Parking / security', 'Stationnement / sécurité', 'D'], ['vehicle.mileage', 'Mileage', 'Kilométrage', 'D'],
                    ['proposal.loss_history', 'Prior claims', 'Sinistres antérieurs', 'D'], ['vehicle.modifications', 'Modifications', 'Modifications', 'D'],
                    ['vehicle.finance_interest', 'Finance interest', 'Créancier gagiste', 'D']]),
            ],
            'DOC-042' => [
                'intro_en' => 'Request for the inspection of the vehicle described below.',
                'intro_fr' => "Demande d'inspection du véhicule décrit ci-dessous.",
                'fields' => array_merge($vehicle, [$policy, $proposal, ['inspection.type', 'Inspection type', "Type d'inspection", 'D'], $reason,
                    ['inspection.location', 'Location', 'Lieu', 'D'], ['inspection.inspector', 'Assigned inspector', 'Inspecteur désigné', 'D'],
                    ['inspection.due_date', 'Due date', 'Date limite', 'D'], ['inspection.required_checks', 'Required photos / checks', 'Photos / contrôles requis', 'D']]),
            ],
            'DOC-043' => [
                'intro_en' => 'Report of the inspection of the vehicle described below.',
                'intro_fr' => "Rapport d'inspection du véhicule décrit ci-dessous.",
                'fields' => array_merge($vehicle, [['inspection.inspector', 'Inspector', 'Inspecteur', 'D'], ['inspection.date_location', 'Date / location', 'Date / lieu', 'D'],
                    ['vehicle.odometer', 'Odometer', 'Compteur kilométrique', 'D'], ['inspection.condition', 'Condition', 'État', 'D'], ['inspection.damage', 'Damage', 'Dommages', 'D'],
                    ['inspection.photos', 'Photos', 'Photos', 'D'], ['vehicle.accessories', 'Accessories', 'Accessoires', 'D'],
                    ['inspection.identity_check', 'VIN / plate verification', 'Vérification VIN / immatriculation', 'D'], ['inspection.recommendation', 'Recommendation', 'Recommandation', 'D']]),
            ],
            'DOC-044' => [
                'intro_en' => 'Valuation report of the vehicle described below.',
                'intro_fr' => "Rapport d'évaluation du véhicule décrit ci-dessous.",
                'fields' => array_merge($vehicle, [['valuation.date', 'Valuation date', "Date d'évaluation", 'D'], ['valuation.method', 'Methodology / source', 'Méthode / source', 'D'],
                    ['inspection.condition', 'Condition', 'État', 'D'], ['valuation.value', 'Market / replacement value', 'Valeur vénale / de remplacement', 'D'],
                    ['valuation.assessor', 'Assessor', 'Évaluateur', 'D'], ['valuation.assumptions', 'Assumptions', 'Hypothèses', 'D'], ['valuation.valid_until', 'Report validity', 'Validité du rapport', 'D']]),
            ],
            'DOC-045' => [
                'intro_en' => 'Roadside assistance certificate for the vehicle described below.',
                'intro_fr' => "Certificat d'assistance routière pour le véhicule décrit ci-dessous.",
                'fields' => array_merge([$policy, $holder], $vehicle, [['assistance.package', 'Assistance package', "Formule d'assistance", 'D'], $from, $until, $territory,
                    ['assistance.contacts', 'Service contacts', "Contacts de l'assistance", 'D'], ['assistance.limits', 'Limits', 'Plafonds', 'D']]),
            ],
            'DOC-046' => [
                'fields' => [$holder, $policy, $plate, ['assistance.number', 'Assistance number', "N° d'assistance", 'D'], ['assistance.emergency_numbers', 'Emergency numbers', "Numéros d'urgence", 'D'], $from, $until],
            ],
            'DOC-047' => [
                'intro_en' => 'Schedule of the drivers declared under policy {policy_number}.',
                'intro_fr' => 'Liste des conducteurs déclarés au titre de la police {policy_number}.',
                'fields' => [$policy, ['driver.schedule', 'Drivers', 'Conducteurs', 'C', 'table', [
                    ['driver_id', 'Driver ID', 'Identifiant du conducteur'], ['licence', 'Licence information', 'Permis de conduire'], ['category', 'Category', 'Catégorie'],
                    ['assigned_vehicle', 'Assigned vehicle', 'Véhicule attribué'], ['effective_from', 'Effective from', "Date d'effet", 'date'],
                    ['effective_until', 'Effective until', "Date d'échéance", 'date'], ['status', 'Status', 'Statut']]], $from, $until],
            ],
            'DOC-048' => [
                'intro_en' => 'Endorsement {event} to policy {policy_number}: authorized driver.',
                'intro_fr' => 'Avenant {event} à la police {policy_number} : conducteur autorisé.',
                'fields' => [$policy, ['driver.name', 'Driver', 'Conducteur', 'C'], ['driver.licences', 'Licence / category', 'Permis / catégorie', 'C'],
                    ['driver.assigned_vehicles', 'Affected vehicle(s)', 'Véhicule(s) concerné(s)', 'C'], ['endorsement.effective_at', 'Effective date', "Date d'effet", 'D'],
                    ['endorsement.premium_delta', 'Premium impact', 'Incidence sur la prime', 'D'], $conditions],
            ],
            'DOC-049' => [
                'intro_en' => 'Endorsement {event} to policy {policy_number}: replacement of the insured vehicle.',
                'intro_fr' => 'Avenant {event} à la police {policy_number} : remplacement du véhicule assuré.',
                'fields' => array_merge([$policy, ['endorsement.old_vehicle', 'Old vehicle', 'Ancien véhicule', 'C']], $vehicle, [
                    ['endorsement.effective_at', 'Effective date', "Date d'effet", 'D'], ['endorsement.value_changes', 'Value changes', 'Modification de valeur', 'D'],
                    ['endorsement.premium_delta', 'Premium delta', 'Écart de prime', 'D'], ['coverage.lines', 'Revised coverage', 'Garanties révisées', 'D'],
                ]),
            ],
            'DOC-050' => [
                'intro_en' => 'Certificate of the territorial extension granted under policy {policy_number}.',
                'intro_fr' => "Certificat de l'extension territoriale accordée au titre de la police {policy_number}.",
                'fields' => array_merge([$policy], $vehicle, [['policy.territory', 'Existing territory', 'Territorialité actuelle', 'D'], ['extension.territory', 'Extended territory', 'Territorialité étendue', 'D'],
                    ['extension.period', 'Period', 'Période', 'D'], $conditions, ['extension.premium', 'Premium / fee', 'Prime / frais', 'D']]),
            ],
            'DOC-051' => [
                'intro_en' => 'Certificate of the motor coverage extension granted under policy {policy_number}.',
                'intro_fr' => "Certificat de l'extension de garantie automobile accordée au titre de la police {policy_number}.",
                'fields' => array_merge([$policy], $vehicle, [['extension.added_cover', 'Added cover', 'Garantie ajoutée', 'D'], ['extension.limits_deductibles', 'Limits / deductibles', 'Limites / franchises', 'D'],
                    ['extension.period', 'Period', 'Période', 'D'], ['extension.premium', 'Premium', 'Prime', 'D'], $conditions]),
            ],
            'DOC-052' => [
                'intro_en' => 'Certificate of the fleet insured under policy {policy_number}.',
                'intro_fr' => 'Certificat de la flotte assurée au titre de la police {policy_number}.',
                'fields' => [$policy, ['party.name', 'Organization', 'Organisation', 'C'], ['fleet.identifier', 'Fleet identifier', 'Identifiant de la flotte', 'C'],
                    ['fleet.vehicle_count', 'Number of vehicles', 'Nombre de véhicules', 'C'], $from, $until, ['coverage.lines', 'Aggregate cover summary', 'Résumé global des garanties', 'D']],
            ],
            'DOC-053' => [
                'intro_en' => 'Certificate of cancellation of the motor cover under policy {policy_number}.',
                'intro_fr' => 'Certificat de résiliation de la garantie automobile au titre de la police {policy_number}.',
                'fields' => array_merge([$policy], $vehicle, [['cancellation.reason', 'Cancellation reason', 'Motif de résiliation', 'D'],
                    ['cancellation.effective_at', 'Effective date and time', "Date et heure d'effet", 'D'], ['cancellation.refund_status', 'Premium / refund status', 'Statut prime / remboursement', 'D'],
                    ['coverage.status', 'Current cover status', 'Statut actuel de la couverture', 'D']]),
            ],
            'DOC-054' => [
                'intro_en' => 'Notice of reinstatement of the motor cover under policy {policy_number}.',
                'intro_fr' => 'Avis de remise en vigueur de la garantie automobile au titre de la police {policy_number}.',
                'fields' => array_merge([$policy], $vehicle, [['reinstatement.prior_reference', 'Suspension / cancellation reference', 'Référence de la suspension / résiliation', 'D'],
                    ['reinstatement.effective_at', 'Reinstatement date and time', 'Date et heure de remise en vigueur', 'D'],
                    ['reinstatement.conditions_fulfilled', 'Conditions fulfilled', 'Conditions remplies', 'D'], ['payment.status', 'Payment status', 'Statut du paiement', 'D']]),
            ],
            'DOC-055' => [
                'intro_en' => 'Replacement of the motor certificate referenced below.',
                'intro_fr' => 'Duplicata du certificat automobile référencé ci-dessous.',
                'fields' => array_merge([['lifecycle.original_reference', 'Original certificate', 'Certificat original', 'D'], ['security.stock_serial', 'Replacement serial', 'Série du duplicata', 'D'],
                    $reason, $policy], $vehicle, [['lifecycle.current_status', 'Original certificate status', 'Statut du certificat original', 'D']]),
            ],
        ];
    }
}
