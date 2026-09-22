<?php

declare(strict_types=1);

use App\Models\Carrier;
use App\Models\InsuranceProduct;
use App\Models\Party;
use App\Models\PaymentIntentRecord;
use App\Models\Proposal;
use App\Models\Quote;
use App\Models\QuoteOffer;
use App\Models\TariffVersion;
use App\Models\Tenant;
use Illuminate\Support\Str;

if (! function_exists('makeMobileMoneyTestProposal')) {
    /**
     * The full FK chain payment_intents needs a real proposal for: tenant ->
     * party -> carrier -> product -> tariff -> quote -> offer -> proposal.
     * Nothing in this chain is exercised by the adapters/controllers under
     * test — it exists purely to satisfy NOT NULL foreign keys on
     * payment_intents so a real row can be persisted.
     */
    function makeMobileMoneyTestProposal(): Proposal
    {
        $tenant = Tenant::create([
            'type' => 'CARRIER',
            'legal_name' => 'Mobile Money Test Tenant '.Str::random(6),
            'status' => 'ACTIVE',
            'country_code' => 'CM',
            'currency' => 'XAF',
            'primary_locale' => 'en',
        ]);

        $party = Party::create([
            'type' => 'INDIVIDUAL',
            'display_name' => 'Mobile Money Test Party',
            'status' => 'ACTIVE',
        ]);

        $carrier = Carrier::create([
            'party_id' => $party->id,
            'cima_code' => 'CIMA-'.Str::random(6),
            'status' => 'ACTIVE',
        ]);

        $product = InsuranceProduct::create([
            'carrier_id' => $carrier->id,
            'line_code' => 'AUTO',
            'code' => 'AUTO-'.Str::random(6),
            'name' => 'Test Auto Plan',
            'version' => 1,
            'effective_from' => now()->toDateString(),
            'status' => 'ACTIVE',
        ]);

        $tariff = TariffVersion::create([
            'insurance_product_id' => $product->id,
            'version' => 1,
            'effective_from' => now()->toDateString(),
            'status' => 'APPROVED',
            'input_schema' => [],
            'rules' => [],
            'rules_hash' => Str::random(64),
        ]);

        $quote = Quote::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'line_code' => 'AUTO',
            'status' => 'RATED',
            'currency' => 'XAF',
            'risk_facts' => [],
        ]);

        $offer = QuoteOffer::create([
            'quote_id' => $quote->id,
            'carrier_id' => $carrier->id,
            'product_id' => $product->id,
            'tariff_version_id' => $tariff->id,
            'premium_minor' => 100000,
            'total_minor' => 100000,
            'currency' => 'XAF',
            'status' => 'OFFERED',
            'calculation_breakdown' => [],
            'valid_until' => now()->addDays(7),
        ]);

        return Proposal::create([
            'tenant_id' => $tenant->id,
            'quote_offer_id' => $offer->id,
            'party_id' => $party->id,
            'status' => 'SUBMITTED',
        ]);
    }

    /**
     * A payment_intents row already in PENDING_CUSTOMER with a
     * provider_reference set — i.e. as if PaymentInitiationService::initiate()
     * had already run — since callback controllers and the polling command
     * only ever act on an already-initiated intent.
     */
    function makeMobileMoneyTestIntent(string $provider, string $providerReference, array $overrides = []): PaymentIntentRecord
    {
        $proposal = makeMobileMoneyTestProposal();

        return PaymentIntentRecord::create(array_merge([
            'tenant_id' => $proposal->tenant_id,
            'proposal_id' => $proposal->id,
            'provider' => $provider,
            'provider_reference' => $providerReference,
            'payer_phone_e164' => '+237670000000',
            'amount_minor' => 100000,
            'currency' => 'XAF',
            'status' => 'PENDING_CUSTOMER',
            'idempotency_key' => (string) Str::uuid(),
        ], $overrides));
    }
}
