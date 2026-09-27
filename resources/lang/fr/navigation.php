<?php

declare(strict_types=1);

/* Libellés de la barre latérale (voir resources/lang/en/navigation.php pour les règles de clé). */
return [
    'groups' => [
        'Administration' => 'Administration', 'Trust & compliance' => 'Confiance et conformité', 'Operations' => 'Opérations', 'Approvals' => 'Approbations',
        'Customers & partners' => 'Clients et partenaires', 'Sales workspace' => 'Espace commercial', 'Health' => 'Santé', 'Distribution' => 'Distribution',
        'Financial operations' => 'Opérations financières', 'Claims operations' => 'Gestion des sinistres', 'Integrations' => 'Intégrations', 'Cases & tasks' => 'Dossiers et tâches',
        'Products & pricing' => 'Produits et tarification', 'Underwriting' => 'Souscription', 'Policy operations' => 'Gestion des polices',
        'CIMA regulatory dictionary' => 'Dictionnaire réglementaire CIMA', 'Institutional directory' => 'Annuaire institutionnel', 'Document engine' => 'Moteur documentaire',
        'Document catalogue' => 'Catalogue documentaire', 'Vehicle master data' => 'Référentiel véhicules', 'Master data' => 'Données de référence',
    ],
    'labels' => [
        'CIMA regulatory dictionary / Dashboard' => 'Vue d’ensemble CIMA', 'Document engine / Dashboard' => 'Vue d’ensemble du moteur', 'Master data / Dashboard' => 'Vue d’ensemble du référentiel',
        'Document engine / Document types' => 'Registre des documents', 'Document engine / Product mapping' => 'Documents par produit', 'CIMA regulatory dictionary / Product mapping' => 'Correspondance produits CIMA',
        'Integrations / Health' => 'État des intégrations',
        'Dashboard' => 'Tableau de bord', 'Reports' => 'Rapports', 'Compliance cases' => 'Dossiers de conformité', 'Data subject requests' => 'Demandes des personnes concernées',
        'Risk alerts' => 'Alertes de risque', 'Fulfilment orders' => 'Ordres d’exécution', 'Notification deliveries' => 'Envois de notifications', 'Support tickets' => 'Tickets d’assistance',
        'App issue reports' => 'Signalements de l’application', 'Approval inbox' => 'Boîte d’approbation', 'Approval matrix' => 'Matrice d’approbation', 'Tenant customers' => 'Clients',
        'Risk assets' => 'Biens assurés', 'Quotes' => 'Devis', 'Proposals' => 'Propositions', 'Payment intent records' => 'Demandes de paiement', 'Commission rule versions' => 'Règles de commission',
        'Partner statements' => 'Relevés partenaires', 'Partner payout requests' => 'Demandes de versement partenaires', 'Claims' => 'Sinistres', 'Claim reserve changes' => 'Mouvements de provisions',
        'Claim decisions' => 'Décisions sinistres', 'Claim payments' => 'Paiements de sinistres', 'Partner connections' => 'Connexions partenaires', 'Delivery attempts' => 'Tentatives de livraison',
        'Cases' => 'Dossiers', 'Tasks' => 'Tâches', 'Case types' => 'Types de dossier', 'Queues' => 'Files de travail', 'Business hours' => 'Heures ouvrables', 'Calendar exceptions' => 'Exceptions de calendrier',
        'Organizations' => 'Organisations', 'Branches' => 'Agences', 'Access & roles' => 'Accès et rôles', 'Invitations' => 'Invitations', 'Trusted devices' => 'Appareils de confiance',
        'Platform users' => 'Utilisateurs de la plateforme', 'Privileged access grants' => 'Accès privilégiés', 'Regulatory report runs' => 'Rapports réglementaires', 'People & organizations' => 'Personnes et organisations',
        'Consents' => 'Consentements', 'Broker & agent licences' => 'Agréments courtiers et agents', 'Partners' => 'Partenaires', 'Customer ownership locks' => 'Verrous de portefeuille client',
        'Insurance lines' => 'Branches d’assurance', 'Insurance products' => 'Produits d’assurance', 'Tariff versions' => 'Versions de tarif', 'Coverage definitions' => 'Garanties', 'Exclusion definitions' => 'Exclusions',
        'Disclosure schema versions' => 'Questionnaires de déclaration', 'Document requirement versions' => 'Exigences documentaires', 'Underwriting cases' => 'Dossiers de souscription',
        'Payment provider connections' => 'Connexions aux prestataires de paiement', 'Payment attempts' => 'Tentatives de paiement', 'Reconciliation imports' => 'Imports de rapprochement',
        'Journal records' => 'Écritures comptables', 'Chargebacks' => 'Rétrofacturations', 'Policy issuance requests' => 'Demandes d’émission', 'Policies' => 'Polices',
        'Policy transactions' => 'Avenants et résiliations', 'Certificate templates' => 'Modèles d’attestation', 'Policy certificates' => 'Attestations', 'Sticker batches' => 'Lots de vignettes',
        'Sticker stocks' => 'Stock de vignettes', 'Renewal cases' => 'Renouvellements', 'Cancellation rule versions' => 'Règles de résiliation', 'Platform settings' => 'Paramètres de la plateforme',
        'Branch register (Art. 328)' => 'Registre des branches (art. 328)', 'Microinsurance (Art. 717)' => 'Micro-assurance (art. 717)', 'Reporting categories (411/557)' => 'Catégories de déclaration (411/557)',
        'Terminology (FR/EN)' => 'Terminologie (FR/EN)', 'Insurer branch authorization' => 'Agréments des assureurs par branche', 'Compulsory insurance' => 'Assurances obligatoires',
        'Reference library' => 'Bibliothèque juridique', 'Authorities' => 'Autorités', 'Insurer CIMA setup' => 'Paramétrage CIMA assureur', 'Reporting mapping' => 'Correspondance de déclaration',
        'Broker CIMA setup' => 'Paramétrage CIMA courtier', 'Insurer directory' => 'Annuaire des assureurs', 'Verification labels' => 'Libellés de vérification', 'Document types' => 'Types de document',
        'Document families' => 'Familles de documents', 'Templates' => 'Modèles', 'Numbering' => 'Numérotation', 'Letterhead designer' => 'Conception de l’en-tête', 'Issuance rules' => 'Règles d’émission',
        'Signature configuration' => 'Configuration des signatures', 'Physical security assets' => 'Éléments de sécurité physique', 'QR configuration' => 'Configuration QR',
        'Generated documents' => 'Documents générés', 'Revocation & replacement' => 'Révocation et remplacement', 'Localization' => 'Localisation', 'Audit' => 'Audit',
        'Quality & missing configuration' => 'Qualité et configuration manquante', 'Document packs' => 'Dossiers documentaires', 'Pack items' => 'Éléments de dossier', 'Class applicability' => 'Applicabilité par classe',
        'Requirement matrix' => 'Matrice des exigences', 'Product overrides' => 'Exceptions par produit', 'Makes' => 'Marques', 'Models' => 'Modèles de véhicule', 'Review queue' => 'File de revue',
        'Reference values' => 'Valeurs de référence', 'Change history' => 'Historique des modifications', 'Generations' => 'Générations', 'Variants' => 'Variantes', 'Data readiness' => 'Préparation des données',
        'Domains' => 'Domaines', 'Lists' => 'Listes', 'Values' => 'Valeurs', 'Aliases' => 'Alias', 'Suggestions' => 'Suggestions', 'Tenant overrides' => 'Exceptions par organisation',
        'Carrier mappings' => 'Correspondances assureurs', 'Broker mappings' => 'Correspondances courtiers', 'History & audit' => 'Historique et audit', 'Merge requests' => 'Demandes de fusion',
        'Import' => 'Import', 'Data quality' => 'Qualité des données',
    ],
];
