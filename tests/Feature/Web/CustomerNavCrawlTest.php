<?php

declare(strict_types=1);

/**
 * UI audit 2026-09-27 (docs/UI_AUDIT_CUSTOMER_2026-09-27.md): the customer role on the web account (/account).
 * 1. every customer link of the account side navigation and every customer page (list and detail) renders
 *    in EN and FR: no 500, no 404 (dead link), no raw translation keys;
 * 2. each customer API feature has a web page and an entry button;
 * 3. the API calls those pages make answer for a signed-in customer (reads and the new self-service writes).
 */

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

const CUST_UUID = '4607772f-b8bf-4344-84f9-46e5f804b0c4';

/** GET each URL in EN and FR; returns the failures ("status url" or "raw-key key url"). */
function customerCrawl($test, array $urls): array
{
    $bad = [];
    foreach (['en', 'fr'] as $lang) {
        foreach ($urls as $url) {
            $u = $url.(str_contains($url, '?') ? '&' : '?').'lang='.$lang;
            $res = $test->get($u);
            if ($res->getStatusCode() !== 200) {
                $bad[] = $res->getStatusCode().' '.$u;

                continue;
            }
            $text = strip_tags(preg_replace('#<script\b[^>]*>.*?</script>#s', '', $res->getContent()));
            if (preg_match('/\b(account|account_[a-z]+|site|desk)\.[a-z_]+\.[a-z_.]+\b/', $text, $m)) {
                $bad[] = 'raw-key '.$m[0].' '.$u;
            }
        }
    }

    return $bad;
}

it('crawls every customer link of the account side navigation in EN and FR', function () {
    $html = $this->get('/account')->assertOk()->getContent();
    $side = Str::before(Str::after($html, 'class="acct-side"'), '</nav>');
    // Customer entries are the ones without a data-role gate (agent / officer links are hidden for customers).
    preg_match_all('#<a href="(/account[^"]*)"(?![^>]*data-role)#', $side, $m);
    $urls = array_values(array_unique($m[1]));

    expect($urls)->toBe(['/account', '/account/policies', '/account/quotes', '/account/claims', '/account/payments', '/account/documents', '/account/vehicles',
        '/account/requests', '/account/profile', '/account/kyc', '/account/privacy', '/account/notifications', '/account/support']);
    expect(customerCrawl($this, $urls))->toBe([]);
});

it('renders every customer page, list and detail, in EN and FR', function () {
    $id = CUST_UUID;
    $urls = [
        '/account/buy', "/account/quotes/$id", "/account/quotes/$id/confirmation", "/account/policies/$id", '/account/payments/new?policy='.$id,
        '/account/claims/new', '/account/claims/new?policy='.$id, "/account/claims/$id", '/account/requests?policy='.$id, '/account/support?claim_id='.$id,
        '/account/delete', '/login', '/compare', '/insurance',
    ];
    expect(customerCrawl($this, $urls))->toBe([]);
    $this->get('/account/nothing-here')->assertNotFound();
});

it('renders the launch customer pages and has no dead /account link in any customer page or its scripts (R2)', function () {
    $id = CUST_UUID;
    $pages = ['/account', '/account/welcome', '/account/onboarding', '/account/kyc', '/account/actions', '/account/needs', "/account/products/$id", '/account/search', '/account/activity',
        '/account/complaints', "/account/complaints/$id", '/account/messages', '/account/buy', '/account/quotes', "/account/quotes/$id/customize", "/account/quotes/$id/review",
        '/account/claims', '/account/claims/new', '/account/payments', '/account/payments/new', '/account/documents', '/account/requests', '/account/privacy', '/account/support',
        '/account/profile', '/account/policies', "/account/policies/$id", '/account/notifications', '/account/vehicles'];
    expect(customerCrawl($this, $pages))->toBe([]);

    // Every '/account/...' target named in the HTML or in the page/portal scripts (hrefs and JS string literals).
    $js = implode("\n", array_map(fn ($f) => file_get_contents(public_path("landing/portal/$f.js")), ['portal', 'policies', 'launch', 'buy', 'claims']));
    $targets = [];
    foreach ($pages as $p) {
        preg_match_all("#['\"](/account(?:/[a-z][a-z0-9-]*)*)(?:/|\\?|\\#|['\"])#", $this->get($p)->getContent()."\n".$js, $m);
        $targets = [...$targets, ...$m[1]];
    }
    $targets = array_values(array_diff(array_unique($targets), ['/account/delete']));
    expect(count($targets))->toBeGreaterThan(20);
    $dead = [];
    foreach ($targets as $t) {
        foreach ([$t, $t.'/'.$id] as $u) {
            if (($s = $this->get($u)->getStatusCode()) === 200) {
                continue 2;
            }
        }
        $dead[] = "$s $t";
    }
    expect($dead)->toBe([]);
});

it('gives every customer API feature a page and an entry button', function () {
    $id = CUST_UUID;
    // [page, entry marker, API path the page calls]
    $features = [
        ['/account/profile', '/account/kyc', '/mobile/account/customer-profile'],
        ['/account/profile', '/account/privacy', '/mobile/account/profile'],
        ['/account/kyc', 'data-page-body', '/mobile/kyc/submission'],
        ['/account/kyc', 'data-page-body', '/mobile/kyc/documents'],
        ['/account/privacy', 'data-consents', '/mobile/account/consents'],
        ['/account/privacy', 'data-prefs', '/mobile/account/notification-preferences'],
        ['/account/privacy', 'data-prefs', '/mobile/account/locale'],
        ['/account/privacy', 'data-dsr', '/mobile/account/privacy-requests'],
        ['/account/privacy', 'data-devices', '/mobile/account/devices'],
        ["/account/policies/$id", '/account/requests?policy=', '/mobile/policies/'],
        ["/account/policies/$id", "'/account/claims/new?policy='", 'verification_url'],
        ['/account/requests', 'data-new', '/service-requests'],
        ['/account/requests', 'data-renew', '/renewal-quote'],
        ['/account/requests', 'data-page-body', '/mobile/policy-service-requests/'],
        ['/account/quotes', 'data-counter', '/counteroffer/'],
        ['/account/quotes', '/account/buy', '/mobile/quotes'],
        ["/account/claims/$id", 'data-appeal', '/appeals'],
        ["/account/claims/$id", 'data-withdraw', '/withdraw'],
        ['/account/claims', '/account/claims/new', '/mobile/claims'],
        ['/account/support', 'data-sos', '/mobile/claims/emergency-assistance'],
        ['/account/support', 'data-new', '/mobile/support/cases'],
        ['/account/notifications', 'data-page-body', '/mobile/notifications/read-all'],
        ['/account/payments', 'data-page-body', 'OP.payments()'],
        ['/account/documents', 'data-cats', '/mobile/documents'],
    ];
    foreach ($features as [$page, $entry, $api]) {
        $html = $this->get($page)->assertOk()->getContent();
        expect(str_contains($html, $entry))->toBeTrue("$page lacks entry $entry")
            ->and(str_contains($html, $api))->toBeTrue("$page does not call $api");
    }
});

it('answers every API read the customer pages make, for a signed-in customer', function () {
    Http::preventStrayRequests();
    $f = makeMobileCustomerFixture('+237672990001');
    $policy = makeMobileTestPolicy($f['proposal'], $f['tenant'], $f['carrier']->id, $f['party']->id);
    Passport::actingAs($f['user']);
    $h = tenantHeaderFor($f['tenant']);

    foreach (['/auth/mobile/session', '/mobile/wallet', "/mobile/wallet/policies/{$policy->id}", "/mobile/policies/{$policy->id}/documents", '/mobile/payments', '/mobile/proposals',
        '/mobile/quotes', '/mobile/claims', '/mobile/claims/drafts', '/mobile/documents', '/mobile/notifications', '/mobile/support/cases', '/mobile/account/customer-profile',
        '/mobile/account/consents', '/mobile/account/notification-preferences', '/mobile/account/devices', '/mobile/account/privacy-requests', '/mobile/kyc/profile',
        '/mobile/policy-service-requests', '/mobile/assets'] as $path) {
        $status = $this->getJson('/api/v1'.$path, $h)->getStatusCode();
        expect($status)->toBe(200, "GET $path answered $status");
    }
});

it('runs the new self-service writes: consents, preferences, language, policy request and message, emergency assistance', function () {
    Http::preventStrayRequests();
    $f = makeMobileCustomerFixture('+237672990002');
    $policy = makeMobileTestPolicy($f['proposal'], $f['tenant'], $f['carrier']->id, $f['party']->id);
    Passport::actingAs($f['user']);
    $h = agentHeaders($f);

    $this->putJson('/api/v1/mobile/account/consents', ['consents' => [['purpose' => 'MARKETING', 'granted' => true], ['purpose' => 'ANALYTICS', 'granted' => false]]], $h)->assertOk()
        ->assertJsonPath('data.0.purpose', 'MARKETING')->assertJsonPath('data.0.granted', true);
    $this->putJson('/api/v1/mobile/account/notification-preferences', ['push' => true, 'sms' => false, 'email' => true, 'renewals' => true, 'claims' => true, 'payments' => false], agentHeaders($f))->assertOk()->assertJsonPath('data.sms', false);
    $this->putJson('/api/v1/mobile/account/locale', ['locale' => 'fr'], agentHeaders($f))->assertOk();
    expect($f['user']->refresh()->locale)->toBe('fr');

    $r = $this->postJson("/api/v1/policies/{$policy->id}/service-requests", ['type' => 'ADDRESS_CHANGE', 'reason' => 'I moved to Douala Bonapriso.'], agentHeaders($f))->assertCreated();
    $this->postJson('/api/v1/mobile/policy-service-requests/'.$r->json('data.id').'/messages', ['message' => 'New address attached.'], agentHeaders($f))->assertCreated();
    $this->getJson('/api/v1/mobile/policy-service-requests', $h)->assertOk()->assertJsonPath('data.0.type', 'ADDRESS_CHANGE');

    $this->postJson('/api/v1/mobile/claims/emergency-assistance', ['policy_id' => $policy->id, 'service' => 'TOWING', 'location' => 'Carrefour Ndokoti, Douala', 'callback_phone' => '+237672990002'], agentHeaders($f))
        ->assertCreated()->assertJsonPath('data.status', 'DISPATCHING');
});

it('keeps the customer self-service copy in sync between EN and FR', function () {
    $keys = function (array $a, string $p = '') use (&$keys): array {
        $o = [];
        foreach ($a as $k => $v) {
            $o = is_array($v) && ! array_is_list($v) ? [...$o, ...$keys($v, "$p.$k")] : [...$o, "$p.$k"];
        }

        return $o;
    };
    foreach (['account_customer', 'account'] as $file) {
        $en = $keys(require lang_path("en/$file.php"));
        $fr = $keys(require lang_path("fr/$file.php"));
        expect(array_values(array_diff($en, $fr)))->toBe([])->and(array_values(array_diff($fr, $en)))->toBe([]);
    }
    $this->get('/account/privacy?lang=fr')->assertOk()->assertSee('Confidentialité et sécurité')->assertSee('Vérification d’identité', false)->assertDontSee('Privacy &amp; security', false);
    $this->get('/account/requests?lang=en')->assertOk()->assertSee('Policy requests')->assertSee('"CANCELLATION_REVIEW":"Cancel my policy"', false);
});

it('wires the one-time-code and claim/delivery/support actions into the customer pages', function () {
    $id = CUST_UUID;
    $checks = [
        '/account/payments' => ['data-refund', "Opes.stepUp('PAYMENT_REFUND_REQUEST'", '/refunds'],
        "/account/claims/$id" => ["Opes.stepUp('CLAIM_SETTLEMENT_DECISION'", 'data-settle-accept', 'data-reschedule', '/inspection/reschedule', 'data-incident-edit', "'/incident'", 'data-party-add', "'/parties'"],
        "/account/policies/$id" => ['/mobile/deliveries/', 'data-delivery-address', 'data-delivery-confirm'],
        '/account/support' => ['/attachments', 'data-attach'],
    ];
    foreach ($checks as $page => $markers) {
        foreach (['en', 'fr'] as $lang) {
            $html = $this->get($page.'?lang='.$lang)->assertOk()->getContent();
            foreach ($markers as $m) {
                expect(str_contains($html, $m))->toBeTrue("$page lacks $m");
            }
        }
    }
    $js = file_get_contents(public_path('landing/portal/portal.js'));
    expect($js)->toContain('/mobile/security/step-up/request', '/mobile/security/step-up/verify', "'X-Step-Up-Grant'", '!o.stepUp');
    $this->get('/account/payments?lang=fr')->assertSee('"stepup":{"title":"Confirmer avec un code"', false);
});

/** Demo-mode step-up exactly as the web dialog does it: request, verify with the demo code, return the grant. */
function webStepUpGrant($test, array $f, string $purpose): string
{
    $ch = $test->postJson('/api/v1/mobile/security/step-up/request', ['purpose' => $purpose], agentHeaders($f))->assertOk();
    $v = $test->postJson('/api/v1/mobile/security/step-up/verify', ['challenge_id' => $ch->json('data.challenge_id'), 'purpose' => $purpose, 'code' => (string) config('demo.otp')], agentHeaders($f))->assertCreated();

    return (string) $v->json('data.grant_token');
}

it('requests a refund and decides a settlement behind the demo one-time code (123456)', function () {
    Http::fake(['*' => Http::response(['sid' => 'SM1'], 201)]);
    config(['demo.enabled' => true]);
    $f = makeMobileCustomerFixture('+237672990003');
    $f['user']->forceFill(['phone_e164' => \Database\Seeders\DemoMobileAccountSeeder::otpPhones()[0]])->save();
    $policy = makeMobileTestPolicy($f['proposal'], $f['tenant'], $f['carrier']->id, $f['party']->id);
    $payment = makeMobileTestPayment($f['proposal'], $f['tenant']);
    Passport::actingAs($f['user']);
    expect(config('demo.otp'))->toBe('123456');

    // Without a grant the write is refused (401 STEP_UP_REQUIRED, which the web client does not treat as a lost session).
    $this->postJson("/api/v1/mobile/payments/{$payment->id}/refunds", ['reason' => 'Double charge'], agentHeaders($f))->assertStatus(401)->assertJsonPath('code', 'STEP_UP_REQUIRED');
    $grant = webStepUpGrant($this, $f, 'PAYMENT_REFUND_REQUEST');
    $this->postJson("/api/v1/mobile/payments/{$payment->id}/refunds", ['reason' => 'Double charge', 'reason_code' => 'CUSTOMER_REQUEST'], agentHeaders($f) + ['X-Step-Up-Grant' => $grant])
        ->assertCreated()->assertJsonPath('data.status', 'REQUESTED');

    $claim = makeMobileTestClaim($f['tenant'], $policy, $f['party'], ['status' => 'APPROVED', 'approved_amount_minor' => 500000]);
    [$staff, $checker] = [\App\Models\User::factory()->create(), \App\Models\User::factory()->create()];
    $decisionId = (string) Str::uuid();
    DB::table('claim_decisions')->insert(['id' => $decisionId, 'claim_id' => $claim->id, 'decision' => 'APPROVE', 'approved_amount_minor' => 500000, 'currency' => 'XAF',
        'reason_code' => 'OK', 'rationale' => 'Covered.', 'status' => 'APPROVED', 'proposed_by' => $staff->id, 'approved_by' => $checker->id, 'approved_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
    // A decision alone (or a CALCULATED settlement) is internal: the customer sees no amount yet.
    $settlementId = (string) Str::uuid();
    DB::table('claim_settlements')->insert(['id' => $settlementId, 'tenant_id' => $f['tenant']->id, 'claim_id' => $claim->id, 'claim_decision_id' => $decisionId, 'payee_party_id' => $f['party']->id,
        'reference' => 'STL-'.strtoupper(Str::random(12)), 'status' => 'CALCULATED', 'currency' => 'XAF', 'covered_minor' => 500000, 'deductible_minor' => 25000, 'gross_minor' => 475000, 'amount_minor' => 475000,
        'breakdown' => json_encode(['lines' => [['code' => 'SETTLEMENT', 'label' => 'Settlement amount payable', 'operator' => '=', 'amount_minor' => 475000]]]), 'calculated_by' => $staff->id, 'created_at' => now(), 'updated_at' => now()]);
    $this->getJson("/api/v1/mobile/claims/{$claim->id}/settlement", tenantHeaderFor($f['tenant']))->assertOk()->assertJsonPath('data.status', 'PENDING')->assertJsonPath('data.net_minor', null);
    $grant = webStepUpGrant($this, $f, 'CLAIM_SETTLEMENT_DECISION');
    $this->postJson("/api/v1/mobile/claims/{$claim->id}/settlement/decision", ['decision' => 'ACCEPT'], agentHeaders($f) + ['X-Step-Up-Grant' => $grant])->assertStatus(422);
    // Released by a checker: now the customer sees the offer and can answer it.
    DB::table('claim_settlements')->where('id', $settlementId)->update(['status' => 'OFFERED', 'offered_by' => $checker->id, 'offered_at' => now()]);
    $this->getJson("/api/v1/mobile/claims/{$claim->id}/settlement", tenantHeaderFor($f['tenant']))->assertOk()->assertJsonPath('data.status', 'OFFERED')
        ->assertJsonPath('data.offered_minor', 500000)->assertJsonPath('data.net_minor', 475000)->assertJsonPath('data.can_decide', true);
    $grant = webStepUpGrant($this, $f, 'CLAIM_SETTLEMENT_DECISION');
    $this->postJson("/api/v1/mobile/claims/{$claim->id}/settlement/decision", ['decision' => 'ACCEPT'], agentHeaders($f) + ['X-Step-Up-Grant' => $grant])
        ->assertOk()->assertJsonPath('data.status', 'ACCEPTED')->assertJsonPath('data.can_decide', false);
    expect(DB::table('claim_settlements')->where('id', $settlementId)->value('status'))->toBe('ACCEPTED')
        ->and(DB::table('claim_settlement_events')->where('claim_settlement_id', $settlementId)->where('to_status', 'ACCEPTED')->exists())->toBeTrue();
    // A grant is single use.
    $this->postJson("/api/v1/mobile/claims/{$claim->id}/settlement/decision", ['decision' => 'REJECT'], agentHeaders($f) + ['X-Step-Up-Grant' => $grant])->assertStatus(401);
});

it('reschedules an inspection, edits the incident and adds a person on the customer claim', function () {
    Http::preventStrayRequests();
    $f = makeMobileCustomerFixture('+237672990004');
    $policy = makeMobileTestPolicy($f['proposal'], $f['tenant'], $f['carrier']->id, $f['party']->id);
    $claim = makeMobileTestClaim($f['tenant'], $policy, $f['party'], ['loss_details' => ['description' => 'Hit.', 'inspection' => ['appointment_at' => now()->addDay()->toIso8601String(), 'surveyor_name' => 'A. Ngono']]]);
    Passport::actingAs($f['user']);
    $base = "/api/v1/mobile/claims/{$claim->id}";

    $this->postJson("$base/inspection/reschedule", ['appointment_at' => now()->addDays(4)->setTime(9, 30)->toIso8601String()], agentHeaders($f))->assertOk();
    $this->getJson("$base/inspection", tenantHeaderFor($f['tenant']))->assertOk()->assertJsonPath('data.status', 'RESCHEDULED');
    $this->postJson("$base/inspection/reschedule", ['appointment_at' => now()->subDay()->toIso8601String()], agentHeaders($f))->assertStatus(422);

    $this->putJson("$base/incident", ['incident_type' => 'COLLISION', 'police_report_number' => 'PV-2026-118', 'injuries_reported' => false, 'vehicle_drivable' => false, 'towing_required' => true, 'declaration_confirmed' => true], agentHeaders($f))
        ->assertOk()->assertJsonPath('data.incident_type', 'COLLISION')->assertJsonPath('data.towing_required', true);

    $this->postJson("$base/parties", ['role' => 'THIRD_PARTY', 'display_name' => 'Paul Mbarga', 'contact_phone' => '+237699000111', 'consent_given' => true], agentHeaders($f))->assertCreated();
    expect($this->getJson("$base/parties", tenantHeaderFor($f['tenant']))->assertOk()->json('data.data.0.display_name'))->toBe('Paul Mbarga');
});

it('attaches a file to a support case and updates a certificate delivery address', function () {
    Http::preventStrayRequests();
    \Illuminate\Support\Facades\Storage::fake('local');
    $f = makeMobileCustomerFixture('+237672990005');
    $policy = makeMobileTestPolicy($f['proposal'], $f['tenant'], $f['carrier']->id, $f['party']->id);
    Passport::actingAs($f['user']);

    $case = $this->postJson('/api/v1/mobile/support/cases', ['category' => 'DOCUMENT', 'subject' => 'Certificate copy', 'description' => 'Please see the attached scan.'], agentHeaders($f))->assertSuccessful();
    $file = \Illuminate\Http\UploadedFile::fake()->create('scan.pdf', 120, 'application/pdf');
    $this->post('/api/v1/mobile/support/cases/'.$case->json('data.id').'/attachments', ['file' => $file], agentHeaders($f) + ['Accept' => 'application/json'])->assertSuccessful();

    $order = \App\Models\FulfilmentOrder::create(['tenant_id' => $f['tenant']->id, 'policy_id' => $policy->id, 'status' => 'CREATED', 'delivery_address' => ['city' => 'Douala']]);
    $this->getJson("/api/v1/mobile/wallet/policies/{$policy->id}", tenantHeaderFor($f['tenant']))->assertOk()->assertJsonPath('data.delivery.id', $order->id);
    $this->putJson("/api/v1/mobile/deliveries/{$order->id}/address", ['address' => ['recipient_name' => 'Awa N.', 'phone_e164' => '+237672990005', 'address_line' => 'Rue 1.234 Bonapriso', 'city' => 'Douala']], agentHeaders($f))
        ->assertOk()->assertJsonPath('data.delivery_address.city', 'Douala');
});
