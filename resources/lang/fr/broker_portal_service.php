<?php

declare(strict_types=1);

// Portail courtier (/broker) : gestion des contrats et opérations (décision du 2026-09-29, portails en écriture).
return [
    'serviceRequest' => ['label' => 'Demande de gestion', 'help' => "Envoyer à l'assureur une demande d'avenant, de réédition de document ou autre demande de gestion pour cette police.", 'done' => 'Demande de gestion envoyée.'],
    'assistedClaim' => ['label' => 'Déclarer un sinistre pour un client', 'help' => "Déclarer un sinistre pour le compte d'un client de votre portefeuille. Le dossier suit le processus sinistre habituel.", 'done' => 'Sinistre déclaré.'],
    'inviteStaff' => ['label' => 'Inviter un collaborateur', 'help' => 'Envoyer une invitation à rejoindre votre cabinet. Vous ne pouvez attribuer que les rôles que vous êtes autorisé à accorder.', 'done' => 'Invitation créée.', 'code' => "Code d'invitation (à transmettre à l'invité)"],
    'renewalQuote' => ['label' => 'Recoter le renouvellement'],
    'renewalComplete' => ['label' => 'Lier la police de remplacement'],
    'fields' => [
        'type' => 'Type de demande',
        'reason' => 'Détails',
        'policy' => 'Police',
        'loss_occurred_at' => 'Date du sinistre',
        'loss_location' => 'Lieu du sinistre',
        'estimated_loss' => 'Perte estimée (unités mineures)',
        'description' => "Description de l'événement",
        'recipient_email' => 'E-mail',
        'recipient_phone' => 'Téléphone (E.164)',
        'role' => 'Rôle',
        'successor' => 'Police de remplacement',
    ],
    'types' => [
        'ENDORSEMENT' => 'Avenant',
        'CANCELLATION_REVIEW' => "Examen d'une résiliation",
        'ADDRESS_CHANGE' => "Changement d'adresse",
        'VEHICLE_CHANGE' => 'Changement de véhicule',
        'BENEFICIARY_CHANGE' => 'Changement de bénéficiaire',
        'DOCUMENT_REISSUE' => 'Réédition de certificat / document',
    ],
];
