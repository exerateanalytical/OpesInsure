<?php

declare(strict_types=1);

use App\Models\Carrier;
use App\Models\Claim;
use App\Models\Document;
use App\Models\FulfilmentOrder;
use App\Models\InsuranceProduct;
use App\Models\KycSubmission;
use App\Models\MfaMethod;
use App\Models\Partner;
use App\Models\PartnerPayoutRequest;
use App\Models\PartnerStatement;
use App\Models\PaymentIntentRecord;
use App\Models\Party;
use App\Models\PartyContact;
use App\Models\Policy;
use App\Models\Proposal;
use App\Models\Quote;
use App\Models\QuoteOffer;
use App\Models\RiskAsset;
use App\Models\StepUpGrant;
use App\Models\Role;
use App\Models\TariffVersion;
use App\Models\Tenant;
use App\Models\TenantCustomer;
use App\Models\TenantMembership;
use App\Models\UploadSession;
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

    /** tenantHeaderFor() plus a fresh (or caller-chosen) Idempotency-Key — every Agent Mode mutating endpoint requires one. */
    function agentHeaders(array $fixture, ?string $idempotencyKey = null): array
    {
        return array_merge(tenantHeaderFor($fixture['tenant']), ['Idempotency-Key' => $idempotencyKey ?? (string) Str::uuid()]);
    }

    /** A valid POST /mobile/agent/clients body — shared by the direct-endpoint tests and the offline-queue dispatch tests, which replay the exact same payload shape through SyncOperationDispatchService. */
    function agentClientIntakePayload(array $overrides = []): array
    {
        return array_merge([
            'type' => 'PERSON',
            'display_name' => 'New Client Kamga',
            'phone_e164' => '+237671112233',
            'notice_version' => 'privacy-2026-01',
            'evidence_reference' => 'field-visit-2026-09-22',
            'consent' => true,
        ], $overrides);
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

    function makeMobileTestUploadSession(Tenant $tenant, User $user, array $overrides = []): UploadSession
    {
        return UploadSession::create(array_merge([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'resource_type' => 'CLAIM_EVIDENCE',
            'mime_type' => 'image/jpeg',
            'total_chunks' => 2,
            'total_size_bytes' => 20,
            'status' => 'IN_PROGRESS',
            'expires_at' => now()->addHours(48),
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

    function makeMobileTestClaim(Tenant $tenant, Policy $policy, Party $party, array $overrides = []): Claim
    {
        return Claim::create(array_merge([
            'tenant_id' => $tenant->id,
            'policy_id' => $policy->id,
            'claimant_party_id' => $party->id,
            'claim_number' => 'CLM-'.Str::random(10),
            'status' => 'SUBMITTED',
            'loss_occurred_at' => now()->subDays(2),
            'loss_details' => ['description' => 'Rear-ended at a traffic light.'],
            'loss_location' => 'Douala',
            'currency' => 'XAF',
            'submitted_at' => now()->subDays(2),
        ], $overrides));
    /**
     * Issues a valid step-up grant directly (bypassing the request/verify
     * OTP round trip), returning the raw token to send back via the
     * X-Step-Up-Grant header. Mirrors makeMobileTestPayment()'s "build the
     * end state a test needs, don't re-run the whole flow" style.
     *
     * @return array{token: string, grant: StepUpGrant}
     */
    function issueMobileStepUpGrant(User $user, Tenant $tenant, string $purpose, array $overrides = []): array
    {
        $token = Str::random(64);

        $grant = StepUpGrant::create(array_merge([
            'user_id' => $user->id,
            'tenant_id' => $tenant->id,
            'device_id' => null,
            'challenge_id' => null,
            'purpose' => $purpose,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addMinutes(5),
        ], $overrides));

        return ['token' => $token, 'grant' => $grant];
    }

    function stepUpHeaderFor(string $token): array
    {
        return ['X-Step-Up-Grant' => $token];
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

    /**
     * RiskAssetService::create() requires the party to be an ACTIVE
     * TenantCustomer of the tenant — makeMobileCustomerFixture() does not
     * create one (it only links the party via a User row), so the KYC/asset
     * batch's own tests need this alongside that fixture whenever they
     * exercise POST /mobile/assets (which goes through the real
     * RiskAssetService, not a raw model insert).
     */
    function makeMobileTestTenantCustomer(Tenant $tenant, Party $party): TenantCustomer
    {
        return TenantCustomer::create([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'customer_number' => 'CUST-'.Str::random(6),
            'status' => 'ACTIVE',
        ]);
    }

    /** Direct model insert (like makeMobileTestDocument) for tests that only need an already-existing owned asset, without exercising RiskAssetService::create(). */
    function makeMobileTestRiskAsset(Tenant $tenant, Party $party, array $overrides = []): RiskAsset
    {
        $facts = $overrides['facts'] ?? ['plate_number' => 'LT-1234-AB'];

        return RiskAsset::create(array_merge([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'type' => 'VEHICLE',
            'display_name' => 'Mobile Test Vehicle',
            'facts' => $facts,
            'facts_hash' => hash('sha256', json_encode($facts)),
            'status' => 'ACTIVE',
            'version' => 1,
        ], $overrides));
    }

    function makeMobileTestKycSubmission(Tenant $tenant, Party $party, array $overrides = []): KycSubmission
    {
        return KycSubmission::create(array_merge([
            'tenant_id' => $tenant->id,
            'party_id' => $party->id,
            'status' => 'DRAFT',
        ], $overrides));
    }
     * The full chain an Agent Mode endpoint needs: a real party_id-linked
     * agent User (per PartyResolver::partnerForUser — the Partner's own
     * party_id must equal the agent User's users.party_id), an ACTIVE AGENT
     * Partner, an AGENT TenantMembership, and a Role carrying every
     * agent.* permission (see config/permissions.php and
     * DatabaseSeeder::DEMO_ACCOUNTS) so RequirePermission passes without
     * every test having to opt into individual permission strings.
     *
     * @return array{tenant: Tenant, user: User, party: Party, partner: Partner, membership: TenantMembership, role: Role}
     */
    function makeMobileAgentFixture(string $phone = '+237680000000', array $partnerOverrides = []): array
    {
        $tenant = Tenant::create(['type' => 'BROKER', 'legal_name' => 'Agent Mode Test Tenant '.Str::random(6), 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en']);
        $party = Party::create(['type' => 'PERSON', 'display_name' => 'Agent Mode Test Agent', 'status' => 'ACTIVE']);
        PartyContact::create(['party_id' => $party->id, 'type' => 'PHONE', 'normalized_value' => $phone, 'is_primary' => true]);
        $user = User::create(['full_name' => 'Agent Mode Test Agent', 'phone_e164' => $phone, 'party_id' => $party->id, 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);

        $membership = TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role_code' => 'AGENT', 'status' => 'ACTIVE']);
        $role = Role::create(['tenant_id' => $tenant->id, 'code' => 'AGENT', 'permissions' => [
            'agent.clients.read', 'agent.clients.manage', 'agent.commissions.read', 'agent.withdrawals.read',
            'agent.withdrawals.request', 'agent.sync.read', 'agent.sync.retry', 'agent.sync.dispatch',
        ], 'is_system' => false]);
        $membership->roles()->attach($role->id);

        $partner = Partner::create(array_merge([
            'tenant_id' => $tenant->id, 'party_id' => $party->id, 'type' => 'AGENT', 'status' => 'ACTIVE', 'compliance' => [],
        ], $partnerOverrides));

        return ['tenant' => $tenant, 'user' => $user, 'party' => $party, 'partner' => $partner, 'membership' => $membership, 'role' => $role];
    }

    /** Same shape as makeMobileAgentFixture(), but joins an EXISTING tenant — for cross-agent-same-tenant isolation tests (two AGENT partners sharing one brokerage tenant). */
    function makeMobileAgentFixtureInTenant(Tenant $tenant, string $phone, array $partnerOverrides = []): array
    {
        $party = Party::create(['type' => 'PERSON', 'display_name' => 'Agent Mode Test Agent '.$phone, 'status' => 'ACTIVE']);
        PartyContact::create(['party_id' => $party->id, 'type' => 'PHONE', 'normalized_value' => $phone, 'is_primary' => true]);
        $user = User::create(['full_name' => 'Agent Mode Test Agent '.$phone, 'phone_e164' => $phone, 'party_id' => $party->id, 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);

        $membership = TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role_code' => 'AGENT', 'status' => 'ACTIVE']);
        $role = Role::firstOrCreate(['tenant_id' => $tenant->id, 'code' => 'AGENT'], ['permissions' => [
            'agent.clients.read', 'agent.clients.manage', 'agent.commissions.read', 'agent.withdrawals.read',
            'agent.withdrawals.request', 'agent.sync.read', 'agent.sync.retry', 'agent.sync.dispatch',
        ], 'is_system' => false]);
        $membership->roles()->attach($role->id);

        $partner = Partner::create(array_merge([
            'tenant_id' => $tenant->id, 'party_id' => $party->id, 'type' => 'AGENT', 'status' => 'ACTIVE', 'compliance' => [],
        ], $partnerOverrides));

        return ['tenant' => $tenant, 'user' => $user, 'party' => $party, 'partner' => $partner, 'membership' => $membership, 'role' => $role];
    }

    /**
     * A PUBLISHED partner statement — the back-office-produced artefact a
     * self-service withdrawal draws its available balance from (see
     * PartnerStatementService::prepare/approve/publish). prepared_by is a
     * separate staff user on purpose: the maker-checker DB constraint on
     * partner_statements forbids approver == preparer, and an agent never
     * prepares their own statement.
     */
    function makeMobileAgentStatement(Tenant $tenant, Partner $partner, array $overrides = []): PartnerStatement
    {
        return PartnerStatement::create(array_merge([
            'prepared_by' => User::factory()->create()->id,
            'tenant_id' => $tenant->id,
            'partner_id' => $partner->id,
            'statement_number' => 'PST-'.strtoupper(Str::random(10)),
            'period_start' => now()->subMonth()->startOfMonth()->toDateString(),
            'period_end' => now()->subMonth()->endOfMonth()->toDateString(),
            'currency' => 'XAF',
            'status' => 'PUBLISHED',
            'opening_balance_minor' => 0,
            'earned_minor' => 100000,
            'clawed_back_minor' => 0,
            'paid_minor' => 0,
            'closing_balance_minor' => 100000,
            'content_hash' => hash('sha256', Str::random(20)),
            'idempotency_key' => (string) Str::uuid(),
            'published_at' => now(),
        ], $overrides));
    }

    function makeMobileAgentPayoutRequest(Tenant $tenant, Partner $partner, PartnerStatement $statement, array $overrides = []): PartnerPayoutRequest
    {
        return PartnerPayoutRequest::create(array_merge([
            'tenant_id' => $tenant->id,
            'partner_id' => $partner->id,
            'partner_statement_id' => $statement->id,
            'payout_number' => 'PAY-'.strtoupper(Str::random(10)),
            'amount_minor' => 10000,
            'currency' => 'XAF',
            'status' => 'REQUESTED',
            'destination_type' => 'MOBILE_MONEY',
            'destination_encrypted' => \Illuminate\Support\Facades\Crypt::encryptString('+237680000000'),
            'idempotency_key' => (string) Str::uuid(),
            'requested_by' => User::factory()->create()->id,
        ], $overrides));
    }

    /** verified_at set immediately: bypasses the enrollment confirmTotp() step for tests that only need a usable step-up method. */
    function makeMobileAgentMfaMethod(User $user, string $secret = 'JBSWY3DPEHPK3PXP'): MfaMethod
    {
        return MfaMethod::create(['user_id' => $user->id, 'type' => 'TOTP', 'secret_encrypted' => $secret, 'verified_at' => now()]);
    }

    /** Reimplements TotpService's RFC 6238 math (kept private there) purely so tests can produce a code that verify() will accept — not a shortcut around the real check. */
    function totpCodeFor(string $secret, ?int $time = null): string
    {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $input = strtoupper((string) preg_replace('/[^A-Z2-7]/', '', $secret));
        $bits = '';
        foreach (str_split($input) as $c) {
            $bits .= str_pad(decbin((int) strpos($alphabet, $c)), 5, '0', STR_PAD_LEFT);
        }
        $key = '';
        foreach (str_split($bits, 8) as $b) {
            if (strlen($b) === 8) {
                $key .= chr((int) bindec($b));
            }
        }

        $counter = intdiv($time ?? time(), 30);
        $bin = pack('N2', ($counter >> 32) & 0xffffffff, $counter & 0xffffffff);
        $hash = hash_hmac('sha1', $bin, $key, true);
        $offset = ord($hash[19]) & 15;
        $value = ((ord($hash[$offset]) & 127) << 24) | ((ord($hash[$offset + 1]) & 255) << 16) | ((ord($hash[$offset + 2]) & 255) << 8) | (ord($hash[$offset + 3]) & 255);

        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }
}
