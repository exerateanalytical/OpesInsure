<?php

return [
    'footer' => [
        'release' => 'Version OpesInsure :release',
    ],
    'errors' => [
        'nav' => 'Erreurs',
        'title' => 'Erreurs',
        'subtitle' => 'Exceptions non gérées regroupées par empreinte. Aucun contenu de requête ni donnée personnelle n\'est conservé. Une erreur résolue qui se reproduit est rouverte.',
        'empty' => 'Aucune erreur enregistrée.',
        'columns' => [
            'status' => 'Statut',
            'exception' => 'Exception',
            'occurrences' => 'Nombre',
            'route' => 'Route',
            'location' => 'Emplacement',
            'first_seen' => 'Première occurrence',
            'last_seen' => 'Dernière occurrence',
            'release' => 'Version',
        ],
        'status' => [
            'OPEN' => 'Ouverte',
            'RESOLVED' => 'Résolue',
            'IGNORED' => 'Ignorée',
        ],
        'actions' => [
            'errorResolve' => 'Résoudre',
            'errorIgnore' => 'Ignorer',
            'errorReopen' => 'Rouvrir',
            'done' => 'Erreur mise à jour.',
        ],
    ],
    'alert_mail' => [
        'subject_firing' => '[:severity] :title — :host',
        'subject_resolved' => '[RÉSOLU] :title — :host',
        'footer' => 'Version :release sur :host à :time. Vous recevez ce message car vous détenez operations.alerts.receive.',
    ],
    'alerts' => [
        'database' => ['title' => 'Erreurs de connexion à la base de données', 'body' => 'Base de données joignable : :reachable. Erreurs de connexion sur les :minutes dernières min : :errors.'],
        'error_spike' => ['title' => 'Pic d\'erreurs', 'body' => ':count erreurs non gérées sur les 5 dernières minutes (seuil :threshold). Voir Opérations → Erreurs.'],
        'failed_jobs' => ['title' => 'Tâches en échec en hausse', 'body' => ':count tâches en échec sur les :minutes dernières min. Voir Opérations → Tâches en échec.'],
        'queue_backlog' => ['title' => 'File d\'attente saturée', 'body' => ':count tâches en attente ; la plus ancienne attend depuis :age min. Vérifiez le worker.'],
        'scheduler_heartbeat' => ['title' => 'Battement du planificateur absent', 'body' => 'Dernier battement du planificateur : il y a :age min. Les tâches planifiées (paiements, renouvellements, alertes) peuvent être arrêtées.'],
        'payment_auth_failures' => ['title' => 'Échecs d\'authentification du prestataire de paiement', 'body' => ':count échecs d\'authentification sur les :minutes dernières min (:providers). Vérifiez les identifiants du prestataire.'],
        'carrier_api_failures' => ['title' => 'Taux d\'échec de l\'API assureur', 'body' => 'Appels API assureur en échec sur les :minutes dernières min : :carriers (pire :rate %).'],
        'scanner_backlog' => ['title' => 'Antivirus indisponible — fichiers en attente', 'body' => ':count fichiers retenus car l\'antivirus est indisponible ; le plus ancien depuis :age min.'],
        'disk_space' => ['title' => 'Espace disque faible', 'body' => 'Seulement :percent % libre (:free_gb Go) sur le disque de stockage.'],
    ],
];
