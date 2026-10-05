<?php

// Lancement 2026-10-02 : échéances, remboursements, quittance et coordonnées de paiement du client
// (MobileCustomerMoneyController, MobileSettlementDischargeController).
return [
    'idempotency_required' => 'Un en-tête Idempotency-Key (16 à 80 caractères) est requis pour payer une échéance.',
    'instalment_not_payable' => 'Cette échéance ne peut pas être payée depuis l\'application. Contactez votre assureur.',
    'instalment_paid' => 'Cette échéance est déjà payée. Ne payez pas une seconde fois.',
    'instalment_in_progress' => 'Un paiement pour cette échéance est déjà en cours. Validez-le sur votre téléphone ou attendez la fin avant de réessayer.',
    'no_discharge' => 'Aucune quittance n\'attend votre signature pour ce sinistre.',
    'payout_locked' => 'Les coordonnées de paiement ne peuvent plus être modifiées : le paiement a déjà été demandé.',
    'payout_alert_title' => 'Coordonnées de paiement modifiées',
    'payout_alert_body' => 'Le compte qui recevra le règlement du sinistre :claim a été modifié. Si ce n\'est pas vous, contactez-nous immédiatement.',
];
