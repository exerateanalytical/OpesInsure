<?php

// Lancement 2026-10-02 (Q2) : écrans client et partagés de l’espace web (docs/LAUNCH_SCREEN_GAPS_2026-09-29.md §5).
return [
    'welcome' => ['title' => 'Compte créé', 'lede' => 'Bienvenue sur OpesInsure. Votre compte est prêt.'],
    'onb' => ['title' => 'Compléter votre profil', 'lede' => 'Quelques étapes pour que les assureurs émettent vos polices sans délai.'],
    'actions' => ['title' => 'Centre d’actions', 'lede' => 'Tout ce qui demande votre attention, le plus urgent en premier.'],
    'product' => ['title' => 'Détails du produit', 'lede' => 'Garanties, exclusions, éligibilité et ce qu’il faut pour obtenir un devis.'],
    'needs' => ['title' => 'Trouver la bonne couverture', 'lede' => 'Répondez à quelques questions et nous affichons les produits qui vous conviennent.'],
    'search_p' => ['title' => 'Recherche', 'lede' => 'Retrouvez vos polices, sinistres, devis, documents et véhicules.'],
    'activity' => ['title' => 'Activité du compte', 'lede' => 'Ce qui a été fait sur votre compte, et quand.'],
    'complaints' => ['title' => 'Réclamations', 'lede' => 'Pas satisfait ? Déposez une réclamation formelle et suivez son traitement.'],
    'complaint' => ['title' => 'Détails de la réclamation', 'lede' => 'Statut, délais et réponses reçues.'],
    'messages' => ['title' => 'Messages', 'lede' => 'Toutes vos communications : notifications, échanges avec le support et courriers de réclamation.'],

    'staff_search' => ['title' => 'Recherche globale', 'lede' => 'Recherchez les dossiers auxquels vous avez accès : clients, polices, sinistres, devis, documents et véhicules.'],
    'search' => [
        'label' => 'Rechercher', 'placeholder' => 'Nom, n° de police ou de sinistre, immatriculation, n° de document…', 'types_label' => 'Chercher dans', 'per_type' => 'Résultats par type',
        'submit' => 'Rechercher', 'open' => 'Ouvrir', 'none' => 'Aucun résultat.', 'too_short' => 'Saisissez au moins 2 caractères.', 'failed' => 'La recherche a échoué. Réessayez.',
        'searched' => 'Recherché dans : :types',
        'types' => ['customers' => 'Clients', 'policies' => 'Polices', 'claims' => 'Sinistres', 'quotes' => 'Devis', 'documents' => 'Documents', 'vehicles' => 'Véhicules', 'risk_assets' => 'Autres biens assurés'],
    ],

    'js' => [
        'welcome' => [
            'hello' => 'Bienvenue, :name !', 'done' => 'Votre compte a été créé et vous êtes connecté.', 'next_t' => 'La suite',
            'n1' => 'Complétez votre profil et vérifiez votre identité une seule fois.', 'n2' => 'Comparez les offres d’assureurs agréés et souscrivez en ligne.', 'n3' => 'Retrouvez attestations, paiements et sinistres au même endroit.',
            'cta' => 'Compléter mon profil', 'later' => 'Aller au tableau de bord', 'browse' => 'Parcourir les assurances',
        ],
        'onb' => [
            'progress' => ':pct % complété', 'continue' => 'Continuer', 'done_all' => 'Votre profil est complet. Vous pouvez souscrire et renouveler sans délai.',
            'incomplete_t' => 'Reste à faire', 'none_left' => 'Plus rien à faire.', 'done' => 'Fait', 'todo' => 'À faire',
            'steps' => [
                'identity' => ['Informations personnelles', 'Nom complet et date de naissance'],
                'contact' => ['Coordonnées et adresse', 'Téléphone et e-mail vérifiés, adresse du domicile'],
                'kyc' => ['Vérification d’identité', 'Numéro d’identité et contrôle'],
                'docs' => ['Documents requis', 'Documents demandés par le contrôleur'],
            ],
            'fix' => ['identity' => 'Ajoutez votre date de naissance', 'phone' => 'Vérifiez votre numéro de téléphone', 'email' => 'Ajoutez et vérifiez une adresse e-mail', 'address' => 'Ajoutez l’adresse de votre domicile', 'kyc_id' => 'Ajoutez un numéro d’identité', 'kyc_submit' => 'Envoyez votre identité pour contrôle', 'kyc_wait' => 'Attendez le contrôle de votre identité', 'kyc_fix' => 'Corrigez ce que le contrôleur a demandé', 'doc' => 'Fournir : :doc'],
        ],
        'kyc_steps' => ['DRAFT' => 'Brouillon', 'SUBMITTED' => 'Envoyé', 'IN_REVIEW' => 'En contrôle', 'APPROVED' => 'Approuvé'],
        'kyc_state' => [
            'NONE' => 'Non commencé', 'DRAFT' => 'Brouillon — pas encore envoyé', 'SUBMITTED' => 'Envoyé — en attente d’un contrôleur', 'IN_REVIEW' => 'En cours de contrôle', 'UNDER_REVIEW' => 'En cours de contrôle',
            'APPROVED' => 'Approuvé', 'VERIFIED' => 'Approuvé', 'MORE_INFO_REQUIRED' => 'Informations complémentaires requises', 'REJECTED' => 'Refusé', 'EXPIRED' => 'Expiré — à renouveler',
        ],
        'remed_t' => 'Ce que vous devez corriger', 'remed_d' => 'Le contrôleur a besoin exactement de ces éléments pour approuver votre identité :', 'remed_reason' => 'Note du contrôleur', 'remed_go' => 'Corriger maintenant',
        'act' => [
            'priority' => 'Priorité', 'item' => 'Élément', 'action' => 'Action', 'due' => 'Échéance', 'reason' => 'Pourquoi', 'none' => 'Rien ne demande votre attention pour le moment.',
            'p' => ['HIGH' => 'Urgent', 'MEDIUM' => 'Bientôt', 'LOW' => 'Quand vous pouvez'],
            'kyc_fix' => ['Corrigez votre contrôle d’identité', 'Le contrôleur a demandé des informations complémentaires.'],
            'kyc_start' => ['Vérifiez votre identité', 'Nécessaire avant l’émission d’une police.'],
            'kyc_expired' => ['Renouvelez votre contrôle d’identité', 'Votre vérification a expiré.'],
            'pay' => ['Payer :product', 'La police est émise dès le paiement de la prime.'],
            'quote' => ['Examinez votre devis :number', 'Une offre attend votre décision.'],
            'claim' => ['Répondre sur le sinistre :number', 'Le service sinistres attend votre retour.'],
            'renew' => ['Renouveler :product', 'Votre police se termine dans :n jours.'],
            'lapsed' => ['Renouveler :product', 'Votre police a expiré.'],
            'support' => ['Répondre au dossier support :number', 'Le support attend votre réponse.'],
            'complaint' => ['Lire la réponse à la réclamation :number', 'Une décision vous a été envoyée.'],
            'notif' => [':n notifications non lues', 'Des mises à jour que vous n’avez pas encore lues.'],
            'go' => ['kyc' => 'Ouvrir le contrôle d’identité', 'pay' => 'Payer', 'quote' => 'Voir le devis', 'claim' => 'Ouvrir le sinistre', 'renew' => 'Renouveler', 'support' => 'Répondre', 'complaint' => 'Lire', 'notif' => 'Lire'],
        ],
        'dash' => [
            'hello' => 'Bonjour, :name', 'actions_t' => 'Actions requises', 'actions_all' => 'Ouvrir le centre d’actions', 's_outstanding' => 'Prime restant due', 's_outstanding_d' => 'À payer maintenant',
            'claims_t' => 'Mes sinistres', 'no_claims' => 'Aucun sinistre déclaré.', 'docs_t' => 'Documents récents', 'no_docs' => 'Aucun document pour l’instant.', 'renew_t' => 'Renouvellements',
            'no_renew' => 'Aucune police à renouveler dans les 60 prochains jours.', 'renew' => 'Renouveler', 'due_t' => 'Paiements dus', 'no_due' => 'Rien à payer.', 'pay' => 'Payer',
            'onb' => 'Votre profil est complété à :pct %.', 'onb_go' => 'Compléter mon profil', 'q_search' => 'Rechercher', 'q_needs' => 'Trouver la bonne couverture', 'q_msgs' => 'Messages', 'q_complaint' => 'Déposer une réclamation',
            'claim_no' => 'Sinistre', 'claim_st' => 'Statut', 'claim_date' => 'Déclaré le',
        ],
        'prod' => [
            'tabs' => ['overview' => 'Aperçu', 'coverage' => 'Garanties', 'exclusions' => 'Exclusions', 'eligibility' => 'Éligibilité', 'requirements' => 'Pièces requises', 'documents' => 'Documents'],
            'insurer' => 'Assureur', 'line' => 'Branche', 'code' => 'Code produit', 'version' => 'Version', 'from' => 'Disponible depuis', 'until' => 'Disponible jusqu’au', 'reg' => 'Référence réglementaire',
            'get_quote' => 'Obtenir un devis', 'mandatory' => 'Toujours incluse', 'optional' => 'Optionnelle', 'no_cov' => 'L’assureur n’a pas publié la liste des garanties de ce produit.',
            'no_excl' => 'Aucune exclusion publiée pour ce produit. Les conditions de la police s’appliquent.', 'no_elig' => 'Aucune règle d’éligibilité particulière : ouvert à tous les clients.', 'req_d' => 'Pour obtenir un devis, il vous sera demandé :',
            'no_req' => 'Seulement les informations de base sur ce que vous assurez.', 'docs_d' => 'Après paiement, vous recevez dans votre compte :', 'docs' => ['Conditions particulières et générales', 'Attestation d’assurance (avec QR code de vérification)', 'Reçu de paiement'],
            'not_found' => 'Ce produit n’est pas disponible.', 'required' => 'obligatoire',
        ],
        'needs' => [
            'q1' => 'Que voulez-vous protéger ?', 'q2' => 'Pour qui est la couverture ?', 'q_use' => 'Quel est l’usage du véhicule ?', 'q_trip' => 'Où voyagez-vous ?', 'q_home' => 'Êtes-vous propriétaire ou locataire ?', 'q_people' => 'Combien de personnes couvrir ?', 'q_staff' => 'Combien de salariés ?',
            'what' => ['MOTOR' => 'Mon véhicule', 'HEALTH' => 'Ma santé et celle de ma famille', 'TRAVEL' => 'Un voyage', 'HOME' => 'Mon logement', 'LIFE' => 'L’avenir de ma famille', 'ACCIDENT' => 'Moi, contre les accidents', 'BUSINESS' => 'Mon entreprise'],
            'who' => ['INDIVIDUAL' => 'Moi (particulier)', 'FAMILY' => 'Ma famille', 'BUSINESS' => 'Une entreprise'],
            'use' => ['PRIVATE' => 'Privé', 'COMMERCIAL' => 'Commercial / taxi', 'FLEET' => 'Flotte d’entreprise'], 'trip' => ['CEMAC' => 'Zone CEMAC', 'AFRICA' => 'Reste de l’Afrique', 'SCHENGEN' => 'Schengen / Europe', 'WORLD' => 'Monde entier'],
            'home' => ['OWNER' => 'Propriétaire', 'TENANT' => 'Locataire'], 'people' => ['1' => '1', '2-4' => '2 à 4', '5+' => '5 ou plus'], 'staff' => ['1-10' => '1 à 10', '11-50' => '11 à 50', '50+' => 'Plus de 50'],
            'result_t' => 'Produits adaptés', 'result_d' => ':n produits correspondent à vos réponses.', 'none' => 'Aucun produit ne correspond encore à ce besoin. Écrivez-nous, un conseiller vous aidera.', 'ask' => 'Demander à un conseiller',
            'details' => 'Détails', 'quote' => 'Obtenir un devis', 'restart' => 'Recommencer', 'pick' => 'Choisissez au moins une réponse.', 'see' => 'Voir les produits',
        ],
        'search' => [
            'label' => 'Rechercher', 'placeholder' => 'N° de police ou de sinistre, immatriculation, document…', 'submit' => 'Rechercher', 'none' => 'Aucun résultat dans vos dossiers.', 'too_short' => 'Saisissez au moins 2 caractères.',
            'filter' => 'Filtres', 'types_label' => 'Chercher dans', 'apply' => 'Appliquer', 'reset' => 'Réinitialiser', 'results' => ':n résultats', 'hint' => 'La recherche porte uniquement sur vos propres dossiers.',
            'types' => ['customers' => 'Clients', 'policies' => 'Polices', 'claims' => 'Sinistres', 'quotes' => 'Devis', 'documents' => 'Documents', 'vehicles' => 'Véhicules', 'risk_assets' => 'Autres biens assurés'],
        ],
        'activity' => [
            'actions_t' => 'Actions sur votre compte', 'logins_t' => 'Connexions', 'none' => 'Aucune activité enregistrée.', 'no_logins' => 'Aucune connexion enregistrée.', 'more' => 'Afficher plus',
            'when' => 'Quand', 'what' => 'Quoi', 'on' => 'Sur', 'via' => 'Canal', 'device' => 'Appareil', 'result' => 'Résultat', 'src' => ['api' => 'Application / site', 'web' => 'Site web', 'console' => 'Système'],
        ],
        'cpl' => [
            'new_t' => 'Déposer une réclamation', 'new_d' => 'Dites-nous ce qui ne va pas. Vous recevez un accusé de réception et une réponse écrite dans le délai réglementaire.',
            'about' => 'Elle concerne', 'general' => 'OpesInsure en général', 'policy' => 'Police :n', 'claim' => 'Sinistre :n', 'desc' => 'Décrivez votre réclamation (10 caractères minimum)', 'contact' => 'Comment vous répondre ? (e-mail ou téléphone, facultatif)',
            'send' => 'Envoyer la réclamation', 'sent' => 'La réclamation :n a été enregistrée. Nous en accuserons réception rapidement.', 'list_t' => 'Mes réclamations', 'none' => 'Vous n’avez déposé aucune réclamation.',
            'number' => 'Réclamation', 'received' => 'Reçue le', 'status' => 'Statut', 'due' => 'Réponse attendue avant le', 'ack' => 'Accusé de réception', 'outcome' => 'Issue', 'resolution' => 'Réponse', 'communicated' => 'Réponse envoyée',
            'escalated' => 'Transmise à', 'timeline_t' => 'Étapes du traitement', 'corr_t' => 'Courriers et messages', 'in' => 'De vous', 'out' => 'À vous', 'not_found' => 'Cette réclamation est introuvable dans votre compte.',
            'support' => 'Pour une simple question, utilisez plutôt le support.', 'open_support' => 'Ouvrir le support', 'back' => 'Toutes les réclamations',
        ],
        'msg' => [
            'all' => 'Tout', 'notif' => 'Notifications', 'support' => 'Support', 'complaints' => 'Réclamations', 'none' => 'Aucun message pour l’instant.', 'open' => 'Ouvrir',
            'k' => ['notif' => 'Notification', 'support' => 'Dossier support', 'complaint' => 'Réclamation'], 'unread' => 'Non lu', 'new_support' => 'Nouvelle demande au support', 'new_complaint' => 'Nouvelle réclamation',
        ],
    ],
];
