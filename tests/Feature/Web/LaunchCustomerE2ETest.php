<?php

declare(strict_types=1);

/**
 * Launch 2026-10-02 (R2): the customer web portal (/account) end to end, as a brand-new visitor uses it. Every step replays
 * exactly the calls the page's JavaScript makes (same path, method, payload and headers: bearer token from sign-up,
 * X-Tenant-Id from the session workspace) and reads the fields the page renders:
 * sign-up (/signup, auth.js) → welcome / onboarding → KYC (/account/kyc) → buy (/account/buy → quotes/{id} → review → pay →
 * confirmation) → documents download → claim with evidence while the malware scanner is down (held, attached later) →
 * complaint → support ticket with attachment → privacy request → renewal quote.
 */

use App\Application\Documents\Adapters\MalwareScanAdapter;
use App\Application\Documents\Adapters\ScanResult;
use App\Application\Policies\PolicyIssuanceService;
use App\Models\Carrier;
use App\Models\InsuranceLine;
use App\Models\InsuranceProduct;
use App\Models\Party;
use App\Models\PaymentIntentRecord;
use App\Models\PaymentProviderConnection;
use App\Models\PolicyIssuanceRequest;
use App\Models\Tenant;
use App\Models\User;
use Database\Seeders\MobileOAuthClientSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';
require_once __DIR__.'/../Wave12/Concerns/mobile_auth_helpers.php';
require_once __DIR__.'/../Concerns/wave_auth_helpers.php';

const R2_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

/** A different small photo (the portal refuses the same file twice for one customer: 409 document_duplicate). */
function r2Photo(int $shade): string
{
    $im = imagecreatetruecolor(4, 4);
    imagefill($im, 0, 0, imagecolorallocate($im, $shade, 90, 160));
    ob_start();
    imagepng($im);

    return base64_encode((string) ob_get_clean());
}

/** Binds the malware scanner: clean, or down (every scan fails → the file is held as SCAN_UNAVAILABLE). */
function r2Scanner(bool $up): void
{
    app()->bind(MalwareScanAdapter::class, fn () => new class($up) implements MalwareScanAdapter
    {
        public function __construct(private bool $up) {}

        public function scan(string $absolutePath, string $declaredMimeType): ScanResult
        {
            return $this->up ? ScanResult::clean() : ScanResult::failed('clamd: connection refused');
        }
    });
}

/** The account pages' API client: bearer + X-Tenant-Id + Accept-Language, and an Idempotency-Key on every write (portal.js api()). */
function r2Api($test, string $method, string $path, array $body = [], ?string $lang = 'en')
{
    $h = ['Authorization' => 'Bearer '.$test->r2token, 'Accept' => 'application/json', 'Accept-Language' => $lang, 'X-Request-ID' => (string) Str::uuid()];
    if ($test->r2tenant) {
        $h['X-Tenant-Id'] = $test->r2tenant;
    }
    if ($method !== 'GET') {
        $h['Idempotency-Key'] = (string) Str::uuid();
    }

    return $test->json($method, '/api/v1'.$path, $body, $h);
}

/** A published motor product with an approved tariff, priced by the shared engine (as the owner's catalogue is at launch). */
function r2MotorCatalogue(Tenant $tenant): InsuranceProduct
{
    InsuranceLine::firstOrCreate(['code' => 'MOTOR'], ['name' => ['en' => 'Motor', 'fr' => 'Automobile'], 'status' => 'ACTIVE', 'risk_schema' => ['required' => []]]);
    InsuranceLine::where('code', 'MOTOR')->update(['status' => 'ACTIVE']);
    $maker = makeAuthTestUser($tenant, ['tariff.manage', 'quotes.rate'], 'R2_MAKER');
    $checker = makeAuthTestUser($tenant, ['tariff.manage', 'tariff.approve', 'tariff.publish'], 'R2_CHECKER');
    $carrier = Carrier::create(['party_id' => Party::create(['type' => 'ORGANIZATION', 'display_name' => 'R2 Assurances', 'status' => 'ACTIVE'])->id, 'cima_code' => 'CIMA-'.Str::random(6), 'status' => 'ACTIVE']);
    $product = InsuranceProduct::create(['carrier_id' => $carrier->id, 'line_code' => 'MOTOR', 'code' => 'R2-MOTOR', 'name' => 'R2 Motor', 'version' => 1, 'effective_from' => now()->subMonth()->toDateString(), 'status' => 'ACTIVE']);
    $as = function (User $u, string $uri, array $body) use ($tenant) {
        Passport::actingAs($u);

        return test()->postJson('/api/v1/'.$uri, $body, tenantHeaderFor($tenant));
    };
    $id = $as($maker, 'tariffs', ['insurance_product_id' => $product->id, 'effective_from' => now()->subMonth()->toDateString(), 'input_schema' => ['usage' => 'string'], 'regulatory_reference' => 'DEMO-UNVERIFIED',
        'rules' => ['required_facts' => ['usage'], 'base' => ['method' => 'RATE_X_SUM_INSURED', 'fact' => 'vehicle_value', 'rate_ppm' => 20000], 'rounding' => ['unit_minor' => 100, 'mode' => 'HALF_UP'],
            'branch_allocation' => [['branch_code' => 'RC_AUTO', 'basis_points' => 10000]]]])->assertCreated()->json('data.id');
    $as($maker, "tariffs/{$id}/submit", ['notes' => 'Ready for technical review.'])->assertOk();
    $as($checker, "tariffs/{$id}/approve", ['reason' => 'Actuarial review completed and signed off.'])->assertOk();
    $as($checker, "tariffs/{$id}/schedule", ['notes' => 'Publish on the effective date.'])->assertOk();
    // The line's approved disclosure questions (asked on the review step).
    DB::table('disclosure_schema_versions')->insert(['id' => (string) Str::uuid(), 'insurance_line_id' => InsuranceLine::where('code', 'MOTOR')->value('id'), 'version' => 1, 'status' => 'APPROVED',
        'questions' => json_encode([['code' => 'prior_claims', 'label' => ['en' => 'Claims in the last 3 years?', 'fr' => 'Sinistres ?'], 'type' => 'boolean', 'required' => true, 'referral_values' => [true], 'referral_code' => 'PRIOR_CLAIMS']]),
        'schema_hash' => str_repeat('e', 64), 'effective_from' => '2026-01-01', 'created_by' => $checker->id, 'created_at' => now(), 'updated_at' => now()]);
    app('auth')->forgetGuards();

    return $product;
}

beforeEach(function () {
    Storage::fake('local');
    $this->seed(MobileOAuthClientSeeder::class);
    App\Models\PlatformSetting::create(['require_contact_verification' => true]);
    config(['payments.providers.mtn_momo' => array_merge(config('payments.providers.mtn_momo'), ['base_url' => 'https://momo.test', 'subscription_key' => 'sk', 'api_user' => 'u', 'api_key' => 'k', 'callback_token' => 'cbt'])]);
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201), 'exp.host/*' => Http::response(['data' => []]),
        'momo.test/collection/token/' => Http::response(['access_token' => 'tok', 'expires_in' => 3600]), 'momo.test/collection/v1_0/requesttopay' => Http::response(null, 202),
        'momo.test/collection/v1_0/requesttopay/*' => fn () => Http::response(['status' => 'SUCCESSFUL', 'amount' => (string) PaymentIntentRecord::latest('created_at')->value('amount_minor'), 'currency' => 'XAF', 'financialTransactionId' => 'MTN-TX-R2'])]);
    r2Scanner(true);
    $this->r2token = null;
    $this->r2tenant = null;
});

it('takes a new customer from sign-up to a renewal quote through the web portal calls', function () {
    $phone = '+237677120001';

    // ---- /signup (auth.js): POST /public/accounts, then the one-time code, then /account/welcome.
    $this->get('/signup')->assertOk();
    $device = ['fingerprint' => 'web-'.Str::uuid(), 'name' => 'Web browser', 'platform' => 'web'];
    $reg = $this->postJson('/api/v1/public/accounts', ['full_name' => 'Awa Nkemdirim', 'email' => 'awa.r2@example.test', 'phone_e164' => $phone, 'password' => 'a-strong-password-12',
        'password_confirmation' => 'a-strong-password-12', 'locale' => 'en', 'terms_version' => 'web', 'device' => $device])->assertCreated()->json('data');
    if (empty($reg['access_token'])) {
        expect($reg['verification_required'] ?? false)->toBeTrue('sign-up answered neither a token nor a code challenge');
        $reg = $this->postJson('/api/v1/auth/mobile/otp/verify', ['challenge_id' => $reg['challenge_id'], 'code' => extractMobileOtpCode(), 'device' => $device])->assertCreated()->json('data');
    }
    expect($reg['access_token'])->not->toBeEmpty();
    $this->r2token = $reg['access_token'];
    $this->app['auth']->forgetGuards();

    // portal.js loadSession(): the workspace gives tenant + customer ids.
    $ses = r2Api($this, 'GET', '/auth/mobile/session')->assertOk()->json('data');
    $ws = collect($ses['workspaces'])->firstWhere('customer_id', '!=', null);
    expect($ws)->not->toBeNull()->and($ws['role_code'])->toBe('CUSTOMER')->and($ses['user']['full_name'])->toBe('Awa Nkemdirim');
    $this->r2tenant = $ws['tenant_id'];
    $user = User::where('phone_e164', $phone)->firstOrFail();
    $tenant = Tenant::findOrFail($ws['tenant_id']);

    // ---- /account/welcome and /account/onboarding (launch.js onboarding()): session, customer profile, KYC profile.
    foreach (['/account/welcome', '/account/onboarding', '/account', '/account/actions'] as $page) {
        $this->get($page)->assertOk();
    }
    $prof = r2Api($this, 'GET', '/mobile/account/customer-profile')->assertOk()->json('data');
    expect($prof)->toHaveKeys(['full_name', 'date_of_birth', 'address_line1', 'city']);
    $kyc = r2Api($this, 'GET', '/mobile/kyc/profile')->assertOk()->json('data');
    expect($kyc)->toHaveKeys(['identifiers', 'submission'])->and($kyc['submission'])->toBeNull();
    // Header badge + action centre: GET /mobile/notifications?unread=1&per_page=1 → meta.unread_count.
    expect(r2Api($this, 'GET', '/mobile/notifications?unread=1&per_page=1')->assertOk()->json('meta'))->toHaveKey('unread_count');

    // ---- /account/kyc: add an identifier, upload + attach a document, submit for review.
    r2Api($this, 'PATCH', '/mobile/kyc/profile', ['identifier_type' => 'NATIONAL_ID', 'identifier_value' => '112233445', 'identifier_country' => 'CM'])->assertOk();
    $doc = r2Api($this, 'POST', '/mobile/documents', ['category' => 'KYC_IDENTITY', 'mime_type' => 'image/png', 'file_base64' => R2_PNG])->assertSuccessful()->json('data');
    expect($doc['id'])->not->toBeEmpty();
    r2Api($this, 'POST', '/mobile/kyc/documents', ['document_id' => $doc['id'], 'purpose' => 'ID_FRONT'])->assertSuccessful();
    r2Api($this, 'POST', '/mobile/kyc/submission', ['notes' => null])->assertSuccessful();
    $kyc = r2Api($this, 'GET', '/mobile/kyc/profile')->assertOk()->json('data');
    expect($kyc['identifiers'][0])->toHaveKeys(['type', 'masked_value', 'country_code'])
        ->and(strtoupper($kyc['submission']['status']))->toBeIn(['SUBMITTED', 'UNDER_REVIEW', 'IN_REVIEW'])
        ->and(collect($kyc['submission']['documents'])->pluck('purpose'))->toContain('ID_FRONT');
    $this->get('/account/kyc')->assertOk();

    // ---- /account/buy: lines, product by code, risk schema, POST /quotes, POST /quotes/{id}/rate.
    $product = r2MotorCatalogue($tenant);
    expect(collect(r2Api($this, 'GET', '/catalogue/lines')->assertOk()->json('data'))->pluck('code'))->toContain('MOTOR');
    $cat = r2Api($this, 'GET', '/catalogue/products?code=R2-MOTOR')->assertOk()->json('data.data');
    expect($cat)->toHaveCount(1)->and($cat[0]['code'])->toBe('R2-MOTOR')->and($cat[0])->not->toHaveKey('tariffs');
    $schema = r2Api($this, 'GET', '/mobile/catalogue/lines/MOTOR/risk-schema')->assertOk()->json('data');
    expect($schema)->toHaveKey('fields');
    $this->get('/account/buy?product=R2-MOTOR')->assertOk();
    $this->get('/account/products/'.$product->id)->assertOk();

    $quote = r2Api($this, 'POST', '/quotes', ['customer_id' => $ws['customer_id'], 'line_code' => 'MOTOR', 'channel' => 'B2C', 'risk_facts' => ['usage' => 'PRIVATE', 'vehicle_value' => 5000000]])
        ->assertStatus(202)->json('data');
    r2Api($this, 'POST', "/quotes/{$quote['id']}/rate", [])->assertOk();

    // /account/quotes/{id}: the offers, and the quote PDF (generated on demand for a CALCULATED quote).
    $q = r2Api($this, 'GET', "/quotes/{$quote['id']}")->assertOk()->json('data');
    $offers = $q['offers'] ?? ($q['quote']['offers'] ?? []);
    expect($offers)->not->toBeEmpty();
    $offer = $offers[0];
    expect($offer)->toHaveKeys(['id', 'total_minor']);
    $this->get("/account/quotes/{$quote['id']}")->assertOk()->assertSee("'/generate'", false);
    r2Api($this, 'GET', "/quotes/{$quote['id']}/document")->assertStatus(422);
    r2Api($this, 'POST', "/quotes/{$quote['id']}/generate", [])->assertOk();
    expect(r2Api($this, 'GET', "/quotes/{$quote['id']}/document")->assertOk()->headers->get('Content-Type'))->toContain('application/pdf');
    expect(collect(r2Api($this, 'GET', '/mobile/quotes')->assertOk()->json('data.data'))->pluck('id'))->toContain($quote['id']);

    // /account/quotes/{id}/review: accept, open the proposal, attest, submit → PAYMENT_PENDING.
    r2Api($this, 'POST', "/quotes/{$quote['id']}/offers/{$offer['id']}/accept", [])->assertSuccessful();
    $pid = r2Api($this, 'POST', '/proposals', ['quote_offer_id' => $offer['id'], 'party_id' => $user->party_id])->assertSuccessful()->json('data.id');
    $proposal = r2Api($this, 'GET', "/proposals/$pid")->assertOk()->json('data');
    expect($proposal)->toHaveKey('required_documents');
    r2Api($this, 'PUT', "/proposals/$pid/disclosures", ['answers' => ['prior_claims' => false]])->assertOk();
    r2Api($this, 'POST', "/proposals/$pid/disclosures/attest", [])->assertSuccessful();
    $submitted = r2Api($this, 'POST', "/proposals/$pid/submit", [])->assertSuccessful()->json('data');
    expect($submitted['status'])->toBe('PAYMENT_PENDING');
    // The review/pay step records the customer's own contract-terms acceptance before any payment (terms gate).
    r2Api($this, 'POST', "/proposals/$pid/terms", ['accepted' => true])->assertSuccessful();
    $mine = collect(r2Api($this, 'GET', '/mobile/proposals')->assertOk()->json('data'))->firstWhere('id', $pid);
    expect($mine['total_minor'])->toBeGreaterThan(0)->and($mine)->toHaveKeys(['product_name', 'line_code']);
    $this->get("/account/quotes/{$quote['id']}/review")->assertOk();

    // Pay: POST /payments (MTN MoMo, provider sandbox faked) + /initiate, MTN callback, status SUCCEEDED.
    PaymentProviderConnection::create(['tenant_id' => $tenant->id, 'provider' => 'mtn_momo', 'environment' => 'PRODUCTION', 'status' => 'ACTIVE', 'credential_reference' => 'vault://momo', 'created_by' => $user->id]);
    $pay = r2Api($this, 'POST', '/payments', ['proposal_id' => $pid, 'provider' => 'mtn_momo', 'payer_phone_e164' => $phone, 'idempotency_key' => 'web-'.Str::uuid()])->assertSuccessful()->json('data');
    if ($pay['status'] === 'CREATED' || ($pay['status'] === 'PENDING_CUSTOMER' && empty($pay['provider_reference']))) {
        r2Api($this, 'POST', "/payments/{$pay['id']}/initiate", [])->assertStatus(202);
    }
    $ref = PaymentIntentRecord::findOrFail($pay['id'])->provider_reference;
    $this->postJson('/api/v1/webhooks/payments/mtn-momo/callback?reference_id='.$ref.'&token=cbt', ['status' => 'SUCCESSFUL'])->assertOk();
    expect(r2Api($this, 'GET', "/payments/{$pay['id']}")->assertOk()->json('data.status'))->toBe('SUCCEEDED');

    // The insurer approves issuance; /account/quotes/{id}/confirmation polls the purchase status, receipt and documents.
    $approver = User::create(['full_name' => 'Carrier Desk', 'phone_e164' => '+237677120099', 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
    $policy = app(PolicyIssuanceService::class)->approve(PolicyIssuanceRequest::where('proposal_id', $pid)->firstOrFail(), ['policy_number' => 'POL-R2-'.Str::random(5), 'carrier_reference' => 'CR-R2'], $approver);
    $st = r2Api($this, 'GET', "/mobile/purchases/$pid/status")->assertOk()->json('data');
    expect($st['status'])->toBe('POLICY_ISSUED')->and($st['policy']['id'])->toBe($policy->id);
    expect(r2Api($this, 'GET', "/mobile/payments/{$pay['id']}/receipt")->assertOk()->json('data.download_url'))->not->toBeEmpty();
    $this->get("/account/quotes/{$quote['id']}/confirmation")->assertOk();

    // ---- /account/policies/{id} and /account/documents: wallet detail, documents, download the PDF.
    $w = r2Api($this, 'GET', "/mobile/wallet/policies/{$policy->id}")->assertOk()->json('data');
    expect($w['policy_number'])->toBe($policy->policy_number)->and($w)->toHaveKeys(['carrier_name', 'product_name', 'days_to_expiry', 'coverage_ends_at']);
    r2Api($this, 'GET', "/mobile/policies/{$policy->id}/documents")->assertOk();
    $docs = r2Api($this, 'GET', '/mobile/documents?per_page=100')->assertOk()->json('data');
    expect($docs['per_page'])->toBe(100);
    $pdoc = collect($docs['data'])->firstWhere('policy_id', $policy->id) ?? collect($docs['data'])->firstWhere('owner_id', $policy->id);
    expect($pdoc)->not->toBeNull('no policy document in /mobile/documents');
    $access = r2Api($this, 'POST', "/mobile/documents/{$pdoc['id']}/access", [])->assertSuccessful()->json('data');
    $url = $access['url'] ?? $access['download_url'];
    $this->get("/account/policies/{$policy->id}")->assertOk();
    $this->get('/account/documents')->assertOk();
    $this->app['auth']->forgetGuards();
    expect($this->get($url)->assertOk()->headers->get('Content-Type'))->toContain('application/pdf');

    // ---- /account/claims/new with the malware scanner DOWN: claim created, evidence held and queued (202), shown as pending.
    $policy->forceFill(['coverage_starts_at' => now()->subDays(10)])->save();
    r2Scanner(false);
    $claim = r2Api($this, 'POST', '/mobile/claims', ['policy_id' => $policy->id, 'incident_at' => now()->subHours(5)->toIso8601String(), 'incident_location' => 'Akwa, Douala',
        'description' => 'Rear-ended at a red light near the market.', 'incident_type' => 'COLLISION', 'injuries_reported' => false, 'police_report_filed' => false])->assertSuccessful()->json('data');
    $ev = r2Api($this, 'POST', '/mobile/documents', ['category' => 'CLAIM_EVIDENCE', 'mime_type' => 'image/png', 'file_base64' => r2Photo(40)])->assertSuccessful()->json('data');
    expect($ev['security_check_pending'])->toBeTrue()->and($ev['usable'])->toBeFalse();
    $att = r2Api($this, 'POST', "/mobile/claims/{$claim['id']}/evidence", ['document_id' => $ev['id'], 'evidence_type' => 'DAMAGE_PHOTO', 'purpose' => 'CLAIM_EVIDENCE'])->assertStatus(202)->json('data');
    expect($att['security_check_pending'])->toBeTrue()->and($att['status'])->toBe('PENDING_SECURITY_CHECK');
    $this->get("/account/claims/{$claim['id']}?created=1&pending=1")->assertOk()->assertSee("q.get('pending')", false);
    $cd = r2Api($this, 'GET', "/mobile/claims/{$claim['id']}")->assertOk()->json('data');
    expect($cd['policy']['carrier'])->toHaveKey('legal_name');
    expect(collect(r2Api($this, 'GET', '/mobile/claims?per_page=100')->assertOk()->json('data.data'))->pluck('id'))->toContain($claim['id']);
    r2Scanner(true);

    // ---- /account/complaints: file, list, open.
    $cpl = r2Api($this, 'POST', '/mobile/complaints', ['description' => 'My certificate was delivered two weeks late.', 'policy_id' => $policy->id])->assertCreated()->json('data');
    expect($cpl)->toHaveKeys(['complaint_number', 'open']);
    expect(collect(r2Api($this, 'GET', '/mobile/complaints')->assertOk()->json('data'))->pluck('id'))->toContain($cpl['id']);
    expect(r2Api($this, 'GET', "/mobile/complaints/{$cpl['id']}")->assertOk()->json('data'))->toHaveKeys(['timeline', 'correspondence']);
    $this->get("/account/complaints/{$cpl['id']}")->assertOk();

    // ---- /account/support: open a ticket, reply, attach a file (queued for the malware check).
    $case = r2Api($this, 'POST', '/mobile/support/cases', ['category' => 'DOCUMENT', 'subject' => 'Certificate copy', 'description' => 'Please send a copy of my certificate.'])->assertSuccessful()->json('data');
    r2Api($this, 'POST', "/mobile/support/cases/{$case['id']}/messages", ['body' => 'Scan attached.'])->assertSuccessful();
    $this->post("/api/v1/mobile/support/cases/{$case['id']}/attachments", ['file' => UploadedFile::fake()->create('scan.pdf', 50, 'application/pdf')],
        ['Authorization' => 'Bearer '.$this->r2token, 'X-Tenant-Id' => $this->r2tenant, 'Accept' => 'application/json', 'Idempotency-Key' => (string) Str::uuid()])->assertSuccessful();
    $att = DB::table('documents')->where('category', 'SUPPORT_ATTACHMENT')->latest('created_at')->first();
    expect($att->scan_status)->not->toBe('PENDING')->and(DB::table('document_scan_queue')->where('document_id', $att->id)->exists())->toBeTrue();
    expect(collect(r2Api($this, 'GET', '/mobile/support/cases')->assertOk()->json('data'))->pluck('reference'))->toContain($case['reference']);

    // ---- /account/privacy: consents and a data export request.
    r2Api($this, 'PUT', '/mobile/account/consents', ['consents' => [['purpose' => 'MARKETING', 'granted' => false]]])->assertOk();
    r2Api($this, 'POST', '/mobile/account/privacy-requests', ['type' => 'EXPORT'])->assertSuccessful();
    expect(collect(r2Api($this, 'GET', '/mobile/account/privacy-requests')->assertOk()->json('data'))->pluck('type'))->toContain('EXPORT');

    // ---- /account/activity: own trail and sign-in history.
    expect(r2Api($this, 'GET', '/mobile/account/activity')->assertOk()->json('data'))->not->toBeEmpty();
    $logins = r2Api($this, 'GET', '/me/security/login-activity')->assertOk()->json('data');
    if ($logins) {
        expect($logins[0])->toHaveKeys(['occurred_at', 'device_name', 'masked_ip']);
    }

    // ---- /account/requests#renew: renewal quote for the policy, opened on /account/quotes/{id}.
    $policy->forceFill(['coverage_ends_at' => now()->addDays(20)])->save();
    $ren = r2Api($this, 'POST', "/policies/{$policy->id}/renewal-quote", []);
    expect($ren->status())->toBeIn([200, 201, 202])->and($ren->json('data.quote.id'))->not->toBeEmpty();
    $this->get('/account/quotes/'.$ren->json('data.quote.id'))->assertOk();
    $this->get('/account/requests?policy='.$policy->id)->assertOk();

    // Optional fixture for the browser smoke (R2_DUMP=<dir>): the real API answers for this account + every page's HTML,
    // replayed in jsdom by a fetch stub so each page script runs against the live response shapes.
    if ($dir = getenv('R2_DUMP')) {
        $ids = ['policy' => $policy->id, 'claim' => $claim['id'], 'quote' => $quote['id'], 'proposal' => $pid, 'payment' => $pay['id'], 'complaint' => $cpl['id'], 'case' => $case['id'], 'product' => $product->id];
        $gets = ['/auth/mobile/session', '/mobile/wallet', "/mobile/wallet/policies/$policy->id", "/mobile/policies/$policy->id/documents", '/mobile/payments', '/mobile/proposals', '/mobile/quotes',
            '/mobile/claims', '/mobile/claims/drafts', "/mobile/claims/{$claim['id']}", "/mobile/claims/{$claim['id']}/timeline", "/mobile/claims/{$claim['id']}/evidence",
            "/mobile/claims/{$claim['id']}/evidence-requirements", "/mobile/claims/{$claim['id']}/settlement", "/mobile/claims/{$claim['id']}/parties", '/mobile/documents', '/mobile/notifications',
            '/mobile/support/cases', "/mobile/support/cases/{$case['id']}", '/mobile/complaints', "/mobile/complaints/{$cpl['id']}", '/mobile/account/customer-profile', '/mobile/account/profile',
            '/mobile/account/consents', '/mobile/account/notification-preferences', '/mobile/account/devices', '/mobile/account/privacy-requests', '/mobile/kyc/profile', '/mobile/account/activity',
            '/me/security/login-activity', '/mobile/policy-service-requests', '/mobile/assets', '/catalogue/lines', '/catalogue/products', "/catalogue/products/$product->id",
            '/mobile/catalogue/lines/MOTOR/risk-schema', "/quotes/{$quote['id']}", "/proposals/$pid", "/payments/{$pay['id']}", "/mobile/purchases/$pid/status",
            "/mobile/payments/{$pay['id']}/receipt", '/search?q=POL', '/master-data/claims'];
        $api = [];
        foreach ($gets as $g) {
            $r = r2Api($this, 'GET', $g);
            $api[preg_replace('/\?.*$/', '', $g)] = ['status' => $r->getStatusCode(), 'body' => $r->json()];
        }
        $pages = ['', 'welcome', 'onboarding', 'kyc', 'actions', 'needs', "products/$product->id", 'search?q=POL', 'activity', 'complaints', "complaints/{$cpl['id']}", 'messages',
            'buy', 'quotes', "quotes/{$quote['id']}", "quotes/{$quote['id']}/customize?offer=".$offer['id'], "quotes/{$quote['id']}/review?offer=".$offer['id'],
            "quotes/{$quote['id']}/confirmation?proposal=$pid&payment={$pay['id']}", 'claims', 'claims/new', "claims/{$claim['id']}?created=1&pending=1", 'payments', 'payments/new?proposal='.$pid,
            'documents', 'requests', 'requests?policy='.$policy->id, 'privacy', 'support', 'profile', 'policies', "policies/$policy->id", 'notifications', 'vehicles'];
        @mkdir($dir, 0777, true);
        $html = [];
        foreach (['en', 'fr'] as $lang) {
            foreach ($pages as $p) {
                $html[$lang.' /account/'.$p] = $this->get('/account/'.$p.(str_contains($p, '?') ? '&' : '?').'lang='.$lang)->assertOk()->getContent();
            }
        }
        file_put_contents($dir.'/fixture.json', json_encode(['ids' => $ids, 'tenant' => $this->r2tenant, 'session' => $ses, 'api' => $api, 'pages' => $html], JSON_UNESCAPED_SLASHES));
    }
});

it('counts throttled writes per route, so one busy form does not lock the customer out of the others', function () {
    // Laravel keys throttle:N,M by user only; PerRouteThrottle adds the route. 10 draft saves must not use up the
    // 5-per-minute complaint budget (before: 429 on POST /mobile/complaints).
    $f = makeMobileCustomerFixture('+237677120002');
    makeMobileTestTenantCustomer($f['tenant'], $f['party']);
    Passport::actingAs($f['user']);
    for ($i = 0; $i < 10; $i++) {
        $this->putJson('/api/v1/mobile/account/consents', ['consents' => [['purpose' => 'MARKETING', 'granted' => (bool) ($i % 2)]]], agentHeaders($f))->assertOk();
    }
    $this->postJson('/api/v1/mobile/complaints', ['description' => 'My certificate was delivered two weeks late.'], agentHeaders($f))->assertCreated();
    // The per-route limit itself still holds.
    for ($i = 0; $i < 4; $i++) {
        $this->postJson('/api/v1/mobile/complaints', ['description' => 'Another complaint number '.$i.' about delays.'], agentHeaders($f));
    }
    $this->postJson('/api/v1/mobile/complaints', ['description' => 'One complaint too many this minute.'], agentHeaders($f))->assertStatus(429);
});
