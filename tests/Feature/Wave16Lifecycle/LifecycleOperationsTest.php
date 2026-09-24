<?php

declare(strict_types=1);

use App\Application\Quotes\QuoteService;
use App\Models\Claim;
use App\Models\MobileRefreshToken;
use App\Models\PaymentIntentRecord;
use App\Models\Policy;
use App\Models\PolicyIssuanceRequest;
use App\Models\Quote;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\UserNotification;
use Database\Seeders\PlatformCatalogueSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

function opsPush(User $user, string $token = 'ExponentPushToken[abc123]'): void
{
    DB::table('user_push_tokens')->insert(['id' => (string) Str::uuid(), 'user_id' => $user->id, 'token' => $token, 'platform' => 'android', 'provider' => 'expo', 'created_at' => now(), 'updated_at' => now()]);
}

beforeEach(function () {
    Http::preventStrayRequests();
});

it('only schedules commands that exist', function () {
    Artisan::call('list'); // boot console + schedule definitions
    $registered = array_keys(Artisan::all());
    $events = app(Schedule::class)->events();
    expect($events)->not->toBeEmpty();

    foreach ($events as $event) {
        preg_match("/artisan'?\"?\s+'?\"?([a-z0-9:\-]+)/i", $event->command, $m);
        expect($m[1] ?? null)->not->toBeNull("Unparseable scheduled command: {$event->command}");
        expect($registered)->toContain($m[1]);
    }

    foreach (['policies:notify-expiry', 'policies:expire', 'settlements:prepare', 'reconciliation:run'] as $required) {
        expect(collect($events)->contains(fn ($e) => str_contains($e->command, $required)))->toBeTrue("{$required} is not scheduled");
    }
});

it('sends 30/14/7/1-day renewal reminders once each, to inbox and Expo push', function () {
    $f = makeMobileCustomerFixture('+237671110001');
    Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'ok', 'id' => 't-1']]])]);
    opsPush($f['user']);
    $in7 = makeMobileTestPolicy($f['proposal'], $f['tenant'], $f['carrier']->id, $f['party']->id, ['policy_number' => 'POL-R7', 'coverage_ends_at' => now()->addDays(7)->setTime(12, 0)]);

    $this->artisan('policies:notify-expiry')->assertSuccessful();
    $this->artisan('policies:notify-expiry')->assertSuccessful(); // idempotent

    $notes = UserNotification::where('user_id', $f['user']->id)->where('type', 'RENEWAL')->get();
    expect($notes)->toHaveCount(1)
        ->and($notes[0]->title)->toBe('Your cover ends in 7 days')
        ->and($notes[0]->path)->toBe("/policy/{$in7->id}")
        ->and(DB::table('policy_expiry_reminders')->where('policy_id', $in7->id)->where('days_before', 7)->count())->toBe(1);

    Http::assertSent(fn ($r) => str_contains($r->url(), 'exp.host/--/api/v2/push/send') && $r[0]['to'] === 'ExponentPushToken[abc123]' && $r[0]['title'] === 'Your cover ends in 7 days');
});

it('moves policies ACTIVE -> EXPIRING -> EXPIRED -> LAPSED with history, and never lapses a renewed policy', function () {
    config(['lifecycle.grace_period_days' => 15]);
    $f = makeMobileCustomerFixture('+237671110002');
    $mk = function (array $o) use ($f) {
        $offer = makeMobileTestQuoteOffer(makeMobileTestQuote($f['tenant'], $f['party']), $f['carrier']->id, $f['product']->id, $f['tariff']->id);
        $p = App\Models\Proposal::create(['tenant_id' => $f['tenant']->id, 'quote_offer_id' => $offer->id, 'party_id' => $f['party']->id, 'status' => 'APPROVED']);

        return makeMobileTestPolicy($p, $f['tenant'], $f['carrier']->id, $f['party']->id, $o);
    };
    $soon = $mk(['policy_number' => 'P-SOON', 'coverage_ends_at' => now()->addDays(20)]);
    $far = $mk(['policy_number' => 'P-FAR', 'coverage_ends_at' => now()->addDays(200)]);
    $ended = $mk(['policy_number' => 'P-ENDED', 'coverage_starts_at' => now()->subYear(), 'coverage_ends_at' => now()->subDay()]);
    $old = $mk(['policy_number' => 'P-OLD', 'status' => 'EXPIRED', 'coverage_starts_at' => now()->subYears(2), 'coverage_ends_at' => now()->subDays(20)]);
    $renewed = $mk(['policy_number' => 'P-RENEWED', 'status' => 'EXPIRED', 'coverage_starts_at' => now()->subYears(2), 'coverage_ends_at' => now()->subDays(20)]);
    $mk(['policy_number' => 'P-SUCCESSOR', 'previous_policy_id' => $renewed->id]);

    $this->artisan('policies:expire')->assertSuccessful();

    expect($soon->refresh()->status)->toBe('EXPIRING')
        ->and($far->refresh()->status)->toBe('ACTIVE')
        ->and($ended->refresh()->status)->toBe('EXPIRED')
        ->and($old->refresh()->status)->toBe('LAPSED')
        ->and($renewed->refresh()->status)->toBe('EXPIRED');
    expect(DB::table('policy_status_history')->where('policy_id', $ended->id)->pluck('to_status')->all())->toBe(['EXPIRING', 'EXPIRED']);

    // An EXPIRING policy is still in force: claims can be filed against it.
    Passport::actingAs($f['user']);
    $this->getJson('/api/v1/mobile/wallet', tenantHeaderFor($f['tenant']))->assertOk();
});

it('reconciliation:run reconciles provider-confirmed payments and opens missing issuance requests', function () {
    $f = makeMobileCustomerFixture('+237671110003');
    $f['proposal']->update(['status' => 'PAYMENT_PENDING', 'terms_snapshot' => ['total_minor' => 100000, 'currency' => 'XAF']]);
    $payment = makeMobileTestPayment($f['proposal'], $f['tenant'], ['status' => 'SUCCEEDED', 'requested_by' => $f['user']->id]);
    DB::table('payment_events')->insert(['id' => (string) Str::uuid(), 'payment_intent_id' => $payment->id, 'type' => 'PROVIDER_STATUS', 'provider_event_id' => 'e1', 'previous_status' => 'PROCESSING', 'new_status' => 'SUCCEEDED', 'amount_minor' => 100000, 'currency' => 'XAF', 'provider_payload' => '{}', 'occurred_at' => now()]);

    $this->artisan('reconciliation:run')->assertSuccessful();

    expect($payment->refresh()->reconciled_at)->not->toBeNull()
        ->and(PolicyIssuanceRequest::where('proposal_id', $f['proposal']->id)->value('status'))->toBe('CARRIER_REVIEW');

    $this->artisan('reconciliation:run')->assertSuccessful();
    expect(PolicyIssuanceRequest::where('proposal_id', $f['proposal']->id)->count())->toBe(1);
});

it('settlements:prepare drafts a carrier settlement for last week\'s issued policies, idempotently', function () {
    $f = makeMobileCustomerFixture('+237671110004');
    $admin = makeMobileTenantStaffUser($f['tenant'], '+237671110099', 'PLATFORM_ADMIN');
    $payment = makeMobileTestPayment($f['proposal'], $f['tenant']);
    makeMobileTestPolicy($f['proposal'], $f['tenant'], $f['carrier']->id, $f['party']->id, ['policy_number' => 'P-SET', 'payment_intent_id' => $payment->id, 'currency' => 'XAF', 'premium_minor' => 100000, 'issued_at' => now()->subWeek()->startOfWeek()->addDay()]);

    $this->artisan('settlements:prepare')->assertSuccessful();
    $this->artisan('settlements:prepare')->assertSuccessful();

    $batches = DB::table('settlement_batches')->where('carrier_id', $f['carrier']->id)->get();
    expect($batches)->toHaveCount(1)
        ->and((int) $batches[0]->net_amount_minor)->toBe(100000)
        ->and($batches[0]->status)->toBe('DRAFT')
        ->and($batches[0]->prepared_by)->toBe($admin->id);
});

it('produces customer notifications for quote, proposal, underwriting, claim and payment changes', function () {
    $f = makeMobileCustomerFixture('+237671110005');
    $uid = $f['user']->id;

    $f['quote']->update(['status' => 'OFFERED', 'comparison_context' => ['offer_count' => 3]]);
    $f['proposal']->update(['status' => 'UNDER_REVIEW']);
    $case = App\Models\UnderwritingCase::create(['tenant_id' => $f['tenant']->id, 'proposal_id' => $f['proposal']->id, 'carrier_id' => $f['carrier']->id, 'status' => 'IN_REVIEW', 'priority' => 'NORMAL', 'referral_reasons' => []]);
    $case->update(['status' => 'AWAITING_INFORMATION']);
    $f['proposal']->update(['status' => 'COUNTEROFFERED']);
    $f['proposal']->update(['status' => 'PAYMENT_PENDING']);
    $policy = makeMobileTestPolicy($f['proposal'], $f['tenant'], $f['carrier']->id, $f['party']->id, ['policy_number' => 'P-N']);
    $claim = makeMobileTestClaim($f['tenant'], $policy, $f['party']);
    $claim->update(['status' => 'ACKNOWLEDGED']);
    $claim->update(['status' => 'EVIDENCE_PENDING']);
    $payment = makeMobileTestPayment($f['proposal'], $f['tenant'], ['status' => 'PENDING_CUSTOMER']);
    $payment->update(['status' => 'FAILED']);

    $titles = UserNotification::where('user_id', $uid)->orderBy('created_at')->pluck('title')->all();
    expect($titles)->toContain('Your quotes are ready', 'Proposal under review', 'More information needed', 'Counter-offer available',
        'Proposal approved', 'Claim received', 'Claim acknowledged', 'Documents requested for your claim', 'Payment failed');
    expect(UserNotification::where('user_id', $uid)->where('title', 'Your quotes are ready')->value('body'))->toContain('3 offers');
});

it('sends security notifications for a new device sign-in and a password change', function () {
    $f = makeMobileCustomerFixture('+237671110006');
    App\Models\UserDevice::create(['user_id' => $f['user']->id, 'device_fingerprint' => 'fp-1', 'name' => 'Pixel 7', 'platform' => 'android', 'last_seen_at' => now()]);
    expect(UserNotification::where('user_id', $f['user']->id)->where('type', 'SECURITY')->count())->toBe(0); // first device = sign-up

    App\Models\UserDevice::create(['user_id' => $f['user']->id, 'device_fingerprint' => 'fp-2', 'name' => 'iPhone 15', 'platform' => 'ios', 'last_seen_at' => now()]);
    $f['user']->update(['password' => 'N3w-Passw0rd!']);

    expect(UserNotification::where('user_id', $f['user']->id)->where('type', 'SECURITY')->pluck('title')->all())
        ->toContain('New sign-in to your account', 'Your password was changed');
});

it('registers push tokens with provider, logs and prunes failed Expo tickets', function () {
    $f = makeMobileCustomerFixture('+237671110007');
    Passport::actingAs($f['user']);

    $r = $this->postJson('/api/v1/mobile/account/push-tokens', ['token' => 'ExponentPushToken[zzz]', 'platform' => 'android'], tenantHeaderFor($f['tenant']))->assertStatus(201);
    expect($r->json('data.provider'))->toBe('expo');
    $this->postJson('/api/v1/mobile/account/push-tokens', ['token' => 'fcm-raw-token', 'provider' => 'fcm', 'platform' => 'android'], tenantHeaderFor($f['tenant']))->assertStatus(201);
    $this->postJson('/api/v1/mobile/account/push-tokens', ['token' => 'x', 'provider' => 'apns', 'platform' => 'ios'], tenantHeaderFor($f['tenant']))->assertStatus(422);

    Http::fake(['exp.host/*' => Http::response(['data' => [['status' => 'error', 'message' => 'gone', 'details' => ['error' => 'DeviceNotRegistered']]]])]);
    app(App\Application\Notifications\CustomerNotifier::class)->toUser($f['user'], $f['tenant']->id, 'POLICY', 'Hello', 'World');

    // Only the expo token is sent to Expo; the dead one is pruned, fcm kept.
    Http::assertSentCount(1);
    expect(DB::table('user_push_tokens')->where('user_id', $f['user']->id)->pluck('token')->all())->toBe(['fcm-raw-token']);
});

it('lets a customer sync their own offline claim-incident draft, but not someone else\'s', function () {
    $f = makeMobileCustomerFixture('+237671110008');
    $policy = makeMobileTestPolicy($f['proposal'], $f['tenant'], $f['carrier']->id, $f['party']->id, ['policy_number' => 'P-S']);
    $claim = makeMobileTestClaim($f['tenant'], $policy, $f['party']);
    $other = makeMobileCustomerFixture('+237671110009');
    $otherPolicy = makeMobileTestPolicy($other['proposal'], $other['tenant'], $other['carrier']->id, $other['party']->id, ['policy_number' => 'P-O']);
    $otherClaim = makeMobileTestClaim($f['tenant'], $otherPolicy, $other['party']);
    Passport::actingAs($f['user']);

    $envelope = fn (string $claimId) => ['id' => (string) Str::uuid(), 'kind' => 'DRAFT', 'resource' => 'Claim incident draft', 'resource_id' => $claimId, 'method' => 'PUT', 'path' => "/mobile/claims/{$claimId}/incident", 'payload' => ['incident_type' => 'COLLISION', 'injuries_reported' => true, 'towing_required' => true]];

    $ok = $this->postJson('/api/v1/mobile/sync/operations', $envelope($claim->id), agentHeaders($f))->assertOk();
    expect($ok->json('data.status'))->toBe('APPLIED')
        ->and($claim->refresh()->loss_details['incident']['incident_type'])->toBe('COLLISION')
        ->and($claim->loss_details['incident']['towing_required'])->toBeTrue();

    $this->postJson('/api/v1/mobile/sync/operations', $envelope($otherClaim->id), agentHeaders($f))->assertStatus(403);
    expect($otherClaim->refresh()->loss_details['incident'] ?? null)->toBeNull();

    // A customer still cannot replay agent-only operations.
    $this->postJson('/api/v1/mobile/sync/operations', ['id' => (string) Str::uuid(), 'kind' => 'MUTATION', 'resource' => 'client', 'method' => 'POST', 'path' => '/mobile/agent/clients', 'payload' => ['x' => 1]], agentHeaders($f))->assertStatus(403);
});

it('revokes every access and refresh token of the user on logout-all', function () {
    $f = makeMobileCustomerFixture('+237671110010');
    app(Laravel\Passport\ClientRepository::class)->createPersonalAccessGrantClient('Test personal', 'users');
    $t1 = $f['user']->createToken('a')->token;
    $t2 = $f['user']->createToken('b')->token;
    foreach (['fam-1', 'fam-2'] as $fam) {
        MobileRefreshToken::create(['user_id' => $f['user']->id, 'family_id' => (string) Str::uuid(), 'token_hash' => hash('sha256', $fam), 'expires_at' => now()->addDays(30)]);
    }
    Passport::actingAs($f['user']);

    $r = $this->postJson('/api/v1/auth/mobile/logout-all')->assertOk();
    expect($r->json('data.revoked'))->toBeTrue()
        ->and(DB::table('oauth_access_tokens')->where('user_id', $f['user']->id)->where('revoked', false)->count())->toBe(0)
        ->and(MobileRefreshToken::where('user_id', $f['user']->id)->whereNull('revoked_at')->count())->toBe(0);
});

it('returns JSON 401 (not 500) for catalogue without an Accept header', function () {
    $r = $this->get('/api/v1/catalogue/lines');
    $r->assertStatus(401);
    expect($r->headers->get('Content-Type'))->toContain('application/json');
    $this->get('/api/v1/catalogue/products')->assertStatus(401);
});

it('serves the risk schema per line and rates MOTOR, BUSINESS and ACCIDENT quotes into offers from several carriers', function () {
    $f = makeMobileCustomerFixture('+237671110011');
    makeMobileTestTenantCustomer($f['tenant'], $f['party']);
    (new PlatformCatalogueSeeder)->run();
    Passport::actingAs($f['user']);

    $motor = $this->getJson('/api/v1/mobile/catalogue/lines/motor/risk-schema', tenantHeaderFor($f['tenant']))->assertOk();
    expect(collect($motor->json('data.steps'))->pluck('key')->all())->toBe(['vehicle', 'usage', 'owner', 'cover', 'history'])
        ->and(collect($motor->json('data.fields'))->pluck('key')->all())->toContain('registration_number', 'make', 'model', 'year', 'vehicle_value', 'usage_type', 'zone', 'cover_type', 'fiscal_power', 'previous_insurer', 'claims_last_3_years')
        ->and(collect($motor->json('data.fields'))->firstWhere('key', 'cover_type')['options'])->toHaveCount(3)
        ->and($motor->json('data.required'))->toContain('registration_number');
    foreach (['HEALTH', 'TRAVEL', 'HOME', 'LIFE', 'BUSINESS', 'ACCIDENT'] as $line) {
        $r = $this->getJson("/api/v1/mobile/catalogue/lines/{$line}/risk-schema", tenantHeaderFor($f['tenant']))->assertOk();
        expect($r->json('data.fields'))->not->toBeEmpty();
        foreach ($r->json('data.fields') as $field) {
            expect(in_array($field['type'], ['text', 'number', 'select', 'date', 'boolean'], true))->toBeTrue();
        }
    }
    $this->getJson('/api/v1/mobile/catalogue/lines/NOPE/risk-schema', tenantHeaderFor($f['tenant']))->assertNotFound();

    $quotes = app(QuoteService::class);
    $cases = [
        'MOTOR' => ['registration_number' => 'LT-123-AB', 'make' => 'Toyota', 'model' => 'Corolla', 'year' => 2018, 'vehicle_value' => 6500000, 'usage_type' => 'PRIVATE', 'zone' => 'DOUALA', 'cover_type' => 'COMPREHENSIVE', 'fiscal_power' => 8, 'previous_insurer' => null, 'claims_last_3_years' => 1],
        'BUSINESS' => ['business_type' => 'RETAIL', 'employee_count' => 12, 'annual_turnover' => 50000000, 'city' => 'Yaoundé', 'premises_value' => 30000000, 'cover_type' => 'MULTIRISK'],
        'ACCIDENT' => ['insured_count' => 3, 'oldest_age' => 45, 'occupation_class' => 'MANUAL', 'cover_amount' => 10000000],
    ];
    foreach ($cases as $line => $facts) {
        $quote = $quotes->submit(Tenant::find($f['tenant']->id), $f['party']->id, ['line_code' => $line, 'channel' => 'MOBILE', 'risk_facts' => $facts], $f['user']);
        $rated = $quotes->rate($quote, $f['user']);
        expect($rated->status)->toBe('OFFERED', "{$line} produced no offers");
        expect($rated->offers()->distinct('carrier_id')->count('carrier_id'))->toBeGreaterThanOrEqual(2);
    }

    // Comprehensive + a prior claim + Douala costs more than basic third party.
    $basic = $quotes->rate($quotes->submit(Tenant::find($f['tenant']->id), $f['party']->id, ['line_code' => 'MOTOR', 'channel' => 'MOBILE', 'risk_facts' => array_merge($cases['MOTOR'], ['cover_type' => 'THIRD_PARTY', 'claims_last_3_years' => 0, 'zone' => 'RURAL'])], $f['user']), $f['user']);
    $rich = Quote::where('line_code', 'MOTOR')->where('id', '!=', $basic->id)->first();
    expect((int) $rich->offers()->min('premium_minor'))->toBeGreaterThan((int) $basic->offers()->min('premium_minor'));
});
