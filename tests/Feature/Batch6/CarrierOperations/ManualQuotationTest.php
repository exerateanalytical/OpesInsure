<?php

declare(strict_types=1);

use App\Application\CarrierOperations\QuoteRequests\Models\CarrierQuoteRequest;
use App\Application\CarrierOperations\QuoteRequests\QuoteRequestService;
use App\Application\Capabilities\CapabilityPinner;
use App\Application\Cases\Models\SlaClock;
use App\Application\Cases\Models\WorkCase;
use App\Application\Distribution\Execution\ExecutionContext;
use App\Application\Quotes\Adapters\QuoteProviderRegistry;
use App\Models\Carrier;
use App\Models\InsuranceProduct;
use App\Models\Party;
use App\Models\Quote;
use App\Models\QuoteOffer;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';

/** @return array{tenant: Tenant, carrier: Carrier, product: InsuranceProduct, quote: Quote, customer: User} */
function b6cFixture(): array
{
    $tenant = makeAuthTestTenant('b6c');
    $party = Party::create(['type' => 'INDIVIDUAL', 'display_name' => 'B6C Customer', 'status' => 'ACTIVE']);
    $customer = User::create(['full_name' => 'B6C Customer', 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'party_id' => $party->id, 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
    $carrier = b6cCarrier();
    $product = InsuranceProduct::create(['carrier_id' => $carrier->id, 'line_code' => 'AUTO', 'code' => 'AUTO-'.Str::upper(Str::random(6)), 'name' => 'B6C Motor', 'version' => 1,
        'effective_from' => now()->subYear()->toDateString(), 'status' => 'ACTIVE']);
    $quote = Quote::create(['tenant_id' => $tenant->id, 'party_id' => $party->id, 'line_code' => 'AUTO', 'status' => 'REFERRED', 'currency' => 'XAF',
        'risk_facts' => ['vehicle_value_minor' => 500000000], 'submitted_at' => now(), 'expires_at' => now()->addDays(7), 'version' => 1]);

    return compact('tenant', 'carrier', 'product', 'quote', 'customer');
}

function b6cCarrier(): Carrier
{
    $p = Party::create(['type' => 'ORGANIZATION', 'display_name' => 'B6C Insurer '.Str::random(4), 'status' => 'ACTIVE']);

    return Carrier::create(['party_id' => $p->id, 'cima_code' => 'B6C-'.Str::upper(Str::random(6)), 'status' => 'ACTIVE']);
}

function b6cInsurerStaff(Tenant $tenant, string $carrierId, array $perms = ['carrier.quote_requests.view', 'carrier.quote_requests.respond']): User
{
    $u = makeAuthTestUser($tenant, $perms, 'CARRIER_STAFF');
    TenantMembership::where('tenant_id', $tenant->id)->where('user_id', $u->id)->update(['carrier_id' => $carrierId]);

    return $u;
}

function b6cDocument(string $tenantId): string
{
    $id = (string) Str::uuid();
    DB::table('documents')->insert(['id' => $id, 'tenant_id' => $tenantId, 'category' => 'CARRIER_OFFER', 'storage_key' => 'b6c/'.$id, 'mime_type' => 'application/pdf',
        'size_bytes' => 100, 'sha256' => str_repeat('b', 64), 'scan_status' => 'CLEAN', 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

function b6cOfferPayload(array $over = []): array
{
    return array_merge([
        'premium_minor' => 9000000, 'tax_minor' => 1300000, 'fee_minor' => 500000,
        'premium_breakdown' => [['code' => 'RC', 'label' => 'Third-party liability', 'amount_minor' => 7000000], ['code' => 'DOM', 'amount_minor' => 2000000],
            ['code' => 'TAX', 'amount_minor' => 1300000], ['code' => 'FEE', 'amount_minor' => 500000]],
        'conditions' => [['code' => 'INSPECTION', 'text' => 'Vehicle inspection within 15 days of cover start.']],
        'valid_until' => now()->addDays(10)->toIso8601String(), 'carrier_reference' => 'INS-REF-001',
    ], $over);
}

it('REQ-QUO-006 a broker sends a quote to a MANUAL insurer: request routed as a case with SLA clocks, idempotent, customer told', function () {
    $f = b6cFixture();
    $h = tenantHeader($f['tenant']);
    // SLA targets are tenant/admin configuration (none invented by the seed): configure them on the type here.
    DB::table('case_types')->where('code', 'CARRIER_QUOTE_REQUEST')->update(['sla_policies' => json_encode([
        ['metric' => 'FIRST_RESPONSE', 'target_business_minutes' => 240], ['metric' => 'RESOLUTION', 'target_business_minutes' => 960]])]);
    Passport::actingAs(makeAuthTestUser($f['tenant'], ['quotes.carrier_requests.create', 'quotes.carrier_requests.view'], 'BROKER_STAFF'));

    $res = $this->postJson("/api/v1/quotes/{$f['quote']->id}/carrier-requests", ['carrier_id' => $f['carrier']->id, 'product_id' => $f['product']->id], $h)
        ->assertCreated()->assertJsonPath('data.status', 'REQUESTED')->assertJsonPath('meta.execution.execution_mode', 'MANUAL')
        ->assertJsonPath('meta.execution.status', 'AWAITING_CARRIER');
    $id = $res->json('data.id');
    $case = WorkCase::withoutGlobalScopes()->find($res->json('data.case_id'));
    expect($case->case_type_code)->toBe('CARRIER_QUOTE_REQUEST')->and($case->carrier_id)->toBe($f['carrier']->id)->and($case->subject_id)->toBe($id)
        ->and(SlaClock::where('case_id', $case->id)->pluck('metric')->sort()->values()->all())->toBe(['FIRST_RESPONSE', 'RESOLUTION'])
        ->and($res->json('data.response_due_at'))->not->toBeNull();

    $this->postJson("/api/v1/quotes/{$f['quote']->id}/carrier-requests", ['carrier_id' => $f['carrier']->id, 'product_id' => $f['product']->id], $h)
        ->assertOk()->assertJsonPath('data.id', $id);
    $this->getJson("/api/v1/quotes/{$f['quote']->id}/carrier-requests", $h)->assertOk()->assertJsonCount(1, 'data');
    expect(DB::table('outbox_messages')->where('event_name', 'carrier_quote_request.opened')->where('aggregate_id', $id)->count())->toBe(1)
        ->and(UserNotification::where('user_id', $f['customer']->id)->where('type', 'QUOTE')->exists())->toBeTrue();

    // A preview through the planner/registry has no side effect.
    app(QuoteProviderRegistry::class)->execute(new ExecutionContext('preview', null, $f['carrier']->id));
    expect(CarrierQuoteRequest::count())->toBe(1);
});

it('REQ-QUO-006 insurer staff work their own queue, enter the offer and it flows into the normal quote/offer path', function () {
    $f = b6cFixture();
    $h = tenantHeader($f['tenant']);
    DB::table('case_types')->where('code', 'CARRIER_QUOTE_REQUEST')->update(['sla_policies' => json_encode([['metric' => 'FIRST_RESPONSE', 'target_business_minutes' => 240]])]);
    $request = app(QuoteRequestService::class)->open($f['quote'], $f['carrier']->id, $f['product']->id, null);
    $otherCarrier = b6cCarrier();

    Passport::actingAs(b6cInsurerStaff($f['tenant'], $otherCarrier->id));
    $this->getJson('/api/v1/carrier/quote-requests', $h)->assertOk()->assertJsonPath('meta.count', 0);
    $this->getJson("/api/v1/carrier/quote-requests/{$request->id}", $h)->assertNotFound();

    $staff = b6cInsurerStaff($f['tenant'], $f['carrier']->id);
    Passport::actingAs($staff);
    $this->getJson('/api/v1/carrier/quote-requests', $h)->assertOk()->assertJsonPath('meta.count', 1)->assertJsonPath('data.0.id', $request->id);
    $this->postJson("/api/v1/carrier/quote-requests/{$request->id}/start", [], $h)->assertOk()->assertJsonPath('data.status', 'IN_PROGRESS');
    expect(SlaClock::where('case_id', $request->case_id)->where('metric', 'FIRST_RESPONSE')->value('stopped_at'))->not->toBeNull();

    $this->postJson("/api/v1/carrier/quote-requests/{$request->id}/offer", b6cOfferPayload(['total_minor' => 1]), $h)->assertStatus(422)->assertJsonPath('code', 'PREMIUM_INCONSISTENT');
    $bad = b6cOfferPayload();
    $bad['premium_breakdown'][0]['amount_minor'] = 1;
    $this->postJson("/api/v1/carrier/quote-requests/{$request->id}/offer", $bad, $h)->assertStatus(422)->assertJsonPath('code', 'BREAKDOWN_INCONSISTENT');

    $doc = b6cDocument($f['tenant']->id);
    $this->postJson("/api/v1/carrier/quote-requests/{$request->id}/offer", b6cOfferPayload(['document_ids' => [$doc]]), $h)
        ->assertCreated()->assertJsonPath('data.status', 'OFFERED')->assertJsonPath('data.responses.0.source', 'INSURER_PORTAL')
        ->assertJsonPath('data.responses.0.total_minor', 10800000);
    $this->postJson("/api/v1/carrier/quote-requests/{$request->id}/offer", b6cOfferPayload(), $h)->assertStatus(409)->assertJsonPath('code', 'REQUEST_CLOSED');

    $request->refresh();
    $offer = QuoteOffer::findOrFail($request->quote_offer_id);
    expect($offer->tariff_version_id)->toBeNull()->and($offer->origin)->toBe('MANUAL')->and($offer->status)->toBe('OFFERED')
        ->and($offer->total_minor)->toBe(10800000)->and($offer->external_reference)->toBe('INS-REF-001')
        ->and($offer->coverage_snapshot['conditions'][0]['code'])->toBe('INSPECTION')
        ->and(app(CapabilityPinner::class)->pinned('quote', $offer->id, 'QUOTATION')?->execution_mode)->toBe('MANUAL');
    $quote = $f['quote']->refresh();
    expect($quote->status)->toBe('OFFERED')->and($quote->lifecycle_state)->toBe('CALCULATED');
    expect(WorkCase::withoutGlobalScopes()->find($request->case_id)->status)->toBe('CLOSED')
        ->and(UserNotification::where('user_id', $f['customer']->id)->where('title', 'Your quotes are ready')->exists())->toBeTrue();

    // Normal path from here: the customer accepts the manual offer.
    app(App\Application\Quotes\QuoteService::class)->accept($quote, $offer, $f['customer']);
    expect($offer->refresh()->status)->toBe('ACCEPTED');
});

it('REQ-QUO-006 a broker records on the insurer\'s behalf only with evidence; insurer declines close the case', function () {
    $f = b6cFixture();
    $h = tenantHeader($f['tenant']);
    $svc = app(QuoteRequestService::class);
    $r1 = $svc->open($f['quote'], $f['carrier']->id, $f['product']->id, null);
    Passport::actingAs(makeAuthTestUser($f['tenant'], ['quotes.carrier_requests.record_on_behalf'], 'BROKER_ADMIN'));
    $this->postJson("/api/v1/quotes/{$f['quote']->id}/carrier-requests/{$r1->id}/offer-on-behalf", b6cOfferPayload(), $h)->assertStatus(422);
    $this->postJson("/api/v1/quotes/{$f['quote']->id}/carrier-requests/{$r1->id}/offer-on-behalf", b6cOfferPayload(['evidence_document_id' => (string) Str::uuid()]), $h)
        ->assertStatus(422)->assertJsonPath('code', 'DOCUMENT_NOT_FOUND');
    $this->postJson("/api/v1/quotes/{$f['quote']->id}/carrier-requests/{$r1->id}/offer-on-behalf", b6cOfferPayload(['evidence_document_id' => b6cDocument($f['tenant']->id)]), $h)
        ->assertCreated()->assertJsonPath('data.responses.0.source', 'BROKER_ON_BEHALF');

    $other = b6cCarrier();
    $otherProduct = InsuranceProduct::create(['carrier_id' => $other->id, 'line_code' => 'AUTO', 'code' => 'AUTO-'.Str::upper(Str::random(6)), 'name' => 'Other', 'version' => 1,
        'effective_from' => now()->subYear()->toDateString(), 'status' => 'ACTIVE']);
    expect(fn () => $svc->open($f['quote'], $other->id, $f['product']->id, null))->toThrow(App\Interfaces\Http\Errors\ApiProblemException::class);
    $r2 = $svc->open($f['quote'], $other->id, $otherProduct->id, null);
    Passport::actingAs(b6cInsurerStaff($f['tenant'], $other->id));
    $this->postJson("/api/v1/carrier/quote-requests/{$r2->id}/decline", ['decline_reason_code' => 'RISK_OUTSIDE_APPETITE', 'source' => 'INSURER_API'], $h)
        ->assertCreated()->assertJsonPath('data.status', 'DECLINED')->assertJsonPath('data.responses.0.source', 'INSURER_API');
    expect(WorkCase::withoutGlobalScopes()->find($r2->case_id)->status)->toBe('CLOSED')
        ->and(DB::table('carrier_quote_responses')->count())->toBe(2);
});

it('REQ-QUO-006 refuses non-MANUAL insurers and unauthorised callers; sweep closes requests of a cancelled quote', function () {
    $f = b6cFixture();
    $h = tenantHeader($f['tenant']);
    Passport::actingAs(makeAuthTestUser($f['tenant'], ['quotes.carrier_requests.view'], 'BROKER_STAFF'));
    $this->postJson("/api/v1/quotes/{$f['quote']->id}/carrier-requests", ['carrier_id' => $f['carrier']->id], $h)->assertForbidden();

    $configured = b6cCarrier();
    $profile = (string) Str::uuid();
    DB::table('carrier_capability_profiles')->insert(['id' => $profile, 'carrier_id' => $configured->id, 'version' => 1, 'status' => 'ACTIVE', 'effective_from' => now()->subDay(),
        'created_by' => $f['customer']->id, 'created_at' => now(), 'updated_at' => now()]);
    DB::table('carrier_capability_modes')->insert(['id' => (string) Str::uuid(), 'profile_id' => $profile, 'capability' => 'QUOTATION', 'mode' => 'CONFIGURED', 'execution_mode' => 'CONFIGURED',
        'fallback_mode' => null, 'config' => '{}', 'created_at' => now(), 'updated_at' => now()]);
    Passport::actingAs(makeAuthTestUser($f['tenant'], ['quotes.carrier_requests.create'], 'BROKER_STAFF'));
    $this->postJson("/api/v1/quotes/{$f['quote']->id}/carrier-requests", ['carrier_id' => $configured->id], $h)->assertStatus(409)->assertJsonPath('code', 'CARRIER_NOT_MANUAL');

    $request = app(QuoteRequestService::class)->open($f['quote'], $f['carrier']->id, null, null);
    $f['quote']->forceFill(['status' => 'CANCELLED', 'lifecycle_state' => 'CANCELLED'])->save();
    $this->artisan('carrier-quote-requests:sweep')->assertSuccessful();
    expect($request->refresh()->status)->toBe('CANCELLED')->and(WorkCase::withoutGlobalScopes()->find($request->case_id)->status)->toBe('CANCELLED');
});
