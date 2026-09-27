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
