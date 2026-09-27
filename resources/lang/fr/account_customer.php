<?php

// Pages libre-service client du compte web (/account/kyc, /account/privacy, /account/requests)
// et actions client ajoutées par l'audit du 2026-09-27 (recours, contre-offre, assistance d'urgence).
return [
    'kyc' => ['title' => 'Vérification d’identité', 'lede' => 'Vérifiez votre identité une fois pour que les assureurs émettent vos polices sans attendre.'],
    'privacy' => ['title' => 'Confidentialité et sécurité', 'lede' => 'Vos consentements, préférences de notification, appareils connectés et demandes sur vos données.'],
    'req' => ['title' => 'Demandes sur police', 'lede' => 'Demandez une modification, un renouvellement ou une nouvelle copie d’un document de l’une de vos polices.'],

    'js' => [
        'kyc' => [
            'status_t' => 'État de la vérification', 'none' => 'Vous n’avez pas encore soumis votre identité.', 'submitted' => 'Soumis le', 'reviewed' => 'Examiné le', 'expires' => 'Valable jusqu’au',
            'remediation' => 'Le vérificateur vous demande de corriger', 'missing' => 'Encore requis', 'all_ok' => 'Tous les éléments requis sont fournis.',
            'ids_t' => 'Pièces d’identité enregistrées', 'ids_none' => 'Aucun numéro d’identité enregistré.', 'verified' => 'Vérifié', 'unverified' => 'Non vérifié',
            'add_t' => 'Ajouter un numéro d’identité', 'id_type' => 'Type de pièce', 'id_value' => 'Numéro', 'id_country' => 'Pays de délivrance', 'add' => 'Enregistrer le numéro', 'added' => 'Numéro d’identité enregistré.',
            'types' => ['NATIONAL_ID' => 'Carte nationale d’identité (CNI)', 'PASSPORT' => 'Passeport', 'DRIVER_LICENCE' => 'Permis de conduire', 'RESIDENCE_PERMIT' => 'Carte de séjour', 'NIU' => 'Numéro d’identifiant unique (NIU)'],
            'doc_t' => 'Téléverser un document', 'doc_purpose' => 'De quel document s’agit-il ?', 'doc_file' => 'Fichier (PDF, JPG ou PNG, 10 Mo max.)', 'upload' => 'Téléverser', 'uploaded' => 'Document téléversé.', 'bad_file' => 'Choisissez un fichier PDF, JPG ou PNG de 10 Mo maximum.',
            'purposes' => ['ID_FRONT' => 'CNI — recto', 'ID_BACK' => 'CNI — verso', 'PASSPORT' => 'Passeport', 'PROOF_OF_ADDRESS' => 'Justificatif de domicile', 'NIU' => 'Carte de contribuable (NIU)', 'RCCM' => 'Registre du commerce (RCCM)'],
            'docs_t' => 'Documents joints', 'submit_t' => 'Envoyer pour examen', 'notes' => 'Note pour le vérificateur (facultatif)', 'submit' => 'Soumettre pour vérification', 'sent' => 'Votre vérification d’identité a été envoyée pour examen.',
        ],
        'priv' => [
            'consent_t' => 'Consentements', 'consent_d' => 'Vous pouvez les modifier à tout moment. Le traitement nécessaire à vos polices n’en dépend pas.',
            'purposes' => ['MARKETING' => 'Offres et actualités d’OpesInsure', 'PARTNER_SHARING' => 'Partager mes coordonnées avec des assureurs partenaires', 'ANALYTICS' => 'Utiliser mes données d’usage pour améliorer le service', 'WHATSAPP_UPDATES' => 'Recevoir des nouvelles sur WhatsApp'],
            'since' => 'Mis à jour le :date', 'saved' => 'Vos choix ont été enregistrés.',
            'notif_t' => 'Préférences de notification', 'channels' => 'Canaux', 'topics' => 'Sujets',
            'prefs' => ['push' => 'Notifications de l’application', 'sms' => 'SMS', 'email' => 'E-mail', 'renewals' => 'Rappels de renouvellement', 'claims' => 'Suivi des sinistres', 'payments' => 'Suivi des paiements'],
            'lang_t' => 'Langue des messages', 'lang_d' => 'Langue des SMS, e-mails et documents que nous vous envoyons.', 'lang_saved' => 'Langue enregistrée.',
            'dev_t' => 'Appareils connectés', 'dev_none' => 'Aucun appareil mobile connecté.', 'last_seen' => 'Vu le :date', 'this' => 'Le plus récent', 'revoke' => 'Déconnecter', 'revoke_q' => 'Déconnecter cet appareil ?', 'revoked' => 'L’appareil a été déconnecté.',
            'dsr_t' => 'Mes données personnelles', 'dsr_d' => 'Demandez une copie de vos données ou leur effacement. Nous répondons sous 30 jours.',
            'export' => 'Demander une copie de mes données', 'delete' => 'Demander l’effacement de mes données', 'delete_q' => 'L’effacement ferme votre compte dès que les polices actives et les durées légales de conservation le permettent. Continuer ?',
            'dsr_sent' => 'Demande :ref enregistrée.', 'dsr_none' => 'Aucune demande.', 'types' => ['EXPORT' => 'Copie de mes données', 'DELETE' => 'Effacement'], 'due' => 'Échéance :date', 'done' => 'Traitée le :date',
        ],
        'req' => [
            'list_t' => 'Mes demandes', 'none' => 'Vous n’avez aucune demande.', 'new_t' => 'Nouvelle demande', 'policy' => 'Police', 'type' => 'Type de demande', 'reason' => 'Détails', 'reason_h' => 'Au moins 5 caractères.',
            'send' => 'Envoyer la demande', 'sent' => 'Votre demande a été envoyée.', 'no_policy' => 'Il vous faut une police active pour faire une demande.',
            'types' => ['ENDORSEMENT' => 'Modifier ma couverture', 'CANCELLATION_REVIEW' => 'Résilier ma police', 'ADDRESS_CHANGE' => 'Changer mon adresse', 'VEHICLE_CHANGE' => 'Changer de véhicule', 'BENEFICIARY_CHANGE' => 'Changer les bénéficiaires', 'DOCUMENT_REISSUE' => 'Réémettre un document'],
            'docs_needed' => 'Documents demandés', 'msg' => 'Ajouter un message', 'msg_send' => 'Envoyer', 'opened' => 'Ouverte le :date',
            'renew_t' => 'Renouveler une police', 'renew_d' => 'Obtenez de nouveaux tarifs pour une police qui arrive à échéance. Vous comparez les offres avant de payer.', 'renew' => 'Obtenir des devis de renouvellement', 'renew_none' => 'Aucune police proche du renouvellement.',
        ],
        'appeal' => ['btn' => 'Contester la décision', 'title' => 'Contester la décision', 'text' => 'Expliquez pourquoi vous n’êtes pas d’accord. L’assureur réexaminera votre sinistre.', 'reason' => 'Vos motifs', 'err' => 'Saisissez au moins 10 caractères.', 'send' => 'Envoyer le recours', 'done' => 'Votre recours a été envoyé.', 'cancel' => 'Annuler'],
        'counter' => ['title' => 'Offres en attente de votre réponse', 'text' => 'L’assureur a modifié les conditions de votre proposition. Acceptez pour passer au paiement, ou refusez.', 'was' => 'Total initial', 'now' => 'Nouveau total', 'accept' => 'Accepter', 'decline' => 'Refuser', 'decline_q' => 'Refuser cette offre ?', 'accepted' => 'Offre acceptée. Vous pouvez payer.', 'declined' => 'Offre refusée.'],
        'settle' => ['accept' => 'Accepter l’offre', 'reject' => 'Refuser l’offre', 'reject_q' => 'Refuser cette offre d’indemnisation ? L’assureur réexaminera votre sinistre.', 'accepted' => 'Vous avez accepté l’offre d’indemnisation.', 'rejected' => 'Vous avez refusé l’offre d’indemnisation.', 'deadline' => 'Répondre avant le :date'],
        'insp' => ['btn' => 'Changer le rendez-vous', 'title' => 'Changer le rendez-vous d’expertise', 'when' => 'Nouvelle date et heure', 'save' => 'Enregistrer la nouvelle date', 'done' => 'L’expertise a été reprogrammée.', 'cancel' => 'Annuler'],
        'inc' => ['title' => 'Détails de l’incident', 'edit' => 'Modifier les détails', 'type' => 'Ce qui s’est passé', 'police' => 'Numéro du procès-verbal de police', 'injuries' => 'Il y a des blessés', 'drivable' => 'Le véhicule peut encore rouler', 'towing' => 'Le véhicule doit être remorqué', 'declare' => 'Je certifie que ces informations sont exactes', 'save' => 'Enregistrer les détails', 'saved' => 'Détails de l’incident enregistrés.',
            'types' => ['COLLISION' => 'Collision', 'THEFT' => 'Vol', 'FIRE' => 'Incendie', 'GLASS' => 'Bris de glace', 'VANDALISM' => 'Vandalisme', 'NATURAL_EVENT' => 'Inondation ou tempête', 'MEDICAL' => 'Médical', 'OTHER' => 'Autre']],
        'parties' => ['title' => 'Personnes impliquées', 'none' => 'Personne n’a encore été ajouté.', 'add' => 'Ajouter une personne', 'role' => 'Rôle', 'name' => 'Nom complet', 'phone' => 'Téléphone', 'email' => 'E-mail', 'notes' => 'Remarques', 'consent' => 'Cette personne accepte d’être contactée', 'save' => 'Ajouter', 'added' => 'Personne ajoutée.',
            'roles' => ['DRIVER' => 'Conducteur', 'PASSENGER' => 'Passager', 'THIRD_PARTY' => 'Tiers', 'WITNESS' => 'Témoin', 'OTHER' => 'Autre']],
        'refund' => ['btn' => 'Demander un remboursement', 'title' => 'Demander un remboursement', 'reason' => 'Pourquoi demandez-vous un remboursement ?', 'amount' => 'Montant (FCFA, vide pour le montant total)', 'send' => 'Continuer', 'done' => 'Votre demande de remboursement a été enregistrée.', 'cancel' => 'Annuler'],
        'delivery' => ['title' => 'Livraison de l’attestation', 'status' => 'État', 'due' => 'Prévue avant le', 'address' => 'Adresse de livraison', 'recipient' => 'Destinataire', 'phone' => 'Téléphone', 'line' => 'Adresse', 'city' => 'Ville', 'save' => 'Mettre à jour l’adresse', 'saved' => 'Adresse de livraison mise à jour.', 'locked' => 'Le coursier est en route : l’adresse ne peut plus être modifiée ici.',
            'confirm_t' => 'Confirmer la réception', 'confirm_d' => 'Saisissez le code à 6 chiffres que le coursier vous donne à la remise de votre attestation.', 'otp' => 'Code de livraison', 'confirm' => 'Confirmer la réception', 'confirmed' => 'Livraison confirmée.'],
        'attach' => ['label' => 'Joindre un fichier (PDF, JPG ou PNG, 10 Mo max.)', 'send' => 'Joindre', 'done' => 'Fichier joint.', 'bad' => 'Choisissez un fichier PDF, JPG ou PNG de 10 Mo maximum.'],
        'sos' => ['title' => 'Assistance d’urgence', 'text' => 'Besoin d’une dépanneuse, de secours médicaux ou de la police après un accident ? Nous vous rappelons immédiatement.', 'service' => 'Aide demandée', 'services' => ['TOWING' => 'Remorquage', 'MEDICAL' => 'Secours médicaux', 'POLICE' => 'Police'], 'location' => 'Où êtes-vous ?', 'phone' => 'Téléphone de rappel', 'send' => 'Demander de l’aide', 'sent' => 'Aide demandée. Référence :ref.'],
    ],
];
