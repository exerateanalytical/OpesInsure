<?php

declare(strict_types=1);

use App\Models\Carrier;
use App\Models\Partner;
use App\Models\Party;
use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\VerificationChallenge;
use Database\Seeders\DemoScenarioSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';
require_once __DIR__.'/../Wave12/Concerns/mobile_auth_helpers.php';

function auditIdem(): array
{
    return ['Idempotency-Key' => (string) Str::uuid()];
}

// ------------------------------------------------------------- 1 + 2: demo OTP

it('issues the fixed demo OTP to a web demo business user (BROKER_STAFF ...007) in demo mode', function () {
    config(['demo.enabled' => true]);
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);
    makeMobileTestUser('+237600000007');

    $response = $this->postJson('/api/v1/auth/mobile/otp/request', ['phone_e164' => '+237600000007']);
    $challenge = VerificationChallenge::findOrFail($response->json('data.challenge_id'));

    expect(Hash::check('123456', $challenge->code_hash))->toBeTrue();
});

it('keeps random OTPs for demo phones when demo mode is off', function () {
    config(['demo.enabled' => false]);
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);
    makeMobileTestUser('+237600000008');

    $response = $this->postJson('/api/v1/auth/mobile/otp/request', ['phone_e164' => '+237600000008']);

    expect(Hash::check('123456', VerificationChallenge::findOrFail($response->json('data.challenge_id'))->code_hash))->toBeFalse();
});

it('logs a critical error when SMS delivery is not configured', function () {
    config(['demo.enabled' => false, 'services.twilio.account_sid' => null, 'services.twilio.auth_token' => null]);
    Log::spy();
    makeMobileTestUser('+237670009001');

    $this->postJson('/api/v1/auth/mobile/otp/request', ['phone_e164' => '+237670009001'])->assertSuccessful();

    Log::shouldHaveReceived('critical')->withArgs(fn ($message) => $message === 'otp.delivery_failed')->once();
});

it('has no route throttle on OTP requests: a demo persona can request 30 codes from one IP', function () {
    config(['demo.enabled' => true]);
    Http::fake();
    makeMobileTestUser('+237600000102');

    for ($i = 0; $i < 30; $i++) {
        $this->postJson('/api/v1/auth/mobile/otp/request', ['phone_e164' => '+237600000102'])->assertSuccessful();
    }
});

// ----------------------------------------------------------------- 3: step-up

it('uses the demo code for step-up on a demo phone in demo mode', function () {
    config(['demo.enabled' => true]);
    Http::fake(['api.twilio.com/*' => Http::response(['sid' => 'SM1'], 201)]);
    $fixture = makeMobileCustomerFixture('+237600000101');
    Passport::actingAs($fixture['user']);

    $response = $this->postJson('/api/v1/mobile/security/step-up/request', ['purpose' => 'COMMISSION_WITHDRAWAL'], tenantHeaderFor($fixture['tenant']) + auditIdem());
    $response->assertStatus(200);

    expect(Hash::check('123456', VerificationChallenge::findOrFail($response->json('data.challenge_id'))->code_hash))->toBeTrue();
});

it('requires a COMMISSION_WITHDRAWAL step-up grant to request a withdrawal', function () {
    $fixture = makeMobileAgentFixture('+237680000501');
    Passport::actingAs($fixture['user']);
    $payload = ['provider' => 'mtn_momo', 'amount_minor' => 1000, 'destination_phone' => '+237670000099'];

    $this->postJson('/api/v1/mobile/agent/withdrawals', $payload, agentHeaders($fixture))
        ->assertStatus(401)->assertJsonPath('code', 'STEP_UP_REQUIRED');

    $grant = issueMobileStepUpGrant($fixture['user'], $fixture['tenant'], 'COMMISSION_WITHDRAWAL');
    // Grant accepted: the request reaches the controller (422 = no statement yet, not 401).
    $this->postJson('/api/v1/mobile/agent/withdrawals', $payload, agentHeaders($fixture) + stepUpHeaderFor($grant['token']))
        ->assertStatus(422);
});

// -------------------------------------------------------------- 4: invitations

it('accepting an AGENT invitation attaches role permissions and a PENDING AGENT partner on the user\'s own party', function () {
    $tenant = Tenant::create(['type' => 'BROKER', 'legal_name' => 'Invite Org', 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en']);
    $admin = makeMobileTenantStaffUser($tenant, '+237670009100', 'PLATFORM_ADMIN');
    $invitee = makeMobileTestUser('+237670009101');

    Passport::actingAs($admin);
    $issued = $this->postJson('/api/v1/invitations', ['tenant_id' => $tenant->id, 'recipient_phone_e164' => '+237670009101', 'role_code' => 'AGENT'], tenantHeaderFor($tenant));
    $issued->assertStatus(201);

    Passport::actingAs($invitee);
    $accepted = $this->postJson('/api/v1/invitations/accept', ['token' => $issued->json('data.token')]);

    $accepted->assertStatus(200);
    expect($accepted->json('data.role_code'))->toBe('AGENT')
        ->and($accepted->json('data.permissions'))->toContain('agent.clients.read')
        ->and($accepted->json('data.partner.type'))->toBe('AGENT')
        ->and($accepted->json('data.partner.status'))->toBe('PENDING');

    $invitee->refresh();
    expect($invitee->party_id)->not->toBeNull();
    expect(Partner::where('party_id', $invitee->party_id)->where('type', 'AGENT')->exists())->toBeTrue();
    expect(TenantInvitation::first()->status)->toBe('ACCEPTED');

    $this->getJson('/api/v1/mobile/agent/clients', tenantHeaderFor($tenant))->assertStatus(200);
});

it('accepting a CARRIER_STAFF invitation links the membership to the invited carrier', function () {
    $fixture = makeMobileCustomerFixture('+237670009200');
    $tenant = $fixture['tenant'];
    $admin = makeMobileTenantStaffUser($tenant, '+237670009201', 'PLATFORM_ADMIN');
    $invitee = makeMobileTestUser('+237670009202');

    Passport::actingAs($admin);
    $token = $this->postJson('/api/v1/invitations', ['tenant_id' => $tenant->id, 'recipient_phone_e164' => '+237670009202', 'role_code' => 'CARRIER_STAFF', 'carrier_id' => $fixture['carrier']->id], tenantHeaderFor($tenant))->json('data.token');

    Passport::actingAs($invitee);
    $accepted = $this->postJson('/api/v1/invitations/accept', ['token' => $token]);

    $accepted->assertStatus(200);
    expect($accepted->json('data.carrier_id'))->toBe($fixture['carrier']->id)
        ->and($accepted->json('data.permissions'))->toContain('carrier.referrals.read');
});

// ------------------------------------------------------------------ 5: partners

it('registers a partner for an existing user on that user\'s own party and lets an admin activate it', function () {
    $tenant = Tenant::create(['type' => 'BROKER', 'legal_name' => 'Partner Org', 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en']);
    $admin = makeMobileTenantStaffUser($tenant, '+237670009300', 'PLATFORM_ADMIN');
    $fixture = makeMobileCustomerFixture('+237670009301');

    Passport::actingAs($admin);
    $response = $this->postJson('/api/v1/partners', ['type' => 'AGENT', 'display_name' => 'Existing User Agent', 'user_id' => $fixture['user']->id], tenantHeaderFor($tenant));

    $response->assertStatus(201);
    expect($response->json('data.party_id'))->toBe($fixture['party']->id)
        ->and($response->json('data.status'))->toBe('PENDING');

    $this->postJson('/api/v1/partners/'.$response->json('data.id').'/status', ['status' => 'ACTIVE', 'notes' => 'Licence checked offline'], tenantHeaderFor($tenant))
        ->assertStatus(200)->assertJsonPath('data.status', 'ACTIVE');
});

// ------------------------------------------------------ 7: carrier/broker gates

it('403s a CUSTOMER on carrier, broker and workspace endpoints', function (string $uri) {
    $fixture = makeMobileCustomerFixture('+237670009400');
    Passport::actingAs($fixture['user']);

    $this->getJson($uri, tenantHeaderFor($fixture['tenant']))->assertStatus(403);
})->with([
    '/api/v1/mobile/carrier/dashboard', '/api/v1/mobile/carrier/referrals', '/api/v1/mobile/carrier/issuance', '/api/v1/mobile/carrier/claims',
    '/api/v1/mobile/carrier/settlements', '/api/v1/mobile/carrier/bordereaux',
    '/api/v1/mobile/broker/dashboard', '/api/v1/mobile/broker/clients', '/api/v1/mobile/broker/compliance', '/api/v1/mobile/broker/receivables',
    '/api/v1/mobile/workspace/dashboard', '/api/v1/mobile/workspace/modules/policies',
]);

it('scopes a carrier-linked insurer to its own carrier\'s referrals', function () {
    $a = makeMobileCustomerFixture('+237670009500');
    $tenant = $a['tenant'];
    $mine = App\Models\UnderwritingCase::create(['tenant_id' => $tenant->id, 'proposal_id' => $a['proposal']->id, 'carrier_id' => $a['carrier']->id, 'status' => 'QUEUED', 'priority' => 'NORMAL', 'referral_reasons' => ['X']]);
    $chainB = makeMobileFinanceProposalChain($tenant); // another insurer's proposal in the same tenant
    $theirs = App\Models\UnderwritingCase::create(['tenant_id' => $tenant->id, 'proposal_id' => $chainB['proposal']->id, 'carrier_id' => $chainB['carrier']->id, 'status' => 'QUEUED', 'priority' => 'NORMAL', 'referral_reasons' => ['Y']]);

    $insurer = makeMobileTenantStaffUser($tenant, '+237670009502', 'CARRIER_STAFF');
    TenantMembership::where('user_id', $insurer->id)->update(['carrier_id' => $a['carrier']->id]);
    Passport::actingAs($insurer);

    $rows = collect($this->getJson('/api/v1/mobile/carrier/referrals', tenantHeaderFor($tenant))->assertStatus(200)->json('data'));
    expect($rows->pluck('id')->all())->toBe([$mine->id]);
    // Each row names its carrier so live checks can prove the scoping.
    expect($rows->pluck('carrier_id')->unique()->all())->toBe([$a['carrier']->id]);
    expect($rows->first())->toHaveKey('carrier_name');
    foreach (['issuance', 'claims'] as $list) {
        foreach ($this->getJson("/api/v1/mobile/carrier/{$list}", tenantHeaderFor($tenant))->assertStatus(200)->json('data') as $row) {
            expect($row['carrier_id'])->toBe($a['carrier']->id);
        }
    }
    $this->getJson('/api/v1/mobile/carrier/referrals/'.$theirs->id, tenantHeaderFor($tenant))->assertStatus(404);
});

it('refuses an unlinked insurer role outside a carrier tenant instead of showing every insurer', function () {
    $tenant = Tenant::create(['type' => 'PLATFORM', 'legal_name' => 'Platform X', 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en']);
    $insurer = makeMobileTenantStaffUser($tenant, '+237670009600', 'CARRIER_STAFF');
    Passport::actingAs($insurer);

    $this->getJson('/api/v1/mobile/carrier/referrals', tenantHeaderFor($tenant))->assertStatus(403);
});

it('keeps the seeded demo personas working: insurer 200 on carrier, broker 200 on broker, customer 403', function () {
    config(['demo.enabled' => true]);
    // Exactly what deploy runs (optimize -> demo:seed), twice to prove idempotency.
    $this->artisan('demo:seed')->assertSuccessful();
    $this->artisan('demo:seed')->assertSuccessful();
    expect(User::where('phone_e164', '+237600000103')->exists())->toBeTrue();
    $tenant = Tenant::where('slug', 'opesinsure-platform')->firstOrFail();

    $insurer = User::where('phone_e164', '+237600000103')->firstOrFail();
    Passport::actingAs($insurer);
    foreach (['dashboard', 'referrals', 'issuance', 'claims', 'settlements', 'bordereaux'] as $path) {
        $this->getJson("/api/v1/mobile/carrier/{$path}", tenantHeaderFor($tenant))->assertStatus(200);
    }
    expect(TenantMembership::where('user_id', $insurer->id)->value('carrier_id'))->not->toBeNull();

    $broker = User::where('phone_e164', '+237600000102')->firstOrFail();
    Passport::actingAs($broker);
    foreach (['dashboard', 'clients', 'production', 'renewals', 'compliance', 'marketplace-publications', 'receivables', 'statements'] as $path) {
        $this->getJson("/api/v1/mobile/broker/{$path}", tenantHeaderFor($tenant))->assertStatus(200);
    }
    expect($this->getJson('/api/v1/mobile/broker/compliance', tenantHeaderFor($tenant))->json('data'))->not->toBeEmpty();

    $agent = User::where('phone_e164', '+237600000101')->firstOrFail();
    Passport::actingAs($agent);
    $this->getJson('/api/v1/mobile/agent/dashboard', tenantHeaderFor($tenant))->assertStatus(200);

    $customer = User::where('phone_e164', '+237600000100')->firstOrFail();
    Passport::actingAs($customer);
    $this->getJson('/api/v1/mobile/carrier/dashboard', tenantHeaderFor($tenant))->assertStatus(403);
});

it('scopes broker compliance to the broker\'s own partner', function () {
    $broker = makeMobilePartnerFixture('BROKER', '+237670009650');
    $other = Partner::create(['tenant_id' => $broker['tenant']->id, 'party_id' => Party::create(['type' => 'ORGANIZATION', 'display_name' => 'Other', 'status' => 'ACTIVE'])->id, 'type' => 'BROKER', 'status' => 'ACTIVE', 'compliance' => []]);
    foreach ([[$broker['partner']->id, 'CC-MINE'], [$other->id, 'CC-THEIRS']] as [$subject, $number]) {
        Illuminate\Support\Facades\DB::table('compliance_cases')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $broker['tenant']->id, 'case_number' => $number, 'type' => 'KYC_REFRESH', 'subject_type' => 'partner', 'subject_id' => $subject, 'status' => 'OPEN', 'severity' => 'LOW', 'review_due_on' => now()->addDays(5)->toDateString(), 'findings' => '[]', 'opened_by' => $broker['user']->id, 'created_at' => now(), 'updated_at' => now()]);
    }
    Passport::actingAs($broker['user']);

    $labels = collect($this->getJson('/api/v1/mobile/broker/compliance', tenantHeaderFor($broker['tenant']))->assertStatus(200)->json('data'))->pluck('label')->implode('|');
    expect($labels)->toContain('CC-MINE')->not->toContain('CC-THEIRS');
});

// --------------------------------------------------------------- 8: workspace

it('returns label as well as title on the workspace dashboard', function () {
    $tenant = Tenant::create(['type' => 'PLATFORM', 'legal_name' => 'WS Org', 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en']);
    $staff = makeMobileTenantStaffUser($tenant, '+237670009700', 'CLAIMS_OFFICER');
    Passport::actingAs($staff);

    $response = $this->getJson('/api/v1/mobile/workspace/dashboard', tenantHeaderFor($tenant));
    $response->assertStatus(200);
    expect($response->json('data.label'))->toBe('Claims officer workspace')
        ->and($response->json('data.title'))->toBe('Claims officer workspace')
        ->and($response->json('data.modules.0.label'))->not->toBeNull();
});

// ------------------------------------------------------ 9: public verify

it('verifies insurance by reference without leaking personal data', function () {
    $fixture = makeMobileCustomerFixture('+237670009800');
    $policy = makeMobileTestPolicy($fixture['proposal'], $fixture['tenant'], $fixture['carrier']->id, $fixture['party']->id, [
        'policy_number' => 'POL-VERIFY-1', 'certificate_number' => 'CERT-VERIFY-1', 'status' => 'ACTIVE',
        'coverage_starts_at' => now()->subDay(), 'coverage_ends_at' => now()->addMonths(6),
    ]);

    $ok = $this->postJson('/api/v1/public/insurance/verify', ['reference' => 'CERT-VERIFY-1']);
    $ok->assertStatus(200);
    expect($ok->json('data.result'))->toBe('valid')
        ->and($ok->json('data.carrier_name'))->toBe('Mobile Wallet Test Carrier Org')
        ->and($ok->json('data.product_class'))->toBe('AUTO')
        ->and(json_encode($ok->json()))->not->toContain('Mobile Wallet Test Party');

    $policy->update(['coverage_ends_at' => now()->subDay()]);
    expect($this->postJson('/api/v1/public/insurance/verify', ['reference' => 'POL-VERIFY-1'])->json('data.result'))->toBe('expired');
    expect($this->postJson('/api/v1/public/insurance/verify', ['reference' => 'NOPE-123'])->json('data.result'))->toBe('not_found');
    $this->postJson('/api/v1/public/insurance/verify', [])->assertStatus(422);
});

// ------------------------------------------------------------ 10: deploy hook

it('demo:seed is a no-op with demo mode off', function () {
    config(['demo.enabled' => false]);
    $this->artisan('demo:seed')->assertSuccessful();
    expect(User::where('phone_e164', '+237600000103')->exists())->toBeFalse();
});

// ------------------------------------------------------------ public accounts

it('refuses a public account without a password (phone + password sign-up)', function () {
    $this->postJson('/api/v1/public/accounts', ['full_name' => 'No Password', 'phone_e164' => '+237670009900', 'locale' => 'en', 'terms_version' => '2026-01'])
        ->assertStatus(422)->assertJsonValidationErrors('password');
});

it('still validates a password when one is supplied', function () {
    $this->postJson('/api/v1/public/accounts', ['full_name' => 'Short', 'phone_e164' => '+237670009901', 'locale' => 'en', 'terms_version' => '2026-01', 'password' => 'short', 'password_confirmation' => 'short'])
        ->assertStatus(422)->assertJsonValidationErrors('password');
});
