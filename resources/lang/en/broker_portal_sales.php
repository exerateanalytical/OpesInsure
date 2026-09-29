<?php

declare(strict_types=1);

// Broker portal (/broker) sales journey — owner decision 2026-09-29 (portals writable).
return [
    'customers' => [
        'nav' => 'My customers',
        'title' => 'My customers',
        'number' => 'Customer no.',
        'name' => 'Customer',
        'status' => 'Status',
        'since' => 'Customer since',
        'empty_t' => 'No customers yet',
        'empty_d' => 'Register a new customer to start a quote.',
    ],
    'brokerRegisterClient' => [
        'label' => 'New customer',
        'help' => 'Registers the customer in your brokerage\'s book with their privacy consent.',
        'done' => 'Customer registered',
    ],
    'brokerNewQuote' => [
        'label' => 'New quote',
        'help' => 'Rates the risk with every insurer your brokerage has an active agreement with, then opens the offers to compare.',
        'done' => 'Quote rated',
    ],
    'brokerRequestPremium' => [
        'label' => 'Request premium payment',
        'help' => 'Sends a payment request to the customer\'s phone. Once paid, the insurer is asked to issue the policy.',
        'done' => 'Payment request sent to the customer',
    ],
    'brokerPremiumReceipt' => [
        'label' => 'Download receipt',
        'done' => 'Receipt ready',
    ],
    'fields' => [
        'consent_reference' => 'Consent reference (signed form, call record ...)',
        'line' => 'Line of business',
        'risk_facts' => 'Risk details',
        'fact' => 'Detail',
        'value' => 'Value',
        'provider' => 'Payment method',
        'payer_phone' => 'Payer mobile number',
    ],
    'providers' => [
        'fake' => 'Test mode (no real money)',
        'mtn_momo' => 'MTN Mobile Money',
        'orange_money' => 'Orange Money',
        'campay' => 'CamPay',
        'maviance' => 'Maviance',
        'bank_transfer' => 'Bank transfer',
        'card_sandbox' => 'Card (sandbox)',
    ],
    'offers' => [
        'title' => 'Offers compared',
        'empty' => 'No offer yet: rate the quote.',
        'rank' => 'Rank',
        'carrier' => 'Insurer',
        'product' => 'Product',
        'premium' => 'Net premium',
        'total' => 'Total to pay',
        'status' => 'Status',
    ],
    'no_partner' => 'Your account is not linked to a brokerage, so no insurer agreement applies.',
];
