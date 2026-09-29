<?php

declare(strict_types=1);

// Actions du service client, des réclamations, des notifications et du centre de sécurité (SupportActions,
// AccountSecurityActions, SecurityFindingResource) et compléments du portail /account (sécurité du compte, assistance).
return [
    'case_group' => 'Actions sur le dossier',
    'complaint_group' => 'Réclamation',

    'caseLinkLegacy' => ['label' => 'Rattacher un élément existant', 'help' => 'Rattache un dossier de souscription, une tâche de référé, un dossier de conformité ou un ticket d\'assistance au moteur de dossiers. Les tickets de réclamation deviennent des réclamations.', 'done' => 'Élément rattaché à un dossier'],
    'caseDecide' => ['label' => 'Enregistrer une décision', 'help' => 'Les décisions sont définitives ; pour en modifier une, enregistrez une nouvelle décision qui l\'annule.', 'done' => 'Décision enregistrée'],
    'caseDiary' => ['label' => 'Ajouter une note au journal', 'help' => 'Notes, appels, réunions et relances sont conservés dans le journal du dossier.', 'done' => 'Note ajoutée'],
    'caseReclassify' => ['label' => 'Modifier le sous-type', 'help' => 'Le changement de sous-type peut recalculer les délais de traitement (SLA).', 'done' => 'Dossier reclassé'],
    'caseAddTask' => ['label' => 'Ajouter une tâche', 'help' => 'Ajoute une tâche à ce dossier ouvert.', 'done' => 'Tâche ajoutée'],
    'caseTaskTransition' => ['label' => 'Mettre à jour une tâche', 'help' => 'Fait évoluer l\'une des tâches ouvertes de ce dossier.', 'done' => 'Tâche mise à jour'],

    'complaintSubmit' => ['label' => 'Enregistrer une réclamation', 'help' => 'Ouvre un dossier de réclamation et enregistre la réclamation comme courrier entrant (preuve de réception).', 'done' => 'Réclamation enregistrée'],
    'complaintFromTicket' => ['label' => 'Traiter comme réclamation', 'help' => 'Rattache ce ticket de réclamation à un dossier de réclamation ; le ticket reflète ensuite le statut du dossier.', 'done' => 'Ticket traité comme réclamation'],
    'complaintAcknowledge' => ['label' => 'Accuser réception', 'help' => 'Enregistre l\'accusé de réception adressé au réclamant.', 'done' => 'Accusé de réception enregistré'],
    'complaintClassify' => ['label' => 'Qualifier', 'help' => 'La gravité fixe la priorité du dossier ; une réclamation réglementaire passe au sous-type RÉGLEMENTAIRE.', 'done' => 'Réclamation qualifiée'],
    'complaintAssign' => ['label' => 'Désigner l\'instructeur', 'help' => 'L\'instructeur doit être un membre actif de cette organisation.', 'done' => 'Instructeur désigné'],
    'complaintInvestigate' => ['label' => 'Démarrer l\'instruction', 'help' => 'Passe la réclamation en cours d\'instruction.', 'done' => 'Instruction démarrée'],
    'complaintResolution' => ['label' => 'Proposer une solution', 'help' => 'Enregistre la décision de règlement sur le dossier.', 'done' => 'Solution proposée'],
    'complaintCommunicate' => ['label' => 'Enregistrer la réponse définitive', 'help' => 'Choisissez la réponse sortante déjà enregistrée et expédiée avec preuve sur ce dossier.', 'done' => 'Réponse définitive enregistrée'],
    'complaintEscalate' => ['label' => 'Porter la réclamation', 'help' => 'Saisine de l\'autorité nationale ou de la CIMA.', 'done' => 'Réclamation portée'],
    'complaintAdvance' => ['label' => 'Faire évoluer la réclamation', 'help' => 'Demander des informations, en constater la réception, rouvrir l\'instruction ou clôturer.', 'done' => 'Réclamation mise à jour'],

    'ticketOpen' => ['label' => 'Ouvrir un ticket', 'help' => 'Ouvre un ticket d\'assistance ; le délai de traitement dépend de la priorité.', 'done' => 'Ticket ouvert'],
    'ticketTransition' => ['label' => 'Changer le statut du ticket', 'help' => 'Seuls les changements prévus par le cycle de vie du ticket sont acceptés.', 'done' => 'Statut du ticket modifié'],

    'notificationQueue' => ['label' => 'Envoyer une notification', 'help' => 'Met en file un message issu d\'un modèle actif. Les préférences de canal du client sont respectées.', 'done' => 'Notification mise en file'],
    'notificationRetry' => ['label' => 'Relancer l\'envoi', 'help' => 'Programme une nouvelle tentative. Au-delà du nombre maximal de tentatives, le message est placé en rebut.', 'done' => 'Nouvelle tentative programmée'],
    'notificationCancel' => ['label' => 'Annuler l\'envoi', 'help' => 'Empêche l\'envoi d\'un message en file ou en échec.', 'done' => 'Envoi annulé'],

    'findingReport' => ['label' => 'Signaler une vulnérabilité', 'help' => 'Enregistre une vulnérabilité dans le registre du centre de sécurité.', 'done' => 'Vulnérabilité signalée'],
    'findingTransition' => ['label' => 'Changer le statut de la vulnérabilité', 'help' => 'L\'acceptation d\'un risque exige une justification, une date d\'expiration future et une personne autre que le déclarant ou le responsable.', 'done' => 'Vulnérabilité mise à jour',
        'accept_risk_denied' => 'L\'acceptation d\'un risque de sécurité requiert la permission security.findings.accept_risk.'],

    'nav' => ['security_findings' => 'Vulnérabilités de sécurité', 'security_finding' => 'vulnérabilité de sécurité'],

    'columns' => ['reference' => 'Référence', 'title' => 'Intitulé', 'severity' => 'Gravité', 'source' => 'Origine'],

    'fields' => [
        'source' => 'Origine', 'source_id' => 'Identifiant de l\'élément', 'decision_type' => 'Type de décision', 'outcome' => 'Issue', 'rationale' => 'Motivation',
        'conditions' => 'Conditions', 'reverses_decision' => 'Décision annulée', 'entry_type' => 'Type de note', 'body' => 'Texte', 'follow_up_at' => 'Relance le',
        'visibility' => 'Visibilité', 'case_subtype' => 'Sous-type', 'reason' => 'Motif', 'title' => 'Intitulé', 'task_type' => 'Type de tâche', 'assignee' => 'Attribué à',
        'due_at' => 'Échéance', 'task' => 'Tâche', 'task_status' => 'Nouveau statut', 'complainant_name' => 'Réclamant', 'complainant_contact' => 'Coordonnées du réclamant',
        'channel' => 'Canal', 'description' => 'Description', 'regulatory' => 'Réclamation réglementaire', 'received_at' => 'Reçue le', 'category' => 'Catégorie',
        'severity' => 'Gravité', 'investigator' => 'Instructeur', 'resolution_summary' => 'Résumé de la solution', 'root_cause' => 'Cause racine',
        'redress_amount' => 'Montant de la réparation (XAF)', 'resolution_reason' => 'Motif de règlement', 'correspondence' => 'Réponse définitive', 'level' => 'Niveau',
        'reference' => 'Référence externe', 'event' => 'Étape', 'customer' => 'Client', 'ticket_type' => 'Type de ticket', 'priority' => 'Priorité',
        'subject' => 'Objet', 'to_status' => 'Nouveau statut', 'message' => 'Message', 'template' => 'Modèle', 'destination' => 'Destinataire (téléphone ou e-mail)',
        'variables' => 'Variables du modèle', 'affected_asset' => 'Actif concerné', 'cve' => 'CVE', 'owner' => 'Responsable', 'notes' => 'Observations',
        'remediation_plan' => 'Plan de correction', 'risk_acceptance_expires_at' => 'Fin de l\'acceptation du risque',
    ],

    'sources' => ['underwriting_cases' => 'Dossier de souscription', 'underwriting_referral_tasks' => 'Tâche de référé de souscription', 'compliance_cases' => 'Dossier de conformité', 'support_tickets' => 'Ticket d\'assistance'],
    'entry_types' => ['NOTE' => 'Note', 'CALL' => 'Appel', 'MEETING' => 'Réunion', 'FOLLOW_UP' => 'Relance'],
    'visibility' => ['INTERNAL' => 'Interne', 'SHARED' => 'Partagée'],
    'task_statuses' => ['IN_PROGRESS' => 'En cours', 'BLOCKED' => 'Bloquée', 'DONE' => 'Terminée', 'CANCELLED' => 'Annulée'],
    'channels' => ['EMAIL' => 'E-mail', 'SMS' => 'SMS', 'WHATSAPP' => 'WhatsApp', 'LETTER' => 'Courrier', 'COURIER' => 'Coursier', 'PORTAL' => 'Portail', 'PHONE' => 'Téléphone', 'IN_PERSON' => 'En agence'],
    'severities' => ['INFO' => 'Information', 'LOW' => 'Faible', 'MEDIUM' => 'Moyenne', 'HIGH' => 'Élevée', 'CRITICAL' => 'Critique'],
    'outcomes' => ['UPHELD' => 'Fondée', 'PARTIALLY_UPHELD' => 'Partiellement fondée', 'NOT_UPHELD' => 'Non fondée'],
    'levels' => ['NATIONAL' => 'Autorité nationale', 'CIMA' => 'CIMA'],
    'events' => ['request_info' => 'Demander des informations au réclamant', 'info_received' => 'Informations reçues', 'reinvestigate' => 'Rouvrir l\'instruction', 'close' => 'Clôturer'],
    'ticket_types' => ['SUPPORT' => 'Assistance', 'COMPLAINT' => 'Réclamation', 'REGULATORY_COMPLAINT' => 'Réclamation réglementaire'],
    'priorities' => ['LOW' => 'Basse', 'NORMAL' => 'Normale', 'HIGH' => 'Haute', 'URGENT' => 'Urgente'],
    'ticket_statuses' => ['TRIAGED' => 'Qualifié', 'IN_PROGRESS' => 'En cours', 'WAITING_CUSTOMER' => 'En attente du client', 'ESCALATED' => 'Escaladé',
        'RESOLVED' => 'Résolu', 'CLOSED' => 'Clôturé', 'REOPENED' => 'Rouvert', 'CANCELLED' => 'Annulé'],
    'finding_sources' => ['PENTEST' => 'Test d\'intrusion', 'SAST' => 'Analyse statique', 'DAST' => 'Analyse dynamique', 'DEPENDENCY_SCAN' => 'Analyse des dépendances',
        'BUG_BOUNTY' => 'Programme de primes aux bogues', 'INTERNAL_REVIEW' => 'Revue interne', 'INCIDENT' => 'Incident', 'AUDIT' => 'Audit', 'MOBILE_MASVS' => 'Mobile (MASVS)'],
    'finding_statuses' => ['OPEN' => 'Rouvrir', 'TRIAGED' => 'Qualifiée', 'IN_REMEDIATION' => 'En correction', 'RESOLVED' => 'Corrigée', 'RISK_ACCEPTED' => 'Risque accepté', 'FALSE_POSITIVE' => 'Faux positif'],

    // Portail /account (clients).
    'portal' => [
        'escalate' => 'Signaler comme urgent', 'escalate_reason' => 'Pourquoi est-ce urgent ? (facultatif)', 'escalated' => 'Votre demande a été signalée comme urgente. Nous revenons vers vous sous 4 heures.',
        'issue_title' => 'Signaler un problème sur ce site', 'issue_text' => 'Quelque chose ne fonctionne pas ? Décrivez-nous ce qui s\'est passé sur cette page.', 'issue_note' => 'Que s\'est-il passé ?',
        'issue_send' => 'Envoyer le signalement', 'issue_done' => 'Merci, votre signalement a été transmis.',
        'security_title' => 'Connexion et sécurité',
        'email_verify' => 'Envoyer le lien de confirmation de l\'e-mail', 'email_sent' => 'Nous avons envoyé un lien de confirmation à votre adresse e-mail.', 'email_already' => 'Votre adresse e-mail est déjà confirmée.',
        'email_none' => 'Ajoutez d\'abord une adresse e-mail à votre profil.', 'email_off' => 'L\'envoi d\'e-mails est momentanément indisponible. Veuillez réessayer plus tard.',
        'phone_verify' => 'Recevoir un code de vérification par téléphone', 'phone_code' => 'Code reçu', 'phone_confirm' => 'Confirmer le numéro de téléphone',
        'phone_done' => 'Votre numéro de téléphone est vérifié.', 'phone_already' => 'Votre numéro de téléphone est déjà vérifié.', 'phone_sent' => 'Nous vous avons envoyé un code à 6 chiffres.',
        'mfa_title' => 'Validation en deux étapes (application d\'authentification)', 'mfa_start' => 'Configurer une application d\'authentification',
        'mfa_secret' => 'Ajoutez cette clé dans votre application d\'authentification, puis saisissez le code à 6 chiffres affiché :', 'mfa_code' => 'Code à 6 chiffres', 'mfa_confirm' => 'Activer la validation en deux étapes',
        'mfa_done' => 'La validation en deux étapes est activée. Conservez ces codes de secours en lieu sûr :',
        'password_title' => 'Modifier le mot de passe', 'password_current' => 'Mot de passe actuel', 'password_new' => 'Nouveau mot de passe (8 caractères minimum)',
        'password_confirm' => 'Confirmer le nouveau mot de passe', 'password_save' => 'Modifier le mot de passe', 'password_mismatch' => 'Les deux nouveaux mots de passe ne correspondent pas.',
        'password_done' => 'Mot de passe modifié. Par sécurité, toutes vos sessions ont été fermées ; veuillez vous reconnecter.',
        'invite_title' => 'Rejoindre une organisation', 'invite_text' => 'Collez le code d\'invitation reçu pour rejoindre l\'équipe de votre courtier ou assureur.',
        'invite_token' => 'Code d\'invitation', 'invite_accept' => 'Accepter l\'invitation', 'invite_done' => 'Invitation acceptée. Vous faites désormais partie de :tenant.',
    ],
];
