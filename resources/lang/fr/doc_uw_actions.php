<?php

// Lot 11 de couverture UI : documents, courrier, délégation de pouvoirs, signature électronique et poste de souscription.
return [
    'empty' => 'Aucun élément pour le moment',
    'uw_group' => 'Souscription',

    'nav' => [
        'group' => 'Documents et courrier',
        'documents' => 'Registre des documents',
        'correspondence' => 'Registre du courrier',
        'delegated_authorities' => 'Délégations de pouvoirs',
        'signatures' => 'Mes signatures',
    ],

    'columns' => [
        'reference' => 'Référence', 'direction' => 'Sens', 'channel' => 'Canal', 'counterparty' => 'Correspondant', 'subject' => 'Objet',
        'status' => 'Statut', 'proof' => 'Preuve', 'created_at' => 'Créé le', 'category' => 'Catégorie', 'party' => 'Tiers', 'mime_type' => 'Type de fichier',
        'size_bytes' => 'Taille (octets)', 'scan_status' => 'Analyse', 'verification_status' => 'Vérification', 'agreement_number' => 'N° de convention',
        'carrier' => 'Compagnie', 'partner' => 'Intermédiaire', 'effective_from' => 'Effet au', 'effective_until' => 'Échéance',
        'permitted_lines' => 'Branches autorisées', 'max_policy_premium' => 'Prime maximale par police', 'signer_role' => 'Qualité du signataire',
        'signing_order' => 'Ordre', 'provider' => 'Procédé', 'expires_at' => 'Expire le',
    ],

    'fields' => [
        'assignee' => 'Affecter au souscripteur', 'note' => 'Note (facultative)', 'referral' => 'Point de référé', 'resolution_notes' => 'Motifs de la levée (20 caractères minimum)',
        'direction' => 'Sens', 'channel' => 'Canal', 'counterparty_type' => 'Type de correspondant', 'counterparty_name' => 'Nom du correspondant',
        'counterparty_contact' => 'Coordonnées du correspondant', 'case' => 'Dossier (facultatif)', 'subject_line' => 'Objet', 'summary' => 'Résumé',
        'external_reference' => 'Référence externe', 'received_at' => 'Reçu le', 'proof_type' => "Preuve d'envoi",
        'proof_reference' => 'Référence de la preuve (accusé, lettre de voiture, identifiant de message)', 'dispatched_at' => 'Envoyé le', 'delivered_at' => 'Remis le (si connu)',
        'delivered' => 'Remis au destinataire', 'failure_reason' => "Motif de l'échec", 'category' => 'Catégorie de document', 'storage_key' => 'Clé de stockage',
        'mime_type' => 'Type de fichier', 'size_bytes' => 'Taille (octets)', 'sha256' => 'Empreinte SHA-256 (64 caractères hexadécimaux)', 'party' => 'Tiers (facultatif)',
        'scan_status' => "Résultat de l'analyse", 'verification_status' => 'Vérification', 'notes' => "Notes d'examen", 'purpose' => "Motif de l'accès",
        'carrier' => 'Compagnie', 'partner' => 'Intermédiaire', 'agreement_number' => 'Numéro de convention', 'effective_from' => "Date d'effet",
        'effective_until' => "Date d'échéance", 'permitted_lines' => "Branches d'assurance autorisées",
        'max_policy_premium_minor' => 'Prime maximale par police (XAF, unités mineures)', 'max_claim_authority_minor' => 'Pouvoir de règlement des sinistres (XAF, unités mineures)',
        'territories' => 'Territoires', 'reason' => 'Motif', 'line_code' => "Branche d'assurance", 'premium_minor' => 'Prime (XAF, unités mineures)',
        'territory' => 'Territoire', 'effective_at' => "Prise d'effet de la garantie", 'consent_text' => 'Déclaration de consentement',
        'consent_accepted' => "J'ai lu et j'accepte la déclaration de consentement et je signe électroniquement",
    ],

    'codes' => [
        'direction' => ['INBOUND' => 'Entrant', 'OUTBOUND' => 'Sortant'],
        'channel' => ['EMAIL' => 'Courriel', 'SMS' => 'SMS', 'WHATSAPP' => 'WhatsApp', 'LETTER' => 'Lettre', 'COURIER' => 'Coursier', 'PORTAL' => 'Portail', 'PHONE' => 'Téléphone', 'IN_PERSON' => 'En personne'],
        'counterparty' => ['CUSTOMER' => 'Client', 'CARRIER' => 'Compagnie', 'BROKER' => 'Courtier', 'REGULATOR' => 'Autorité de contrôle', 'OMBUDSMAN' => 'Médiateur', 'PROVIDER' => 'Prestataire', 'LAWYER' => 'Avocat', 'OTHER' => 'Autre'],
        'proof' => ['PROVIDER_RECEIPT' => 'Accusé du prestataire', 'REGISTERED_MAIL' => 'Lettre recommandée', 'COURIER_WAYBILL' => 'Lettre de voiture',
            'SIGNED_ACKNOWLEDGEMENT' => 'Accusé de réception signé', 'PORTAL_READ_RECEIPT' => 'Accusé de lecture portail', 'EMAIL_MESSAGE_ID' => 'Identifiant du courriel'],
        'scan' => ['CLEAN' => 'Sain', 'INFECTED' => 'Infecté', 'FAILED' => "Échec de l'analyse"],
        'verification' => ['VERIFIED' => 'Vérifié', 'REJECTED' => 'Rejeté', 'NEEDS_REVIEW' => 'À réexaminer'],
    ],

    'uwAssign' => ['label' => 'Affecter un souscripteur', 'help' => 'Affecte le dossier à un souscripteur de cette organisation.', 'done' => 'Souscripteur affecté'],
    'uwStartReview' => ['label' => "Commencer l'examen", 'help' => "Passe le dossier référé en examen ; vous en devenez responsable si personne n'est affecté.", 'done' => 'Examen commencé'],
    'uwEvaluate' => ['label' => "Lancer l'évaluation des règles", 'help' => "Évalue la dernière proposition soumise au regard des règles de souscription. Le système recommande ; le souscripteur décide.",
        'done' => 'Évaluation enregistrée', 'result' => 'Recommandation :recommendation (score :score, classe :band)'],
    'uwReadyForDecision' => ['label' => 'Prêt pour décision', 'help' => "Clôt l'examen. Tous les référés doivent être levés au préalable.", 'done' => 'Dossier prêt pour décision'],
    'uwResolveReferral' => ['label' => 'Lever un référé', 'help' => 'Clôt un référé ouvert avec vos motifs.', 'done' => 'Référé levé'],

    'corRegister' => ['label' => 'Enregistrer un courrier', 'help' => 'Enregistre un courrier entrant comme reçu ou un courrier sortant comme brouillon.', 'done' => 'Courrier enregistré'],
    'corDispatch' => ['label' => "Enregistrer l'envoi", 'help' => "Enregistre l'envoi et sa preuve. La preuve ne peut plus être modifiée.", 'done' => 'Envoi enregistré'],
    'corOutcome' => ['label' => "Enregistrer l'issue de la remise", 'help' => 'Indique si le courrier envoyé a été remis ou non. Un courrier non remis doit être réémis sous une nouvelle entrée.', 'done' => 'Issue enregistrée'],

    'docRegister' => ['label' => 'Enregistrer un document', 'help' => "Enregistre un fichier stocké. Il reste en attente jusqu'à son analyse et son examen.", 'done' => 'Document enregistré'],
    'docReview' => ['label' => 'Examiner le document', 'help' => "Enregistre le résultat de l'analyse et de la vérification. Un document non sain ne peut être vérifié.", 'done' => 'Document examiné'],
    'docAccess' => ['label' => 'Ouvrir le document', 'help' => "Génère un lien à durée limitée. Chaque accès est journalisé avec son motif.", 'done' => 'Accès journalisé',
        'link' => 'Lien valable :minutes minutes', 'open' => 'Ouvrir'],

    'daCreate' => ['label' => 'Nouvelle convention', 'help' => 'Crée une convention de délégation à l’état de brouillon ; un approbateur de la compagnie doit l’activer.', 'done' => 'Convention créée à l’état de brouillon',
        'duplicate' => 'Ce numéro de convention est déjà utilisé.'],
    'daApprove' => ['label' => 'Approuver la convention', 'help' => 'Active la convention au nom de la compagnie.', 'done' => 'Convention activée', 'not_draft' => 'Seule une convention en brouillon peut être approuvée.'],
    'daCheck' => ['label' => 'Vérifier le pouvoir', 'help' => "Vérifie si une police entrerait dans le périmètre de la convention. Rien n'est enregistré.", 'done' => 'Pouvoir vérifié',
        'within' => 'Dans la limite du pouvoir (:reason)', 'outside' => 'Hors pouvoir : :reason'],

    'sigSign' => ['label' => 'Signer', 'help' => "Signe le document électroniquement. Votre identité, la déclaration de consentement et l'empreinte du document sont enregistrées.", 'done' => 'Document signé'],
    'sigDecline' => ['label' => 'Refuser de signer', 'help' => 'Refuse la demande de signature. La demande est close pour tous les signataires.', 'done' => 'Signature refusée'],
];
