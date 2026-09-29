<?php

// Q3 launch — agent web screens (AGT-004/005/014/015/016/018/019/026/028/029/030/031/032/033/034).
return [
    'dash_t' => 'Agent Dashboard', 'dash_lede' => 'Your pipeline, quotes, proposals, payments, commissions and renewals at a glance.',
    'actions_t' => 'Action Centre', 'actions_lede' => 'Everything that needs you today, in one list.',
    'kyc_t' => 'Customer KYC', 'kyc_lede' => 'Check the client\'s identity file and capture the missing documents.',
    'activities_t' => 'Customer Activities', 'activities_lede' => 'Every interaction and record for this client, newest first.',
    'products_t' => 'Product Details', 'products_lede' => 'What you can sell: covers, exclusions and insurers.',
    'needs_t' => 'Needs Assessment', 'needs_lede' => 'A few questions to find the right covers for your client.',
    'send_t' => 'Send Quote', 'send_lede' => 'Share this quote with your client.',
    'lost_t' => 'Lost Quote', 'lost_lede' => 'Record why the client did not go ahead.',
    'builder_t' => 'Proposal Builder', 'builder_lede' => 'Turn an accepted offer into a proposal for the insurer.',
    'proposal_t' => 'Proposal Review', 'proposal_lede' => 'Proposal, underwriting status and conditional offer.',
    'pdocs_t' => 'Proposal Documents', 'pdocs_lede' => 'Documents the insurer requires for this proposal.',
    'info_t' => 'Information Request', 'info_lede' => 'Answer the insurer\'s request and send the proposal back.',
    'js' => [
        'loading' => 'Loading…', 'none' => 'Nothing to show.', 'back' => 'Back', 'open' => 'Open', 'save' => 'Save', 'cancel' => 'Cancel',
        'client' => 'Client', 'choose_client' => 'Choose a client…', 'status' => 'Status', 'date' => 'Date', 'amount' => 'Amount', 'line' => 'Product line', 'insurer' => 'Insurer', 'product' => 'Product',
        'premium' => 'Premium', 'expires' => 'Expires', 'created' => 'Created', 'actions' => 'Actions', 'view_all' => 'View all', 'reason' => 'Reason', 'note' => 'Note',
        // dashboard
        'd_clients' => 'Clients', 'd_leads' => 'Open leads', 'd_quotes' => 'Quotes awaiting', 'd_proposals' => 'Proposals in progress', 'd_pay' => 'Payments due',
        'd_comm' => 'Commission available', 'd_comm_p' => 'Commission pending', 'd_ren' => 'Renewals due', 'd_pipeline' => 'Lead pipeline',
        'd_quotes_h' => 'Quotes awaiting the client', 'd_props_h' => 'Proposals', 'd_pay_h' => 'Payments due', 'd_ren_h' => 'Renewals due soon', 'd_quick' => 'Quick actions',
        'q_new_quote' => 'New quote', 'q_new_lead' => 'New lead', 'q_actions' => 'Action Centre', 'q_needs' => 'Needs assessment', 'q_products' => 'Products', 'q_builder' => 'Build a proposal',
        'no_quotes' => 'No quote is waiting for a client.', 'no_props' => 'No proposal in progress.', 'no_pay' => 'No payment is due.', 'no_ren' => 'No renewal is due.',
        // action centre
        'a_all' => 'All', 'a_urgent' => 'Urgent', 'a_none' => 'You are all caught up.', 'a_quote_exp' => 'Quote expires in :d day(s)', 'a_quote_follow' => 'Quote sent — follow up with the client',
        'a_prop_info' => 'The insurer asked for more information', 'a_prop_counter' => 'Conditional offer waiting for the client', 'a_prop_draft' => 'Proposal not submitted yet',
        'a_prop_pay' => 'Approved — premium not paid yet', 'a_ren' => 'Renewal due :date', 'a_lead_new' => 'New lead to contact', 'a_kyc' => 'KYC not verified (:s)',
        'a_type' => 'Task', 'a_due' => 'Due', 'a_do' => 'Do it',
        // kyc
        'kyc_status' => 'KYC status', 'kyc_level' => 'Level', 'kyc_none' => 'No KYC file yet. Capture a first document to start one.', 'kyc_req' => 'Requirements',
        'kyc_missing' => 'Missing', 'kyc_ok' => 'Satisfied', 'kyc_docs' => 'Documents on file', 'kyc_capture' => 'Capture a document', 'kyc_purpose' => 'Requirement',
        'kyc_file' => 'File (PDF, JPG or PNG, max 10 MB)', 'kyc_upload' => 'Upload and attach', 'kyc_uploaded' => 'Document attached to the KYC file.',
        'kyc_submit' => 'Submit for review', 'kyc_submitted' => 'KYC submitted for review.', 'kyc_notes' => 'Notes for the reviewer', 'kyc_locked' => 'This file is under review; no change is possible now.',
        'file_required' => 'Choose a file first.', 'file_type' => 'Only PDF, JPG or PNG files are accepted.', 'file_big' => 'The file is larger than 10 MB.',
        'scan' => 'Scan', 'verification' => 'Verification', 'other_doc' => 'Other document',
        // activities
        'act_type' => 'Type', 'act_quote' => 'Quote', 'act_proposal' => 'Proposal', 'act_policy' => 'Policy', 'act_claim' => 'Claim', 'act_note' => 'Diary',
        'act_log' => 'Log an activity', 'act_body' => 'What happened?', 'act_logged' => 'Activity logged.', 'act_no_lead' => 'Notes are kept in the lead diary; this client has no lead record.',
        'act_filter' => 'Show', 'act_kinds' => ['NOTE' => 'Note', 'CALL' => 'Call', 'MEETING' => 'Meeting', 'FOLLOW_UP' => 'Follow-up'],
        // products
        'p_search' => 'Search products…', 'p_sellable' => 'Available to sell', 'p_blocked' => 'Not available', 'p_covers' => 'Covers', 'p_excl' => 'Exclusions', 'p_quote' => 'Quote this product',
        'p_pick' => 'Choose a product to see its details.', 'p_commission' => 'Commission', 'p_mandatory' => 'Mandatory', 'p_optional' => 'Optional', 'p_approval' => 'Needs insurer approval',
        // needs
        'n_q' => [
            'vehicle' => 'Does the client own or drive a vehicle?', 'travel' => 'Travelling abroad in the next 12 months?', 'home' => 'Owns or rents a home to protect?',
            'health' => 'Wants cover for medical costs (self or family)?', 'dependants' => 'Has people depending on their income?', 'business' => 'Runs a business or shop?',
            'accident' => 'Works in a physical or risky job, or rides a motorbike?',
        ],
        'n_yes' => 'Yes', 'n_no' => 'No', 'n_budget' => 'Monthly budget for insurance (FCFA)', 'n_result' => 'Recommended covers', 'n_run' => 'See recommendations',
        'n_none' => 'Answer the questions to see recommendations.', 'n_why' => [
            'MOTOR' => 'Third-party motor cover is compulsory in Cameroon for every vehicle.', 'TRAVEL' => 'Visa applications for Schengen require travel medical cover.',
            'HOME' => 'Protects the home and contents against fire, water damage and theft.', 'HEALTH' => 'Covers hospital, consultations and medicines.',
            'LIFE' => 'Protects the family income if something happens.', 'BUSINESS' => 'Covers premises, stock and liability of the business.', 'ACCIDENT' => 'Pays out on accidental injury, disability or death.',
        ],
        'n_quote' => 'Quote', 'n_details' => 'Details', 'n_saved' => 'Assessment saved to the lead diary.', 'n_save' => 'Save to lead diary', 'n_lead' => 'Lead (optional)',
        // send / lost
        's_channel' => 'Channel', 's_recipient' => 'Recipient (email or phone)', 's_send' => 'Send quote', 's_sent' => 'Quote sent. Share link:', 's_copy' => 'Copy link', 's_copied' => 'Link copied.',
        's_channels' => ['EMAIL' => 'Email', 'SMS' => 'SMS', 'WHATSAPP' => 'WhatsApp', 'LINK' => 'Link only', 'IN_APP' => 'In the client\'s app'],
        'l_reasons' => ['CUSTOMER_DECLINED' => 'Client declined', 'PRICE_TOO_HIGH' => 'Price too high', 'COVER_NOT_SUITABLE' => 'Cover not suitable', 'LOST_TO_COMPETITOR' => 'Lost to a competitor', 'NO_RESPONSE' => 'No response', 'DUPLICATE' => 'Duplicate', 'OTHER' => 'Other'],
        'l_submit' => 'Mark as lost', 'l_confirm' => 'Mark this quote as lost? It cannot be reopened.', 'l_done' => 'Quote marked as lost.', 'q_offers' => 'Offers', 'q_best' => 'Best price',
        'q_compare' => 'Compare offers', 'q_customize' => 'Configure coverage', 'q_send' => 'Send quote', 'q_lost' => 'Mark lost', 'q_closed' => 'This quote is closed.',
        // builder
        'b_quote' => 'Quote', 'b_pick_quote' => 'Choose a priced quote…', 'b_offer' => 'Offer', 'b_go' => 'Build proposal', 'b_no_quotes' => 'This client has no priced quote yet.',
        // proposal
        'pr_number' => 'Proposal', 'pr_uw' => 'Underwriting status', 'pr_case' => 'Underwriting case', 'pr_decisions' => 'Decisions', 'pr_checklist' => 'Checklist',
        'pr_questions' => 'Questions answered', 'pr_attested' => 'Declaration signed', 'pr_decl' => 'Declarations', 'pr_docs' => 'Documents', 'pr_blockers' => 'Still needed before submission',
        'pr_counter' => 'Conditional offer', 'pr_counter_d' => 'The insurer offers revised terms. Only the client can accept or decline them, from their OpesInsure app or account.',
        'pr_counter_terms' => 'Revised terms', 'pr_conditions' => 'Conditions', 'pr_open_builder' => 'Continue in the builder', 'pr_submit' => 'Submit to insurer', 'pr_submitted' => 'Proposal submitted.',
        'pr_withdraw' => 'Withdraw', 'pr_withdraw_q' => 'Why is the proposal withdrawn?', 'pr_withdrawn' => 'Proposal withdrawn.', 'pr_answer_info' => 'Answer the request',
        'pr_manage_docs' => 'Manage documents', 'pr_yes' => 'Yes', 'pr_no' => 'No', 'pr_steps' => ['DRAFT' => 'Draft', 'SUBMITTED' => 'Submitted', 'REVIEWING' => 'Reviewing', 'INFORMATION_REQUIRED' => 'Information', 'COUNTEROFFERED' => 'Conditional offer', 'APPROVED' => 'Approved'],
        'pr_pay' => 'Collect the premium', 'pr_policy' => 'Open the policy',
        'd_req' => 'Requirement', 'd_form' => 'Produced from the proposal form','d_level' => 'Level', 'd_upload' => 'Upload', 'd_done' => 'Document linked to the proposal.', 'd_locked' => 'Documents can no longer be changed on this proposal.',
        // info
        'i_items' => 'Requested items', 'i_message' => 'Message from the insurer', 'i_response' => 'Your answer', 'i_send' => 'Send back to the insurer', 'i_sent' => 'Proposal resubmitted to the insurer.',
        'i_none' => 'The insurer has not asked for information on this proposal.', 'i_requested' => 'Requested on',
    ],
];
