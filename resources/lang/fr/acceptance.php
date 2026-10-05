<?php

// Acceptation web du contrat par le client (ProposalAcceptanceLinks, /account/accept/{token}) et messages côté
// agent/courtier. Anglais dans resources/lang/en/acceptance.php.
return [
    'title' => 'Vérifiez et acceptez votre assurance',
    'lede' => 'Votre conseiller a préparé cette demande pour vous. Vous seul pouvez confirmer vos réponses et accepter les conditions du contrat.',
    'sms' => "OpesInsure : votre conseiller a préparé la demande d'assurance :number. Consultez et acceptez les conditions ici (lien valable 72 h) : :url",

    'only_customer' => "Seul le client peut accepter les conditions du contrat. Envoyez-lui plutôt le lien d'acceptation.",
    'verify_first' => "Confirmez d'abord le code envoyé sur votre téléphone.",
    'not_acceptable' => 'Cette demande ne peut plus être acceptée ici.',
    'link_expired' => 'Ce lien a expiré ou a déjà été utilisé. Demandez-en un nouveau à votre conseiller.',
    'phone_other_account' => "Ce numéro a déjà un compte OpesInsure. Connectez-vous avec lui dans l'application OpesInsure pour accepter, ou demandez à votre conseiller de vérifier vos coordonnées.",
    'code_sent' => 'Nous avons envoyé un code à 6 chiffres par SMS au :phone.',
    'terms_required' => 'Cochez la case pour accepter les conditions du contrat.',
    'attest_required' => 'Cochez la case pour confirmer que vos réponses sont exactes et complètes.',

    'verify_t' => "Confirmez qu'il s'agit bien de vous",
    'verify_d' => 'Nous allons envoyer un code à 6 chiffres par SMS au téléphone de cette demande (:phone).',
    'send_code' => 'Recevoir le code',
    'code' => 'Code à 6 chiffres',
    'verify' => 'Confirmer',
    'resend' => 'Renvoyer un code',

    'review_t' => 'Vérifier et accepter',
    'review_d' => 'Vérifiez la formule et le prix, répondez vous-même aux questions, puis acceptez les conditions.',
    'questions_t' => 'Vos réponses',
    'yes' => 'Oui',
    'no' => 'Non',
    'choose' => 'Choisir…',
    'terms_t' => 'Conditions du contrat',
    'terms_d' => "L'assureur vous couvre selon les conditions, la prime et les garanties indiquées sur cette page. Total à payer : :total. Vous ne payez qu'après acceptation, depuis votre propre téléphone mobile money.",
    'account_note' => "En acceptant, cette demande est liée à votre numéro de téléphone : vous pourrez la suivre, ainsi que votre police, dans l'application OpesInsure.",
    'accept' => "J'accepte",

    'done_t' => 'Merci, votre acceptation est enregistrée',
    'done_d' => 'La demande :number porte désormais votre acceptation des conditions du contrat.',
    'done_pending' => "Une étape reste ouverte avant l'examen par l'assureur :",
    'done_next' => 'Votre conseiller peut maintenant envoyer la demande de paiement sur votre téléphone. Validez-la avec votre code PIN mobile money.',
    'get_app' => "Obtenir l'application OpesInsure",

    'state' => [
        'invalid_t' => "Ce lien n'est pas valide",
        'invalid_d' => 'Ouvrez le lien exactement tel que reçu par SMS, ou demandez-en un nouveau à votre conseiller.',
        'expired_t' => 'Ce lien a expiré',
        'expired_d' => "Pour votre sécurité, les liens d'acceptation expirent après 72 heures. Demandez à votre conseiller d'en envoyer un nouveau.",
        'used_t' => 'Ce lien a déjà été utilisé',
        'used_d' => "Chaque lien d'acceptation ne fonctionne qu'une fois. Demandez-en un nouveau à votre conseiller si nécessaire.",
        'accepted_t' => 'Déjà acceptée',
        'accepted_d' => "Vous avez déjà accepté les conditions de cette demande. Aucune autre action n'est nécessaire ici.",
        'closed_t' => 'Cette demande est close',
        'closed_d' => 'Elle a été retirée ou refusée. Contactez votre conseiller pour un nouveau devis.',
        'blocked_t' => "L'assureur a d'abord besoin d'autre chose",
        'blocked_d' => "L'assureur a demandé des informations complémentaires ou proposé de nouvelles conditions. Contactez votre conseiller ou ouvrez l'application OpesInsure.",
    ],

    'summary_t' => 'Votre formule',
    'application' => 'Demande',
    'insured' => 'Assuré',
    'insurer' => 'Assureur',
    'product' => 'Formule',
    'premium' => 'Prime',
    'tax' => 'Taxes',
    'fees' => 'Frais',
    'total' => 'Total',
    'coverages' => 'Garanties',
    'optional' => 'optionnelle',
    'help_t' => "Besoin d'aide ?",
    'safety' => "OpesInsure ne vous demande jamais votre code PIN mobile money sur cette page. Votre conseiller ne peut pas accepter à votre place.",

    // Côté agent / courtier
    'link_sent_broker' => "Le client n'a pas encore accepté les conditions du contrat. Nous lui avons envoyé un lien d'acceptation par SMS (:phone). Relancez la demande de paiement une fois qu'il aura accepté.",
    'link_recent_broker' => "Le client n'a pas encore accepté les conditions du contrat. Un lien d'acceptation a déjà été envoyé au :phone ; relancez la demande de paiement une fois qu'il aura accepté.",
    'link_no_phone_broker' => "Le client n'a pas encore accepté les conditions du contrat et aucun numéro de téléphone client n'est disponible pour envoyer le lien d'acceptation. Ajoutez d'abord son numéro.",
    'link_failed_broker' => "Le client n'a pas encore accepté les conditions du contrat et le SMS contenant le lien d'acceptation n'a pas pu être envoyé. Vérifiez les paramètres du fournisseur SMS.",
];
