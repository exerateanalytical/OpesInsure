<?php

declare(strict_types=1);

// Portail courtier (/broker) parcours de vente — décision du propriétaire 2026-09-29 (portails modifiables).
return [
    'customers' => [
        'nav' => 'Mes clients',
        'title' => 'Mes clients',
        'number' => 'N° client',
        'name' => 'Client',
        'status' => 'Statut',
        'since' => 'Client depuis',
        'empty_t' => 'Aucun client pour le moment',
        'empty_d' => 'Enregistrez un nouveau client pour démarrer un devis.',
    ],
    'brokerRegisterClient' => [
        'label' => 'Nouveau client',
        'help' => 'Enregistre le client dans le portefeuille de votre cabinet avec son consentement.',
        'done' => 'Client enregistré',
    ],
    'brokerNewQuote' => [
        'label' => 'Nouveau devis',
        'help' => 'Tarifie le risque auprès de chaque assureur avec lequel votre cabinet a une convention active, puis ouvre les offres à comparer.',
        'done' => 'Devis tarifé',
    ],
    'brokerRequestPremium' => [
        'label' => 'Demander le paiement de la prime',
        'help' => 'Envoie une demande de paiement sur le téléphone du client. Une fois payé, l\'assureur est sollicité pour émettre la police.',
        'done' => 'Demande de paiement envoyée au client',
    ],
    'brokerPremiumReceipt' => [
        'label' => 'Télécharger le reçu',
        'done' => 'Reçu prêt',
    ],
    'fields' => [
        'consent_reference' => 'Référence du consentement (formulaire signé, appel ...)',
        'line' => 'Branche',
        'risk_facts' => 'Détails du risque',
        'fact' => 'Détail',
        'value' => 'Valeur',
        'provider' => 'Moyen de paiement',
        'payer_phone' => 'Numéro mobile du payeur',
    ],
    'providers' => [
        'fake' => 'Mode test (sans argent réel)',
        'mtn_momo' => 'MTN Mobile Money',
        'orange_money' => 'Orange Money',
        'campay' => 'CamPay',
        'maviance' => 'Maviance',
        'bank_transfer' => 'Virement bancaire',
        'card_sandbox' => 'Carte (bac à sable)',
    ],
    'offers' => [
        'title' => 'Comparaison des offres',
        'empty' => 'Aucune offre : tarifiez le devis.',
        'rank' => 'Rang',
        'carrier' => 'Assureur',
        'product' => 'Produit',
        'premium' => 'Prime nette',
        'total' => 'Total à payer',
        'status' => 'Statut',
    ],
    'no_partner' => 'Votre compte n\'est rattaché à aucun cabinet : aucune convention assureur ne s\'applique.',
];
