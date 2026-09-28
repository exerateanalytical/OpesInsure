<?php

declare(strict_types=1);

// Actions de revue KYC (App\Filament\Shared\Actions\KycActions) et écran de revue KYC (KycSubmissionResource).
return [
    'group' => 'Revue KYC',

    'kycAttachDocument' => ['label' => 'Joindre un document', 'help' => 'Ajoute l\'un des documents du client à ce dossier KYC.', 'done' => 'Document joint'],
    'kycDeclareSources' => ['label' => 'Déclarer l\'origine des fonds / du patrimoine', 'help' => 'Enregistrez l\'origine des fonds et/ou l\'origine du patrimoine, avec les justificatifs.', 'done' => 'Origines déclarées',
        'missing' => 'Indiquez l\'origine des fonds et/ou l\'origine du patrimoine.'],
    'kycStartReview' => ['label' => 'Démarrer la revue', 'help' => 'Vous devenez l\'examinateur de ce dossier KYC.', 'done' => 'Revue démarrée'],
    'kycSetLevel' => ['label' => 'Modifier le niveau KYC', 'help' => 'Le niveau peut être relevé, mais jamais fixé en dessous du niveau fondé sur les risques.', 'done' => 'Niveau KYC modifié'],
    'kycAssessRisk' => ['label' => 'Évaluer le risque client', 'help' => 'Éléments saisis par l\'examinateur pour la notation du risque client.', 'done' => 'Évaluation du risque enregistrée',
        'too_many' => 'Trop de codes produits (50 max.) ou de facteurs déclarés (20 max.).'],
    'kycRecordScreening' => ['label' => 'Enregistrer le résultat du filtrage', 'help' => 'Enregistrez le résultat d\'un contrôle sanctions / PPE / listes de surveillance. Une correspondance possible ou confirmée porte le niveau à Renforcé.', 'done' => 'Résultat du filtrage enregistré'],
    'kycRequestInformation' => ['label' => 'Demander des informations', 'help' => 'Le client est invité à compléter le dossier ; toute recommandation en cours est annulée.', 'done' => 'Informations demandées'],
    'kycRecommend' => ['label' => 'Recommander une décision', 'help' => 'Étape de saisie : un autre utilisateur habilité à décider la confirme.', 'done' => 'Recommandation enregistrée'],
    'kycDecide' => ['label' => 'Décider', 'help' => 'Décision recommandée : :outcome. Confirmez-la ou renvoyez le dossier en revue.', 'done' => 'Décision enregistrée'],
    'kycRescreen' => ['label' => 'Relancer le filtrage', 'help' => 'Lance un nouveau cycle de filtrage sur ce KYC approuvé.', 'done' => 'Nouveau filtrage lancé'],
    'kycRemediate' => ['label' => 'Lancer une remédiation', 'help' => 'Crée un nouveau dossier KYC en brouillon ; celui-ci est conservé sans modification.', 'done' => 'Remédiation lancée'],

    'fields' => [
        'party' => 'Client',
        'subject_kind' => 'Type de personne',
        'kyc_level' => 'Niveau KYC',
        'status' => 'Statut',
        'screening_status' => 'Filtrage',
        'submitted_at' => 'Soumis le',
        'expires_at' => 'Expire le',
        'document' => 'Document',
        'purpose' => 'Objet',
        'source_of_funds' => 'Origine des fonds',
        'source_of_wealth' => 'Origine du patrimoine',
        'description' => 'Description',
        'origin' => 'Provenance',
        'evidence_documents' => 'Justificatifs',
        'reason' => 'Motif',
        'country_code' => 'Pays (code ISO)',
        'product_codes' => 'Codes produits',
        'channel' => 'Canal de distribution',
        'customer_type' => 'Type de client',
        'declared_factors' => 'Facteurs de risque déclarés',
        'check' => 'Contrôle de filtrage',
        'result' => 'Résultat',
        'list_reference' => 'Référence de la liste',
        'notes' => 'Notes',
        'outcome' => 'Décision recommandée',
        'rationale' => 'Justification',
        'confirm' => 'Confirmer la recommandation',
        'confirm_help' => 'Désactivez pour renvoyer plutôt le dossier en revue.',
    ],

    'codes' => [
        'level' => ['SIMPLIFIED' => 'Simplifié', 'STANDARD' => 'Standard', 'ENHANCED' => 'Renforcé'],
        'kind' => ['INDIVIDUAL' => 'Personne physique', 'CORPORATE' => 'Personne morale'],
        'check_status' => ['PENDING' => 'En attente', 'CLEAR' => 'Aucune correspondance', 'POSSIBLE_MATCH' => 'Correspondance possible', 'CONFIRMED_MATCH' => 'Correspondance confirmée'],
        'outcome' => ['APPROVE' => 'Approuver', 'REJECT' => 'Rejeter'],
    ],
];
