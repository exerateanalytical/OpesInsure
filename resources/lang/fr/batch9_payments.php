<?php

declare(strict_types=1);

return [
    'retry_started' => 'Une nouvelle demande de paiement a été envoyée sur votre téléphone. Veuillez l\'approuver pour finaliser votre paiement.',
    'retry_available' => 'Votre paiement n\'a pas abouti. Vous pouvez réessayer (:remaining tentative(s) restante(s)) ; vous ne serez pas débité deux fois.',
    'retry_exhausted' => 'Ce paiement a atteint la limite de :max tentatives. Veuillez contacter votre courtier ou choisir un autre moyen de paiement.',
    'payment_not_retryable' => 'Seul un paiement échoué peut être relancé.',
    'already_paid' => 'Ce paiement a déjà été effectué. Vous ne serez pas débité à nouveau.',
    'payment_in_progress' => 'Votre paiement est en cours de traitement. Veuillez attendre la confirmation avant de réessayer.',
    'obligation_already_covered' => 'Ce montant est déjà payé ou un paiement est en cours. Vous ne serez pas débité deux fois.',
    'idempotency_key_reused' => 'Cette clé d\'idempotence a déjà été utilisée pour un autre paiement.',
    'off_platform_collection' => 'Ce paiement est encaissé hors de l\'application (:mode). Veuillez payer selon les instructions de votre courtier ou assureur.',
];
