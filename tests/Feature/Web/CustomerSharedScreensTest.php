<?php

declare(strict_types=1);

/**
 * Launch 2026-10-02 (Q2) — customer and shared screens (docs/LAUNCH_SCREEN_GAPS_2026-09-29.md §5):
 * CUST-008 /account/welcome, 009 /account/onboarding, 015/016 /account/kyc (status + remediation), 019 /account/actions,
 * 023 /account/products/{id}, 025 /account/needs, SHR-001/002 /account/search + staff /admin/search, 008 /account/activity,
 * 013 /account/complaints, 014 /account/complaints/{id}, 017 /account/messages, and the rebuilt /account dashboard.
 * Each page renders in EN and FR without raw keys and calls the app's /api/v1 endpoints; the data behind them answers for
 * the owner and refuses everybody else (other customer → 404 / empty, staff without permission → 403).
 */

use App\Application\Identity\RoleCatalogue;
use App\Models\{Role, Tenant, TenantMembership, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

const Q2_UUID = '6a1f2c3d-4b5e-4f60-8a7b-9c0d1e2f3a4b';

function q2Staff(Tenant $tenant, string $role): User
{
    $user = User::factory()->create(['status' => 'ACTIVE', 'locale' => 'en']);
    $m = TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role_code' => $role, 'status' => 'ACTIVE']);
    $r = Role::firstOrCreate(['tenant_id' => $tenant->id, 'code' => $role], ['id' => (string) Str::uuid(), 'permissions' => RoleCatalogue::defaultPermissions($role), 'is_system' => true]);
    $m->roles()->syncWithoutDetaching([$r->id]);

    return $user;
}

it('renders every Q2 customer/shared page in EN and FR with its API calls and no raw keys', function () {
    $id = Q2_UUID;
    $pages = [
        '/account/welcome' => ['data-welcome', '/account/onboarding'],
        '/account/onboarding' => ['data-onboarding', 'launch.js'],
        '/account/kyc' => ['/mobile/kyc/profile', 'data-kyc-remediation', 'kyc_steps'],
        '/account/actions' => ['data-actions', 'LC.actions()'],
        "/account/products/$id" => ['/catalogue/products/', '/risk-schema', '"exclusions":', '"requirements":'],
        '/account/needs' => ['data-needs', '/catalogue/products', '/account/products/'],
        '/account/search' => ['/search?', "types[]", 'data-filter'],
        '/account/activity' => ['/mobile/account/activity', '/me/security/login-activity'],
        '/account/complaints' => ['data-complaint-form', '/mobile/complaints'],
        "/account/complaints/$id" => ['/mobile/complaints/', 'data-complaint'],
        '/account/messages' => ['/mobile/notifications', 'LC.support()', 'LC.complaints()'],
        '/account' => ['data-actions', 'data-claims', 'data-due', 'data-renew', 'data-docs', '/mobile/documents', '/account/actions'],
    ];
    foreach (['en', 'fr'] as $lang) {
        foreach ($pages as $url => $markers) {
            $res = $this->get($url.'?lang='.$lang)->assertOk();
            $html = $res->getContent();
            foreach ($markers as $m) {
                expect(str_contains($html, $m))->toBeTrue("$url ($lang) lacks $m");
            }
            $text = strip_tags(preg_replace('#<script\b[^>]*>.*?</script>#s', '', $html));
            expect(preg_match('/\b(launch_customer|account_[a-z]+)\.[a-z_]+\.[a-z_.]+\b/', $text, $k) ? $k[0] : null)->toBeNull("$url ($lang) raw key");
            // Header search entry (SHR-001) on every account page.
            expect($html)->toContain('href="/account/search"');
        }
    }
    $this->get('/account/welcome?lang=fr')->assertSee('Compte créé')->assertSee('"cta":"Compléter mon profil"', false);
    $this->get('/account/complaints?lang=en')->assertSee('"send":"Send complaint"', false);
    $this->get('/account/support?lang=en')->assertSee('href="/account/complaints"', false)->assertSee('href="/account/messages"', false);
    $this->get('/account/profile?lang=fr')->assertSee('href="/account/onboarding"', false)->assertSee('Activité du compte');
});

it('keeps the launch_customer copy in sync between EN and FR', function () {
    $keys = function (array $a, string $p = '') use (&$keys): array {
        $o = [];
        foreach ($a as $k => $v) {
            $o = is_array($v) && ! array_is_list($v) ? [...$o, ...$keys($v, "$p.$k")] : [...$o, "$p.$k"];
        }

        return $o;
    };
    $en = $keys(require lang_path('en/launch_customer.php'));
    $fr = $keys(require lang_path('fr/launch_customer.php'));
    expect(array_values(array_diff($en, $fr)))->toBe([])->and(array_values(array_diff($fr, $en)))->toBe([]);
});

it('sends a new account to the Account created screen after sign-up', function () {
    $js = file_get_contents(public_path('landing/auth.js'));
    expect($js)->toContain("fresh ? '/account/welcome' : '/account'", 'signedIn(d, true)', "signedIn(r.body.data, pending.mode === 'register')");
});

it('files, lists and shows a complaint for its owner only (SHR-013 / SHR-014)', function () {
    Http::preventStrayRequests();
    $f = makeMobileCustomerFixture('+237672991001');
    makeMobileTestTenantCustomer($f['tenant'], $f['party']);
    $policy = makeMobileTestPolicy($f['proposal'], $f['tenant'], $f['carrier']->id, $f['party']->id);
    Passport::actingAs($f['user']);

    $this->postJson('/api/v1/mobile/complaints', ['description' => 'short'], agentHeaders($f))->assertUnprocessable();
    $c = $this->postJson('/api/v1/mobile/complaints', ['description' => 'My certificate was delivered two weeks late.', 'policy_id' => $policy->id], agentHeaders($f))
        ->assertCreated()->assertJsonPath('data.subject_type', 'policy')->assertJsonPath('data.open', true);
    $id = $c->json('data.id');
    expect($c->json('data.complaint_number'))->not->toBeEmpty();
    expect(DB::table('complaints')->where('id', $id)->value('channel'))->toBe('PORTAL');

    $this->getJson('/api/v1/mobile/complaints', tenantHeaderFor($f['tenant']))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
    $show = $this->getJson("/api/v1/mobile/complaints/$id", tenantHeaderFor($f['tenant']))->assertOk()->assertJsonPath('data.id', $id);
    expect($show->json('data.timeline'))->toBeArray()->and($show->json('data.correspondence'))->toBeArray()->not->toBeEmpty();
    expect($show->json('data'))->not->toHaveKey('owner_user_id');

    // Another customer's policy cannot be the subject.
    $other = makeMobileCustomerFixture('+237672991002');
    $otherPolicy = makeMobileTestPolicy($other['proposal'], $other['tenant'], $other['carrier']->id, $other['party']->id);
    $this->postJson('/api/v1/mobile/complaints', ['description' => 'This is about somebody else policy.', 'policy_id' => $otherPolicy->id], agentHeaders($f))->assertUnprocessable();

    // The other customer sees nothing and gets a 404 on the complaint.
    Passport::actingAs($other['user']);
    $this->getJson('/api/v1/mobile/complaints', tenantHeaderFor($other['tenant']))->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/mobile/complaints/$id", tenantHeaderFor($other['tenant']))->assertNotFound();
});

it('shows a customer only their own activity trail (SHR-008)', function () {
    $f = makeMobileCustomerFixture('+237672991003');
    $other = makeMobileCustomerFixture('+237672991004');
    // Written through the real AuditWriter (hash-chained, append-only): actor = auth user, tenant = context.
    foreach ([[$f, 'mine.one'], [$other, 'theirs.one']] as [$who, $action]) {
        $this->actingAs($who['user']);
        app(\App\Domain\Tenancy\TenantContext::class)->set($who['tenant']->id);
        app(\App\Application\Audit\AuditWriter::class)->record($action, 'policy', null);
    }
    // Same user, other tenant: not shown either.
    app(\App\Domain\Tenancy\TenantContext::class)->set($other['tenant']->id);
    $this->actingAs($f['user']);
    app(\App\Application\Audit\AuditWriter::class)->record('mine.elsewhere', 'policy', null);
    Passport::actingAs($f['user']);
    $actions = collect($this->getJson('/api/v1/mobile/account/activity', tenantHeaderFor($f['tenant']))->assertOk()->json('data'))->pluck('action');
    expect($actions)->toContain('mine.one')->not->toContain('theirs.one')->not->toContain('mine.elsewhere');
    expect($this->getJson('/api/v1/mobile/account/activity', tenantHeaderFor($f['tenant']))->json('data.0'))->not->toHaveKey('ip_address');
});

it('scopes the search the customer pages call to the customer\'s own rows (SHR-001 / SHR-002)', function () {
    $f = makeMobileCustomerFixture('+237672991005');
    $mine = makeMobileTestPolicy($f['proposal'], $f['tenant'], $f['carrier']->id, $f['party']->id, ['policy_number' => 'POL-Q2-MINE']);
    $other = makeMobileCustomerFixture('+237672991006');
    makeMobileTestPolicy($other['proposal'], $f['tenant'], $other['carrier']->id, $other['party']->id, ['policy_number' => 'POL-Q2-THEIRS']);
    Passport::actingAs($f['user']);
    $r = $this->getJson('/api/v1/search?q=POL-Q2&types[]=policies&limit=10', tenantHeaderFor($f['tenant']))->assertOk();
    $ids = collect($r->json('data.results'))->pluck('id');
    expect($ids)->toContain($mine->id)->toHaveCount(1);
    expect($r->json('data.searched'))->not->toContain('customers');
});

it('opens staff global search only for roles with a read permission and keeps it tenant-scoped', function () {
    $tenant = Tenant::create(['type' => 'CARRIER', 'legal_name' => 'Q2 Carrier '.Str::random(5), 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en', 'settings' => []]);
    $f = makeMobileCustomerFixture('+237672991007');
    makeMobileTestPolicy($f['proposal'], $f['tenant'], $f['carrier']->id, $f['party']->id, ['policy_number' => 'POL-Q2-OTHERTENANT']);
    $g = makeMobileCustomerFixture('+237672991008');
    makeMobileTestPolicy($g['proposal'], $tenant, $g['carrier']->id, $g['party']->id, ['policy_number' => 'POL-Q2-HERE']);
    $seenHere = false;

    $allowedSeen = 0;
    foreach (['PLATFORM_ADMIN', 'CLAIMS_OFFICER', 'FINANCE_MANAGER', 'COMPLIANCE_ADMIN', 'UNDERWRITER'] as $role) {
        $user = q2Staff($tenant, $role);
        app(\App\Domain\Tenancy\TenantContext::class)->set($tenant->id);
        $can = collect(['customers.read', 'policies.read', 'claims.view', 'quotes.read', 'documents.read', 'risk_assets.read'])->contains(fn ($p) => $user->hasPermission($p));
        foreach (['en', 'fr'] as $lang) {
            $this->flushSession();
            auth()->forgetGuards();
            $this->actingAs($user);
            app(\App\Domain\Tenancy\TenantContext::class)->set($tenant->id);
            $panel = $this->get('/admin?lang='.$lang)->status() === 200; // roles outside the admin panel are refused by the panel itself
            $res = $this->get('/admin/search?q=POL-Q2&lang='.$lang);
            expect($res->status())->toBe($can && $panel ? 200 : 403, "$role /admin/search");
            $can = $can && $panel;
            if ($can) {
                $allowedSeen++;
                $res->assertDontSee('POL-Q2-OTHERTENANT')->assertDontSee('launch_customer.');
                $res->assertSee($lang === 'fr' ? 'Recherche globale' : 'Global search');
                $seenHere = $seenHere || str_contains($res->getContent(), 'POL-Q2-HERE');
            }
        }
    }
    expect($allowedSeen)->toBeGreaterThan(0)->and($seenHere)->toBeTrue();
});
