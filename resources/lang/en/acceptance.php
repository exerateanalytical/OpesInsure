<?php

// Web contract acceptance by the customer (ProposalAcceptanceLinks, /account/accept/{token}) and the agent/broker
// messages about it. French in resources/lang/fr/acceptance.php.
return [
    'title' => 'Review and accept your insurance',
    'lede' => 'Your adviser prepared this application for you. Only you can confirm your answers and accept the contract terms.',
    'sms' => 'OpesInsure: your adviser prepared insurance application :number. Review and accept the terms here (link valid 72 h): :url',

    'only_customer' => 'Only the customer can accept the contract terms. Send them the acceptance link instead.',
    'verify_first' => 'Confirm the code sent to your phone first.',
    'not_acceptable' => 'This application can no longer be accepted here.',
    'link_expired' => 'This link has expired or was already used. Ask your adviser for a new one.',
    'phone_other_account' => 'This phone number already has an OpesInsure account. Sign in to the OpesInsure app with it to accept, or ask your adviser to check your details.',
    'code_sent' => 'We sent a 6-digit code by SMS to :phone.',
    'terms_required' => 'Tick the box to accept the contract terms.',
    'attest_required' => 'Tick the box to confirm your answers are true and complete.',

    'verify_t' => 'Confirm it is you',
    'verify_d' => 'We will send a 6-digit code by SMS to the phone on this application (:phone).',
    'send_code' => 'Send me the code',
    'code' => '6-digit code',
    'verify' => 'Confirm',
    'resend' => 'Send a new code',

    'review_t' => 'Review and accept',
    'review_d' => 'Check the plan and the price, answer the questions yourself, then accept the terms.',
    'questions_t' => 'Your answers',
    'yes' => 'Yes',
    'no' => 'No',
    'choose' => 'Choose…',
    'terms_t' => 'Contract terms',
    'terms_d' => 'The insurer covers you on the terms, premium and guarantees shown on this page. Total to pay: :total. You pay only after acceptance, from your own mobile-money phone.',
    'account_note' => 'Accepting links this application to your phone number, so you can follow it and your policy in the OpesInsure app.',
    'accept' => 'I accept',

    'done_t' => 'Thank you, your acceptance is recorded',
    'done_d' => 'Application :number now carries your acceptance of the contract terms.',
    'done_pending' => 'One step is still open before the insurer can review it:',
    'done_next' => 'Your adviser can now send the payment request to your phone. Approve it with your mobile-money PIN.',
    'get_app' => 'Get the OpesInsure app',

    'state' => [
        'invalid_t' => 'This link is not valid',
        'invalid_d' => 'Open the link exactly as received by SMS, or ask your adviser for a new one.',
        'expired_t' => 'This link has expired',
        'expired_d' => 'For your safety, acceptance links expire after 72 hours. Ask your adviser to send a new one.',
        'used_t' => 'This link was already used',
        'used_d' => 'Each acceptance link works once. Ask your adviser for a new one if you still need it.',
        'accepted_t' => 'Already accepted',
        'accepted_d' => 'You have already accepted the terms of this application. Nothing more is needed here.',
        'closed_t' => 'This application is closed',
        'closed_d' => 'It was withdrawn or declined. Contact your adviser for a new quote.',
        'blocked_t' => 'The insurer needs something else first',
        'blocked_d' => 'The insurer asked for more information or proposed new terms. Contact your adviser or open the OpesInsure app.',
    ],

    'summary_t' => 'Your plan',
    'application' => 'Application',
    'insured' => 'Insured',
    'insurer' => 'Insurer',
    'product' => 'Plan',
    'premium' => 'Premium',
    'tax' => 'Taxes',
    'fees' => 'Fees',
    'total' => 'Total',
    'coverages' => 'Cover',
    'optional' => 'optional',
    'help_t' => 'Need help?',
    'safety' => 'OpesInsure never asks for your mobile-money PIN on this page. Your adviser cannot accept on your behalf.',

    // Agent / broker side
    'link_sent_broker' => 'The customer has not accepted the contract terms yet. We sent them an acceptance link by SMS (:phone). Request the payment again once they accept.',
    'link_recent_broker' => 'The customer has not accepted the contract terms yet. An acceptance link was already sent to :phone; request the payment again once they accept.',
    'link_no_phone_broker' => 'The customer has not accepted the contract terms yet, and there is no customer phone number to send the acceptance link to. Add their phone number first.',
    'link_failed_broker' => 'The customer has not accepted the contract terms yet, and the acceptance link SMS could not be sent. Check the SMS provider settings.',
];
