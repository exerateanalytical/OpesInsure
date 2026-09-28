<?php

declare(strict_types=1);

/** Actions conformité et confiance (App\Filament\Shared\Actions\ComplianceActions, page GovernanceRegisters). */
return [
    'case_group' => 'Actions sur le dossier',
    'caseOpen' => ['label' => 'Ouvrir un dossier de conformité', 'help' => 'Ouvre un dossier de conformité dans le moteur de dossiers pour le sujet sélectionné.', 'done' => 'Dossier de conformité ouvert.'],
    'caseLinkEvidence' => ['label' => 'Rattacher une pièce justificative', 'help' => 'Rattachez un document ou une référence externe comme pièce justificative. Un document ou une référence est obligatoire.', 'done' => 'Pièce justificative rattachée.'],
    'caseAddFinding' => ['label' => 'Ajouter un constat', 'help' => 'Enregistrez un constat sur ce dossier. Un dossier clôturé doit d\'abord être rouvert.', 'done' => 'Constat enregistré.'],
    'findingWithdraw' => ['label' => 'Retirer un constat', 'help' => 'Le retrait d\'un constat annule ses actions correctives en cours.', 'done' => 'Constat retiré.'],
    'findingPlanAction' => ['label' => 'Planifier une action corrective', 'help' => 'Planifiez une action corrective sur un constat ouvert ; une tâche est ajoutée au dossier de travail du responsable.', 'done' => 'Action corrective planifiée.'],
    'actionEvent' => ['label' => 'Mettre à jour l\'action corrective', 'help' => 'Démarrez, achevez ou annulez une action corrective. L\'annulation doit être motivée.', 'done' => 'Action corrective mise à jour.'],
    'actionVerify' => ['label' => 'Vérifier l\'action corrective', 'help' => 'La vérification doit être effectuée par une personne autre que celle qui a achevé l\'action.', 'done' => 'Vérification enregistrée.'],
    'dsrReceive' => ['label' => 'Enregistrer une demande de personne concernée', 'help' => 'Enregistre une demande d\'exercice de droits reçue d\'un client.', 'done' => 'Demande de personne concernée enregistrée.'],
    'accessRequest' => ['label' => 'Demander un accès privilégié', 'help' => 'Crée une demande d\'accès privilégié limitée dans le temps. Un autre agent doit l\'approuver.', 'done' => 'Accès privilégié demandé.'],
    'fraudAlert' => ['label' => 'Émettre une alerte de fraude', 'help' => 'Choisissez une règle antifraude active, ou laissez vide et indiquez un score de risque manuel.', 'done' => 'Alerte de fraude émise.'],
    'reportPrepare' => ['label' => 'Préparer une déclaration', 'help' => 'Prépare une déclaration réglementaire à partir d\'une définition de rapport active.', 'done' => 'Déclaration préparée.'],
    'reportAcknowledge' => ['label' => 'Enregistrer l\'accusé de réception', 'help' => 'Saisissez la référence de l\'accusé de réception du régulateur.', 'done' => 'Accusé de réception enregistré.'],
    'reportFail' => ['label' => 'Enregistrer un échec de transmission', 'help' => 'La déclaration repasse en attente de nouvelle tentative.', 'done' => 'Échec de transmission enregistré.'],
    'governanceCreate' => ['label' => 'Ajouter une entrée', 'help' => 'Ajoute une entrée au registre de gouvernance sélectionné.', 'done' => 'Entrée ajoutée au registre.'],
    'governanceUpdate' => ['label' => 'Modifier l\'entrée', 'help' => 'La modification d\'un plan de sortie approuvé le repasse en brouillon pour une nouvelle approbation.', 'done' => 'Entrée du registre mise à jour.'],
    'exitPlanApprove' => ['label' => 'Approuver le plan de sortie', 'help' => 'L\'approbateur doit être différent de la personne qui a préparé le plan.', 'done' => 'Plan de sortie approuvé.'],

    'governance' => [
        'title' => 'Registres de gouvernance',
        'switch' => 'Registre',
        'registers' => [
            'ict-assets' => 'Actifs TIC',
            'ict-incidents' => 'Incidents TIC',
            'vendors' => 'Prestataires',
            'outsourcing-contracts' => 'Contrats d\'externalisation',
            'due-diligence-reviews' => 'Revues de due diligence',
            'exit-plans' => 'Plans de sortie',
        ],
    ],

    'sections' => ['findings' => 'Constats', 'corrective_actions' => 'Actions correctives'],

    'fields' => [
        'type' => 'Type', 'subject_type' => 'Type de sujet', 'subject_id' => 'Identifiant du sujet', 'severity' => 'Gravité', 'owner_id' => 'Responsable',
        'review_due_on' => 'Revue prévue le', 'description' => 'Description', 'document_id' => 'Identifiant du document', 'external_reference' => 'Référence externe',
        'finding_id' => 'Constat', 'corrective_action_id' => 'Action corrective', 'title' => 'Intitulé', 'category' => 'Catégorie', 'reason' => 'Motif',
        'owner_user_id' => 'Responsable', 'due_on' => 'Échéance', 'event' => 'Événement', 'notes' => 'Observations', 'accepted' => 'Acceptée',
        'party_id' => 'Client', 'assigned_to' => 'Attribuée à', 'user_id' => 'Utilisateur', 'purpose' => 'Finalité', 'justification' => 'Justification',
        'starts_at' => 'Début', 'expires_at' => 'Expiration', 'scope' => 'Périmètre', 'alert_type' => 'Type d\'alerte', 'fraud_rule_version_id' => 'Règle antifraude',
        'risk_score' => 'Score de risque', 'signals' => 'Signaux', 'review_due_at' => 'Revue prévue le', 'definition_id' => 'Définition du rapport',
        'period_key' => 'Période', 'payload' => 'Données du rapport', 'status' => 'Statut', 'created_at' => 'Créé le', 'version' => 'Version',
        'asset_code' => 'Code de l\'actif', 'name' => 'Nom', 'criticality' => 'Criticité', 'vendor_id' => 'Prestataire', 'ict_asset_id' => 'Actif TIC',
        'detected_at' => 'Détecté le', 'resolved_at' => 'Résolu le', 'reported_externally_at' => 'Déclaré à l\'extérieur le',
        'compliance_case_id' => 'Dossier de conformité', 'vendor_code' => 'Code prestataire', 'services' => 'Services', 'is_outsourcing' => 'Externalisation',
        'risk_rating' => 'Notation du risque', 'contract_reference' => 'Référence du contrat', 'service_description' => 'Description du service',
        'start_on' => 'Début le', 'end_on' => 'Fin le', 'contract_id' => 'Contrat', 'review_date' => 'Date de revue', 'outcome' => 'Conclusion',
        'next_review_on' => 'Prochaine revue le', 'summary' => 'Synthèse', 'last_tested_on' => 'Dernier test le', 'incident_number' => 'Numéro d\'incident',
        'key' => 'Clé', 'value' => 'Valeur',
    ],

    'options' => [
        'severity' => ['LOW' => 'Faible', 'MEDIUM' => 'Moyenne', 'HIGH' => 'Élevée', 'CRITICAL' => 'Critique'],
        'event' => ['start' => 'Démarrer', 'complete' => 'Achever', 'cancel' => 'Annuler'],
        'dsr_type' => ['ACCESS' => 'Accès', 'CORRECTION' => 'Rectification', 'ERASURE' => 'Effacement', 'RESTRICTION' => 'Limitation', 'PORTABILITY' => 'Portabilité', 'OBJECTION' => 'Opposition'],
        'subject_type' => ['PAYMENT' => 'Paiement', 'QUOTE' => 'Devis', 'POLICY' => 'Police', 'CLAIM' => 'Sinistre', 'COMMISSION' => 'Commission', 'USER' => 'Utilisateur'],
        'outcome' => ['SATISFACTORY' => 'Satisfaisante', 'CONDITIONAL' => 'Sous réserve', 'UNSATISFACTORY' => 'Insatisfaisante'],
    ],
];
