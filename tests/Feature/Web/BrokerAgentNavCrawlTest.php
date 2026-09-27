<?php

declare(strict_types=1);

/**
 * UI audit 2026-09-27 (docs/UI_AUDIT_BROKER_AGENT_2026-09-27.md): broker portal (/broker) per broker role,
 * and the web account area (/account) used by agents, broker staff and customers.
 * Every navigation URL a role can see must render: no 500, no 403, no 404 (dead link), and no raw
 * translation keys. Roles use their governed default permissions (RoleCatalogue), never '*'.
 */

use App\Application\Identity\RoleCatalogue;
use App\Models\{Role, Tenant, TenantMembership, User};
use App\Providers\Filament\BrokerPanelProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function brokerCrawlTenant(string $type = 'BROKER'): Tenant
{
    return Tenant::create(['type' => $type, 'legal_name' => 'Crawl '.$type.' '.Str::random(4), 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en', 'settings' => []]);
}

function brokerCrawlUser(Tenant $tenant, string $role): User
{
    $user = User::factory()->create(['status' => 'ACTIVE']);
    $m = TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role_code' => $role, 'status' => 'ACTIVE']);
    $r = Role::firstOrCreate(['tenant_id' => $tenant->id, 'code' => $role], ['id' => (string) Str::uuid(), 'permissions' => RoleCatalogue::defaultPermissions($role), 'is_system' => true]);
    $m->roles()->syncWithoutDetaching([$r->id]);

    return $user;
}

/** @return list<string> every link in the rendered Filament sidebar of the page at $url. */
function brokerCrawlSidebar(string $html): array
{
    $nav = Str::before(Str::after($html, 'fi-sidebar-nav-groups'), '</nav>');
    preg_match_all('#href="([^"]+)"#', $nav, $m);

    return array_values(array_unique(array_map(fn (string $u): string => html_entity_decode($u), $m[1])));
}

/** GET each URL; returns the failures ("status url" or "raw-key url"). */
function brokerCrawlUrls($test, array $urls): array
{
    $bad = [];
    foreach ($urls as $url) {
        $res = $test->get($url);
        $status = $res->getStatusCode();
        if ($status !== 200) {
            $bad[] = $status.' '.$url;
        } elseif (preg_match('/\b(filament-[a-z-]+|web_experience|partner_portal|organisation_settings|dashboards)(::|\.)[a-z_]+\.[a-z_.]+\b/', strip_tags($res->getContent()), $raw)) {
            $bad[] = 'raw-key '.$raw[0].' '.$url;
        }
    }

    return $bad;
}

dataset('broker crawl roles', [
    // role => nav URLs that must be present (the role's core work)
    'BROKER_ADMIN' => ['BROKER_ADMIN', ['/broker/commission-accruals', '/broker/memberships', '/account/customers', '/account/buy', '/account/buy?new_client=1', '/account/commissions']],
    'BROKER_SUPERVISOR' => ['BROKER_SUPERVISOR', ['/broker/commission-accruals', '/account/customers', '/account/buy', '/account/buy?new_client=1', '/account/leads']],
    'BROKER_STAFF' => ['BROKER_STAFF', ['/broker/commission-accruals', '/account/customers', '/account/buy', '/account/buy?new_client=1']],
    'BRANCH_MANAGER' => ['BRANCH_MANAGER', ['/broker/policies', '/broker/claims', '/account/leads']],
]);

it('crawls every broker-portal nav URL for the role (EN and FR) without 500s, 403s, dead links or raw keys', function (string $role, array $expected) {
    $this->actingAs(brokerCrawlUser(brokerCrawlTenant(), $role));

    foreach (['fr', 'en'] as $lang) {
        $html = $this->get('/broker?lang='.$lang)->assertOk()->getContent();
        $urls = array_values(array_filter(brokerCrawlSidebar($html), fn (string $u): bool => ! str_contains($u, '?lang=')));
        $paths = array_map(fn (string $u): string => Str::after($u, 'opesinsure.test') ?: $u, $urls);
        foreach ($expected as $path) {
            expect($paths)->toContain($path);
        }
        expect(brokerCrawlUrls($this, $urls))->toBe([]);
    }
})->with('broker crawl roles');

it('labels the broker portal in French (nav groups, organisation settings, table counts)', function () {
    $this->actingAs(brokerCrawlUser(brokerCrawlTenant(), 'BROKER_ADMIN'));

    $this->get('/broker/commission-accruals?lang=fr')->assertOk()
        ->assertSee('Opérations financières')->assertSee('Espace partenaire')->assertSee('Mes clients')
        ->assertSee("Paramètres de l'organisation")->assertSee('Montant')->assertDontSee('filament-tables::')
        ->assertDontSee('Financial operations')->assertDontSee('Amount minor');
    $this->get('/broker/organisation-settings')->assertOk()->assertSee('Mes préférences')->assertSee('Enregistrer')->assertDontSee('My preferences');
});

it('hides partner-workspace links from roles without the target API permission', function () {
    $tenant = brokerCrawlTenant();
    $this->actingAs(brokerCrawlUser($tenant, 'BRANCH_MANAGER'));
    app(\App\Domain\Tenancy\TenantContext::class)->set($tenant->id);
    $visible = collect(BrokerPanelProvider::workspaceItems())->filter->isVisible()->map->getUrl()->values()->all();
    // Branch managers carry crm.leads.* but not broker.portal.read / quotes.manage / broker.finance.read.
    expect($visible)->toContain('/account/leads', '/account/buy?new_client=1')
        ->not->toContain('/account/customers')->not->toContain('/account/buy')->not->toContain('/account/commissions');
});

it('crawls every link of the web account side navigation (customer, agent, broker and officer entries)', function () {
    $html = $this->get('/account')->assertOk()->getContent();
    $side = Str::before(Str::after($html, 'class="acct-side"'), '</nav>');
    preg_match_all('#href="(/account[^"]*)"#', $side, $m);
    $urls = array_values(array_unique($m[1]));

    expect($urls)->toContain('/account/policies', '/account/quotes', '/account/claims', '/account/documents', '/account/customers', '/account/leads', '/account/commissions', '/account/claims-desk');
    expect(brokerCrawlUrls($this, $urls))->toBe([]);
    foreach (['en', 'fr'] as $lang) {
        expect(brokerCrawlUrls($this, array_map(fn ($u) => $u.'?lang='.$lang, $urls)))->toBe([]);
    }
});

it('gives partners an entry point to onboard a new client from their client list', function () {
    $this->get('/account/customers')->assertOk()->assertSee('/account/buy?new_client=1', false)->assertSee('data-new-client-link', false);
    $this->get('/account/buy?new_client=1')->assertOk()->assertSee("params.get('new_client') === '1'", false);
});
