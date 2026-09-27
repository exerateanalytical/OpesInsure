<?php

// Customer self-service pages of the web account (/account/kyc, /account/privacy, /account/requests)
// and the customer actions added by the 2026-09-27 audit (claim appeal, counter-offer, emergency assistance).
return [
    'kyc' => ['title' => 'Identity check', 'lede' => 'Verify your identity once so insurers can issue your policies without delay.'],
    'privacy' => ['title' => 'Privacy & security', 'lede' => 'Your consents, notification choices, signed-in devices and personal-data requests.'],
    'req' => ['title' => 'Policy requests', 'lede' => 'Ask for a change, a renewal or a new copy of a document on one of your policies.'],

    'js' => [
        'kyc' => [
            'status_t' => 'Verification status', 'none' => 'You have not submitted your identity yet.', 'submitted' => 'Submitted', 'reviewed' => 'Reviewed', 'expires' => 'Valid until',
            'remediation' => 'The reviewer asked you to fix', 'missing' => 'Still needed', 'all_ok' => 'All required items are provided.',
            'ids_t' => 'Identity documents on file', 'ids_none' => 'No identity number recorded yet.', 'verified' => 'Verified', 'unverified' => 'Not verified yet',
            'add_t' => 'Add an identity number', 'id_type' => 'Document type', 'id_value' => 'Number', 'id_country' => 'Issuing country', 'add' => 'Save number', 'added' => 'Identity number saved.',
            'types' => ['NATIONAL_ID' => 'National ID card (CNI)', 'PASSPORT' => 'Passport', 'DRIVER_LICENCE' => 'Driving licence', 'RESIDENCE_PERMIT' => 'Residence permit', 'NIU' => 'Taxpayer number (NIU)'],
            'doc_t' => 'Upload a document', 'doc_purpose' => 'What is this document?', 'doc_file' => 'File (PDF, JPG or PNG, max 10 MB)', 'upload' => 'Upload', 'uploaded' => 'Document uploaded.', 'bad_file' => 'Choose a PDF, JPG or PNG file of 10 MB or less.',
            'purposes' => ['ID_FRONT' => 'ID card — front', 'ID_BACK' => 'ID card — back', 'PASSPORT' => 'Passport', 'PROOF_OF_ADDRESS' => 'Proof of address', 'NIU' => 'Taxpayer card (NIU)', 'RCCM' => 'Trade register (RCCM)'],
            'docs_t' => 'Documents attached', 'submit_t' => 'Send for review', 'notes' => 'Note for the reviewer (optional)', 'submit' => 'Submit for verification', 'sent' => 'Your identity check was sent for review.',
        ],
        'priv' => [
            'consent_t' => 'Consents', 'consent_d' => 'You can change these at any time. Required processing for your policies does not depend on them.',
            'purposes' => ['MARKETING' => 'Offers and news from OpesInsure', 'PARTNER_SHARING' => 'Share my details with partner insurers for offers', 'ANALYTICS' => 'Use my usage data to improve the service', 'WHATSAPP_UPDATES' => 'Receive updates on WhatsApp'],
            'since' => 'Updated :date', 'saved' => 'Your choices were saved.',
            'notif_t' => 'Notification preferences', 'channels' => 'Channels', 'topics' => 'Topics',
            'prefs' => ['push' => 'App notifications', 'sms' => 'SMS', 'email' => 'Email', 'renewals' => 'Renewal reminders', 'claims' => 'Claim updates', 'payments' => 'Payment updates'],
            'lang_t' => 'Language for messages', 'lang_d' => 'Language used for SMS, emails and documents we send you.', 'lang_saved' => 'Language saved.',
            'dev_t' => 'Signed-in devices', 'dev_none' => 'No mobile device is signed in.', 'last_seen' => 'Last seen :date', 'this' => 'Most recent', 'revoke' => 'Sign out', 'revoke_q' => 'Sign this device out?', 'revoked' => 'The device was signed out.',
            'dsr_t' => 'My personal data', 'dsr_d' => 'Ask for a copy of your data, or for your account data to be erased. We answer within 30 days.',
            'export' => 'Request a copy of my data', 'delete' => 'Request erasure of my data', 'delete_q' => 'Erasure closes your account once active policies and legal retention periods allow it. Continue?',
            'dsr_sent' => 'Request :ref recorded.', 'dsr_none' => 'No request yet.', 'types' => ['EXPORT' => 'Copy of my data', 'DELETE' => 'Erasure'], 'due' => 'Due :date', 'done' => 'Completed :date',
        ],
        'req' => [
            'list_t' => 'My requests', 'none' => 'You have no policy requests.', 'new_t' => 'New request', 'policy' => 'Policy', 'type' => 'Request type', 'reason' => 'Details', 'reason_h' => 'At least 5 characters.',
            'send' => 'Send request', 'sent' => 'Your request was sent.', 'no_policy' => 'You need an active policy to make a request.',
            'types' => ['ENDORSEMENT' => 'Change my cover', 'CANCELLATION_REVIEW' => 'Cancel my policy', 'ADDRESS_CHANGE' => 'Change my address', 'VEHICLE_CHANGE' => 'Change my vehicle', 'BENEFICIARY_CHANGE' => 'Change beneficiaries', 'DOCUMENT_REISSUE' => 'Re-issue a document'],
            'docs_needed' => 'Documents requested', 'msg' => 'Add a message', 'msg_send' => 'Send', 'opened' => 'Opened :date',
            'renew_t' => 'Renew a policy', 'renew_d' => 'Get new prices for a policy that ends soon. You compare offers before you pay.', 'renew' => 'Get renewal quotes', 'renew_none' => 'No policy is close to renewal.',
        ],
        'appeal' => ['btn' => 'Appeal this decision', 'title' => 'Appeal the decision', 'text' => 'Explain why you disagree. The insurer will review your claim again.', 'reason' => 'Your reasons', 'err' => 'Enter at least 10 characters.', 'send' => 'Send appeal', 'done' => 'Your appeal was sent.', 'cancel' => 'Cancel'],
        'counter' => ['title' => 'Offers waiting for your answer', 'text' => 'The insurer changed the terms of your proposal. Accept to continue to payment, or decline.', 'was' => 'Original total', 'now' => 'New total', 'accept' => 'Accept', 'decline' => 'Decline', 'decline_q' => 'Decline this offer?', 'accepted' => 'Offer accepted. You can now pay.', 'declined' => 'Offer declined.'],
        'settle' => ['accept' => 'Accept the offer', 'reject' => 'Reject the offer', 'reject_q' => 'Reject this settlement offer? The insurer will review your claim again.', 'accepted' => 'You accepted the settlement offer.', 'rejected' => 'You rejected the settlement offer.', 'deadline' => 'Answer before :date'],
        'insp' => ['btn' => 'Change the appointment', 'title' => 'Change the inspection appointment', 'when' => 'New date and time', 'save' => 'Save the new date', 'done' => 'The inspection was rescheduled.', 'cancel' => 'Cancel'],
        'inc' => ['title' => 'Incident details', 'edit' => 'Edit details', 'type' => 'What happened', 'police' => 'Police report number', 'injuries' => 'Someone was injured', 'drivable' => 'The vehicle can still be driven', 'towing' => 'The vehicle needs towing', 'declare' => 'I confirm these details are true', 'save' => 'Save details', 'saved' => 'Incident details saved.',
            'types' => ['COLLISION' => 'Collision', 'THEFT' => 'Theft', 'FIRE' => 'Fire', 'GLASS' => 'Glass breakage', 'VANDALISM' => 'Vandalism', 'NATURAL_EVENT' => 'Flood or storm', 'MEDICAL' => 'Medical', 'OTHER' => 'Other']],
        'parties' => ['title' => 'People involved', 'none' => 'No one added yet.', 'add' => 'Add a person', 'role' => 'Role', 'name' => 'Full name', 'phone' => 'Phone', 'email' => 'Email', 'notes' => 'Notes', 'consent' => 'This person agrees to be contacted', 'save' => 'Add', 'added' => 'Person added.',
            'roles' => ['DRIVER' => 'Driver', 'PASSENGER' => 'Passenger', 'THIRD_PARTY' => 'Other party', 'WITNESS' => 'Witness', 'OTHER' => 'Other']],
        'refund' => ['btn' => 'Request a refund', 'title' => 'Request a refund', 'reason' => 'Why do you want a refund?', 'amount' => 'Amount (FCFA, leave empty for the full amount)', 'send' => 'Continue', 'done' => 'Your refund request was recorded.', 'cancel' => 'Cancel'],
        'delivery' => ['title' => 'Certificate delivery', 'status' => 'Status', 'due' => 'Expected by', 'address' => 'Delivery address', 'recipient' => 'Recipient', 'phone' => 'Phone', 'line' => 'Address', 'city' => 'City', 'save' => 'Update the address', 'saved' => 'Delivery address updated.', 'locked' => 'The courier is on the way, so the address can no longer be changed here.',
            'confirm_t' => 'Confirm receipt', 'confirm_d' => 'Enter the 6-digit code the courier gives you when you receive your certificate.', 'otp' => 'Delivery code', 'confirm' => 'Confirm receipt', 'confirmed' => 'Delivery confirmed.'],
        'attach' => ['label' => 'Attach a file (PDF, JPG or PNG, max 10 MB)', 'send' => 'Attach', 'done' => 'File attached.', 'bad' => 'Choose a PDF, JPG or PNG file of 10 MB or less.'],
        'sos' => ['title' => 'Emergency assistance', 'text' => 'Need a tow truck, medical help or the police after an accident? We call you back right away.', 'service' => 'Help needed', 'services' => ['TOWING' => 'Towing', 'MEDICAL' => 'Medical help', 'POLICE' => 'Police'], 'location' => 'Where are you?', 'phone' => 'Phone to call back', 'send' => 'Request help now', 'sent' => 'Help requested. Reference :ref.'],
    ],
];
