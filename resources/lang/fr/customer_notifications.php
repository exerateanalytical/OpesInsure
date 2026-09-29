<?php

/*
 * French copy of resources/lang/en/customer_notifications.php (same codes and
 * placeholders). Rendered for users whose app language / users.locale is fr.
 */
return [
    '_defaults' => [
        'product' => 'votre couverture',
        'device' => 'un nouvel appareil',
    ],
    '_service_types' => [
        'ENDORSEMENT' => 'd’avenant',
        'CANCELLATION_REVIEW' => 'd’examen de résiliation',
        'ADDRESS_CHANGE' => 'de changement d’adresse',
        'VEHICLE_CHANGE' => 'de changement de véhicule',
        'BENEFICIARY_CHANGE' => 'de changement de bénéficiaire',
        'DOCUMENT_REISSUE' => 'de réédition de document',
    ],

    // Devis
    'quote_offers_ready_one' => ['title' => 'Vos devis sont prêts', 'body' => '1 offre est prête à être consultée.'],
    'quote_offers_ready' => ['title' => 'Vos devis sont prêts', 'body' => ':count offres d’assureurs agréés sont prêtes à être comparées.'],
    'quote_referred' => ['title' => 'Votre devis nécessite un examen', 'body' => 'Aucune offre immédiate ne correspond à vos informations. Un souscripteur va étudier votre demande.'],
    'quote_sent_to_insurer' => ['title' => 'Votre demande a été transmise à un assureur', 'body' => 'Un assureur prépare une offre personnalisée. Nous vous préviendrons dès qu’elle arrive.'],
    'quote_new_offer' => ['title' => 'Une nouvelle offre d’assureur est prête', 'body' => 'Un assureur vous a envoyé une offre personnalisée à consulter.'],
    'quote_declined' => ['title' => 'L’assureur ne peut pas proposer de couverture', 'body' => 'L’assureur a refusé de tarifer ce risque. Votre conseiller vous proposera des alternatives.'],
    'carrier_quote_request' => ['title' => 'Nouvelle demande de cotation manuelle', 'body' => 'La demande de cotation :request attend votre offre.'],

    // Propositions
    'proposal_under_review' => ['title' => 'Proposition en cours d’examen', 'body' => 'Votre proposition a été soumise et est en cours d’examen par l’assureur.'],
    'proposal_approved' => ['title' => 'Proposition approuvée', 'body' => 'Votre proposition a été approuvée. Effectuez le paiement pour activer votre couverture.'],
    'proposal_counteroffer' => ['title' => 'Contre-proposition disponible', 'body' => 'L’assureur a proposé des conditions révisées. Consultez la contre-proposition pour l’accepter ou la refuser.'],
    'proposal_declined' => ['title' => 'Proposition refusée', 'body' => 'L’assureur a refusé votre proposition. Vous pouvez comparer d’autres offres.'],
    'proposal_information_needed' => ['title' => 'Informations complémentaires requises', 'body' => 'Le souscripteur a besoin de plus d’informations sur votre proposition. Ouvrez-la pour répondre.'],

    // Paiements et émission
    'payment_failed' => ['title' => 'Échec du paiement', 'body' => 'Votre paiement n’a pas abouti. Aucun montant n’a été prélevé ; vous pouvez réessayer depuis l’écran de paiement.'],
    'payment_expired' => ['title' => 'Échec du paiement', 'body' => 'Votre demande de paiement a expiré avant d’être validée. Vous pouvez réessayer depuis l’écran de paiement.'],
    'payment_issuance_in_progress' => ['title' => 'Paiement reçu — émission en cours', 'body' => 'Nous avons reçu votre paiement pour :product. L’assureur émet votre police ; nous vous préviendrons dès que vous serez couvert.'],
    'payment_issuance_delayed' => ['title' => 'Paiement reçu — émission de la police retardée', 'body' => 'Nous avons reçu votre paiement pour :product et il est en sécurité. L’émission de votre police prend plus de temps que prévu ; notre équipe s’en occupe et vous tiendra informé.'],
    'policy_issued' => ['title' => 'Vous êtes couvert', 'body' => 'Votre police :policy (:product) est active à partir du :starts. Votre attestation est dans votre portefeuille.'],
    'policy_active' => ['title' => 'Votre police est active', 'body' => 'La police :policy a été émise. Votre attestation est disponible dans votre portefeuille.'],
    'policy_issuance_failed' => ['title' => 'L’émission n’a pas pu aboutir', 'body' => 'L’assureur n’a pas pu émettre votre police. Notre équipe vous contactera pour la suite, y compris un remboursement le cas échéant.'],

    // Gestion de la police
    'policy_cancellation_notice' => ['title' => 'Avis de résiliation', 'body' => 'La police :policy sera résiliée avec effet au :effective. Remboursement estimé : :refund :currency (unités mineures).'],
    'policy_cancellation_requested' => ['title' => 'Demande de résiliation reçue', 'body' => 'La police :policy sera résiliée avec effet au :effective. Remboursement estimé : :refund :currency (unités mineures).'],
    'policy_cancelled' => ['title' => 'Police résiliée', 'body' => 'La police :policy est résiliée avec effet au :effective.'],
    'policy_cancelled_refund' => ['title' => 'Police résiliée', 'body' => 'La police :policy est résiliée avec effet au :effective. Un remboursement de :refund :currency (unités mineures) a été demandé.'],
    'service_request_received' => ['title' => 'Demande reçue', 'body' => 'Nous avons reçu votre demande :service pour la police :policy. Notre équipe l’examinera sous 2 jours ouvrés.'],
    'document_new_one' => ['title' => 'Nouveau document disponible', 'body' => '1 nouveau document pour la police :policy (:label). Ouvrez votre police pour le consulter et le télécharger.'],
    'document_new_many' => ['title' => 'Nouveaux documents disponibles', 'body' => ':count nouveaux documents pour la police :policy (:label). Ouvrez votre police pour les consulter et les télécharger.'],
    'renewal_ends_tomorrow' => ['title' => 'Votre couverture se termine demain', 'body' => 'Votre police :policy (:product) expire demain (:ends). Renouvelez-la maintenant pour rester couvert.'],
    'renewal_ends_in_days' => ['title' => 'Votre couverture se termine dans :days jours', 'body' => 'Votre police :policy (:product) expire dans :days jours (:ends). Renouvelez-la maintenant pour rester couvert.'],

    // Sinistres
    'claim_submitted' => ['title' => 'Déclaration de sinistre reçue', 'body' => 'Nous avons reçu la déclaration :claim. Nous en accuserons réception sous peu.'],
    'claim_acknowledged' => ['title' => 'Déclaration prise en charge', 'body' => 'La déclaration :claim a été prise en charge et confiée à un gestionnaire.'],
    'claim_evidence_pending' => ['title' => 'Documents demandés pour votre sinistre', 'body' => 'L’assureur a besoin de documents supplémentaires pour la déclaration :claim. Ouvrez-la pour voir quoi envoyer.'],
    'claim_assessment' => ['title' => 'Sinistre en cours d’évaluation', 'body' => 'La déclaration :claim est en cours d’évaluation.'],
    'claim_carrier_review' => ['title' => 'Sinistre transmis à l’assureur', 'body' => 'La déclaration :claim est chez l’assureur pour décision.'],
    'claim_approved' => ['title' => 'Sinistre approuvé', 'body' => 'La déclaration :claim a été approuvée. Le règlement est en préparation.'],
    'claim_partially_approved' => ['title' => 'Sinistre partiellement approuvé', 'body' => 'La déclaration :claim a été partiellement approuvée. Ouvrez-la pour consulter le règlement.'],
    'claim_declined' => ['title' => 'Sinistre refusé', 'body' => 'La déclaration :claim a été refusée. Ouvrez-la pour voir le motif et vos possibilités de recours.'],
    'claim_paid' => ['title' => 'Sinistre réglé', 'body' => 'Le règlement de la déclaration :claim a été payé.'],
    'claim_disputed' => ['title' => 'Recours enregistré', 'body' => 'Votre recours sur la déclaration :claim est enregistré et sera examiné.'],
    'claim_closed' => ['title' => 'Sinistre clôturé', 'body' => 'La déclaration :claim a été clôturée.'],
    'claim_reopened' => ['title' => 'Sinistre rouvert', 'body' => 'La déclaration :claim a été rouverte.'],
    'claim_status_changed' => ['title' => 'Mise à jour du sinistre', 'body' => 'Nouveau statut de la déclaration :claim : :status.'],
    'claim_appeal_received' => ['title' => 'Recours reçu', 'body' => 'Votre recours sur la déclaration :claim a été reçu (réf. :reference). Un gestionnaire vous répondra sous 5 jours ouvrés.'],
    'claim_large_loss' => ['title' => 'Sinistre majeur déclaré', 'body' => 'La déclaration :claim présente une perte de :amount :currency (unités mineures), égale ou supérieure au seuil de sinistre majeur.'],

    // Sécurité du compte
    'security_new_sign_in' => ['title' => 'Nouvelle connexion à votre compte', 'body' => 'Votre compte a été connecté sur :device. Si ce n’était pas vous, changez votre mot de passe et déconnectez tous les appareils.'],
    'security_password_changed' => ['title' => 'Votre mot de passe a été modifié', 'body' => 'Votre mot de passe OpesInsure vient d’être modifié. Si ce n’était pas vous, réinitialisez-le maintenant et déconnectez tous les appareils.'],
    'security_alert_new_device' => ['title' => 'Connexion depuis un nouvel appareil', 'body' => 'Votre compte vient d’être utilisé sur un nouvel appareil. Si ce n’était pas vous, déconnectez-vous partout et changez votre mot de passe.'],
    'security_alert_password_changed' => ['title' => 'Mot de passe modifié', 'body' => 'Votre mot de passe a été modifié. Si ce n’était pas vous, contactez immédiatement le support.'],
    'security_alert_identity_changed' => ['title' => 'Informations du compte modifiées', 'body' => 'Vos identifiants de connexion ou vos coordonnées ont été modifiés. Si ce n’était pas vous, contactez immédiatement le support.'],
    'security_alert_signed_out_everywhere' => ['title' => 'Déconnecté partout', 'body' => 'Toutes les sessions sur tous les appareils ont été fermées.'],
    'security_alert_repeated_failed_sign_ins' => ['title' => 'Tentatives de connexion échouées', 'body' => 'Plusieurs tentatives de connexion ont échoué sur votre compte.'],
    'security_alert_integrity_failure' => ['title' => 'Échec du contrôle d’intégrité de l’appareil', 'body' => 'Un appareil utilisant votre compte a échoué au contrôle d’intégrité. Les actions sensibles y sont limitées.'],
    'security_alert_payout_destination_changed' => ['title' => 'Compte de versement modifié', 'body' => 'Le compte sur lequel vos versements sont envoyés a été modifié. Si ce n’était pas vous, contactez immédiatement le support.'],
    'security_alert_access_suspended' => ['title' => 'Accès suspendu', 'body' => 'Votre organisation a suspendu votre accès.'],
    'security_alert_reauth_required' => ['title' => 'Reconnectez-vous', 'body' => 'Votre organisation vous demande de vous reconnecter sur chaque appareil.'],

    // S8 couverture lancement (LaunchNotificationRouter)
    'complaint_received' => ['title' => 'Réclamation reçue', 'body' => 'Nous avons enregistré votre réclamation :reference. Nous en accuserons réception sous 2 jours ouvrés.'],
    'complaint_acknowledged' => ['title' => 'Réclamation prise en charge', 'body' => 'Votre réclamation :reference a été prise en charge et est en cours d’instruction.'],
    'complaint_information_needed' => ['title' => 'Informations requises sur votre réclamation', 'body' => 'Nous avons besoin de précisions pour traiter la réclamation :reference. Contactez-nous ou répondez dans l’application.'],
    'complaint_resolution' => ['title' => 'Réponse à votre réclamation', 'body' => 'Nous avons envoyé notre réponse à la réclamation :reference. Si elle ne vous satisfait pas, vous pouvez la faire remonter.'],
    'complaint_escalated' => ['title' => 'Réclamation transmise', 'body' => 'La réclamation :reference a été transmise pour un examen approfondi.'],
    'complaint_closed' => ['title' => 'Réclamation clôturée', 'body' => 'La réclamation :reference est clôturée.'],
    'kyc_approved' => ['title' => 'Identité vérifiée', 'body' => 'La vérification de votre identité a été approuvée.'],
    'kyc_rejected' => ['title' => 'Vérification d’identité non approuvée', 'body' => 'La vérification de votre identité n’a pas pu être approuvée. Ouvrez votre profil pour connaître la suite.'],
    'kyc_information_requested' => ['title' => 'Informations requises pour la vérification', 'body' => 'Nous avons besoin d’informations complémentaires pour vérifier votre identité. Ouvrez votre profil pour répondre.'],
    'kyc_remediation_requested' => ['title' => 'Mettez à jour vos pièces d’identité', 'body' => 'Vos pièces d’identité doivent être mises à jour. Ouvrez votre profil pour en soumettre de nouvelles.'],
    'kyc_expired' => ['title' => 'Vérification d’identité expirée', 'body' => 'La vérification de votre identité a expiré. Ouvrez votre profil pour la renouveler.'],
    'agent_payment_requested' => ['title' => 'Paiement demandé par votre conseiller', 'body' => 'Votre conseiller vous invite à régler le devis :reference. Ouvrez l’application pour payer.'],
    'proposal_conditional_offer' => ['title' => 'Accord sous conditions', 'body' => 'Votre proposition a été acceptée sous conditions. Consultez les conditions avant de payer.'],
    'endorsement_issued' => ['title' => 'Modification de police confirmée', 'body' => 'La modification de la police :policy a été émise. Vos documents à jour sont dans votre portefeuille.'],
    'claim_expert_assigned' => ['title' => 'Expert désigné sur votre sinistre', 'body' => 'Un expert a été désigné pour le sinistre :claim et vous contactera pour organiser une expertise.'],
    'claim_inspection_scheduled' => ['title' => 'Expertise programmée', 'body' => 'Une expertise a été programmée pour le sinistre :claim. Ouvrez le sinistre pour voir la date et le lieu.'],
    'expert_assignment_new' => ['title' => 'Nouvelle mission d’expertise', 'body' => 'Vous avez été désigné sur le sinistre :claim. Acceptez ou refusez la mission.'],
    'settlement_offered' => ['title' => 'Offre d’indemnisation disponible', 'body' => 'Une offre d’indemnisation est disponible pour le sinistre :claim. Ouvrez-le pour l’accepter ou la contester.'],
    'settlement_discharge_requested' => ['title' => 'Quittance à signer', 'body' => 'Veuillez signer la quittance du sinistre :claim pour permettre le paiement.'],
    'refund_requested' => ['title' => 'Remboursement demandé', 'body' => 'Un remboursement :reference a été demandé sur votre paiement. Nous vous préviendrons dès son approbation.'],
    'refund_approved' => ['title' => 'Remboursement approuvé', 'body' => 'Le remboursement :reference a été approuvé et sera versé sur votre moyen de paiement initial.'],
    'commission_statement_ready' => ['title' => 'Relevé de commissions disponible', 'body' => 'Le relevé de commissions :reference a été approuvé et peut être consulté.'],
    'commission_statement_dispute_resolved' => ['title' => 'Contestation de relevé résolue', 'body' => 'La contestation du relevé de commissions :reference a été résolue.'],
    'payout_approved' => ['title' => 'Versement approuvé', 'body' => 'Le versement :reference a été approuvé.'],
    'payout_paid' => ['title' => 'Versement effectué', 'body' => 'Le versement :reference a été payé.'],
    'payout_reversed' => ['title' => 'Versement annulé', 'body' => 'Le versement :reference a été annulé. Contactez la finance pour plus de détails.'],
    'bordereau_approved' => ['title' => 'Bordereau approuvé', 'body' => 'Le bordereau :reference a été approuvé.'],
    'bordereau_acknowledged' => ['title' => 'Bordereau accepté par l’assureur', 'body' => 'L’assureur a accusé réception du bordereau :reference.'],
    'bordereau_rejected' => ['title' => 'Bordereau rejeté par l’assureur', 'body' => 'L’assureur a rejeté le bordereau :reference. Ouvrez-le pour voir le motif.'],
    'staff_invitation' => ['title' => 'Invitation à rejoindre OpesInsure', 'body' => 'Vous êtes invité à rejoindre une équipe sur OpesInsure. Code d’invitation : :code (valable :hours heures).'],
    'document_scan_clean' => ['title' => 'Document accepté', 'body' => 'Votre document a passé le contrôle de sécurité.'],
    'document_scan_infected' => ['title' => 'Document refusé', 'body' => 'Votre document n’a pas passé le contrôle de sécurité. Veuillez en téléverser une copie saine.'],
];
