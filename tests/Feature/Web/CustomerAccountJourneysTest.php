<?php

declare(strict_types=1);

/**
 * Customer web portal (/account) journeys, end to end, through exactly the API calls the account pages make
 * (same endpoints as the mobile app): buy -> pay -> issue -> download + verify, claim declare -> evidence -> track,
 * support ticket, privacy requests. Each step names the page that makes the call.
 */

use App\Application\Documents\Adapters\MalwareScanAdapter;
use App\Application\Documents\Adapters\ScanResult;
use App\Application\Policies\PolicyIssuanceService;
use App\Models\InsuranceLine;
use App\Models\PaymentIntentRecord;
use App\Models\PaymentProviderConnection;
use App\Models\PolicyIssuanceRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

beforeEach(function () {
    Storage::fake('local');
    Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 'ticket']]])]);
});

it('buys a policy from a rated quote, pays, gets it issued and downloads the documents with a verification QR', function () {
    $f = makeMobileCustomerFixture('+237672880001');
    // A fresh rated quote with one offer, as /account/buy leaves it after POST /quotes + /rate.
    $quote = makeMobileTestQuote($f['tenant'], $f['party'], ['status' => 'OFFERED']);
    $offer = makeMobileTestQuoteOffer($quote, $f['carrier']->id, $f['product']->id, $f['tariff']->id);
    // The line's approved disclosure questions (asked on the review step).
    $line = InsuranceLine::firstOrCreate(['code' => 'AUTO'], ['name' => ['en' => 'Motor', 'fr' => 'Auto'], 'status' => 'ACTIVE', 'risk_schema' => []]);
    DB::table('disclosure_schema_versions')->insert(['id' => (string) Str::uuid(), 'insurance_line_id' => $line->id, 'version' => 1, 'status' => 'APPROVED',
        'questions' => json_encode([['code' => 'prior_claims', 'label' => ['en' => 'Claims in the last 3 years?', 'fr' => 'Sinistres ?'], 'type' => 'boolean', 'required' => true, 'referral_values' => [true], 'referral_code' => 'PRIOR_CLAIMS']]),
        'schema_hash' => str_repeat('d', 64), 'effective_from' => '2026-01-01', 'created_by' => User::factory()->create()->id, 'created_at' => now(), 'updated_at' => now()]);
    Passport::actingAs($f['user']);
    $h = tenantHeaderFor($f['tenant']);

    // /account/quotes/{id}: the offers.
    $this->getJson("/api/v1/quotes/{$quote->id}", $h)->assertOk();
    $this->get("/account/quotes/{$quote->id}")->assertOk();

    // /account/quotes/{id}/review: accept the offer, open the proposal, answer + attest disclosures, submit.
    $this->postJson("/api/v1/quotes/{$quote->id}/offers/{$offer->id}/accept", [], agentHeaders($f))->assertSuccessful();
    $proposal = $this->postJson('/api/v1/proposals', ['quote_offer_id' => $offer->id, 'party_id' => $quote->party_id], agentHeaders($f))->assertSuccessful()->json('data');
    $pid = $proposal['id'];
    $this->getJson("/api/v1/proposals/$pid", $h)->assertOk();
    expect(collect($this->getJson('/api/v1/mobile/proposals', $h)->assertOk()->json('data'))->pluck('id'))->toContain($pid);
    $this->putJson("/api/v1/proposals/$pid/disclosures", ['answers' => ['prior_claims' => false]], agentHeaders($f))->assertOk();
    $this->postJson("/api/v1/proposals/$pid/disclosures/attest", [], agentHeaders($f))->assertSuccessful();
    $submitted = $this->postJson("/api/v1/proposals/$pid/submit", [], agentHeaders($f))->assertSuccessful();

    expect($submitted->json('data.status'))->toBe('PAYMENT_PENDING');

    // Pay with MTN MoMo (sandbox, HTTP faked): create + initiate exactly as the review page does. POST /payments answers
    // PENDING_CUSTOMER without prompting anyone, so the page must initiate while the provider holds no reference.
    config(['payments.providers.mtn_momo' => array_merge(config('payments.providers.mtn_momo'), ['base_url' => 'https://momo.test', 'subscription_key' => 'sk', 'api_user' => 'u', 'api_key' => 'k', 'callback_token' => 'cbt'])]);
    PaymentProviderConnection::create(['tenant_id' => $f['tenant']->id, 'provider' => 'mtn_momo', 'status' => 'ACTIVE', 'credential_reference' => 'vault://momo', 'created_by' => $f['user']->id]);
    Http::fake(['momo.test/collection/token/' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]), 'momo.test/collection/v1_0/requesttopay' => Http::response(null, 202),
        'momo.test/collection/v1_0/requesttopay/*' => Http::response(['status' => 'SUCCESSFUL', 'amount' => '100000', 'currency' => 'XAF', 'financialTransactionId' => 'MTN-TX-1']), 'exp.host/*' => Http::response(['data' => []])]);
    $pay = $this->postJson('/api/v1/payments', ['proposal_id' => $pid, 'provider' => 'mtn_momo', 'payer_phone_e164' => '+237672880001', 'idempotency_key' => 'web-'.Str::uuid()], agentHeaders($f))
        ->assertSuccessful()->json('data');
    expect($pay['status'])->toBe('PENDING_CUSTOMER')->and($pay['provider_reference'] ?? null)->toBeNull();
    $this->get("/account/quotes/{$quote->id}/review")->assertOk()->assertSee("(p.status === 'PENDING_CUSTOMER' && !p.provider_reference)", false);
    $this->postJson("/api/v1/payments/{$pay['id']}/initiate", [], agentHeaders($f))->assertStatus(202);
    $payment = PaymentIntentRecord::findOrFail($pay['id']);
    expect($payment->provider_reference)->not->toBeNull();
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/collection/v1_0/requesttopay') && $r['payer']['partyId'] === '237672880001');

    // MTN calls back; the status is re-queried and the payment reconciled. The carrier then approves issuance.
    $this->postJson('/api/v1/webhooks/payments/mtn-momo/callback?reference_id='.$payment->provider_reference.'&token=cbt', ['status' => 'SUCCESSFUL'])->assertOk();
    expect($this->getJson("/api/v1/payments/{$pay['id']}", $h)->assertOk()->json('data.status'))->toBe('SUCCEEDED');
    $request = PolicyIssuanceRequest::where('proposal_id', $pid)->firstOrFail();
    $approver = User::create(['full_name' => 'Carrier Desk', 'phone_e164' => '+237672880099', 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
    $policy = app(PolicyIssuanceService::class)->approve($request, ['policy_number' => 'POL-WEB-'.Str::random(5), 'carrier_reference' => 'CR-W1'], $approver);

    // /account/quotes/{id}/confirmation polls the purchase status, then shows the receipt and the documents.
    $st = $this->getJson("/api/v1/mobile/purchases/$pid/status", $h)->assertOk();
    expect($st->json('data.status'))->toBe('POLICY_ISSUED')->and($st->json('data.policy.id'))->toBe($policy->id);
    $receipt = $this->getJson("/api/v1/mobile/payments/{$pay['id']}/receipt", $h)->assertOk();
    expect($receipt->json('data.download_url'))->not->toBeEmpty();
    $docs = $this->getJson('/api/v1/mobile/documents?owner_type=policy&owner_id='.$policy->id.'&per_page=50', $h)->assertOk()->json('data');
    $docs = $docs['data'] ?? $docs;
    expect($docs)->not->toBeEmpty();
    $access = $this->postJson('/api/v1/mobile/documents/'.$docs[0]['id'].'/access', [], agentHeaders($f))->assertSuccessful()->json('data');
    $url = $access['url'] ?? $access['download_url'];
    expect($url)->not->toBeEmpty();

    // /account/policies/{id}: wallet detail, certificate with verification QR link, the PDF itself.
    $this->getJson("/api/v1/mobile/wallet/policies/{$policy->id}", $h)->assertOk()->assertJsonPath('data.policy_number', $policy->policy_number);
    $this->getJson("/api/v1/mobile/policies/{$policy->id}/documents", $h)->assertOk();
    $cert = $this->getJson("/api/v1/policies/{$policy->id}/certificate", $h)->assertOk();
    expect($cert->json('data.verification_url'))->toContain('verify?ref=');
    $this->get("/account/policies/{$policy->id}")->assertOk();

    $this->app['auth']->forgetGuards();
    $pdf = $this->get($url)->assertOk();
    expect($pdf->headers->get('Content-Type'))->toContain('application/pdf');
    expect($this->postJson('/api/v1/public/insurance/verify', ['reference' => $policy->refresh()->certificate_number])->assertOk()->json('data.result'))->toBe('valid');
});

it('declares a claim with a photo, then tracks it', function () {
    // Production scans uploads with ClamAV; here a clean scanner stands in for it.
    app()->bind(MalwareScanAdapter::class, fn () => new class implements MalwareScanAdapter
    {
        public function scan(string $absolutePath, string $declaredMimeType): ScanResult
        {
            return ScanResult::clean();
        }
    });
    $f = makeMobileCustomerFixture('+237672880002');
    $policy = makeMobileTestPolicy($f['proposal'], $f['tenant'], $f['carrier']->id, $f['party']->id, ['coverage_starts_at' => now()->subMonth()]);
    Passport::actingAs($f['user']);
    $h = tenantHeaderFor($f['tenant']);

    // /account/claims/new: policies from the wallet, a saved draft, submit, then the photo as evidence.
    expect(collect($this->getJson('/api/v1/mobile/wallet', $h)->assertOk()->json('data'))->pluck('id'))->toContain($policy->id);
    $at = now()->subHours(3)->toIso8601String();
    $draft = $this->postJson('/api/v1/mobile/claims/drafts', ['policy_id' => $policy->id, 'incident_type' => 'COLLISION', 'description' => 'Rear-ended at a red light.', 'incident_at' => $at,
        'incident_location' => 'Akwa, Douala', 'injuries_reported' => false, 'police_report_filed' => false], agentHeaders($f))->assertSuccessful()->json('data');
    $this->patchJson('/api/v1/mobile/claims/drafts/'.$draft['id'], ['policy_id' => $policy->id, 'incident_type' => 'COLLISION', 'description' => 'Rear-ended at a red light near the market.',
        'incident_at' => $at, 'incident_location' => 'Akwa, Douala', 'injuries_reported' => false, 'police_report_filed' => false], agentHeaders($f))->assertSuccessful();
    $claim = $this->postJson('/api/v1/mobile/claims/drafts/'.$draft['id'].'/submit', [], agentHeaders($f))->assertSuccessful()->json('data');
    expect($claim['id'])->not->toBeEmpty();

    $png = base64_encode(base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='));
    $doc = $this->postJson('/api/v1/mobile/documents', ['category' => 'CLAIM_EVIDENCE', 'mime_type' => 'image/png', 'file_base64' => $png], agentHeaders($f))->assertSuccessful()->json('data');
    $this->postJson("/api/v1/mobile/claims/{$claim['id']}/evidence", ['document_id' => $doc['id'], 'evidence_type' => 'DAMAGE_PHOTO', 'purpose' => 'CLAIM_EVIDENCE'], agentHeaders($f))->assertSuccessful();

    // /account/claims/{id}: detail, timeline, evidence, requirements, settlement, parties.
    $base = "/api/v1/mobile/claims/{$claim['id']}";
    $this->getJson($base, $h)->assertOk();
    foreach (['/timeline', '/evidence', '/evidence-requirements', '/parties'] as $p) {
        $this->getJson($base.$p, $h)->assertOk();
    }
    expect(count($this->getJson("$base/evidence", $h)->json('data.data') ?? $this->getJson("$base/evidence", $h)->json('data')))->toBeGreaterThanOrEqual(1);
    $list = $this->getJson('/api/v1/mobile/claims', $h)->assertOk();
    expect(collect($list->json('data.data') ?? $list->json('data'))->pluck('id'))->toContain($claim['id']);
    $this->get('/account/claims/'.$claim['id'])->assertOk();
});

it('opens a support ticket, replies and escalates it', function () {
    $f = makeMobileCustomerFixture('+237672880003');
    Passport::actingAs($f['user']);
    $h = tenantHeaderFor($f['tenant']);

    $case = $this->postJson('/api/v1/mobile/support/cases', ['category' => 'PAYMENT', 'subject' => 'Charged twice', 'description' => 'My MoMo was debited twice for one premium.'], agentHeaders($f))->assertSuccessful()->json('data');
    $this->postJson("/api/v1/mobile/support/cases/{$case['id']}/messages", ['body' => 'Transaction ids attached.'], agentHeaders($f))->assertSuccessful();
    $this->postJson("/api/v1/mobile/support/cases/{$case['id']}/escalate", ['reason' => 'No answer yet.'], agentHeaders($f))->assertSuccessful();
    $this->getJson("/api/v1/mobile/support/cases/{$case['id']}", $h)->assertOk();
    expect(collect($this->getJson('/api/v1/mobile/support/cases', $h)->assertOk()->json('data'))->pluck('id'))->toContain($case['id']);
    $this->get('/account/support?lang=fr')->assertOk()->assertSee('/escalate', false);
});

it('files privacy requests (export, erasure) and lists them', function () {
    $f = makeMobileCustomerFixture('+237672880004');
    Passport::actingAs($f['user']);
    $h = tenantHeaderFor($f['tenant']);

    foreach (['EXPORT', 'DELETE'] as $type) {
        $this->postJson('/api/v1/mobile/account/privacy-requests', ['type' => $type], agentHeaders($f))->assertSuccessful();
    }
    expect(collect($this->getJson('/api/v1/mobile/account/privacy-requests', $h)->assertOk()->json('data'))->pluck('type')->all())->toContain('EXPORT', 'DELETE');
    $this->get('/account/privacy?lang=en')->assertOk()->assertSee('/mobile/account/privacy-requests', false);
});

it('sends every policy servicing request type the requests page offers and asks for renewal quotes', function () {
    Http::preventStrayRequests();
    $f = makeMobileCustomerFixture('+237672880005');
    $policy = makeMobileTestPolicy($f['proposal'], $f['tenant'], $f['carrier']->id, $f['party']->id, ['coverage_starts_at' => now()->subYear()->addDays(20), 'coverage_ends_at' => now()->addDays(20)]);
    Passport::actingAs($f['user']);

    $f['quote']->update(['risk_facts' => ['registration_number' => 'LT-123-AB', 'usage' => 'PRIVATE', 'fiscal_power' => 7]]);
    makeMobileTestTenantCustomer($f['tenant'], $f['party']);
    InsuranceLine::firstOrCreate(['code' => 'AUTO'], ['name' => ['en' => 'Motor', 'fr' => 'Auto'], 'status' => 'ACTIVE', 'risk_schema' => []]);

    // /account/requests: one request per type in the form (endorsement, cancellation, certificate re-issue, ...).
    foreach (array_keys(__('account_customer.js.req.types', [], 'en')) as $type) {
        $this->postJson("/api/v1/policies/{$policy->id}/service-requests", ['type' => $type, 'reason' => 'Web portal request for '.$type], agentHeaders($f))->assertSuccessful();
    }
    $this->get('/account/requests?lang=fr')->assertOk()->assertSee('/renewal-quote', false);
    $list = $this->getJson('/api/v1/mobile/policy-service-requests', tenantHeaderFor($f['tenant']))->assertOk();
    expect(count($list->json('data.data') ?? $list->json('data')))->toBe(count(__('account_customer.js.req.types', [], 'en')));

    // Renewal: the page posts renewal-quote and opens /account/quotes/{quote.id}.
    $r = $this->postJson("/api/v1/policies/{$policy->id}/renewal-quote", [], agentHeaders($f));
    expect($r->status())->toBeIn([200, 201, 202])->and($r->json('data.quote.id'))->not->toBeEmpty();
});
