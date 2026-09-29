<?php

// Intégrations → Activa Assurances (App\Filament\Admin\Pages\Integrations\ActivaIntegration). Miroir EN : resources/lang/en/activa_integration.php.
return [
    'nav' => 'Activa Assurances',
    'title' => 'Activa Assurances Cameroun — connexion API',
    'empty' => 'Rien n’a encore été envoyé à Activa.',
    'not_configured' => 'Saisissez la clé d’abonnement API Activa et les identifiants des services via « Identifiants ». Rien n’est envoyé à Activa d’ici là ; dès qu’Activa accepte les identifiants, les polices et paiements en attente sont synchronisés automatiquement.',
    'connection' => 'Connexion :environment',
    'set' => 'Renseignée',
    'missing' => 'Absente',
    'last_reference_sync' => 'Dernière synchro des référentiels',
    'last_reconciled' => 'Dernier rapprochement',
    'last_ok' => 'Dernier succès',
    'queue' => 'File de synchronisation',
    'queue_counts' => ':failures à corriger · :synced synchronisés · :calls appels (24 h)',
    'circuit_open' => 'Disjoncteur ouvert : appels suspendus brièvement après des échecs répétés.',
    'secret_help' => 'Écriture seule. Laissez vide pour conserver la valeur enregistrée.',
    'status' => [
        'CONFIG_REQUIRED' => 'Configuration requise',
        'PENDING_VERIFICATION' => 'Identifiants saisis, non vérifiés',
        'ACTIVE' => 'Active',
        'AUTH_FAILED' => 'Refusée par Activa (401) — en attente de l’approbation de l’abonnement par Activa ou d’identifiants corrigés',
        'DISABLED' => 'Désactivée',
    ],
    'health' => [
        'OK' => 'OK', 'CONFIG_REQUIRED' => 'Non configuré', 'PENDING_VERIFICATION' => 'Pas encore testé', 'AUTH_FAILED' => 'Connexion refusée',
        'SUBSCRIPTION_KEY_REJECTED' => 'Clé d’abonnement refusée', 'UNAVAILABLE' => 'Indisponible', 'INVALID_RESPONSE' => 'Réponse inattendue',
    ],
    'services' => ['travel' => 'Voyage (cmr-travel)', 'pricing' => 'Tarification (Tarifiktor)', 'subscription' => 'Souscription (Souscription CMR)', 'documents' => 'Documents (DocGenerator)'],
    'sections' => [
        'travel' => 'API Voyage — client OAuth', 'pricing' => 'Tarifiktor — identifiants', 'subscription' => 'Souscription CMR — identifiants', 'documents' => 'DocGenerator — identifiants',
        'settings' => 'Paramètres avancés (non secrets)',
    ],
    'fields' => [
        'carrier' => 'Assureur', 'environment' => 'Environnement', 'subscription_key' => 'Clé d’abonnement API (Ocp-Apim-Subscription-Key)',
        'service_subscription_key' => 'Clé d’abonnement propre à cette API (si différente)', 'client_id' => 'Client ID', 'client_secret' => 'Secret client', 'scope' => 'Scope',
        'email' => 'E-mail', 'user_id' => 'Identifiant', 'password' => 'Mot de passe', 'gateway_url' => 'URL de la passerelle', 'api_version' => 'Version d’API',
        'paths' => 'Chemins d’API (service → chemin)', 'intermediary' => 'Codes intermédiaire (code_intermediaire, codeinte, bureau, …)', 'categories' => 'Codes catégorie Activa (AUTO, MRH, SANTE, IA)',
        'travel' => 'Options voyage (agent_scope, category, language)', 'attestation' => 'Attestation (codtypdocument)', 'payment_modes' => 'Mode de paiement par opérateur (mtn_momo → code)',
        'disabled' => 'Désactiver cette connexion', 'include_failed' => 'Relancer aussi les éléments en échec / à mapper',
    ],
    'columns' => [
        'operation' => 'Étape', 'subject_type' => 'Enregistrement', 'status' => 'Statut', 'attempts' => 'Tentatives', 'external_reference' => 'Référence Activa',
        'last_error_code' => 'Dernière erreur', 'next_attempt_at' => 'Prochaine tentative', 'updated_at' => 'Mis à jour',
    ],
    'configure' => ['label' => 'Identifiants', 'help' => 'Stockés chiffrés. Les secrets ne sont jamais réaffichés ; laissez un secret vide pour le conserver.', 'done' => 'Connexion Activa enregistrée.'],
    'testConnection' => ['label' => 'Tester la connexion', 'help' => 'Appelle l’authentification de chaque service configuré et indique OK ou le statut HTTP (ex. 401).', 'done' => 'Connexion testée.'],
    'referenceSync' => ['label' => 'Synchroniser les référentiels', 'help' => 'Copie les données de référence d’Activa (chaque jour à 01h30).', 'done' => 'Synchronisation des référentiels terminée.',
        'summary' => 'Reçus :received · nouveaux :created · modifiés :updated · inchangés :unchanged · retirés :retired · mappés :mapped :errors'],
    'reconcile' => ['label' => 'Lancer le rapprochement', 'help' => 'Envoie les polices émises et les paiements encaissés qu’Activa ne détient pas encore (toutes les 10 minutes).', 'done' => 'Rapprochement terminé.',
        'summary' => 'Polices :policies · paiements :payments · annulées :cancelled · modifiées :updated · sondages :probed'],
    'retry' => ['label' => 'Relancer', 'help' => 'Relance cette étape maintenant.', 'done' => 'Relancé.'],
];
