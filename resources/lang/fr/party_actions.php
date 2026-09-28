<?php

declare(strict_types=1);

// Référentiel des tiers, enregistrement de clients par l'agent et génération des renouvellements (poste de travail).
return [
    'group' => 'Référentiel tiers',
    'matchScan' => ['label' => 'Rechercher les doublons', 'help' => 'Compare ce tiers au référentiel et liste les doublons probables à examiner.', 'done' => 'Recherche de doublons terminée.'],
    'matchDismiss' => ['label' => 'Écarter le doublon', 'help' => 'Atteste que les deux tiers sont des personnes ou des organisations distinctes.', 'done' => 'Doublon écarté.'],
    'addOwnership' => ['label' => 'Enregistrer une participation', 'help' => 'Enregistre une participation au capital, des droits de vote ou un contrôle détenus dans cette organisation.', 'done' => 'Participation enregistrée.'],
    'endOwnership' => ['label' => 'Clôturer une participation', 'done' => 'Participation clôturée.'],
    'mergeDecision' => ['label' => 'Statuer sur la fusion', 'help' => 'Approuver ou rejeter une demande de fusion en attente. Le validateur doit être distinct du demandeur.', 'done' => 'Décision de fusion enregistrée.'],
    'mergeUnmerge' => ['label' => 'Annuler la fusion', 'help' => 'Rétablit le tiers fusionné et lui réaffecte ses enregistrements.', 'done' => 'Fusion annulée.'],
    'registerClient' => ['label' => 'Enregistrer un client', 'help' => 'Enregistre un nouveau client personne physique avec son consentement confirmé.', 'done' => 'Client enregistré.'],
    'renewalSweep' => ['label' => 'Générer les dossiers de renouvellement', 'help' => 'Ouvre les dossiers de renouvellement des polices arrivant à échéance dans le nombre de jours choisi.', 'done' => 'Dossiers de renouvellement générés.'],
    'fields' => [
        'candidate' => 'Doublon potentiel',
        'owner' => 'Détenteur',
        'percentage' => 'Pourcentage',
        'interest_type' => 'Nature de la participation',
        'interest' => 'Participation',
        'merge' => 'Demande de fusion',
        'decision' => 'Décision',
        'full_name' => 'Nom complet',
        'phone' => 'Numéro de téléphone',
        'city' => 'Ville',
        'consent_confirmed' => 'Le client consent au traitement de ses données personnelles',
        'days_ahead' => 'Horizon (jours)',
    ],
    'interest_types' => ['SHAREHOLDING' => 'Participation au capital', 'VOTING' => 'Droits de vote', 'CONTROL' => 'Contrôle par d\'autres moyens'],
    'decisions' => ['APPROVED' => 'Approuver', 'REJECTED' => 'Rejeter'],
];
