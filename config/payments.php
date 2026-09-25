<?php
return [
'webhook_tolerance_seconds'=>(int)env('PAYMENT_WEBHOOK_TOLERANCE_SECONDS',300),
'providers'=>[
// REQ-PAY-003 bank transfer: reference-based instruction; a signed bank-statement feed (webhook) or reconciliation settles it.
'bank_transfer'=>['webhook_secret'=>env('BANK_TRANSFER_WEBHOOK_SECRET'),'bank_name'=>env('BANK_TRANSFER_BANK_NAME'),'account_name'=>env('BANK_TRANSFER_ACCOUNT_NAME'),'account_number'=>env('BANK_TRANSFER_ACCOUNT_NUMBER'),'swift'=>env('BANK_TRANSFER_SWIFT'),'validity_days'=>(int)env('BANK_TRANSFER_VALIDITY_DAYS',7)],
// REQ-PAY-003 card: hosted-checkout sandbox (no real vendor). Enabled only in local/testing unless CARD_SANDBOX_ENABLED=true.
'card_sandbox'=>['enabled'=>(bool)env('CARD_SANDBOX_ENABLED',false),'checkout_base_url'=>env('CARD_SANDBOX_CHECKOUT_URL','https://sandbox.checkout.invalid/pay'),'webhook_secret'=>env('CARD_SANDBOX_WEBHOOK_SECRET')],
'fake'=>['webhook_secret'=>env('FAKE_PAYMENT_WEBHOOK_SECRET')],
'maviance'=>['initiate_url'=>env('MAVIANCE_INITIATE_URL'),'api_token'=>env('MAVIANCE_API_TOKEN'),'webhook_secret'=>env('MAVIANCE_WEBHOOK_SECRET')],
'campay'=>['initiate_url'=>env('CAMPAY_INITIATE_URL'),'api_token'=>env('CAMPAY_API_TOKEN'),'webhook_secret'=>env('CAMPAY_WEBHOOK_SECRET')],

// MTN MoMo Collections API (Request to Pay). base_url defaults to MTN's
// public sandbox host; point it at a local simulator for testing by
// overriding MTN_MOMO_BASE_URL. subscription_key/api_user/api_key are the
// three real MTN sandbox credentials (Ocp-Apim-Subscription-Key + the
// apiuser/apikey pair created via MTN's apiuser/apikey provisioning
// endpoints) — all blank until real sandbox keys are supplied.
'mtn_momo'=>[
    // env(), not the ?? default parameter: MTN_MOMO_BASE_URL is present in
    // .env but deliberately blank until real sandbox keys are added, and
    // env()'s own default only applies when a key is entirely absent, not
    // when it's set-but-empty — so the fallback has to be applied here.
    'base_url'=>env('MTN_MOMO_BASE_URL')?:'https://sandbox.momodeveloper.mtn.com',
    'target_environment'=>env('MTN_MOMO_TARGET_ENVIRONMENT','sandbox'),
    'subscription_key'=>env('MTN_MOMO_SUBSCRIPTION_KEY'),
    'api_user'=>env('MTN_MOMO_API_USER'),
    'api_key'=>env('MTN_MOMO_API_KEY'),
    // MTN does not sign its callback payloads — this token is OpesInsure's
    // own anti-spoofing measure: generated locally, never sent to MTN,
    // checked as a query-string parameter on the callback URL we register.
    'callback_token'=>env('MTN_MOMO_CALLBACK_TOKEN'),
    // legacy generic keys kept for backward compatibility with anything
    // still constructing a ConfiguredJsonPaymentAdapter('mtn_momo') directly.
    'initiate_url'=>env('MTN_MOMO_INITIATE_URL'),'api_token'=>env('MTN_MOMO_API_TOKEN'),'webhook_secret'=>env('MTN_MOMO_WEBHOOK_SECRET'),
],

// Orange Money Web Payment API. Orange's status-query endpoint
// (transactionstatus) is the authoritative source of truth — the adapter
// re-queries it rather than trusting a notification body's own status
// field, per the plan's "never trust a client-declared payment status"
// rule extended to callback-declared status.
'orange_money'=>[
    'base_url'=>env('ORANGE_MONEY_BASE_URL')?:'https://api.orange.com',
    'country'=>env('ORANGE_MONEY_COUNTRY','cm'),
    'merchant_key'=>env('ORANGE_MONEY_MERCHANT_KEY'),
    'client_id'=>env('ORANGE_MONEY_CLIENT_ID'),
    'client_secret'=>env('ORANGE_MONEY_CLIENT_SECRET'),
    'return_url'=>env('ORANGE_MONEY_RETURN_URL'),
    'cancel_url'=>env('ORANGE_MONEY_CANCEL_URL'),
    'callback_token'=>env('ORANGE_MONEY_CALLBACK_TOKEN'),
    'initiate_url'=>env('ORANGE_MONEY_INITIATE_URL'),'api_token'=>env('ORANGE_MONEY_API_TOKEN'),'webhook_secret'=>env('ORANGE_MONEY_WEBHOOK_SECRET'),
],
],
];
