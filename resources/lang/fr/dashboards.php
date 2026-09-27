<?php

// Widgets des tableaux de bord admin / assureur / courtier et écran Rapports partagé (web).
return [
    'widgets' => [
        'premium_collected' => 'Primes encaissées (6 derniers mois)',
        'my_work' => 'Ma file de travail',
        'expiring_policies' => 'Polices expirant sous 30 jours',
        'open_claims' => 'Sinistres ouverts',
        'recent_activity' => 'Activité récente',
    ],
    'columns' => [
        'policy_number' => 'Police', 'status' => 'Statut', 'coverage_ends_at' => 'Fin', 'premium' => 'Prime', 'currency' => 'Devise',
        'claim_number' => 'Sinistre', 'reserve' => 'Provision', 'created_at' => 'Déclaré le', 'title' => 'Élément', 'kind' => 'Type', 'due_at' => 'Échéance',
        'action' => 'Action', 'subject' => 'Enregistrement', 'actor' => 'Par', 'when' => 'Quand',
    ],
    'metrics' => [
        'premium_collected_30d' => 'Primes encaissées (30 jours)', 'receivables_outstanding' => 'Créances en cours',
        'receivables_overdue' => 'Créances échues', 'commissions_pending' => 'Commissions en attente', 'refunds_open' => 'Remboursements à traiter',
    ],
    'states' => [
        'empty' => 'Rien à afficher.',
        'error' => 'Ces informations n’ont pas pu être chargées. Réessayez plus tard.',
        'loading' => 'Chargement…',
    ],
    'reports' => [
        'title' => 'Rapports',
        'report' => 'Rapport',
        'from' => 'Du',
        'to' => 'Au',
        'currency' => 'Devise',
        'as_of' => 'Arrêté au',
        'export_csv' => 'Exporter CSV',
        'insurance_portfolio' => 'Synthèse du portefeuille',
        'renewals' => 'Renouvellements par statut',
        'not_available' => 'non disponible',
        'kpi' => 'Enregistrements KPI',
        'rows' => '{1} :count ligne|[2,*] :count lignes',
        'truncated' => 'la liste est tronquée ; réduisez la période',
    ],
];
