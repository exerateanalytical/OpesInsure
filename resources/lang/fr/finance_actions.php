<?php

// Actions bureau : commissions, relevés et versements partenaires, règlements assureurs, bordereaux (couverture UI, lots 9-10).
return [
    // règles de commission
    'ruleCreate' => ['label' => 'Nouvelle règle de commission', 'help' => 'Crée une version de règle en BROUILLON. Un autre utilisateur doit l\'approuver avant qu\'elle ne s\'applique.', 'done' => 'Règle de commission créée (brouillon).'],
    'ruleApprove' => ['label' => 'Approuver la règle', 'help' => 'Le créateur de la règle ne peut pas l\'approuver. Les règles approuvées qui se chevauchent sont refusées.', 'done' => 'Règle de commission approuvée.'],

    // commissions
    'accrualAccrue' => ['label' => 'Constater une commission', 'help' => 'Constate la commission d\'un partenaire sur une police selon une règle approuvée.', 'done' => 'Commission constatée.'],
    'accrualEarn' => ['label' => 'Marquer acquise', 'help' => 'Possible une fois les obligations de prime de la police soldées.', 'done' => 'Commission acquise.'],
    'accrualVest' => ['label' => 'Rendre exigible (ancien circuit)', 'help' => 'Rend directement exigible une commission constatée une fois la date d\'acquisition passée.', 'done' => 'Commission rendue exigible.'],
    'accrualApprove' => ['label' => 'Approuver la commission', 'help' => 'Une commission ajustée doit être approuvée par un autre utilisateur que celui qui l\'a ajustée.', 'done' => 'Commission approuvée.'],
    'accrualMakePayable' => ['label' => 'Rendre exigible', 'help' => 'Possible une fois la période d\'acquisition écoulée.', 'done' => 'Commission rendue exigible.'],
    'accrualAdjust' => ['label' => 'Ajuster le montant', 'help' => 'Fixe un nouveau montant de commission, qui doit ensuite être de nouveau approuvé.', 'done' => 'Commission ajustée ; en attente d\'approbation.'],
    'accrualDispute' => ['label' => 'Contester', 'done' => 'Commission contestée.'],
    'accrualResolveDispute' => ['label' => 'Résoudre la contestation', 'help' => 'Corrige éventuellement le montant ; la commission doit ensuite être de nouveau approuvée.', 'done' => 'Contestation résolue ; en attente d\'approbation.'],
    'accrualClawback' => ['label' => 'Reprise de commission (rétrocession)', 'help' => 'Récupère tout ou partie de la commission non encore versée.', 'done' => 'Commission reprise.'],
    'accrualReverse' => ['label' => 'Extourner', 'done' => 'Commission extournée.'],

    // relevés partenaires
    'statementGenerate' => ['label' => 'Générer les relevés', 'help' => 'Génère le relevé de commissions d\'un partenaire, ou de tous les partenaires actifs sur la période si aucun n\'est choisi.', 'done' => 'Relevés générés.'],
    'statementPrepare' => ['label' => 'Préparer un relevé', 'help' => 'Prépare un relevé en BROUILLON à partir des commissions exigibles du partenaire sur la période.', 'done' => 'Relevé préparé (brouillon).'],
    'statementApprove' => ['label' => 'Approuver le relevé', 'help' => 'Le préparateur ne peut pas approuver. Les ajustements en attente doivent d\'abord être tranchés.', 'done' => 'Relevé approuvé.'],
    'statementPublish' => ['label' => 'Publier le relevé', 'done' => 'Relevé publié au partenaire.'],
    'statementDispute' => ['label' => 'Contester le relevé', 'help' => 'Refusé tant qu\'un versement sur ce relevé est demandé, approuvé, en cours ou payé.', 'done' => 'Relevé contesté.'],
    'statementResolveDispute' => ['label' => 'Résoudre la contestation', 'help' => 'Remet le relevé en BROUILLON pour une nouvelle approbation.', 'done' => 'Contestation résolue ; relevé remis en brouillon.'],
    'adjustmentPropose' => ['label' => 'Proposer un ajustement', 'help' => 'Un autre utilisateur doit approuver l\'ajustement.', 'done' => 'Ajustement proposé.'],
    'adjustmentApprove' => ['label' => 'Approuver l\'ajustement', 'help' => 'L\'auteur de l\'ajustement ne peut pas l\'approuver.', 'done' => 'Ajustement approuvé.'],
    'adjustmentReject' => ['label' => 'Rejeter l\'ajustement', 'done' => 'Ajustement rejeté.'],

    // versements partenaires
    'payoutRequest' => ['label' => 'Demander un versement', 'help' => 'Ne peut dépasser le solde de clôture du relevé diminué des versements déjà réservés.', 'done' => 'Versement demandé.'],
    'payoutApprove' => ['label' => 'Approuver le versement', 'help' => 'Le demandeur ne peut pas approuver le versement.', 'done' => 'Versement approuvé.'],
    'payoutProcess' => ['label' => 'Envoyer en paiement', 'done' => 'Versement transmis au prestataire.'],
    'payoutComplete' => ['label' => 'Confirmer le paiement', 'done' => 'Versement confirmé comme payé.'],
    'payoutFail' => ['label' => 'Enregistrer l\'échec', 'done' => 'Échec du versement enregistré.'],
    'payoutReverse' => ['label' => 'Annuler le versement', 'help' => 'Le demandeur ne peut pas annuler le versement. Les commissions payées redeviennent exigibles.', 'done' => 'Versement annulé.'],

    // règlements assureurs (base polices)
    'carrierSettlementPrepare' => ['label' => 'Préparer un règlement (polices)', 'help' => 'Compense les primes des polices actives émises sur la période avec leurs commissions.', 'done' => 'Règlement préparé (brouillon).'],
    'carrierSettlementApprove' => ['label' => 'Approuver le règlement', 'help' => 'Le préparateur ne peut pas approuver le règlement.', 'done' => 'Règlement approuvé.'],
    'carrierSettlementSubmit' => ['label' => 'Transmettre à la banque', 'done' => 'Règlement transmis à la banque.'],
    'carrierSettlementPaid' => ['label' => 'Confirmer le paiement', 'done' => 'Règlement confirmé comme payé.'],
    'carrierSettlementFail' => ['label' => 'Enregistrer l\'échec bancaire', 'done' => 'Échec du règlement enregistré.'],
    'carrierSettlementReverse' => ['label' => 'Contrepasser le règlement', 'done' => 'Règlement contrepassé.'],

    // règlements assureurs (base obligations)
    'ledgerSettlementDraft' => ['label' => 'Nouveau règlement (obligations)', 'help' => 'Crée un règlement en BROUILLON calculé à partir des obligations ouvertes envers l\'assureur.', 'done' => 'Règlement créé (brouillon).'],
    'ledgerSettlementCalculate' => ['label' => 'Calculer', 'done' => 'Règlement calculé.'],
    'ledgerSettlementReview' => ['label' => 'Soumettre en revue', 'done' => 'Règlement soumis en revue.'],
    'ledgerSettlementCancel' => ['label' => 'Annuler le règlement', 'done' => 'Règlement annulé.'],
    'ledgerSettlementApprove' => ['label' => 'Approuver le règlement', 'help' => 'Le préparateur ne peut pas approuver le règlement.', 'done' => 'Règlement approuvé.'],
    'ledgerSettlementReject' => ['label' => 'Renvoyer', 'help' => 'Remet le règlement en BROUILLON et annule ses lignes calculées.', 'done' => 'Règlement renvoyé en brouillon.'],
    'ledgerSettlementProcess' => ['label' => 'Envoyer en paiement', 'done' => 'Paiement du règlement transmis.'],
    'ledgerSettlementFail' => ['label' => 'Enregistrer l\'échec du paiement', 'done' => 'Échec du paiement enregistré ; règlement remis à l\'état approuvé.'],
    'ledgerSettlementSettle' => ['label' => 'Confirmer le règlement', 'done' => 'Règlement soldé.'],
    'ledgerSettlementReconcile' => ['label' => 'Rapprocher', 'done' => 'Règlement rapproché.'],

    // bordereaux
    'bordereauPrepare' => ['label' => 'Préparer un bordereau', 'done' => 'Bordereau préparé (brouillon).'],
    'bordereauApprove' => ['label' => 'Approuver le bordereau', 'help' => 'Le préparateur ne peut pas approuver le bordereau.', 'done' => 'Bordereau approuvé.'],
    'bordereauSubmit' => ['label' => 'Transmettre à l\'assureur', 'help' => 'Le préparateur ne peut pas transmettre le bordereau.', 'done' => 'Bordereau transmis à l\'assureur.'],
    'bordereauAcknowledge' => ['label' => 'Enregistrer l\'accusé de réception', 'done' => 'Accusé de réception de l\'assureur enregistré.'],
    'bordereauReject' => ['label' => 'Enregistrer le rejet', 'done' => 'Rejet de l\'assureur enregistré.'],

    'fields' => [
        'carrier' => 'Assureur', 'product' => 'Produit', 'partner' => 'Partenaire', 'partner_optional' => 'Partenaire (facultatif)', 'policy' => 'Police', 'rule' => 'Règle de commission',
        'basis_points' => 'Taux de commission (points de base, 100 = 1 %)', 'holdback_basis_points' => 'Retenue (points de base)', 'vesting_days' => 'Période d\'acquisition (jours)',
        'effective_from' => 'En vigueur du', 'effective_until' => 'En vigueur jusqu\'au',
        'amount_minor' => 'Montant (unités mineures, 100 = 1 FCFA)', 'new_amount_minor' => 'Nouveau montant (unités mineures, 100 = 1 FCFA)',
        'corrected_amount_minor' => 'Montant corrigé (unités mineures, 100 = 1 FCFA, facultatif)', 'signed_amount_minor' => 'Montant (unités mineures, 100 = 1 FCFA ; négatif pour déduire)',
        'opening_balance_minor' => 'Solde d\'ouverture (unités mineures, 100 = 1 FCFA)',
        'reason' => 'Motif', 'reason_code' => 'Code motif', 'note' => 'Note', 'resolution' => 'Résolution', 'adjustment' => 'Ajustement',
        'period_start' => 'Début de période', 'period_end' => 'Fin de période', 'currency' => 'Devise',
        'destination_type' => 'Type de destination', 'destination' => 'Destination (numéro ou compte)', 'provider' => 'Prestataire de paiement', 'provider_reference' => 'Référence du prestataire',
        'failure_code' => 'Code d\'échec', 'failure_message' => 'Message d\'échec', 'bank_reference' => 'Référence bancaire', 'reconciliation_reference' => 'Référence de rapprochement',
        'approval_notes' => 'Notes d\'approbation (au moins 20 caractères, facultatif)', 'bordereau_type' => 'Type de bordereau', 'carrier_reference' => 'Référence de l\'assureur',
    ],

    'codes' => [
        'MOBILE_MONEY' => 'Mobile money', 'BANK' => 'Virement bancaire',
        'PREMIUM' => 'Primes', 'CLAIM' => 'Sinistres', 'ENDORSEMENT' => 'Avenants', 'CANCELLATION' => 'Résiliations', 'COMMISSION' => 'Commissions',
    ],
];
