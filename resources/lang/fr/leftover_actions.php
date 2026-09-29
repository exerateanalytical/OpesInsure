<?php

// S3 2026-09-29 : points d'entrée web des dernières actions API sans interface (réaffectation des renouvellements,
// espace de travail, documents du véhicule, profil agent). Même permission et même service que l'API.
return [
    'renewalReassign' => [
        'label' => 'Réaffecter',
        'help' => 'Confier ce dossier de renouvellement à un collègue qui traite les renouvellements, ou le laisser sans responsable.',
        'done' => 'Dossier de renouvellement réaffecté.',
    ],
    'workspaceSave' => [
        'label' => 'Mon espace de travail',
        'help' => 'Mémoriser ce portail pour vous : langue actuelle et page de démarrage souhaitée.',
        'done' => 'Espace de travail enregistré.',
    ],
    'fields' => [
        'assignee' => 'Responsable',
        'unassigned' => 'Non affecté',
        'reason' => 'Motif (facultatif)',
        'start_page' => 'Page de démarrage',
    ],
    'start_pages' => [
        'dashboard' => 'Tableau de bord',
        'reports' => 'Rapports',
        'search' => 'Recherche',
        'help' => 'Aide',
    ],
    'renewal' => [
        'assignee_invalid' => 'Choisissez un collègue actif de cette organisation autorisé à traiter les renouvellements.',
        'not_open' => 'Seul un dossier de renouvellement ouvert peut être réaffecté.',
        'unchanged' => 'Le dossier est déjà affecté ainsi.',
    ],
    'assets' => [
        'docs_t' => 'Documents du véhicule',
        'none' => 'Aucun document rattaché à ce véhicule pour le moment.',
        'purpose' => 'Document',
        'file' => 'Fichier (JPG, PNG ou PDF)',
        'attach' => 'Téléverser et rattacher',
        'pick_file' => "Choisissez d'abord un fichier.",
        'attached' => 'Document rattaché au véhicule.',
        'scan' => 'Lire les informations',
        'scanned' => 'Lecture demandée. Si les informations ne peuvent pas être lues automatiquement, confirmez-les vous-même.',
        'confirm' => 'Confirmer les informations',
        'confirm_help' => 'Saisissez les informations exactement comme sur le document. Notre équipe les vérifiera.',
        'confirm_save' => 'Enregistrer les informations',
        'confirmed' => 'Informations enregistrées. Notre équipe va vérifier le document.',
        'purposes' => [
            'VEHICLE_REGISTRATION' => 'Carte grise',
            'DRIVING_LICENCE' => 'Permis de conduire',
            'VEHICLE_PHOTO' => 'Photo du véhicule',
            'OTHER' => 'Autre document',
        ],
        'scan_status' => [
            'PENDING' => 'Contrôle de sécurité en attente',
            'CLEAN' => 'Contrôlé',
            'INFECTED' => 'Refusé (fichier dangereux)',
            'FAILED' => 'Échec du contrôle',
        ],
        'ocr' => [
            'MANUAL_REVIEW_REQUIRED' => 'Lecture automatique impossible',
            'CUSTOMER_CONFIRMED' => 'Informations confirmées par vous',
            'EXTRACTED' => 'Informations lues',
        ],
    ],
    'agent_profile' => [
        'title' => 'Profil agent',
        'help' => "Vos informations d'agent et votre numéro de paiement. Code agent :",
        'national_id' => "Numéro de CNI (laisser vide pour le conserver)",
        'momo' => 'Numéro mobile money pour les paiements',
        'code' => 'Code de sécurité (SMS)',
        'code_sent' => 'Un code de sécurité a été envoyé sur votre téléphone. Saisissez-le puis enregistrez à nouveau pour changer votre numéro de paiement.',
        'saved' => 'Profil agent enregistré.',
    ],
];
