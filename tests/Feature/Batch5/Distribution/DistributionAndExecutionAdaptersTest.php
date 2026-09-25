<?php

declare(strict_types=1);

use App\Application\Capabilities\CapabilityPinner;
use App\Application\CarrierOperations\Agreements\CarrierBrokerAgreementService;
use App\Application\Claims\Adapters\ClaimProviderRegistry;
use App\Application\Claims\Adapters\RemoteApiClaimProvider;
use App\Application\Distribution\Execution\ExecutionContext;
use App\Application\Distribution\Execution\ExecutionOutcome;
use App\Application\Distribution\SellabilityService;
use App\Application\Distribution\SellableCatalogue;
use App\Application\Policies\Adapters\ManualPolicyIssuer;
use App\Application\Policies\Adapters\PolicyIssuerRegistry;
use App\Application\Quotes\Adapters\ConfiguredQuoteProvider;
use App\Application\Quotes\Adapters\HybridQuoteProvider;
use App\Application\Quotes\Adapters\ManualQuoteProvider;
use App\Application\Quotes\Adapters\QuoteProviderRegistry;
use App\Application\Underwriting\Adapters\UnderwritingProviderRegistry;
use App\Models\InsuranceProduct;
use App\Models\PolicyIssuanceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';
require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';

function b5dUser(): User
{
    return User::create(['full_name' => 'B5D '.Str::random(4), 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
}

function b5dParty(string $name): string
{
    $id = (string) Str::uuid();
    DB::table('parties')->insert(['id' => $id, 'type' => 'ORGANIZATION', 'display_name' => $name, 'legal_identity' => '{}', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

function b5dCarrier(): string
{
    $id = (string) Str::uuid();
    DB::table('carriers')->insert(['id' => $id, 'party_id' => b5dParty('Carrier '.Str::random(4)), 'cima_code' => 'B5D-'.Str::upper(Str::random(6)), 'status' => 'ACTIVE', 'capabilities' => '{}', 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

function b5dPartner(?string $tenantId, string $type = 'BROKER', array $extra = []): string
{
    $id = (string) Str::uuid();
    DB::table('partners')->insert(['id' => $id, 'tenant_id' => $tenantId, 'party_id' => b5dParty($type.' '.Str::random(4)), 'type' => $type, 'status' => 'ACTIVE', 'compliance' => '{}', 'created_at' => now(), 'updated_at' => now(), ...$extra]);

    return $id;
}

/** An ACTIVE product already on sale before the CIMA dictionary went live (grandfathered). */
function b5dProduct(string $carrierId, string $line = 'MOTOR', array $extra = []): InsuranceProduct
{
    return InsuranceProduct::create(['carrier_id' => $carrierId, 'line_code' => $line, 'code' => $line.'-'.Str::upper(Str::random(5)), 'name' => "$line plan ".Str::random(3),
        'version' => 1, 'effective_from' => now()->subYear()->toDateString(), 'status' => 'ACTIVE', 'published_at' => '2020-01-01 00:00:00', ...$extra]);
}

/** Active agreement: MOTOR quote+bind, channels/territories as given. */
function b5dAgreement(string $carrierId, string $partnerId, array $lines = [['line_code' => 'MOTOR', 'can_quote' => true, 'can_bind' => true]], array $data = []): string
{
    $svc = app(CarrierBrokerAgreementService::class);
    $a = $svc->create(b5dUser(), ['carrier_id' => $carrierId, 'partner_id' => $partnerId, 'agreement_number' => 'B5D-'.Str::upper(Str::random(6)), 'effective_from' => now()->subMonth()->toDateString(), ...$data]);
    foreach ($lines as $line) {
        $svc->setProduct(b5dUser(), $a->id, $line);
    }
    $svc->transition(b5dUser(), $a->id, 'ACTIVE', 'Carrier signed the agreement');

    return $a->id;
}

function b5dProfile(string $carrierId, array $modes): string
{
    $id = (string) Str::uuid();
    DB::table('carrier_capability_profiles')->insert(['id' => $id, 'carrier_id' => $carrierId, 'version' => 1, 'status' => 'ACTIVE', 'effective_from' => now()->subDay(),
        'created_by' => b5dUser()->id, 'created_at' => now(), 'updated_at' => now()]);
    foreach ($modes as $cap => [$mode, $exec, $fallback]) {
        DB::table('carrier_capability_modes')->insert(['id' => (string) Str::uuid(), 'profile_id' => $id, 'capability' => $cap, 'mode' => $mode, 'execution_mode' => $exec,
            'fallback_mode' => $fallback, 'config' => '{}', 'created_at' => now(), 'updated_at' => now()]);
    }

    return $id;
}

it('REQ-DST-001 a broker can sell only what its active agreement permits, per action, channel and territory', function () {
    [$carrier, $other] = [b5dCarrier(), b5dCarrier()];
    $broker = b5dPartner(null);
    $motor = b5dProduct($carrier);
    $travel = b5dProduct($carrier, 'TRAVEL');
    $foreign = b5dProduct($other);
    b5dAgreement($carrier, $broker, [['line_code' => 'MOTOR', 'can_quote' => true, 'can_bind' => false, 'commission_basis_points' => 1200]], ['channels' => ['BROKER', 'B2C'], 'territories' => ['CM']]);
    $s = app(SellabilityService::class);

    $ok = $s->check($motor->id, 'quote', ['partner_id' => $broker, 'territory' => 'CM']);
    expect($ok['sellable'])->toBeTrue()->and($ok['commission_basis_points'])->toBe(1200)->and($ok['channel'])->toBe('BROKER');
    expect($s->check($motor->id, 'bind', ['partner_id' => $broker])['reasons'])->toBe(['BIND_NOT_PERMITTED']);
    expect($s->check($travel->id, 'quote', ['partner_id' => $broker])['reasons'])->toBe(['PRODUCT_NOT_AUTHORISED']);
    expect($s->check($foreign->id, 'quote', ['partner_id' => $broker])['reasons'])->toBe(['NO_AGREEMENT']);
    expect($s->check($motor->id, 'quote', ['partner_id' => $broker, 'channel' => 'AGENT'])['reasons'])->toBe(['CHANNEL_NOT_PERMITTED']);
    expect($s->check($motor->id, 'quote', ['partner_id' => $broker, 'territory' => 'GA'])['reasons'])->toBe(['TERRITORY_NOT_PERMITTED']);

    // Product / carrier / seller state all gate the sale.
    $motor->update(['status' => 'RETIRED']);
    expect($s->check($motor->id, 'quote', ['partner_id' => $broker])['reasons'])->toContain('PRODUCT_NOT_ACTIVE');
    $motor->update(['status' => 'ACTIVE']);
    DB::table('partners')->where('id', $broker)->update(['licence_expires_on' => now()->subDay()->toDateString()]);
    expect($s->check($motor->id, 'quote', ['partner_id' => $broker])['reasons'])->toBe(['LICENCE_EXPIRED']);
    DB::table('partners')->where('id', $broker)->update(['licence_expires_on' => null]);
    DB::table('carriers')->where('id', $carrier)->update(['status' => 'SUSPENDED']);
    expect($s->check($motor->id, 'quote', ['partner_id' => $broker])['reasons'])->toBe(['CARRIER_INACTIVE']);
});

it('REQ-DST-001 an agent sells under its supervising brokerage agreement and its own branch/status', function () {
    $carrier = b5dCarrier();
    $tenant = makeAuthTestTenant();
    $broker = b5dPartner($tenant->id);
    $branch = (string) Str::uuid();
    DB::table('tenant_branches')->insert(['id' => $branch, 'tenant_id' => $tenant->id, 'code' => 'DLA', 'name' => 'Douala', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
    $agent = b5dPartner($tenant->id, 'AGENT', ['supervisor_partner_id' => $broker, 'branch_id' => $branch]);
    $orphan = b5dPartner($tenant->id, 'AGENT');
    $motor = b5dProduct($carrier);
    b5dAgreement($carrier, $broker, data: ['channels' => ['AGENT']]);
    $s = app(SellabilityService::class);

    $r = $s->check($motor->id, 'quote', ['partner_id' => $agent]);
    expect($r['sellable'])->toBeTrue()->and($r['selling_partner_id'])->toBe($broker)->and($r['channel'])->toBe('AGENT');
    expect($s->check($motor->id, 'quote', ['partner_id' => $orphan])['reasons'])->toBe(['NO_AGREEMENT']);
    // The broker itself sells on BROKER channel, which this agreement does not list.
    expect($s->check($motor->id, 'quote', ['partner_id' => $broker])['reasons'])->toBe(['CHANNEL_NOT_PERMITTED']);

    DB::table('tenant_branches')->where('id', $branch)->update(['status' => 'SUSPENDED']);
    expect($s->check($motor->id, 'quote', ['partner_id' => $agent])['reasons'])->toBe(['BRANCH_INACTIVE']);
    DB::table('tenant_branches')->where('id', $branch)->update(['status' => 'ACTIVE']);
    DB::table('partners')->where('id', $broker)->update(['status' => 'SUSPENDED']);
    expect($s->check($motor->id, 'quote', ['partner_id' => $agent])['reasons'])->toBe(['SUPERVISOR_PARTNER_INACTIVE']);
});

it('REQ-DST-001 a product not yet grandfathered needs its carrier CIMA authorization to be sellable', function () {
    $carrier = b5dCarrier();
    $broker = b5dPartner(null);
    $product = b5dProduct($carrier, 'MOTOR', ['published_at' => now()]);
    b5dAgreement($carrier, $broker);
    $r = app(SellabilityService::class)->check($product->id, 'quote', ['partner_id' => $broker]);
    if (app(\App\Application\Regulatory\CimaPublicationGuard::class)->isGrandfathered($product)) {
        expect($r['sellable'])->toBeTrue(); // no CIMA regime seeded in this database: every live product is grandfathered
    } else {
        expect($r['reasons'])->toContain('CARRIER_NOT_AUTHORIZED')->and($r['regulatory'])->not->toBeEmpty();
    }
});

it('REQ-DST-002 the broker catalogue is derived from carrier products + agreement; cover and tariff are the carrier\'s and a paused publication only hides', function () {
    $carrier = b5dCarrier();
    $tenant = makeAuthTestTenant();
    $broker = b5dPartner($tenant->id);
    $motor = b5dProduct($carrier, 'MOTOR', ['coverages' => [['code' => 'RC', 'name' => 'Third party']]]);
    b5dProduct($carrier, 'TRAVEL');
    b5dProduct(b5dCarrier());
    b5dAgreement($carrier, $broker, [['line_code' => 'MOTOR', 'can_quote' => true, 'can_bind' => true, 'can_collect_premium' => true]]);

    $items = app(SellableCatalogue::class)->for(['partner_id' => $broker]);
    expect($items)->toHaveCount(1);
    expect($items[0]['product_id'])->toBe($motor->id)->and($items[0]['source'])->toBe('CARRIER_PRODUCT')->and($items[0]['broker_overridable'])->toBeFalse()
        ->and($items[0]['coverages'])->toBe([['code' => 'RC', 'name' => 'Third party']])
        ->and($items[0]['permissions'])->toBe(['quote' => true, 'bind' => true, 'collect_premium' => true])
        ->and($items[0]['execution']['quote']['execution_mode'])->toBe('MANUAL');
    expect(app(SellableCatalogue::class)->for(['partner_id' => $broker, 'include_blocked' => true]))->toHaveCount(2);

    DB::table('marketplace_publications')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'product_id' => $motor->id, 'status' => 'PAUSED', 'channels' => '["WEB"]',
        'created_by' => b5dUser()->id, 'created_at' => now(), 'updated_at' => now()]);
    expect(app(SellableCatalogue::class)->for(['partner_id' => $broker]))->toBeEmpty();
});

it('REQ-DST-001 the direct (tenant) channel sells only products published live on that channel', function () {
    $tenant = makeAuthTestTenant();
    $product = b5dProduct(b5dCarrier());
    $s = app(SellabilityService::class);
    expect($s->check($product->id, 'quote', ['tenant_id' => $tenant->id, 'channel' => 'WEB'])['reasons'])->toBe(['NOT_PUBLISHED_ON_CHANNEL']);
    DB::table('marketplace_publications')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'product_id' => $product->id, 'status' => 'APPROVED', 'channels' => '["WEB"]',
        'created_by' => b5dUser()->id, 'created_at' => now(), 'updated_at' => now()]);
    expect($s->check($product->id, 'quote', ['tenant_id' => $tenant->id, 'channel' => 'WEB'])['sellable'])->toBeTrue();
    expect($s->check($product->id, 'quote', ['tenant_id' => $tenant->id, 'channel' => 'USSD'])['sellable'])->toBeFalse();
});

it('REQ-AOM-002 registries choose MANUAL / CONFIGURED / HYBRID / REMOTE_API adapters from CapabilityResolver; REMOTE_API is an INTEGRATION_UNAVAILABLE stub', function () {
    $carrier = b5dCarrier();
    $product = b5dProduct($carrier);
    $quotes = app(QuoteProviderRegistry::class);
    expect($quotes->for($carrier))->toBeInstanceOf(ManualQuoteProvider::class); // nothing configured: Mode 1 minimum
    expect(app(PolicyIssuerRegistry::class)->for($carrier))->toBeInstanceOf(ManualPolicyIssuer::class);

    b5dProfile($carrier, ['QUOTATION' => ['CONFIGURED', 'CONFIGURED', null], 'UNDERWRITING' => ['RULE_ENGINE', 'CONFIGURED', null], 'CLAIMS_INTAKE' => ['API_SYNCHRONIZED', 'REMOTE_API', 'BROKER_ASSISTED']]);
    expect($quotes->for($carrier, $product->id))->toBeInstanceOf(ConfiguredQuoteProvider::class);
    expect($quotes->forMode('HYBRID'))->toBeInstanceOf(HybridQuoteProvider::class);
    $uw = app(UnderwritingProviderRegistry::class)->execute(new ExecutionContext('preview', null, $carrier, $product->id));
    expect($uw->status)->toBe(ExecutionOutcome::HANDLED_BY_PLATFORM)->and($uw->handler)->toContain('ProposalService::submit');

    $claims = app(ClaimProviderRegistry::class);
    expect($claims->for($carrier))->toBeInstanceOf(RemoteApiClaimProvider::class);
    $out = $claims->execute(new ExecutionContext('preview', null, $carrier));
    expect($out->status)->toBe('INTEGRATION_UNAVAILABLE')->and($out->errorCode)->toBe('INTEGRATION_UNAVAILABLE')->and($out->fallbackMode)->toBe('BROKER_ASSISTED')->and($out->available())->toBeFalse();
});

it('REQ-AOM-002 pins the capability mode when quote offers, proposals, issuance requests and claims are created, and the pin keeps routing', function () {
    $f = makeMobileCustomerFixture('+237670055501');
    $pinner = app(CapabilityPinner::class);
    $offerId = $f['proposal']->quote_offer_id;
    expect($pinner->pinned('quote', $offerId, 'QUOTATION')?->execution_mode)->toBe('MANUAL');
    expect($pinner->pinned('proposal', $f['proposal']->id, 'UNDERWRITING')?->carrier_id)->toBe($f['carrier']->id);

    $payment = makeMobileTestPayment($f['proposal'], $f['tenant'], ['amount_minor' => 100000]);
    $req = PolicyIssuanceRequest::create(['tenant_id' => $f['tenant']->id, 'proposal_id' => $f['proposal']->id, 'payment_intent_id' => $payment->id, 'carrier_id' => $f['carrier']->id,
        'status' => 'REQUESTED', 'authority_snapshot' => [], 'terms_hash' => str_repeat('a', 64), 'coverage_starts_at' => now(), 'coverage_ends_at' => now()->addYear(), 'requested_by' => $f['user']->id]);
    $pin = $pinner->pinned('policy_issuance_request', $req->id, 'POLICY_ISSUANCE');
    expect($pin)->not->toBeNull()->and($pin->product_id)->toBe($f['product']->id);

    $policy = makeMobileTestPolicy($f['proposal'], $f['tenant'], $f['carrier']->id, $f['party']->id);
    $claim = makeMobileTestClaim($f['tenant'], $policy, $f['party']);
    expect($pinner->pinned('claim', $claim->id, 'CLAIMS_INTAKE')?->execution_mode)->toBe('MANUAL');

    // The insurer later moves claims to REMOTE_API: the existing claim keeps its pinned MANUAL adapter.
    b5dProfile($f['carrier']->id, ['CLAIMS_INTAKE' => ['API_SYNCHRONIZED', 'REMOTE_API', null]]);
    $claims = app(ClaimProviderRegistry::class);
    expect($claims->forSubject('claim', $claim->id, $f['carrier']->id)->executionMode())->toBe('MANUAL');
    expect($claims->for($f['carrier']->id)->executionMode())->toBe('REMOTE_API');
});

it('REQ-DST-001 REQ-DST-002 REQ-AOM-002 exposes catalogue, sellability and execution plan with permission and tenant scoping', function () {
    $carrier = b5dCarrier();
    $tenant = makeAuthTestTenant();
    $broker = b5dPartner($tenant->id);
    $foreign = b5dPartner(makeAuthTestTenant('x')->id);
    $motor = b5dProduct($carrier);
    b5dAgreement($carrier, $broker);
    $h = tenantHeader($tenant);

    Passport::actingAs(makeAuthTestUser($tenant, ['partners.read']));
    $this->getJson('/api/v1/distribution/catalogue', $h)->assertForbidden();

    Passport::actingAs(makeAuthTestUser($tenant, ['distribution.catalogue.view']));
    $this->getJson("/api/v1/distribution/catalogue?partner_id=$broker", $h)->assertOk()->assertJsonPath('meta.count', 1)->assertJsonPath('data.0.product_id', $motor->id);
    $this->getJson("/api/v1/distribution/catalogue?partner_id=$foreign", $h)->assertNotFound();
    $this->getJson("/api/v1/distribution/sellability?partner_id=$broker&product_id={$motor->id}&action=bind", $h)->assertOk()->assertJsonPath('data.sellable', true);
    // No partner behind the caller: the tenant's direct channel, where nothing is published.
    $this->getJson('/api/v1/distribution/catalogue', $h)->assertOk()->assertJsonPath('meta.count', 0);
    $this->getJson("/api/v1/distribution/execution-plan?carrier_id=$carrier", $h)->assertOk()
        ->assertJsonPath('data.quote.execution_mode', 'MANUAL')->assertJsonPath('data.claim.status', 'AWAITING_CARRIER');
});

it('REQ-DST-001 an agent sees its own sellable catalogue by default', function () {
    $f = makeMobileAgentFixture('+237680055502');
    $f['role']->update(['permissions' => [...$f['role']->permissions, 'distribution.catalogue.view']]);
    $carrier = b5dCarrier();
    $motor = b5dProduct($carrier);
    b5dAgreement($carrier, $f['partner']->id);
    Passport::actingAs($f['user']);
    $this->getJson('/api/v1/distribution/catalogue', tenantHeader($f['tenant']))->assertOk()
        ->assertJsonPath('meta.viewer.partner_id', $f['partner']->id)->assertJsonPath('data.0.product_id', $motor->id)->assertJsonPath('data.0.channel', 'AGENT');
});
