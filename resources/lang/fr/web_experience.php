<?php

return [
    'unknown' => 'Inconnu',
    'portals' => ['insurer' => 'Portail assureur', 'broker' => 'Portail courtier'],
    'meta' => ['carrier' => 'Assureur', 'premium' => 'Prime', 'cover' => 'Couverture', 'version' => 'Version', 'priority' => 'Priorité', 'reserve' => 'Provision', 'assignee' => 'Gestionnaire', 'loss_at' => 'Date du sinistre', 'line' => 'Branche', 'created' => 'Créé le'],
    'tabs' => ['overview' => 'Aperçu', 'timeline' => 'Historique', 'documents' => 'Documents', 'financial' => 'Finances', 'authority' => 'Pouvoirs'],
    'list' => [
        'empty_heading' => 'Aucun élément pour le moment',
        'empty_description' => 'Les enregistrements apparaîtront ici dès leur création.',
        'no_result_heading' => 'Aucun résultat',
        'no_result_description' => 'Effacez la recherche ou les filtres pour tout afficher.',
        'export' => 'Exporter la sélection (CSV)',
    ],
    'timeline' => ['heading' => 'Historique', 'empty' => 'Aucun événement enregistré.', 'system' => 'Système', 'actor' => 'Acteur', 'role' => 'Rôle', 'event' => 'Événement', 'result' => 'Résultat', 'reason' => 'Motif', 'channel' => 'Canal', 'document' => 'Document'],
    'documents' => ['heading' => 'Documents', 'empty' => 'Aucun document émis pour cet enregistrement.', 'withheld' => ':count document(s) masqué(s) : votre rôle ne permet pas ce niveau de sécurité.', 'certificate' => "Attestation d'assurance", 'number' => 'Numéro', 'version' => 'Version', 'status' => 'Statut', 'verification' => 'Vérification', 'issuer' => 'Émetteur', 'issued' => 'Émis le', 'expires' => 'Expire le', 'replaces' => 'Remplace', 'verify' => 'Vérifier (QR)'],
    'financial' => ['heading' => 'Finances', 'empty' => 'Aucun mouvement financier enregistré.', 'premium_payment' => 'Paiement de prime', 'claim_payment' => 'Règlement de sinistre', 'amount' => 'Montant', 'status' => 'Statut', 'source' => 'Source', 'payer' => 'Payeur', 'payee' => 'Bénéficiaire', 'reference' => 'Référence', 'reconciliation' => 'Rapprochement', 'journal' => 'Écriture'],
    'authority' => ['heading' => 'Pouvoirs', 'amount' => 'Montant de la transaction', 'yours' => 'Vos pouvoirs', 'required' => 'Pouvoirs requis', 'within' => 'Dans vos pouvoirs', 'referral' => 'Renvoi requis', 'yes' => 'Oui', 'no' => 'Non', 'no_rule' => "Aucune règle d'approbation active : le double contrôle par défaut s'applique.", 'restricted' => "Le détail des règles est réservé aux lecteurs de la matrice d'approbation."],
    'metrics' => [
        'active_policies' => 'Polices actives', 'pending_issuance' => "Payées, en attente d'émission", 'open_claims' => 'Sinistres ouverts',
        'outstanding_reserve' => 'Provisions en cours', 'underwriting_queue' => 'File de souscription', 'failed_claim_payments' => 'Règlements de sinistre échoués',
        'quotes_30d' => 'Devis (30 jours)', 'proposals_open' => 'Propositions ouvertes', 'renewals_due_30d' => 'Renouvellements (30 jours)', 'payments_pending' => 'Paiements en attente',
    ],
    'failure' => [
        'PAYMENT_OK_ISSUANCE_FAILED' => ['title' => 'Paiement reçu, police non émise', 'message' => "La prime a été encaissée mais l'émission a été rejetée. Ne pas réencaisser ; soumettre à nouveau l'émission ou rembourser."],
        'ISSUED_DOCUMENT_FAILED' => ['title' => 'Police émise, document en échec', 'message' => "La police est active mais un document n'a pas pu être généré. Régénérez-le depuis le registre des documents."],
        'CLAIM_PAYMENT_FAILED' => ['title' => 'Règlement de sinistre échoué', 'message' => 'Le prestataire de paiement a rejeté un règlement. Vérifiez les coordonnées du bénéficiaire et réessayez.'],
        'PROVIDER_SETTLEMENT_FAILED' => ['title' => 'Règlement prestataire échoué', 'message' => "Le règlement au prestataire n'a pas abouti. Il ne sera pas relancé automatiquement."],
        'CARRIER_API_DOWN' => ['title' => "Système de l'assureur indisponible", 'message' => "L'intégration assureur ne répond pas. Votre demande est enregistrée et sera transmise au rétablissement."],
        'REINSURANCE_API_DOWN' => ['title' => 'Système du réassureur indisponible', 'message' => "L'intégration de réassurance ne répond pas. Les cessions sont en file d'attente."],
        'STALE_RECORD' => ['title' => 'Enregistrement modifié', 'message' => "Quelqu'un a modifié cet enregistrement depuis son ouverture. Rechargez avant d'enregistrer."],
        'DUPLICATE_SUBMISSION' => ['title' => 'Déjà soumis', 'message' => "Cette demande a déjà été reçue. Elle n'a pas été traitée deux fois."],
        'PERMISSION_DENIED' => ['title' => 'Non autorisé', 'message' => "Votre rôle ne permet pas cette action. Demandez l'accès à un administrateur."],
        'NETWORK_FAILURE' => ['title' => 'Connexion perdue', 'message' => "La requête n'a pas atteint le serveur. Rien n'a été enregistré ; réessayez."],
    ],
];
