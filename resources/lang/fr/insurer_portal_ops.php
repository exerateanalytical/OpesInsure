<?php

declare(strict_types=1);

// /insurer — réassurance, documents et organisation (agent portail P4, décision du propriétaire 2026-09-29).
return [
    'nav' => [
        'organisation' => 'Organisation',
        'invitations' => 'Invitations du personnel',
        'documents' => 'Documents',
        'document_register' => 'Registre des documents',
    ],
    'staff' => [
        'role_not_allowed' => 'Ce rôle ne peut pas être attribué depuis le portail assureur.',
    ],
    'branches' => [
        'add' => 'Ajouter une agence',
        'code' => 'Code',
        'name' => 'Nom',
        'phone' => 'Téléphone',
        'email' => 'E-mail',
        'duplicate' => 'Une agence avec ce code existe déjà.',
        'added' => 'Agence ajoutée',
    ],
    // Document register (GeneratedDocumentResource), shared by /admin and /insurer (R7 2026-09-29).
    'register' => [
        'revoke_replace' => 'Révoquer / remplacer',
        'action' => 'Action',
        'revoke' => 'Révoquer',
        'replace' => 'Remplacer',
        'cancel' => 'Annuler',
        'replacement' => 'Document de remplacement',
        'reason' => 'Motif',
        'tamper_identify' => "Contrôler l'intégrité d'un PDF",
        'tamper_check' => "Contrôle d'intégrité",
        'tamper_help' => "Le fichier est haché et comparé à l'original du registre et à sa signature plateforme. Le fichier n'est pas conservé.",
        'pdf_to_check' => 'PDF à contrôler',
        'number' => 'Numéro du document',
        'carrier_original' => 'original assureur',
        'type' => 'Type',
        'policy' => 'Police',
        'subject' => 'Objet',
        'issuer' => 'Émetteur',
        'carrier' => 'Assureur',
        'language' => 'Langue',
        'verification_code' => 'Code de vérification',
        'template_version' => 'V. modèle',
        'tier' => 'Niveau',
        'status' => 'Statut',
        'issuer_type' => "Type d'émetteur",
        'carrier_originals' => 'Originaux assureur',
        'upload' => 'Téléverser un document assureur',
        'document_type' => 'Type de document',
        'issue_date' => "Date d'émission",
        'carrier_document_number' => 'Numéro du document assureur',
        'carrier_version' => 'Version assureur',
        'subject_key' => 'Immatriculation / adhérent / expédition (facultatif)',
        'file' => 'Fichier',
        'history_tab' => 'Historique révocations / remplacements',
        'lookups_tab' => 'Consultations de vérification',
    ],
];
