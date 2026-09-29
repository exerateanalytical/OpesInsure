<?php

// Lancement 2026-10-02 (agent P10) : écrans internes en lecture seule (voir docs/LAUNCH_SCREEN_GAPS_2026-09-29.md).
return [
    'nav' => [
        'system_health' => 'État du système',
        'backup_recovery' => 'Sauvegarde et restauration',
        'finance_exceptions' => 'Anomalies financières',
        'account_statements' => 'Relevés de compte',
        'audit_trail' => 'Piste d’audit',
        'login_activity' => 'Historique des connexions',
    ],
    'intro' => [
        'system_health' => 'Contrôles en direct de la base de données, de la file d’attente, du planificateur, du stockage, de l’e-mail et des SMS.',
        'backup_recovery' => 'Exercices de restauration des sauvegardes, du plus récent au plus ancien. Objectifs : RPO :rpo min, RTO :rto min.',
        'finance_exceptions' => 'Anomalies financières ouvertes pour cette organisation, par origine.',
        'account_statements' => 'Choisissez un client, un courtier, un agent ou un assureur et une période pour établir son relevé de compte.',
        'audit_trail' => 'Les 200 dernières entrées d’audit de cette organisation.',
        'login_activity' => 'Connexions récentes des membres de cette organisation. Les adresses IP ne sont jamais affichées.',
    ],
    'columns' => [
        'check' => 'Contrôle', 'status' => 'Statut', 'details' => 'Détails', 'source' => 'Origine', 'count' => 'Éléments ouverts',
        'amounts' => 'Montants', 'breakdown' => 'Répartition', 'available' => 'Disponible', 'sequence' => 'N°',
        'occurred_at' => 'Date', 'action' => 'Action', 'subject_type' => 'Type d’enregistrement', 'subject_id' => 'Enregistrement',
        'actor_id' => 'Utilisateur', 'correlation_id' => 'Corrélation', 'user' => 'Utilisateur', 'method' => 'Méthode', 'device' => 'Appareil',
        'platform' => 'Plateforme', 'country' => 'Pays', 'new_device' => 'Nouvel appareil', 'anomaly_flags' => 'Alertes',
        'environment' => 'Environnement', 'rto' => 'RTO (réel / cible, min)', 'rpo' => 'RPO (réel / cible, min)',
        'subject' => 'Titulaire du compte', 'from' => 'Du', 'to' => 'Au', 'currency' => 'Devise', 'line_type' => 'Type',
        'description' => 'Libellé', 'reference' => 'Référence', 'amount' => 'Montant', 'balance' => 'Solde',
    ],
    'checks' => [
        'database' => 'Base de données', 'queue' => 'File d’attente', 'scheduler' => 'Planificateur', 'storage' => 'Stockage des fichiers', 'mail' => 'E-mail', 'sms' => 'SMS',
        'cache' => 'Cache',
    ],
    'sources' => [
        'issuance_exceptions' => 'Anomalies d’émission',
        'reconciliation_exceptions' => 'Écarts de rapprochement',
        'refunds_awaiting_action' => 'Remboursements en attente',
        'clearing_variances' => 'Écarts de compensation',
        'overdue_obligations' => 'Créances échues',
        'cashier_sessions_awaiting_approval' => 'Sessions de caisse à valider',
    ],
    'subjects' => ['customer' => 'Client', 'broker' => 'Courtier', 'agent' => 'Agent', 'carrier' => 'Assureur'],
    'actions' => ['build_statement' => 'Établir le relevé'],
    'statement_summary' => ':number — :name, du :from au :to. Solde d’ouverture :opening, solde de clôture :closing.',
    'statement_empty' => 'Aucun relevé pour l’instant. Utilisez « Établir le relevé » ci-dessus.',
    'empty' => 'Rien à afficher.',
    'yes' => 'Oui',
    'no' => 'Non',
];
