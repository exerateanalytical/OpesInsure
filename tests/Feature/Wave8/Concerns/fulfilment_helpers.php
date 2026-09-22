<?php

declare(strict_types=1);

use App\Models\Carrier;
use App\Models\InsuranceProduct;
use App\Models\Party;
use App\Models\Policy;
use App\Models\Proposal;
use App\Models\Quote;
use App\Models\QuoteOffer;
use App\Models\TariffVersion;
use App\Models\Tenant;
use Illuminate\Support\Str;

if (! function_exists('makeFulfilmentTestPolicy')) {
    /**
     * The full FK chain policies needs: tenant -> party -> carrier -> product
     * -> tariff -> quote -> offer -> proposal -> policy. $partyContact lets
     * callers control the party's phone contact for OTP-delivery assertions.
     */
    function makeFulfilmentTestPolicy(?string $partyPhone = '+237670000000'): Policy
    {
        $tenant = Tenant::create(['type' => 'CARRIER', 'legal_name' => 'Fulfilment Test Tenant '.Str::random(6), 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en']);
        $party = Party::create(['type' => 'INDIVIDUAL', 'display_name' => 'Fulfilment Test Party', 'status' => 'ACTIVE']);

        if ($partyPhone !== null) {
            $party->contacts()->create(['type' => 'PHONE', 'normalized_value' => $partyPhone, 'is_primary' => true]);
        }

        $carrier = Carrier::create(['party_id' => $party->id, 'cima_code' => 'CIMA-'.Str::random(6), 'status' => 'ACTIVE']);
        $product = InsuranceProduct::create(['carrier_id' => $carrier->id, 'line_code' => 'AUTO', 'code' => 'AUTO-'.Str::random(6), 'name' => 'Test Plan', 'version' => 1, 'effective_from' => now()->toDateString(), 'status' => 'ACTIVE']);
        $tariff = TariffVersion::create(['insurance_product_id' => $product->id, 'version' => 1, 'effective_from' => now()->toDateString(), 'status' => 'APPROVED', 'input_schema' => [], 'rules' => [], 'rules_hash' => Str::random(64)]);
        $quote = Quote::create(['tenant_id' => $tenant->id, 'party_id' => $party->id, 'line_code' => 'AUTO', 'status' => 'RATED', 'currency' => 'XAF', 'risk_facts' => []]);
        $offer = QuoteOffer::create(['quote_id' => $quote->id, 'carrier_id' => $carrier->id, 'product_id' => $product->id, 'tariff_version_id' => $tariff->id, 'premium_minor' => 100000, 'total_minor' => 100000, 'currency' => 'XAF', 'status' => 'OFFERED', 'calculation_breakdown' => [], 'valid_until' => now()->addDays(7)]);
        $proposal = Proposal::create(['tenant_id' => $tenant->id, 'quote_offer_id' => $offer->id, 'party_id' => $party->id, 'status' => 'APPROVED']);

        return Policy::create([
            'tenant_id' => $tenant->id,
            'proposal_id' => $proposal->id,
            'carrier_id' => $carrier->id,
            'party_id' => $party->id,
            'status' => 'ACTIVE',
            'coverage_starts_at' => now(),
            'coverage_ends_at' => now()->addYear(),
            'terms_snapshot' => [],
        ]);
    }
}
