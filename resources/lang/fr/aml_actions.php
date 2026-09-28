<?php

return [
    'nav' => [
        'hits' => 'Alertes de filtrage LBC/FT',
        'lists' => 'Listes de filtrage',
        'monitoring' => 'Surveillance des opérations',
        'str' => 'Déclarations de soupçon',
    ],
    'empty' => 'Aucun élément à afficher',
    'monitoring_inactive' => 'Aucune règle active : la surveillance des opérations reste inactive tant qu\'aucune règle n\'est configurée',

    'screenParty' => ['label' => 'Filtrer la personne', 'help' => 'Filtrer ce client contre les listes actives (personnes politiquement exposées, listes de sanctions, listes de surveillance).', 'done' => 'Filtrage effectué'],
    'riskRate' => ['label' => 'Évaluer le risque LBC/FT', 'help' => 'Calculer la cotation du risque LBC/FT du client. Une cotation ÉLEVÉE ouvre un dossier de vigilance renforcée.', 'done' => 'Risque LBC/FT évalué'],
    'hitPropose' => ['label' => 'Proposer une qualification', 'help' => 'Proposer une qualification de cette correspondance ; un second agent doit la valider.', 'done' => 'Qualification proposée'],
    'hitDecide' => ['label' => 'Statuer sur la qualification', 'help' => 'Valider ou rejeter la qualification proposée (principe des quatre yeux).', 'done' => 'Décision enregistrée'],
    'listCreate' => ['label' => 'Nouvelle liste de filtrage', 'help' => 'Enregistrer une source : personnes politiquement exposées, liste de sanctions ou liste de surveillance.', 'done' => 'Liste de filtrage créée'],
    'listImport' => ['label' => 'Importer une version', 'help' => 'Importer une nouvelle version de la liste. Elle n\'est utilisée pour le filtrage qu\'après validation par un second agent.', 'done' => 'Version importée'],
    'listVersionDecide' => ['label' => 'Statuer sur la version', 'help' => 'Valider (activer) ou rejeter cette version importée (principe des quatre yeux).', 'done' => 'Décision enregistrée'],
    'monitorEvaluate' => ['label' => 'Évaluer une opération', 'help' => 'Évaluer une opération au regard des règles de surveillance actives.', 'done' => 'Opération évaluée',
        'result' => 'Résultat : :status, :alerts alerte(s)'],
    'strDraft' => ['label' => 'Rédiger une DOS', 'help' => 'Confidentiel. Ne jamais en informer le client (interdiction de divulgation).', 'done' => 'DOS rédigée'],
    'strSubmit' => ['label' => 'Transmettre la DOS', 'help' => 'Enregistrer la transmission à la cellule de renseignement financier (ANIF). Le transmetteur doit être différent du rédacteur.', 'done' => 'DOS transmise'],

    'fields' => [
        'country_code' => 'Pays (code ISO)', 'product_codes' => 'Codes produits', 'channel' => 'Canal', 'customer_type' => 'Type de client', 'reason' => 'Motif',
        'disposition' => 'Qualification', 'rationale' => 'Justification', 'decision' => 'Décision', 'note' => 'Note',
        'code' => 'Code', 'name' => 'Nom', 'list_type' => 'Type de liste', 'publisher' => 'Éditeur',
        'source' => 'Liste', 'csv_content' => 'Contenu CSV', 'csv_help' => 'Ligne d\'en-tête : entry_ref,name,aliases,date_of_birth,country (alias séparés par |).',
        'source_reference' => 'Référence de la source', 'subject_type' => 'Type d\'objet', 'subject_id' => 'Identifiant de l\'objet', 'party_id' => 'Identifiant de la personne',
        'facts' => 'Données', 'fact' => 'Donnée', 'value' => 'Valeur', 'party' => 'Client', 'grounds' => 'Motifs du soupçon', 'regulator_reference' => 'Référence du régulateur',
    ],
    'columns' => [
        'party' => 'Client', 'matched_name' => 'Nom correspondant', 'list_type' => 'Type de liste', 'entry_ref' => 'Référence de l\'entrée', 'score' => 'Score', 'status' => 'Statut',
        'disposition' => 'Qualification', 'created_at' => 'Créé le', 'source' => 'Liste', 'version' => 'Version', 'format' => 'Format', 'entry_count' => 'Entrées',
        'source_reference' => 'Référence de la source', 'activated_at' => 'Activée le', 'grounds' => 'Motifs', 'regulator_reference' => 'Référence du régulateur',
        'submitted_at' => 'Transmise le', 'rule' => 'Règle', 'risk_points' => 'Points de risque', 'conditions' => 'Conditions', 'effective_from' => 'En vigueur du', 'effective_until' => 'En vigueur jusqu\'au',
    ],
    'codes' => [
        'customer_type' => ['INDIVIDUAL' => 'Personne physique', 'CORPORATE' => 'Personne morale'],
        'disposition' => ['FALSE_POSITIVE' => 'Faux positif', 'TRUE_MATCH' => 'Correspondance avérée', 'ESCALATED' => 'Remontée'],
        'decision' => ['APPROVE' => 'Valider', 'REJECT' => 'Rejeter'],
        'list_type' => ['PEP' => 'Personnes politiquement exposées', 'SANCTIONS' => 'Liste de sanctions (gel des avoirs)', 'WATCHLIST' => 'Liste de surveillance'],
        'subject_type' => ['PAYMENT' => 'Paiement', 'POLICY' => 'Police', 'CLAIM' => 'Sinistre', 'COMMISSION' => 'Commission', 'REFUND' => 'Remboursement'],
        'hit_status' => ['OPEN' => 'Ouverte', 'PROPOSED' => 'Proposée', 'DISPOSED' => 'Qualifiée'],
        'version_status' => ['PENDING_APPROVAL' => 'En attente de validation', 'ACTIVE' => 'Active', 'SUPERSEDED' => 'Remplacée', 'REJECTED' => 'Rejetée'],
        'str_status' => ['DRAFT' => 'Brouillon', 'SUBMITTED' => 'Transmise'],
    ],
];
