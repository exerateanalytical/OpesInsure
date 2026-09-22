<?php

declare(strict_types=1);

use App\Models\Carrier;
use App\Models\Document;
use App\Models\FulfilmentOrder;
use App\Models\InsuranceProduct;
use App\Models\PaymentIntentRecord;
use App\Models\Party;
use App\Models\PartyContact;
use App\Models\Policy;
use App\Models\Proposal;
use App\Models\Quote;
use App\Models\QuoteOffer;
use App\Models\TariffVersion;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Support\Str;

if (! function_exists('makeMobileCustomerFixture')) {
    /**
     * The full chain a customer-facing mobile endpoint needs to exercise
     * realistically: a real party_id-linked User (via the Batch 2 identity
     * fix, not the phone-match fallback), a proposal, and — via optional
     * flags — a payment and/or an issued policy with a delivery.
     *
     * @return array{tenant: Tenant, user: User, party: Party, proposal: Proposal}
     */
    function makeMobileCustomerFixture(string $phone = '+237670000000'): array
    {
        $tenant = Tenant::create(['type' => 'CARRIER', 'legal_name' => 'Mobile Wallet Test Tenant '.Str::random(6), 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en']);
        $party = Party::create(['type' => 'INDIVIDUAL', 'display_name' => 'Mobile Wallet Test Party', 'status' => 'ACTIVE']);
        PartyContact::create(['party_id' => $party->id, 'type' => 'PHONE', 'normalized_value' => $phone, 'is_primary' => true]);
        $user = User::create(['full_name' => 'Mobile Wallet Test User', 'phone_e164' => $phone, 'party_id' => $party->id, 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
        // ResolveTenant middleware requires an ACTIVE membership in the
        // requested tenant regardless of role — a customer login is no
        // exception, even though "CUSTOMER" grants no staff permissions.
        TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role_code' => 'CUSTOMER', 'status' => 'ACTIVE']);

        $carrierParty = Party::create(['type' => 'ORGANIZATION', 'display_name' => 'Mobile Wallet Test Carrier Org', 'status' => 'ACTIVE']);
        $carrier = Carrier::create(['party_id' => $carrierParty->id, 'cima_code' => 'CIMA-'.Str::random(6), 'status' => 'ACTIVE']);
        $product = InsuranceProduct::create(['carrier_id' => $carrier->id, 'line_code' => 'AUTO', 'code' => 'AUTO-'.Str::random(6), 'name' => 'Test Plan', 'version' => 1, 'effective_from' => now()->toDateString(), 'status' => 'ACTIVE']);
        $tariff = TariffVersion::create(['insurance_product_id' => $product->id, 'version' => 1, 'effective_from' => now()->toDateString(), 'status' => 'APPROVED', 'input_schema' => [], 'rules' => [], 'rules_hash' => Str::random(64)]);
        $quote = Quote::create(['tenant_id' => $tenant->id, 'party_id' => $party->id, 'line_code' => 'AUTO', 'status' => 'RATED', 'currency' => 'XAF', 'risk_facts' => []]);
        $offer = QuoteOffer::create(['quote_id' => $quote->id, 'carrier_id' => $carrier->id, 'product_id' => $product->id, 'tariff_version_id' => $tariff->id, 'premium_minor' => 100000, 'total_minor' => 100000, 'currency' => 'XAF', 'status' => 'OFFERED', 'calculation_breakdown' => [], 'valid_until' => now()->addDays(7)]);
        $proposal = Proposal::create(['tenant_id' => $tenant->id, 'quote_offer_id' => $offer->id, 'party_id' => $party->id, 'status' => 'APPROVED']);

        return ['tenant' => $tenant, 'user' => $user, 'party' => $party, 'proposal' => $proposal, 'carrier' => $carrier, 'product' => $product, 'tariff' => $tariff, 'quote' => $quote];
    }

    function tenantHeaderFor(Tenant $tenant): array
    {
        return ['X-Tenant-Id' => $tenant->id];
    }

    function makeMobileTestCertificateTemplate(array $overrides = []): App\Models\CertificateTemplate
    {
        return App\Models\CertificateTemplate::create(array_merge([
            'type' => 'MOTOR_STICKER',
            'code' => 'TPL-'.Str::random(8),
            'version' => 1,
            'status' => 'ACTIVE',
            'template_hash' => hash('sha256', Str::random(20)),
            'layout_schema' => [],
            'effective_from' => now()->toDateString(),
        ], $overrides));
    }

    function makeMobileTestPayment(Proposal $proposal, Tenant $tenant, array $overrides = []): PaymentIntentRecord
    {
        return PaymentIntentRecord::create(array_merge([
            'tenant_id' => $tenant->id,
            'proposal_id' => $proposal->id,
            'provider' => 'fake',
            'provider_reference' => (string) Str::uuid(),
            'payer_phone_e164' => '+237670000000',
            'amount_minor' => 100000,
            'currency' => 'XAF',
            'status' => 'SUCCEEDED',
            'idempotency_key' => (string) Str::uuid(),
        ], $overrides));
    }

    function makeMobileTestPolicy(Proposal $proposal, Tenant $tenant, string $carrierId, string $partyId, array $overrides = []): Policy
    {
        return Policy::create(array_merge([
            'tenant_id' => $tenant->id,
            'proposal_id' => $proposal->id,
            'carrier_id' => $carrierId,
            'party_id' => $partyId,
            'status' => 'ACTIVE',
            'coverage_starts_at' => now(),
            'coverage_ends_at' => now()->addYear(),
            'terms_snapshot' => [],
            'issued_at' => now(),
        ], $overrides));
    }

    function makeMobileTestQuote(Tenant $tenant, Party $party, array $overrides = []): Quote
    {
        return Quote::create(array_merge([
            'tenant_id' => $tenant->id, 'party_id' => $party->id, 'line_code' => 'AUTO',
            'status' => 'SUBMITTED', 'currency' => 'XAF', 'risk_facts' => [],
            'submitted_at' => now(), 'expires_at' => now()->addDays(7), 'version' => 1,
        ], $overrides));
    }

    function makeMobileTestQuoteOffer(Quote $quote, string $carrierId, string $productId, string $tariffId, array $overrides = []): QuoteOffer
    {
        return QuoteOffer::create(array_merge([
            'quote_id' => $quote->id, 'carrier_id' => $carrierId, 'product_id' => $productId, 'tariff_version_id' => $tariffId,
            'premium_minor' => 100000, 'total_minor' => 100000, 'currency' => 'XAF', 'status' => 'OFFERED',
            'calculation_breakdown' => [], 'valid_until' => now()->addDays(7),
        ], $overrides));
    }

    function makeMobileTestDelivery(Policy $policy, Tenant $tenant, array $overrides = []): FulfilmentOrder
    {
        return FulfilmentOrder::create(array_merge([
            'tenant_id' => $tenant->id,
            'policy_id' => $policy->id,
            'status' => 'CREATED',
            'delivery_address' => ['city' => 'Douala', 'street' => '12 Rue de la Paix'],
            'delivery_otp_hash' => Illuminate\Support\Facades\Hash::make('123456'),
            'delivery_attempts' => 0,
            'sla_due_at' => now()->addDays(3),
            'idempotency_key' => (string) Str::uuid(),
        ], $overrides));
    }

    function makeMobileTestDocument(Tenant $tenant, Party $party, array $overrides = []): Document
    {
        return Document::create(array_merge([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'category' => 'POLICY_DOCUMENT',
            'storage_key' => 'documents/test/'.Str::random(20).'.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
            'sha256' => hash('sha256', Str::random(32)),
            'scan_status' => 'CLEAN',
            'verification_status' => 'VERIFIED',
            'ocr_data' => [],
        ], $overrides));
    }
}
