<?php

declare(strict_types=1);

/**
 * S11 in-app help: every role guide exists in EN and FR with the same anchors; the Help page of each panel and of the
 * account area shows the signed-in role's guide (EN and FR), searches and links to the printable version; the contextual
 * "?" links of the key screens point to an existing section of a guide offered in that panel; markdown is rendered safely.
 */

use App\Application\Help\{HelpContext, HelpGuides};
use App\Application\Identity\RoleCatalogue;
use App\Models\{Role, Tenant, TenantMembership, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

require_once __DIR__.'/Concerns/provider_journey_fixture.php';

uses(RefreshDatabase::class);

function hgTenant(string $type): Tenant
{
    return Tenant::create(['type' => $type, 'legal_name' => 'HG '.$type.' '.Str::random(5), 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en', 'settings' => []]);
}

function hgUser(Tenant $tenant, string $role): User
{
    $user = User::factory()->create(['status' => 'ACTIVE', 'locale' => 'en']);
    $m = TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role_code' => $role, 'status' => 'ACTIVE']);
    $r = Role::firstOrCreate(['tenant_id' => $tenant->id, 'code' => $role], ['id' => (string) Str::uuid(), 'permissions' => RoleCatalogue::defaultPermissions($role), 'is_system' => true]);
    $m->roles()->syncWithoutDetaching([$r->id]);

    return $user;
}

it('has every guide in EN and FR with a title, sections and identical anchors', function () {
    foreach (HelpGuides::GUIDES as $g) {
        foreach (['en', 'fr'] as $l) {
            expect(is_file(HelpGuides::path($g, $l)))->toBeTrue("missing {$l}/{$g}.md");
            $guide = HelpGuides::load($g, $l);
            expect($guide['title'])->not->toBe('')
                ->and(count($guide['sections']))->toBeGreaterThanOrEqual(4)
                ->and(collect($guide['sections'])->pluck('id')->duplicates()->all())->toBe([]);
        }
        expect(HelpGuides::anchors($g, 'fr'))->toBe(HelpGuides::anchors($g, 'en'), "anchors differ for {$g}");
    }
    // every surface offers only real guides, and every guide is offered somewhere
    $offered = collect(HelpGuides::SURFACES)->flatten()->unique()->sort()->values()->all();
    expect($offered)->toBe(collect(HelpGuides::GUIDES)->sort()->values()->all());
});

it('renders markdown safely (raw HTML escaped, unsafe links dropped)', function () {
    $html = HelpGuides::markdown("<script>alert(1)</script>\n\n[x](javascript:alert(1)) **ok**");
    expect($html)->not->toContain('<script>')->not->toContain('javascript:')->toContain('<strong>ok</strong>');
    foreach (HelpGuides::GUIDES as $g) {
        foreach (['en', 'fr'] as $l) {
            $all = implode('', array_column(HelpGuides::load($g, $l)['sections'], 'html'));
            expect($all)->not->toContain('<script')->not->toContain('javascript:');
        }
    }
});

it('maps role codes to their guide', function () {
    $map = ['BROKER_ADMIN' => 'broker_admin', 'BROKER_SUPERVISOR' => 'broker_admin', 'BROKER_STAFF' => 'broker_staff', 'BRANCH_MANAGER' => 'branch_manager',
        'AGENT' => 'commercial_agent', 'CARRIER_ADMIN' => 'insurer_admin', 'CARRIER_SUPER_ADMIN' => 'insurer_admin', 'UNDERWRITER' => 'underwriter',
        'SENIOR_UNDERWRITER' => 'underwriter', 'CLAIMS_OFFICER' => 'claims_handler', 'CLAIMS_MANAGER' => 'claims_handler', 'ADJUSTER' => 'claims_handler',
        'FINANCE_OFFICER' => 'finance', 'FINANCE_MANAGER' => 'finance', 'PROVIDER_ADMIN' => 'provider', 'CUSTOMER' => 'customer', 'SYSTEM_ADMIN' => 'platform_admin',
        'PLATFORM_ADMIN' => 'platform_admin'];
    foreach ($map as $role => $guide) {
        expect(HelpGuides::guideForRole($role))->toBe($guide, $role);
    }
});

it('points every contextual help link to an existing section of a guide offered in a panel', function () {
    expect(count(HelpContext::MAP))->toBeGreaterThanOrEqual(20);
    foreach (HelpContext::MAP as $class => $candidates) {
        expect(class_exists($class))->toBeTrue("missing class {$class}");
        foreach ($candidates as [$guide, $anchor]) {
            foreach (['en', 'fr'] as $l) {
                expect(HelpGuides::anchors($guide, $l))->toContain($anchor);
            }
        }
        expect(collect(HelpGuides::SURFACES)->contains(fn (array $gs) => in_array($candidates[0][0], $gs, true)))->toBeTrue();
    }
});

dataset('portal roles', [
    'broker admin' => ['broker', 'BROKER', 'BROKER_ADMIN', 'broker_admin'],
    'broker staff' => ['broker', 'BROKER', 'BROKER_STAFF', 'broker_staff'],
    'branch manager' => ['broker', 'BROKER', 'BRANCH_MANAGER', 'branch_manager'],
    'insurer admin' => ['insurer', 'CARRIER', 'CARRIER_ADMIN', 'insurer_admin'],
    'underwriter' => ['insurer', 'CARRIER', 'UNDERWRITER', 'underwriter'],
    'claims officer' => ['insurer', 'CARRIER', 'CLAIMS_OFFICER', 'claims_handler'],
    'adjuster' => ['insurer', 'CARRIER', 'ADJUSTER', 'claims_handler'],
    'finance officer' => ['insurer', 'CARRIER', 'FINANCE_OFFICER', 'finance'],
    'platform admin' => ['admin', 'PLATFORM', 'SYSTEM_ADMIN', 'platform_admin'],
]);

it('shows the signed-in role\'s guide on the panel help page, EN and FR', function (string $panel, string $type, string $role, string $guide) {
    $user = hgUser(hgTenant($type), $role);
    $this->actingAs($user);
    foreach (['en', 'fr'] as $l) {
        $g = HelpGuides::load($guide, $l);
        $r = $this->get("/{$panel}/help?lang={$l}")->assertOk()
            ->assertSee($g['title'], false)
            ->assertSee('id="'.$g['sections'][0]['id'].'"', false)
            ->assertSee('/help/'.$guide.'/print?lang='.$l, false)
            ->assertSee('data-help-search', false);
        expect($r->getContent())->toContain($g['sections'][1]['title']);
    }
    // search narrows the sections; a nonsense query shows the empty state
    $first = HelpGuides::load($guide, 'en')['sections'];
    $word = Str::of(strip_tags($first[array_key_last($first)]['title']))->explode(' ')->sortByDesc(fn ($w) => mb_strlen($w))->first();
    $this->get("/{$panel}/help?lang=en&q=".urlencode($word))->assertOk()->assertSee('id="'.$first[array_key_last($first)]['id'].'"', false);
    $this->get("/{$panel}/help?lang=en&q=zzqxnothing")->assertOk()->assertSee(__('help_guides.no_results', [], 'en'));
})->with('portal roles');

it('lets a user open another guide of the panel, never one outside it', function () {
    $this->actingAs(hgUser(hgTenant('BROKER'), 'BROKER_STAFF'));
    $this->get('/broker/help?guide=finance&lang=en')->assertOk()->assertSee(HelpGuides::load('finance', 'en')['title']);
    $this->get('/broker/help?guide=provider&lang=en')->assertOk()->assertSee(HelpGuides::load('broker_staff', 'en')['title'])
        ->assertDontSee(HelpGuides::load('provider', 'en')['title']);
});

it('shows the provider guide on the provider panel help page', function () {
    pjFixture($this);
    pjActAs($this, $this->user, $this->clinic);
    foreach (['en', 'fr'] as $l) {
        $this->get("/provider/help?lang={$l}")->assertOk()->assertSee(HelpGuides::load('provider', $l)['title'], false)->assertSee('id="eligibility"', false);
    }
});

it('serves the account help page with the customer, agent and claims-officer guides, EN and FR', function () {
    foreach (['en', 'fr'] as $l) {
        $this->get("/account/help?lang={$l}")->assertOk()->assertSee(HelpGuides::load('customer', $l)['title'], false)->assertSee('id="claims"', false);
        $this->get("/account/help?guide=commercial_agent&lang={$l}")->assertOk()->assertSee(HelpGuides::load('commercial_agent', $l)['title'], false)->assertSee('id="leads"', false);
        $this->get("/account/help?guide=claims_handler&lang={$l}")->assertOk()->assertSee('id="claims-desk"', false);
    }
    $this->get('/account/help?guide=platform_admin&lang=en')->assertOk()->assertSee(HelpGuides::load('customer', 'en')['title'], false);
    $this->get('/account/help?q=zzqxnothing&lang=en')->assertOk()->assertSee(__('help_guides.no_results', [], 'en'));
    $this->get('/account?lang=en')->assertOk()->assertSee('href="/account/help"', false);
});

it('serves a printable version of every guide in EN and FR', function () {
    foreach (HelpGuides::GUIDES as $g) {
        foreach (['en', 'fr'] as $l) {
            $guide = HelpGuides::load($g, $l);
            $this->get("/help/{$g}/print?lang={$l}")->assertOk()->assertSee($guide['title'], false)
                ->assertSee('window.print()', false)->assertSee('@media print', false)->assertSee('id="'.$guide['sections'][0]['id'].'"', false);
        }
    }
    $this->get('/help/nobody/print')->assertNotFound();
});

it('renders the contextual "?" link on key screens and the link resolves to the section', function () {
    $this->actingAs(hgUser(hgTenant('BROKER'), 'BROKER_STAFF'));
    $html = $this->get('/broker/quotes?lang=en')->assertOk()->getContent();
    expect($html)->toContain('data-help-link')->toContain('/broker/help?guide=broker_staff#quotes');
    $this->get('/broker/help?guide=broker_staff')->assertOk()->assertSee('id="quotes"', false);
});

it('renders the contextual "?" link on the insurer underwriting screen', function () {
    $this->actingAs(hgUser(hgTenant('CARRIER'), 'UNDERWRITER'));
    $html = $this->get('/insurer/underwriting-cases?lang=fr')->assertOk()->getContent();
    expect($html)->toContain('/insurer/help?guide=underwriter#cases');
    $this->get('/insurer/help?guide=underwriter&lang=fr')->assertOk()->assertSee('id="cases"', false);
});
