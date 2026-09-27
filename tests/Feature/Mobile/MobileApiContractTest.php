<?php

declare(strict_types=1);

/*
 | Mobile app <-> API contract (docs/MOBILE_API_CONTRACT_2026-09-27.md). Each test asserts the response keys
 | the app's screens read (types in "mobile app/src/api/*.ts"), so a presenter change that drops one fails here.
 */

use App\Models\CustomerAttribution;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

/** @param list<string> $keys */
function assertMobileKeys(TestResponse $r, string $path, array $keys): void
{
    $r->assertOk();
    $row = $r->json($path);
    expect($row)->toBeArray("{$path} is missing");
    foreach ($keys as $k) {
        expect(array_key_exists($k, $row))->toBeTrue("{$path}.{$k} missing from the response");
    }
}

/** A customer with an issued policy, a claim and a policy document. */
function mobileContractCustomer(): array
{
    $f = makeMobileCustomerFixture();
    $policy = makeMobileTestPolicy($f['proposal'], $f['tenant'], $f['carrier']->id, $f['party']->id, ['policy_number' => 'POL-CONTRACT-1', 'premium_minor' => 100000, 'currency' => 'XAF']);
    $claim = makeMobileTestClaim($f['tenant'], $policy, $f['party']);
    $doc = makeMobileTestDocument($f['tenant'], $f['party'], ['policy_id' => $policy->id]);
    Passport::actingAs($f['user']);

    return $f + ['policy' => $policy, 'claim' => $claim, 'document' => $doc, 'h' => tenantHeaderFor($f['tenant'])];
}

function mobilePolicyKeys(): array
{
    return ['id', 'policy_number', 'status', 'coverage_starts_at', 'coverage_ends_at', 'carrier_id', 'proposal_id', 'party_id', 'premium_minor', 'currency', 'issued_at', 'terms_snapshot', 'carrier', 'proposal', 'carrier_name', 'carrier_logo_url', 'product_name', 'risk_asset'];
}

it('wallet list and policy detail carry the WalletPolicy fields', function () {
    $c = mobileContractCustomer();

    assertMobileKeys($this->getJson('/api/v1/mobile/wallet', $c['h']), 'data.0', mobilePolicyKeys());
    assertMobileKeys($this->getJson("/api/v1/mobile/wallet/policies/{$c['policy']->id}", $c['h']), 'data', [...mobilePolicyKeys(), 'certificates', 'documents', 'certificate', 'delivery', 'allowed_actions']);
    assertMobileKeys($this->getJson("/api/v1/mobile/policies/{$c['policy']->id}/documents", $c['h']), 'data', ['policy', 'groups', 'packs', 'pack_download_url']);
});

it('quote detail returns quote + ranked offers, and quote history carries the summary fields', function () {
    $c = mobileContractCustomer();

    $r = $this->getJson("/api/v1/quotes/{$c['quote']->id}", $c['h']);
    expect($r->json('data.allowed_actions'))->toBeArray();
    assertMobileKeys($r, 'data.quote', ['id', 'party_id', 'line_code', 'status', 'currency', 'risk_facts', 'expires_at', 'version', 'risk_asset_id', 'lifecycle_state', 'quote_number']);
    assertMobileKeys($r, 'data.offers.0', ['id', 'quote_id', 'carrier_id', 'product_id', 'premium_minor', 'tax_minor', 'fee_minor', 'total_minor', 'currency', 'status', 'valid_until', 'coverage_snapshot', 'ranking_reasons', 'carrier', 'product', 'carrier_logo_url']);

    $list = $this->getJson('/api/v1/mobile/quotes', $c['h']);
    assertMobileKeys($list, 'data.data.0', ['id', 'line_code', 'status', 'quote_number', 'lifecycle_state', 'expires_at', 'offer_count', 'lowest_total_minor', 'product_name', 'vehicle_label', 'can_resume']);
    expect($list->json('data.data.0.offer_count'))->toBe(1)
        ->and($list->json('data.data.0.lowest_total_minor'))->toBe(100000)
        ->and($list->json('data.data.0.product_name'))->toBe('Test Plan');
});

it('proposal list rows and proposal detail carry the Proposal fields', function () {
    $c = mobileContractCustomer();

    assertMobileKeys($this->getJson('/api/v1/mobile/proposals', $c['h']), 'data.0', ['id', 'proposal_number', 'status', 'terms_snapshot', 'quote_offer_id', 'policy_id', 'product_name', 'carrier_name', 'carrier_logo_url', 'total_minor', 'currency', 'submitted_at', 'decided_at', 'created_at', 'updated_at']);
    $detail = $this->getJson("/api/v1/proposals/{$c['proposal']->id}", $c['h']);
    assertMobileKeys($detail, 'data', ['id', 'proposal_number', 'status', 'terms_snapshot', 'quote_offer_id', 'policy_id', 'carrier_logo_url', 'submitted_at', 'decided_at', 'created_at', 'offer', 'documents', 'required_documents', 'underwriting_case', 'payments', 'disclosure_schema', 'allowed_actions']);
    expect($detail->json('data.policy_id'))->toBe($c['policy']->id);
});

it('claim list and claim detail carry the Claim fields', function () {
    $c = mobileContractCustomer();
    $keys = ['id', 'claim_number', 'policy_id', 'status', 'incident_at', 'incident_location', 'description', 'created_at', 'policy', 'carrier_logo_url'];

    assertMobileKeys($this->getJson('/api/v1/mobile/claims', $c['h']), 'data.data.0', $keys);
    assertMobileKeys($this->getJson("/api/v1/mobile/claims/{$c['claim']->id}", $c['h']), 'data', [...$keys, 'can_withdraw', 'allowed_actions']);
});

it('documents list, detail and access carry the SecureDocument fields', function () {
    $c = mobileContractCustomer();
    $keys = ['id', 'owner_type', 'owner_id', 'label', 'mime_type', 'status', 'issued_at', 'expires_at', 'signed_url', 'share_reference'];

    assertMobileKeys($this->getJson('/api/v1/mobile/documents', $c['h']), 'data.data.0', $keys);
    assertMobileKeys($this->getJson("/api/v1/mobile/documents/{$c['document']->id}", $c['h']), 'data', $keys);
    expect($this->getJson("/api/v1/mobile/documents?owner_type=POLICY&owner_id={$c['policy']->id}", $c['h'])->json('data.data.0.owner_type'))->toBe('POLICY');
    expect($this->getJson("/api/v1/mobile/documents?owner_type=CLAIM&owner_id={$c['claim']->id}", $c['h'])->json('data.data'))->toBe([]);

    $access = $this->postJson("/api/v1/mobile/documents/{$c['document']->id}/access", [], $c['h'] + ['Idempotency-Key' => (string) Str::uuid()]);
    assertMobileKeys($access, 'data', [...$keys, 'url']);
    expect($access->json('data.signed_url'))->toBeString()->toBe($access->json('data.url'));
});

it('partner book lists (agent and broker) carry the PartnerQuote/Policy/Proposal/Claim fields', function () {
    foreach (['agent' => makeMobileAgentFixture('+237680000901'), 'broker' => makeMobilePartnerFixture('BROKER', '+237670000902')] as $kind => $p) {
        $chain = makeMobileFinanceProposalChain($p['tenant']);
        $policy = makeMobileTestPolicy($chain['proposal'], $p['tenant'], $chain['carrier']->id, $chain['party']->id, ['policy_number' => 'POL-BOOK-'.$kind]);
        makeMobileTestClaim($p['tenant'], $policy, $chain['party']);
        $chain['proposal']->offer->quote->forceFill(['channel' => 'AGENT'])->save();
        CustomerAttribution::create(['party_id' => $chain['party']->id, 'partner_id' => $p['partner']->id, 'origin_type' => $kind === 'agent' ? 'AGENT' : 'BROKER', 'terms_version' => 't1', 'effective_from' => now()->subDay(), 'status' => 'ACTIVE', 'recorded_by' => $p['user']->id]);
        Passport::actingAs($p['user']);
        $h = tenantHeaderFor($p['tenant']);
        $base = "/api/v1/mobile/partner/{$kind}";

        assertMobileKeys($this->getJson("{$base}/policies", $h), 'data.0', ['id', 'policy_number', 'customer_name', 'carrier_id', 'carrier_name', 'line_code', 'premium_minor', 'currency', 'status', 'coverage_starts_at', 'coverage_ends_at', 'issued_at']);
        assertMobileKeys($this->getJson("{$base}/proposals", $h), 'data.0', ['id', 'proposal_number', 'customer_id', 'customer_name', 'status', 'line_code', 'carrier_name', 'carrier_logo_url', 'policy_id', 'total_minor', 'currency', 'submitted_at', 'decided_at', 'created_at']);
        expect($this->getJson("{$base}/proposals", $h)->json('data.0.policy_id'))->toBe($policy->id);
        assertMobileKeys($this->getJson("{$base}/claims", $h), 'data.0', ['id', 'claim_number', 'policy_id', 'policy_number', 'customer_name', 'status', 'priority', 'carrier_name', 'carrier_logo_url', 'estimated_loss_minor', 'approved_amount_minor', 'currency', 'loss_occurred_at', 'submitted_at']);
        $quotes = $this->getJson("{$base}/quotes", $h)->assertOk();
        if ($kind === 'agent') {
            assertMobileKeys($quotes, 'data.0', ['id', 'customer_id', 'customer_name', 'line_code', 'status', 'channel', 'offers', 'best_premium_minor', 'currency', 'expires_at', 'created_at']);
        }
    }
});

it('the institution directory is public and carries the Institution fields', function () {
    $f = makeMobileCustomerFixture();
    // Anonymous: the app calls it before sign-in (anonymous: true), without X-Tenant-Id.
    $r = $this->getJson('/api/v1/public/institutions?type=insurer');
    assertMobileKeys($r, 'data.0', ['id', 'type', 'name', 'initials', 'logo_url', 'letterhead_available', 'legal_footer', 'city', 'code', 'phone', 'website', 'products', 'canonical_id', 'legal_name', 'short_name', 'insurer_code', 'branch', 'regulator_sequence', 'product_families', 'is_official_register', 'licensed', 'contacts', 'head_office']);
    assertMobileKeys($this->getJson("/api/v1/public/institutions/{$f['carrier']->id}"), 'data', ['id', 'type', 'name', 'products', 'contacts', 'branches']);
});

it('capabilities, payment detail and customer profile carry the A1 / location fields', function () {
    $c = mobileContractCustomer();
    $payment = makeMobileTestPayment($c['proposal'], $c['tenant']);

    $caps = $this->getJson('/api/v1/mobile/capabilities', $c['h']);
    assertMobileKeys($caps, 'data', ['data_scope', 'modules']);
    assertMobileKeys($caps, 'data.modules.policies', ['view', 'actions']);
    assertMobileKeys($this->getJson("/api/v1/mobile/payments/{$payment->id}", $c['h']), 'data', ['id', 'status', 'amount_minor', 'currency', 'allowed_actions']);
    assertMobileKeys($this->getJson('/api/v1/mobile/account/customer-profile', $c['h']), 'data', ['party_id', 'full_name', 'address_line1', 'city', 'region', 'country_code', 'latitude', 'longitude', 'allowed_actions']);
    assertMobileKeys($this->getJson('/api/v1/mobile/account/devices', $c['h']), 'data', []);
    assertMobileKeys($this->getJson('/api/v1/me/security/login-activity', $c['h']), 'data', []);
});
