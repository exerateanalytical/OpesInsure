<?php

// Launch 2026-10-02 (Q2): customer and shared screens of the web account (docs/LAUNCH_SCREEN_GAPS_2026-09-29.md §5):
// CUST-008 welcome, CUST-009 onboarding, CUST-019 action centre, CUST-023 product details, CUST-025 needs assessment,
// SHR-001/002 search, SHR-008 activity, SHR-013/014 complaints, SHR-017 messages; plus the staff search page.
return [
    'welcome' => ['title' => 'Account created', 'lede' => 'Welcome to OpesInsure. Your account is ready.'],
    'onb' => ['title' => 'Complete your profile', 'lede' => 'A few steps so insurers can issue your policies without delay.'],
    'actions' => ['title' => 'Action centre', 'lede' => 'Everything that needs your attention, most urgent first.'],
    'product' => ['title' => 'Product details', 'lede' => 'Cover, exclusions, eligibility and what you need to get a quote.'],
    'needs' => ['title' => 'Find the right cover', 'lede' => 'Answer a few questions and we show the products that fit you.'],
    'search_p' => ['title' => 'Search', 'lede' => 'Find your policies, claims, quotes, documents and vehicles.'],
    'activity' => ['title' => 'Account activity', 'lede' => 'What was done on your account, and when.'],
    'complaints' => ['title' => 'Complaints', 'lede' => 'Not satisfied? File a formal complaint and follow its handling.'],
    'complaint' => ['title' => 'Complaint details', 'lede' => 'Status, deadlines and the answers you received.'],
    'messages' => ['title' => 'Messages', 'lede' => 'All communications with you: notifications, support conversations and complaint letters.'],

    'staff_search' => ['title' => 'Global search', 'lede' => 'Search the records you are allowed to see: customers, policies, claims, quotes, documents and vehicles.'],
    'search' => [
        'label' => 'Search for', 'placeholder' => 'Name, policy or claim number, plate, document number…', 'types_label' => 'Look in', 'per_type' => 'Results per type',
        'submit' => 'Search', 'open' => 'Open', 'none' => 'No result.', 'too_short' => 'Type at least 2 characters.', 'failed' => 'The search failed. Try again.',
        'searched' => 'Searched: :types',
        'types' => ['customers' => 'Customers', 'policies' => 'Policies', 'claims' => 'Claims', 'quotes' => 'Quotes', 'documents' => 'Documents', 'vehicles' => 'Vehicles', 'risk_assets' => 'Other insured items'],
    ],

    'js' => [
        'welcome' => [
            'hello' => 'Welcome, :name!', 'done' => 'Your account was created and you are signed in.', 'next_t' => 'What happens next',
            'n1' => 'Complete your profile and verify your identity once.', 'n2' => 'Compare offers from licensed insurers and buy online.', 'n3' => 'Keep your certificates, payments and claims in one place.',
            'cta' => 'Complete my profile', 'later' => 'Go to my dashboard', 'browse' => 'Browse insurance',
        ],
        'onb' => [
            'progress' => ':pct% complete', 'continue' => 'Continue', 'done_all' => 'Your profile is complete. You can buy and renew without delay.',
            'incomplete_t' => 'Still to do', 'none_left' => 'Nothing left to do.', 'done' => 'Done', 'todo' => 'To do',
            'steps' => [
                'identity' => ['Personal information', 'Full name and date of birth'],
                'contact' => ['Contact & address', 'Verified phone, email and home address'],
                'kyc' => ['Identity verification', 'Identity number and review'],
                'docs' => ['Required documents', 'Documents the reviewer needs'],
            ],
            'fix' => ['identity' => 'Add your date of birth', 'phone' => 'Verify your phone number', 'email' => 'Add and verify an email address', 'address' => 'Add your home address', 'kyc_id' => 'Add an identity number', 'kyc_submit' => 'Send your identity for review', 'kyc_wait' => 'Wait for the review of your identity', 'kyc_fix' => 'Fix what the reviewer asked', 'doc' => 'Provide: :doc'],
        ],
        'kyc_steps' => ['DRAFT' => 'Draft', 'SUBMITTED' => 'Submitted', 'IN_REVIEW' => 'Reviewing', 'APPROVED' => 'Approved'],
        'kyc_state' => [
            'NONE' => 'Not started', 'DRAFT' => 'Draft — not sent yet', 'SUBMITTED' => 'Submitted — waiting for a reviewer', 'IN_REVIEW' => 'Being reviewed', 'UNDER_REVIEW' => 'Being reviewed',
            'APPROVED' => 'Approved', 'VERIFIED' => 'Approved', 'MORE_INFO_REQUIRED' => 'More information required', 'REJECTED' => 'Rejected', 'EXPIRED' => 'Expired — please renew',
        ],
        'remed_t' => 'What you need to fix', 'remed_d' => 'The reviewer needs these exact items before approving your identity:', 'remed_reason' => 'Reviewer’s note', 'remed_go' => 'Fix now',
        'act' => [
            'priority' => 'Priority', 'item' => 'Item', 'action' => 'Action', 'due' => 'Due', 'reason' => 'Why', 'none' => 'Nothing needs your attention right now.',
            'p' => ['HIGH' => 'Urgent', 'MEDIUM' => 'Soon', 'LOW' => 'When you can'],
            'kyc_fix' => ['Fix your identity check', 'The reviewer asked for more information.'],
            'kyc_start' => ['Verify your identity', 'Needed before a policy can be issued.'],
            'kyc_expired' => ['Renew your identity check', 'Your verification has expired.'],
            'pay' => ['Pay :product', 'The policy is issued once the premium is paid.'],
            'quote' => ['Review your quote :number', 'An offer is ready for your decision.'],
            'claim' => ['Respond on claim :number', 'The claims team is waiting for you.'],
            'renew' => ['Renew :product', 'Your policy ends in :n days.'],
            'lapsed' => ['Renew :product', 'Your policy has expired.'],
            'support' => ['Reply to support case :number', 'Support is waiting for your answer.'],
            'complaint' => ['Read the answer to complaint :number', 'A decision was sent to you.'],
            'notif' => [':n unread notifications', 'Updates you have not read yet.'],
            'go' => ['kyc' => 'Open identity check', 'pay' => 'Pay now', 'quote' => 'Review quote', 'claim' => 'Open claim', 'renew' => 'Renew', 'support' => 'Reply', 'complaint' => 'Read', 'notif' => 'Read'],
        ],
        'dash' => [
            'hello' => 'Hello, :name', 'actions_t' => 'Action required', 'actions_all' => 'Open action centre', 's_outstanding' => 'Outstanding premium', 's_outstanding_d' => 'To pay now',
            'claims_t' => 'My claims', 'no_claims' => 'No claim reported.', 'docs_t' => 'Recent documents', 'no_docs' => 'No documents yet.', 'renew_t' => 'Renewals',
            'no_renew' => 'No policy is due for renewal in the next 60 days.', 'renew' => 'Renew', 'due_t' => 'Payments due', 'no_due' => 'Nothing to pay.', 'pay' => 'Pay',
            'onb' => 'Your profile is :pct% complete.', 'onb_go' => 'Complete my profile', 'q_search' => 'Search', 'q_needs' => 'Find the right cover', 'q_msgs' => 'Messages', 'q_complaint' => 'File a complaint',
            'claim_no' => 'Claim', 'claim_st' => 'Status', 'claim_date' => 'Reported',
        ],
        'prod' => [
            'tabs' => ['overview' => 'Overview', 'coverage' => 'Coverage', 'exclusions' => 'Exclusions', 'eligibility' => 'Eligibility', 'requirements' => 'Requirements', 'documents' => 'Documents'],
            'insurer' => 'Insurer', 'line' => 'Class of insurance', 'code' => 'Product code', 'version' => 'Version', 'from' => 'Available from', 'until' => 'Available until', 'reg' => 'Regulatory reference',
            'get_quote' => 'Get a quote', 'mandatory' => 'Always included', 'optional' => 'Optional', 'no_cov' => 'The insurer has not published a coverage list for this product.',
            'no_excl' => 'No exclusion is published for this product. The policy wording applies.', 'no_elig' => 'No specific eligibility rule: open to every customer.', 'req_d' => 'To get a quote you will be asked for:',
            'no_req' => 'Only the basic details of what you insure.', 'docs_d' => 'Once you have paid, you receive in your account:', 'docs' => ['Policy schedule and conditions', 'Insurance certificate (with verification QR code)', 'Payment receipt'],
            'not_found' => 'This product is not available.', 'required' => 'required',
        ],
        'needs' => [
            'q1' => 'What do you want to protect?', 'q2' => 'Who is the cover for?', 'q_use' => 'How is the vehicle used?', 'q_trip' => 'Where are you travelling?', 'q_home' => 'Are you the owner or the tenant?', 'q_people' => 'How many people to cover?', 'q_staff' => 'How many employees?',
            'what' => ['MOTOR' => 'My vehicle', 'HEALTH' => 'My health and my family’s', 'TRAVEL' => 'A trip', 'HOME' => 'My home', 'LIFE' => 'My family’s future', 'ACCIDENT' => 'Me, against accidents', 'BUSINESS' => 'My business'],
            'who' => ['INDIVIDUAL' => 'Me (individual)', 'FAMILY' => 'My family', 'BUSINESS' => 'A company'],
            'use' => ['PRIVATE' => 'Private', 'COMMERCIAL' => 'Commercial / taxi', 'FLEET' => 'Company fleet'], 'trip' => ['CEMAC' => 'CEMAC zone', 'AFRICA' => 'Rest of Africa', 'SCHENGEN' => 'Schengen / Europe', 'WORLD' => 'Worldwide'],
            'home' => ['OWNER' => 'Owner', 'TENANT' => 'Tenant'], 'people' => ['1' => '1', '2-4' => '2 to 4', '5+' => '5 or more'], 'staff' => ['1-10' => '1 to 10', '11-50' => '11 to 50', '50+' => 'More than 50'],
            'result_t' => 'Products that fit', 'result_d' => ':n products match your answers.', 'none' => 'No product matches yet for this need. Ask us and an adviser will help.', 'ask' => 'Ask an adviser',
            'details' => 'Details', 'quote' => 'Get a quote', 'restart' => 'Start again', 'pick' => 'Choose at least one answer.', 'see' => 'Show products',
        ],
        'search' => [
            'label' => 'Search for', 'placeholder' => 'Policy or claim number, plate, document…', 'submit' => 'Search', 'none' => 'No result in your records.', 'too_short' => 'Type at least 2 characters.',
            'filter' => 'Filters', 'types_label' => 'Look in', 'apply' => 'Apply', 'reset' => 'Reset', 'results' => ':n results', 'hint' => 'Search your own records only.',
            'types' => ['customers' => 'Customers', 'policies' => 'Policies', 'claims' => 'Claims', 'quotes' => 'Quotes', 'documents' => 'Documents', 'vehicles' => 'Vehicles', 'risk_assets' => 'Other insured items'],
        ],
        'activity' => [
            'actions_t' => 'Actions on your account', 'logins_t' => 'Sign-ins', 'none' => 'No activity recorded yet.', 'no_logins' => 'No sign-in recorded.', 'more' => 'Show more',
            'when' => 'When', 'what' => 'What', 'on' => 'On', 'via' => 'Channel', 'device' => 'Device', 'result' => 'Result', 'src' => ['api' => 'App / website', 'web' => 'Website', 'console' => 'System'],
        ],
        'cpl' => [
            'new_t' => 'File a complaint', 'new_d' => 'Tell us what went wrong. You get an acknowledgement and a written answer within the regulatory deadline.',
            'about' => 'It concerns', 'general' => 'OpesInsure in general', 'policy' => 'Policy :n', 'claim' => 'Claim :n', 'desc' => 'Describe your complaint (at least 10 characters)', 'contact' => 'How should we reply? (email or phone, optional)',
            'send' => 'Send complaint', 'sent' => 'Complaint :n was filed. We will acknowledge it shortly.', 'list_t' => 'My complaints', 'none' => 'You have not filed any complaint.',
            'number' => 'Complaint', 'received' => 'Received', 'status' => 'Status', 'due' => 'Answer due by', 'ack' => 'Acknowledged', 'outcome' => 'Outcome', 'resolution' => 'Answer', 'communicated' => 'Answer sent',
            'escalated' => 'Escalated to', 'timeline_t' => 'Handling steps', 'corr_t' => 'Letters and messages', 'in' => 'From you', 'out' => 'To you', 'not_found' => 'This complaint was not found in your account.',
            'support' => 'For a simple question, use support instead.', 'open_support' => 'Open support', 'back' => 'All complaints',
        ],
        'msg' => [
            'all' => 'All', 'notif' => 'Notifications', 'support' => 'Support', 'complaints' => 'Complaints', 'none' => 'No message yet.', 'open' => 'Open',
            'k' => ['notif' => 'Notification', 'support' => 'Support case', 'complaint' => 'Complaint'], 'unread' => 'Unread', 'new_support' => 'New support request', 'new_complaint' => 'New complaint',
        ],
    ],
];
