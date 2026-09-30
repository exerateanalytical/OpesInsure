<?php

return [
    'stale_record' => "Cet enregistrement a été modifié par quelqu'un d'autre depuis son ouverture. Rechargez-le et vérifiez avant de réessayer.",
    'precondition_required' => "Cette mise à jour exige l'en-tête If-Match avec la version chargée en dernier.",
    'authority_exceeded' => "Cette action dépasse votre délégation d'autorité. Elle doit être transmise à un utilisateur disposant d'une limite supérieure.",
    'payment_ok_issuance_failed' => "Votre paiement a été reçu, mais la police n'a pas encore pu être émise. Ne payez pas à nouveau ; nous finalisons l'émission et vous informerons.",
    'integration_unavailable' => 'Un service partenaire est temporairement indisponible. Veuillez réessayer dans un instant.',
    'duplicate_submission' => 'Cette demande est déjà en cours de traitement.',
    'payment_already_made' => "Cette demande est déjà payée. Ne payez pas une seconde fois : votre police est en cours d'émission et vous serez notifié.",
    'payment_in_progress' => 'Un paiement pour cette demande est déjà en cours. Validez-le sur votre téléphone ou attendez la fin avant de réessayer.',
    'terms_not_accepted' => 'Veuillez accepter les conditions du contrat avant de payer.',
    'idempotency_key_invalid' => "L'en-tête Idempotency-Key ne doit pas dépasser 255 caractères.",
];
