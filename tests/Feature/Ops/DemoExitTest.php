<?php

declare(strict_types=1);

use App\Application\Bordereaux\BordereauItemSource;
use App\Application\Demo\DemoCoverageReport;
use App\Application\Demo\DemoMode;
use App\Application\Reporting\Kpi\KpiQueryRegistry;
use App\Models\Carrier;
use App\Models\Claim;
use App\Models\Partner;
use App\Models\Party;
use App\Models\Policy;
use App\Models\Quote;
use App\Models\Tenant;
use App\Models\TenantCustomer;
use App\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoMobileAccountSeeder;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

const S13_DEMO_PHONE = '+237600000100';

/** Demo mode as it is on production today: .env switch on, demo accounts + scenario seeded. */
function seedDemoWorld(): void
{
    config(['demo.enabled' => true, 'demo.password' => 'Demo@12345', 'demo.local_admin_password' => 'Admin@12345!x']);
    test()->artisan('demo:seed')->assertSuccessful();
    expect(DB::table('policies')->where('is_demo', true)->count())->toBeGreaterThan(0);
}

function s13Clone(string $table, string $id, array $overrides): string
{
    $row = (array) DemoMode::reveal(fn () => DB::table($table)->where('id', $id)->first());
    $new = (string) Str::uuid();
    DB::table($table)->insert([...collect($row)->except(['is_demo', 'data_origin'])->all(), ...$overrides, 'id' => $new, 'created_at' => now(), 'updated_at' => now()]);

    return $new;
}

/** A real customer with a real issued policy on the same platform tenant and the same insurer as demo data. */
function realPolicy(): Policy
{
    $tenant = Tenant::where('slug', 'opesinsure-platform')->firstOrFail();
    $carrier = Carrier::where('cima_code', 'ASAC-CHANAS')->firstOrFail();
    $party = Party::create(['type' => 'INDIVIDUAL', 'display_name' => 'Real Customer Mbarga', 'status' => 'ACTIVE']);
    User::create(['full_name' => 'Real Customer Mbarga', 'phone_e164' => '+237677445566', 'party_id' => $party->id, 'password' => 'Real@12345', 'locale' => 'fr', 'status' => 'ACTIVE']);
    TenantCustomer::create(['tenant_id' => $tenant->id, 'party_id' => $party->id, 'customer_number' => 'CUS-REAL-0001', 'status' => 'ACTIVE']);
    // Clone a demo purchase chain (quote -> offer -> proposal -> policy) into a real, unflagged one.
    $demo = DemoMode::reveal(fn () => DB::table('policies')->where('is_demo', true)->where('carrier_id', $carrier->id)->whereNotNull('issued_at')->first());
    $proposal = DemoMode::reveal(fn () => DB::table('proposals')->where('id', $demo->proposal_id)->first());
    $offer = DemoMode::reveal(fn () => DB::table('quote_offers')->where('id', $proposal->quote_offer_id)->first());
    $quoteId = s13Clone('quotes', $offer->quote_id, ['party_id' => $party->id, 'quote_number' => 'QTE-REAL-0001']);
    $offerId = s13Clone('quote_offers', $offer->id, ['quote_id' => $quoteId]);
    $proposalId = s13Clone('proposals', $proposal->id, ['quote_offer_id' => $offerId, 'party_id' => $party->id, 'proposal_number' => 'PRP-REAL-0001']);
    $id = s13Clone('policies', $demo->id, ['proposal_id' => $proposalId, 'party_id' => $party->id, 'payment_intent_id' => null,
        'policy_number' => 'POL-REAL-000001', 'certificate_number' => 'CERT-REAL-000001', 'status' => 'ACTIVE', 'issued_at' => now()]);

    return Policy::findOrFail($id);
}

it('requires exactly one of --dry-run / --confirm, and a dry run changes nothing', function () {
    seedDemoWorld();

    $this->artisan('demo:exit')->assertExitCode(2);
    $this->artisan('demo:exit --dry-run')->expectsOutputToContain('DRY RUN')->assertSuccessful();

    expect(app(DemoMode::class)->stored())->toBeNull()
        ->and(User::where('phone_e164', S13_DEMO_PHONE)->value('status'))->toBe('ACTIVE')
        ->and(DB::table('policies')->where('is_demo', true)->count())->toBeGreaterThan(0);
});

it('after demo:exit no demo record appears in any list, KPI, report or bordereau, and demo rows are kept', function () {
    seedDemoWorld();
    $real = realPolicy();
    $tenant = Tenant::where('slug', 'opesinsure-platform')->firstOrFail();
    $chanas = Carrier::where('cima_code', 'ASAC-CHANAS')->firstOrFail();
    $kept = DemoMode::reveal(fn () => DB::table('policies')->where('is_demo', true)->count());

    $this->artisan('demo:exit --confirm')->expectsOutputToContain('Demo mode is off')->assertSuccessful();
    app()->forgetScopedInstances();

    // Stored in the database, not .env; every config reader follows it.
    expect(app(DemoMode::class)->stored())->toBeFalse()->and(config('demo.enabled'))->toBeFalse();

    // Raw query builder and Eloquent, every flagged table.
    foreach (DemoMode::TABLES as $table) {
        expect(DB::table($table)->where('is_demo', true)->count())->toBe(0, "demo rows visible in {$table}");
    }
    expect(Policy::pluck('policy_number')->all())->toBe(['POL-REAL-000001'])
        ->and(Claim::count())->toBe(0)
        ->and(Quote::where('party_id', User::where('phone_e164', S13_DEMO_PHONE)->value('party_id'))->count())->toBe(0)
        ->and(TenantCustomer::pluck('customer_number')->all())->toBe(['CUS-REAL-0001'])
        ->and(Partner::where('is_demo', true)->exists())->toBeFalse()
        ->and(Policy::where('policy_number', 'POL-2026-000101')->exists())->toBeFalse();

    // Joins (aliases) and OR-clauses keep their meaning.
    expect(DB::table('claims as c')->join('policies as p', 'p.id', '=', 'c.policy_id')->count())->toBe(0)
        ->and(DB::table('policies')->where('status', 'ACTIVE')->orWhere('status', 'EXPIRED')->pluck('policy_number')->all())->toBe(['POL-REAL-000001'])
        ->and(DB::table('commission_accruals as a')->join('policies as p', 'p.id', '=', 'a.policy_id')->count())->toBe(0);

    // KPIs, finance commission report, carrier bordereau.
    expect(KpiQueryRegistry::base('policies.active', $tenant->id)->count())->toBe(1)
        ->and(KpiQueryRegistry::base('claims.reported', $tenant->id)->count())->toBe(0)
        ->and(KpiQueryRegistry::base('commissions.pending', $tenant->id)->count())->toBe(0)
        ->and(KpiQueryRegistry::base('payments.collected', $tenant->id)->count())->toBe(0);
    $items = DB::transaction(fn () => app(BordereauItemSource::class)->items($tenant->id, $chanas->id, 'PREMIUM', now()->subYears(3)->toDateString(), now()->toDateString(), 'XAF'));
    expect(collect($items)->pluck('policy_id')->all())->toBe([$real->id]);

    // Nothing deleted; platform admins still see it in the explicit demo data view.
    expect(DemoMode::reveal(fn () => DB::table('policies')->where('is_demo', true)->count()))->toBe($kept);
    $coverage = DemoMode::reveal(fn () => app(DemoCoverageReport::class)->build());
    expect($coverage['states']['policies'] ?? [])->not->toBe([]);
});

it('after demo:exit demo users cannot sign in and demo affordances are gone', function () {
    seedDemoWorld();
    $this->artisan('demo:exit --confirm')->assertSuccessful();
    app()->forgetScopedInstances();

    $emails = [...array_column(DemoMobileAccountSeeder::ACCOUNTS, 'email'), 'demo-platform-admin@opesinsure.local', 'demo-agent@opesinsure.local'];
    expect(User::whereIn('email', $emails)->where('status', '!=', 'SUSPENDED')->count())->toBe(0)
        ->and(User::whereIn('email', $emails)->count())->toBe(count($emails)); // suspended, not deleted
    // The bootstrap admin is never suspended by default.
    expect(User::where('email', 'admin@opesinsure.local')->value('status'))->toBe('ACTIVE');

    // Mobile password sign-in with the documented demo password.
    $this->postJson('/api/v1/auth/mobile/password-login', ['phone_e164' => S13_DEMO_PHONE, 'password' => 'Demo@12345', 'device_fingerprint' => 'dev-s13'])
        ->assertStatus(422);
    // The fixed OTP no longer works.
    $challenge = $this->postJson('/api/v1/auth/mobile/otp/request', ['phone_e164' => S13_DEMO_PHONE])->json('data.challenge_id') ?? $this->postJson('/api/v1/auth/mobile/otp/request', ['phone_e164' => S13_DEMO_PHONE])->json('challenge_id');
    if ($challenge) {
        $this->postJson('/api/v1/auth/mobile/otp/verify', ['challenge_id' => $challenge, 'code' => '123456', 'device_fingerprint' => 'dev-s13'])->assertStatus(422);
    }
    // Web panel.
    $staff = User::where('email', 'demo-platform-admin@opesinsure.local')->firstOrFail();
    expect($staff->canAccessPanel(Filament::getPanel('admin')))->toBeFalse();

    $this->get('/demo')->assertNotFound();
    $this->getJson('/api/v1/public/demo-accounts')->assertNotFound();

    // The deploy-time demo:seed step no longer re-seeds (even though .env still says demo is on).
    config(['demo.enabled' => true]);
    app()->forgetScopedInstances();
    app(DemoMode::class)->applyToConfig(); // what AppServiceProvider::boot() does on every request / deploy
    expect(config('demo.enabled'))->toBeFalse();
    $this->artisan('demo:seed')->expectsOutputToContain('Demo mode is off')->assertSuccessful();
    expect(User::where('phone_e164', S13_DEMO_PHONE)->value('status'))->toBe('SUSPENDED');
});

it('leaves real users and real records untouched', function () {
    seedDemoWorld();
    $real = realPolicy();
    $this->artisan('demo:exit --confirm')->assertSuccessful();
    app()->forgetScopedInstances();

    expect(User::where('phone_e164', '+237677445566')->value('status'))->toBe('ACTIVE')
        ->and(Policy::find($real->id))->not->toBeNull()
        ->and(DB::table('parties')->where('id', $real->party_id)->value('is_demo'))->toBeFalse();

    $this->postJson('/api/v1/auth/mobile/password-login', ['phone_e164' => '+237677445566', 'password' => 'Real@12345', 'device_fingerprint' => 'dev-real'])
        ->assertSuccessful();
});

it('demo:enter reverses demo:exit', function () {
    seedDemoWorld();
    $this->artisan('demo:exit --confirm')->assertSuccessful();
    app()->forgetScopedInstances();
    $this->artisan('demo:enter --confirm')->assertSuccessful();
    app()->forgetScopedInstances();

    expect(app(DemoMode::class)->stored())->toBeTrue()
        ->and(User::where('phone_e164', S13_DEMO_PHONE)->value('status'))->toBe('ACTIVE')
        ->and(DB::table('policies')->where('is_demo', true)->count())->toBeGreaterThan(0);
});

it('real OTP login fails clearly when no SMS provider is configured (S14 gate, still in force after demo:exit)', function () {
    seedDemoWorld();
    $this->artisan('demo:exit --confirm')->assertSuccessful();
    app()->forgetScopedInstances();
    // No SMS/WhatsApp provider configured on this host.
    app(\App\Application\Settings\PlatformSettings::class)->editable()->forceFill(['twilio_enabled' => false, 'etech_sms_enabled' => false, 'etech_whatsapp_enabled' => false])->save();

    $res = $this->postJson('/api/v1/auth/mobile/otp/request', ['phone_e164' => '+237677001122']);

    $res->assertStatus(422);
    expect((string) $res->json('message'))->toBe(__('sms_providers.otp_config_required'));
    // A former demo persona gets the same answer: no fixed code any more.
    $this->postJson('/api/v1/auth/mobile/otp/request', ['phone_e164' => S13_DEMO_PHONE])->assertStatus(422);
});
