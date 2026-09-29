<?php

// S9 — intégration groupée des courtiers (/admin → Administration → Intégration des courtiers, API /api/v1/platform/broker-onboarding).
return [
    'target_label' => 'Intégration groupée des courtiers',
    'nav' => 'Intégration des courtiers',
    'group' => 'Administration',
    'model' => 'lot d’intégration de courtiers',
    'models' => 'lots d’intégration de courtiers',
    'intro' => 'Téléchargez le modèle, remplissez une ligne par cabinet de courtage, importez-le et vérifiez l’aperçu. Soumettez le lot : un autre administrateur l’approuve, puis chaque ligne valide reçoit son organisation courtier, son agrément, ses agences et une invitation pour son administrateur. Les cabinets déjà présents sur la plateforme sont ignorés.',
    'summary' => ':rows lignes · :new nouvelles · :duplicates déjà sur la plateforme · :errors erreurs · :created créées · :failed en échec',
    'columns' => [
        'uploaded' => 'Importé le', 'file' => 'Fichier', 'status' => 'Statut', 'summary' => 'Lignes', 'maker' => 'Importé par', 'checker' => 'Approuvé par',
    ],
    'actions' => [
        'template_csv' => 'Modèle (CSV)', 'template_xlsx' => 'Modèle (Excel)', 'upload' => 'Importer des courtiers', 'preview' => 'Aperçu des lignes',
        'report' => 'Télécharger le rapport', 'submit' => 'Soumettre pour approbation', 'approve' => 'Approuver et créer', 'reject' => 'Rejeter', 'cancel' => 'Annuler',
    ],
    'fields' => [
        'file' => 'Fichier CSV ou Excel', 'reason' => 'Commentaire', 'note' => 'Note',
        'legal_name' => 'Raison sociale', 'trade_name' => 'Nom commercial', 'rccm' => 'RCCM', 'niu' => 'NIU', 'licence_number' => 'Numéro d’agrément',
        'licence_expires_on' => 'Date d’expiration de l’agrément', 'city' => 'Ville', 'address' => 'Adresse', 'phone' => 'Téléphone', 'email' => 'E-mail',
        'admin_name' => 'Nom de l’administrateur', 'admin_phone' => 'Téléphone de l’administrateur', 'admin_email' => 'E-mail de l’administrateur', 'branches' => 'Agences', 'locale' => 'Langue',
    ],
    'help' => [
        'upload' => 'Utilisez les colonnes du modèle. Téléphones au format +237 ; dates AAAA-MM-JJ ou JJ/MM/AAAA ; agences sous la forme « Nom@Ville|Nom@Ville ».',
        'approve' => 'Cette action crée :n organisations courtiers, leurs agréments, agences et invitations administrateur (envoyées par SMS/e-mail).',
    ],
    'notify' => [
        'uploaded' => 'Fichier vérifié',
        'imported' => ':created courtiers intégrés, :failed en échec',
    ],
    'empty' => [
        'heading' => 'Aucune intégration de courtiers',
        'description' => 'Téléchargez le modèle, remplissez-le avec vos courtiers partenaires et importez-le.',
    ],
    'preview' => [
        'row' => 'Ligne', 'legal_name' => 'Cabinet', 'niu' => 'NIU', 'licence_number' => 'Agrément', 'admin' => 'Administrateur',
        'status' => 'Statut', 'reason' => 'Détails', 'delivery' => 'Invitation',
    ],
    'status' => [
        'new' => 'Prête', 'created' => 'Créée', 'duplicate' => 'Déjà sur la plateforme', 'skipped_duplicate' => 'Ignorée (déjà sur la plateforme)',
        'error' => 'Erreur', 'skipped_error' => 'Ignorée (erreur)', 'failed' => 'Échec', 'linked_register' => 'Rattachée à l’entrée du registre officiel',
    ],
    'errors' => [
        'required' => ':field est obligatoire.',
        'niu_format' => 'Le NIU doit comporter 1 lettre, 12 chiffres et 1 lettre (ex. M012345678901A).',
        'rccm_format' => 'Le RCCM n’est pas valide (ex. RC/DLA/2019/B/1234).',
        'licence_format' => 'Le numéro d’agrément n’est pas valide (lettres, chiffres, / - . uniquement, avec au moins un chiffre).',
        'date_format' => 'La date d’expiration de l’agrément n’est pas valide (AAAA-MM-JJ ou JJ/MM/AAAA).',
        'licence_expired' => 'L’agrément a expiré le :date.',
        'phone_format' => ':field doit être un numéro camerounais (+237 puis 9 chiffres commençant par 2 ou 6).',
        'email_format' => ':field n’est pas une adresse e-mail valide.',
        'admin_contact' => 'Indiquez un téléphone ou un e-mail de l’administrateur pour lui envoyer l’invitation.',
        'repeated' => ':field est répété dans le fichier (ligne :row).',
    ],
    'matches' => [
        'tenant' => 'Organisation déjà existante : :name',
        'identifier' => ':type déjà enregistré sur la plateforme',
        'licence' => 'Agrément :number déjà enregistré',
        'partner' => 'Courtier déjà enregistré : :name',
        'contact' => 'Le contact :value appartient déjà à un autre tiers',
    ],
    'invite' => [
        'sms' => 'OpesInsure : bonjour :name, vous êtes invité(e) à administrer :broker. Code d\'invitation : :code (valable :days jours). Appli : :url/download',
        'email_subject' => 'Votre invitation d’administrateur courtier OpesInsure — :broker',
        'email_body' => "Bonjour :name,\n\n:broker a été intégré sur OpesInsure et vous en êtes l’administrateur.\nInstallez l’application (:url/download), connectez-vous avec ce téléphone ou cet e-mail, puis saisissez le code d’invitation :\n\n:code\n\nLe code est valable :days jours.\n\nOpesInsure",
    ],
];
