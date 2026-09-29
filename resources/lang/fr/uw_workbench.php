<?php

// Q8 poste de travail souscription (UND-001..020).
return [
    'dashboard' => 'Tableau de bord souscription',
    'assigned_cases' => 'Mes dossiers assignés',
    'view_all' => 'Tout voir',
    'by_status' => 'Dossiers par statut',
    'performance' => 'Performance et délais (SLA)',
    'empty' => 'Rien à afficher pour le moment',
    'yes' => 'Oui',
    'no' => 'Non',
    'restricted' => 'Restreint',
    'medical_withheld' => 'Information médicale masquée',
    'medical_masked' => ':count élément(s) médical(aux) masqué(s) : votre rôle n\'a pas accès aux documents médicaux.',
    'documents_withheld' => ':count document(s) masqué(s) selon le niveau de sécurité documentaire.',
    'not_evaluated' => 'Le dossier n\'a pas encore été évalué par le moteur de règles. Utilisez « Évaluer » pour calculer la recommandation et le score de risque.',
    'no_information_request' => 'Aucune information complémentaire n\'a été demandée sur cette proposition.',
    'coverage_changes_note' => 'Les modifications de garanties ou de prime passent par une contre-proposition ou des conditions sur la décision ; les conditions offertes ne sont jamais modifiées sur place.',
    'requirements_note' => 'Les exigences d\'inspection et médicales sont émises via « Demander des informations » (type Inspection ou Médical) et renvoyées par le proposant.',
    'kind_not_allowed' => 'Vous ne pouvez pas demander ce type d\'élément.',

    'sections' => ['case' => 'Dossier de souscription'],

    'tabs' => [
        'profile' => 'Proposant et risque', 'policy_history' => 'Historique des polices', 'claims_history' => 'Historique des sinistres', 'questionnaire' => 'Questionnaire',
        'documents' => 'Pièces justificatives', 'risk_assessment' => 'Évaluation du risque', 'risk_score' => 'Score de risque', 'coverage' => 'Garanties',
        'pricing' => 'Tarification et prime', 'information_request' => 'Demande d\'informations', 'requirements' => 'Inspection et médical', 'supervisor' => 'Approbation du superviseur',
    ],

    'list' => ['all' => 'Tous', 'mine' => 'Assignés à moi', 'unassigned' => 'Non assignés', 'awaiting' => 'En attente d\'informations', 'overdue' => 'En retard'],

    'kpi' => [
        'open' => 'Dossiers ouverts', 'unassigned' => 'Non assignés', 'mine' => 'Assignés à moi', 'awaiting_information' => 'En attente d\'informations',
        'decision_pending' => 'Décision en attente', 'open_referrals' => 'Renvois ouverts', 'overdue' => 'En retard',
    ],

    'perf' => [
        'period' => 'Période', 'last_days' => ':days derniers jours', 'decisions' => 'Décisions', 'avg_turnaround' => 'Délai moyen de traitement',
        'sla_met' => 'Décidés dans le délai', 'overdue_open' => 'Dossiers ouverts hors délai', 'by_underwriter' => 'Par souscripteur',
    ],

    'kinds' => ['DOCUMENT' => 'Document', 'ANSWER' => 'Réponse', 'CLARIFICATION' => 'Précision', 'INSPECTION' => 'Inspection', 'MEDICAL' => 'Médical'],

    'fields' => ['items' => 'Éléments demandés', 'kind' => 'Type', 'code' => 'Code (MAJUSCULES_SOULIGNÉ)', 'description' => 'Description', 'mandatory' => 'Obligatoire', 'message' => 'Message au proposant'],

    'uwRequestInformation' => [
        'label' => 'Demander des informations',
        'help' => 'Demandez au proposant des éléments précis : documents, réponses, précisions, inspection ou exigences médicales. Le dossier attend sa réponse.',
        'done' => 'Informations demandées',
    ],

    'f' => [
        'applicant' => 'Proposant', 'party_type' => 'Type de tiers', 'party_status' => 'Statut du tiers', 'identity' => 'Identité', 'profile' => 'Profil',
        'proposal' => 'Proposition', 'product' => 'Produit', 'line' => 'Branche', 'priority' => 'Priorité', 'referral_reasons' => 'Motifs de renvoi',
        'risk_details' => 'Détails du risque', 'item' => 'Élément', 'value' => 'Valeur', 'status' => 'Statut', 'carrier' => 'Assureur',
        'policies_total' => 'Polices', 'policies_active' => 'Polices actives', 'policy_history' => 'Polices détenues', 'policy_number' => 'N° de police',
        'coverage_starts_at' => 'Début de garantie', 'coverage_ends_at' => 'Fin de garantie', 'premium' => 'Prime',
        'claims_total' => 'Sinistres', 'claims_open' => 'Sinistres ouverts', 'claims_approved_total' => 'Total approuvé', 'claims_history' => 'Sinistres',
        'claim_number' => 'N° de sinistre', 'loss_occurred_at' => 'Date du sinistre', 'estimated_loss' => 'Perte estimée', 'approved_amount' => 'Montant approuvé',
        'question_set' => 'Questionnaire', 'attested_at' => 'Attesté le', 'referral_flags' => 'Indicateurs de renvoi', 'questionnaire' => 'Réponses',
        'question' => 'Question', 'answer' => 'Réponse', 'required' => 'Obligatoire',
        'documents_total' => 'Documents', 'documents_verified' => 'Vérifiés', 'supporting_documents' => 'Documents requis', 'requirement' => 'Exigence',
        'document' => 'Document', 'scan' => 'Analyse', 'verified_at' => 'Vérifié le', 'notes' => 'Notes',
        'state' => 'État', 'recommendation' => 'Recommandation du système', 'risk_band' => 'Classe de risque', 'risk_score' => 'Score de risque', 'evaluated_at' => 'Évalué le',
        'engine_evaluation_id' => 'Évaluation', 'rule_set_versions' => 'Versions des règles', 'available_events' => 'Actions disponibles', 'referrals' => 'Renvois',
        'reason_code' => 'Motif', 'severity' => 'Gravité', 'due_at' => 'Échéance', 'resolved_at' => 'Résolu le', 'resolution_notes' => 'Notes de résolution',
        'factors_fired' => 'Facteurs déclenchés', 'score_factors' => 'Facteurs de score', 'factor' => 'Facteur', 'source' => 'Source', 'weight' => 'Poids', 'fired' => 'Déclenché',
        'contribution' => 'Contribution', 'input' => 'Entrée',
        'cover_terms' => 'Conditions de garantie convenues', 'decision_conditions' => 'Conditions de la décision', 'coverage' => 'Garanties offertes', 'cover' => 'Garantie', 'detail' => 'Détail',
        'tax' => 'Taxes', 'fees' => 'Frais', 'total' => 'Total', 'original_premium' => 'Prime initiale (avant dérogation)', 'premium_override' => 'Dérogation de prime',
        'tariff_version' => 'Version du tarif', 'valid_until' => 'Offre valable jusqu\'au', 'premium_breakdown' => 'Calcul de la prime', 'component' => 'Composante', 'amount' => 'Montant',
        'requested_at' => 'Demandé le', 'requested_by' => 'Demandé par', 'responded_at' => 'Répondu le', 'message' => 'Message', 'requested_items' => 'Éléments demandés',
        'code' => 'Code', 'kind' => 'Type', 'description' => 'Description', 'mandatory' => 'Obligatoire',
        'inspections' => 'Inspections', 'medical' => 'Exigences médicales', 'requirements' => 'Exigences d\'inspection et médicales',
        'open_referrals' => 'Renvois ouverts', 'pending_approvals' => 'Approbations en attente', 'outcome' => 'Issue', 'approval_requests' => 'Demandes d\'approbation',
        'action_code' => 'Action', 'decided_by' => 'Décidé par', 'decided_at' => 'Décidé le', 'reason' => 'Motif', 'decisions' => 'Historique des décisions',
        'system_recommendation' => 'Recommandation du système', 'agrees' => 'Conforme au système',
        'decision_due_at' => 'Décision attendue', 'underwriter' => 'Souscripteur', 'avg_hours' => 'Heures moy.', 'approved' => 'Approuvés', 'declined' => 'Refusés', 'agreement' => 'Concordance avec le système',
    ],
];
