<?php

// Lancement Q3 — écrans web agent (AGT-004/005/014/015/016/018/019/026/028/029/030/031/032/033/034).
return [
    'dash_t' => 'Tableau de bord agent', 'dash_lede' => 'Votre pipeline, devis, propositions, paiements, commissions et renouvellements en un coup d\'œil.',
    'actions_t' => 'Centre d\'actions', 'actions_lede' => 'Tout ce qui vous attend aujourd\'hui, dans une seule liste.',
    'kyc_t' => 'KYC client', 'kyc_lede' => 'Vérifiez le dossier d\'identité du client et saisissez les pièces manquantes.',
    'activities_t' => 'Activités client', 'activities_lede' => 'Toutes les interactions et opérations de ce client, les plus récentes d\'abord.',
    'products_t' => 'Détails du produit', 'products_lede' => 'Ce que vous pouvez vendre : garanties, exclusions et assureurs.',
    'needs_t' => 'Analyse des besoins', 'needs_lede' => 'Quelques questions pour trouver les bonnes garanties pour votre client.',
    'send_t' => 'Envoyer le devis', 'send_lede' => 'Partagez ce devis avec votre client.',
    'lost_t' => 'Devis perdu', 'lost_lede' => 'Indiquez pourquoi le client n\'a pas donné suite.',
    'builder_t' => 'Création de proposition', 'builder_lede' => 'Transformez une offre acceptée en proposition pour l\'assureur.',
    'proposal_t' => 'Revue de la proposition', 'proposal_lede' => 'Proposition, état de la souscription et offre conditionnelle.',
    'pdocs_t' => 'Documents de la proposition', 'pdocs_lede' => 'Pièces exigées par l\'assureur pour cette proposition.',
    'info_t' => 'Demande d\'informations', 'info_lede' => 'Répondez à la demande de l\'assureur et renvoyez la proposition.',
    'js' => [
        'loading' => 'Chargement…', 'none' => 'Rien à afficher.', 'back' => 'Retour', 'open' => 'Ouvrir', 'save' => 'Enregistrer', 'cancel' => 'Annuler',
        'client' => 'Client', 'choose_client' => 'Choisissez un client…', 'status' => 'Statut', 'date' => 'Date', 'amount' => 'Montant', 'line' => 'Branche', 'insurer' => 'Assureur', 'product' => 'Produit',
        'premium' => 'Prime', 'expires' => 'Expire', 'created' => 'Créé', 'actions' => 'Actions', 'view_all' => 'Tout voir', 'reason' => 'Motif', 'note' => 'Note',
        // dashboard
        'd_clients' => 'Clients', 'd_leads' => 'Prospects ouverts', 'd_quotes' => 'Devis en attente', 'd_proposals' => 'Propositions en cours', 'd_pay' => 'Paiements attendus',
        'd_comm' => 'Commission disponible', 'd_comm_p' => 'Commission en attente', 'd_ren' => 'Renouvellements dus', 'd_pipeline' => 'Pipeline des prospects',
        'd_quotes_h' => 'Devis en attente du client', 'd_props_h' => 'Propositions', 'd_pay_h' => 'Paiements attendus', 'd_ren_h' => 'Renouvellements proches', 'd_quick' => 'Actions rapides',
        'q_new_quote' => 'Nouveau devis', 'q_new_lead' => 'Nouveau prospect', 'q_actions' => 'Centre d\'actions', 'q_needs' => 'Analyse des besoins', 'q_products' => 'Produits', 'q_builder' => 'Créer une proposition',
        'no_quotes' => 'Aucun devis n\'attend de client.', 'no_props' => 'Aucune proposition en cours.', 'no_pay' => 'Aucun paiement attendu.', 'no_ren' => 'Aucun renouvellement dû.',
        // action centre
        'a_all' => 'Tout', 'a_urgent' => 'Urgent', 'a_none' => 'Vous êtes à jour.', 'a_quote_exp' => 'Le devis expire dans :d jour(s)', 'a_quote_follow' => 'Devis envoyé — relancez le client',
        'a_prop_info' => 'L\'assureur demande des informations', 'a_prop_counter' => 'Offre conditionnelle en attente du client', 'a_prop_draft' => 'Proposition non encore soumise',
        'a_prop_pay' => 'Approuvée — prime non encore payée', 'a_ren' => 'Renouvellement dû le :date', 'a_lead_new' => 'Nouveau prospect à contacter', 'a_kyc' => 'KYC non vérifié (:s)',
        'a_type' => 'Tâche', 'a_due' => 'Échéance', 'a_do' => 'Traiter',
        // kyc
        'kyc_status' => 'Statut KYC', 'kyc_level' => 'Niveau', 'kyc_none' => 'Pas encore de dossier KYC. Saisissez une première pièce pour l\'ouvrir.', 'kyc_req' => 'Exigences',
        'kyc_missing' => 'Manquant', 'kyc_ok' => 'Satisfait', 'kyc_docs' => 'Pièces au dossier', 'kyc_capture' => 'Saisir une pièce', 'kyc_purpose' => 'Exigence',
        'kyc_file' => 'Fichier (PDF, JPG ou PNG, 10 Mo max.)', 'kyc_upload' => 'Téléverser et joindre', 'kyc_uploaded' => 'Pièce jointe au dossier KYC.',
        'kyc_submit' => 'Soumettre pour vérification', 'kyc_submitted' => 'KYC soumis pour vérification.', 'kyc_notes' => 'Notes pour le vérificateur', 'kyc_locked' => 'Ce dossier est en cours de vérification ; aucune modification possible pour le moment.',
        'file_required' => 'Choisissez d\'abord un fichier.', 'file_type' => 'Seuls les fichiers PDF, JPG ou PNG sont acceptés.', 'file_big' => 'Le fichier dépasse 10 Mo.',
        'scan' => 'Analyse', 'verification' => 'Vérification', 'other_doc' => 'Autre pièce',
        // activities
        'act_type' => 'Type', 'act_quote' => 'Devis', 'act_proposal' => 'Proposition', 'act_policy' => 'Police', 'act_claim' => 'Sinistre', 'act_note' => 'Journal',
        'act_log' => 'Consigner une activité', 'act_body' => 'Que s\'est-il passé ?', 'act_logged' => 'Activité consignée.', 'act_no_lead' => 'Les notes sont tenues dans le journal du prospect ; ce client n\'a pas de fiche prospect.',
        'act_filter' => 'Afficher', 'act_kinds' => ['NOTE' => 'Note', 'CALL' => 'Appel', 'MEETING' => 'Rendez-vous', 'FOLLOW_UP' => 'Relance'],
        // products
        'p_search' => 'Rechercher un produit…', 'p_sellable' => 'Disponible à la vente', 'p_blocked' => 'Non disponible', 'p_covers' => 'Garanties', 'p_excl' => 'Exclusions', 'p_quote' => 'Établir un devis',
        'p_pick' => 'Choisissez un produit pour voir ses détails.', 'p_commission' => 'Commission', 'p_mandatory' => 'Obligatoire', 'p_optional' => 'Facultative', 'p_approval' => 'Accord de l\'assureur requis',
        // needs
        'n_q' => [
            'vehicle' => 'Le client possède-t-il ou conduit-il un véhicule ?', 'travel' => 'Voyage à l\'étranger dans les 12 prochains mois ?', 'home' => 'Propriétaire ou locataire d\'un logement à protéger ?',
            'health' => 'Souhaite couvrir ses frais médicaux (lui ou sa famille) ?', 'dependants' => 'Des personnes dépendent-elles de ses revenus ?', 'business' => 'Dirige-t-il une entreprise ou un commerce ?',
            'accident' => 'Métier physique ou à risque, ou conduit une moto ?',
        ],
        'n_yes' => 'Oui', 'n_no' => 'Non', 'n_budget' => 'Budget mensuel pour l\'assurance (FCFA)', 'n_result' => 'Garanties recommandées', 'n_run' => 'Voir les recommandations',
        'n_none' => 'Répondez aux questions pour voir les recommandations.', 'n_why' => [
            'MOTOR' => 'La responsabilité civile automobile est obligatoire au Cameroun pour tout véhicule.', 'TRAVEL' => 'Les demandes de visa Schengen exigent une assurance voyage médicale.',
            'HOME' => 'Protège le logement et son contenu contre l\'incendie, les dégâts des eaux et le vol.', 'HEALTH' => 'Couvre l\'hospitalisation, les consultations et les médicaments.',
            'LIFE' => 'Protège le revenu de la famille en cas de coup dur.', 'BUSINESS' => 'Couvre les locaux, le stock et la responsabilité de l\'entreprise.', 'ACCIDENT' => 'Indemnise en cas de blessure, d\'invalidité ou de décès accidentels.',
        ],
        'n_quote' => 'Devis', 'n_details' => 'Détails', 'n_saved' => 'Analyse enregistrée dans le journal du prospect.', 'n_save' => 'Enregistrer dans le journal', 'n_lead' => 'Prospect (facultatif)',
        // send / lost
        's_channel' => 'Canal', 's_recipient' => 'Destinataire (e-mail ou téléphone)', 's_send' => 'Envoyer le devis', 's_sent' => 'Devis envoyé. Lien de partage :', 's_copy' => 'Copier le lien', 's_copied' => 'Lien copié.',
        's_channels' => ['EMAIL' => 'E-mail', 'SMS' => 'SMS', 'WHATSAPP' => 'WhatsApp', 'LINK' => 'Lien uniquement', 'IN_APP' => 'Dans l\'application du client'],
        'l_reasons' => ['CUSTOMER_DECLINED' => 'Refus du client', 'PRICE_TOO_HIGH' => 'Prix trop élevé', 'COVER_NOT_SUITABLE' => 'Garantie inadaptée', 'LOST_TO_COMPETITOR' => 'Perdu face à un concurrent', 'NO_RESPONSE' => 'Sans réponse', 'DUPLICATE' => 'Doublon', 'OTHER' => 'Autre'],
        'l_submit' => 'Marquer comme perdu', 'l_confirm' => 'Marquer ce devis comme perdu ? Il ne pourra pas être rouvert.', 'l_done' => 'Devis marqué comme perdu.', 'q_offers' => 'Offres', 'q_best' => 'Meilleur prix',
        'q_compare' => 'Comparer les offres', 'q_customize' => 'Configurer les garanties', 'q_send' => 'Envoyer le devis', 'q_lost' => 'Marquer perdu', 'q_closed' => 'Ce devis est clôturé.',
        // builder
        'b_quote' => 'Devis', 'b_pick_quote' => 'Choisissez un devis tarifé…', 'b_offer' => 'Offre', 'b_go' => 'Créer la proposition', 'b_no_quotes' => 'Ce client n\'a pas encore de devis tarifé.',
        // proposal
        'pr_number' => 'Proposition', 'pr_uw' => 'État de la souscription', 'pr_case' => 'Dossier de souscription', 'pr_decisions' => 'Décisions', 'pr_checklist' => 'Liste de contrôle',
        'pr_questions' => 'Questions répondues', 'pr_attested' => 'Déclaration signée', 'pr_decl' => 'Déclarations', 'pr_docs' => 'Documents', 'pr_blockers' => 'Encore requis avant soumission',
        'pr_counter' => 'Offre conditionnelle', 'pr_counter_d' => 'L\'assureur propose des conditions révisées. Seul le client peut les accepter ou les refuser, depuis son application ou son compte OpesInsure.',
        'pr_counter_terms' => 'Conditions révisées', 'pr_conditions' => 'Conditions', 'pr_open_builder' => 'Continuer dans l\'assistant', 'pr_submit' => 'Soumettre à l\'assureur', 'pr_submitted' => 'Proposition soumise.',
        'pr_withdraw' => 'Retirer', 'pr_withdraw_q' => 'Pourquoi la proposition est-elle retirée ?', 'pr_withdrawn' => 'Proposition retirée.', 'pr_answer_info' => 'Répondre à la demande',
        'pr_manage_docs' => 'Gérer les documents', 'pr_yes' => 'Oui', 'pr_no' => 'Non', 'pr_steps' => ['DRAFT' => 'Brouillon', 'SUBMITTED' => 'Soumise', 'REVIEWING' => 'En étude', 'INFORMATION_REQUIRED' => 'Informations', 'COUNTEROFFERED' => 'Offre conditionnelle', 'APPROVED' => 'Approuvée'],
        'pr_pay' => 'Encaisser la prime', 'pr_policy' => 'Ouvrir la police',
        'd_req' => 'Exigence', 'd_form' => 'Produit à partir du formulaire de proposition','d_level' => 'Niveau', 'd_upload' => 'Téléverser', 'd_done' => 'Document rattaché à la proposition.', 'd_locked' => 'Les documents ne peuvent plus être modifiés sur cette proposition.',
        // info
        'i_items' => 'Éléments demandés', 'i_message' => 'Message de l\'assureur', 'i_response' => 'Votre réponse', 'i_send' => 'Renvoyer à l\'assureur', 'i_sent' => 'Proposition renvoyée à l\'assureur.',
        'i_none' => 'L\'assureur n\'a demandé aucune information sur cette proposition.', 'i_requested' => 'Demandé le',
    ],
];
