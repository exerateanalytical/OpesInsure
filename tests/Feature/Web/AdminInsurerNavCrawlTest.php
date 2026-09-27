<?php

declare(strict_types=1);

/**
 * UI audit 2026-09-27 (docs/UI_AUDIT_ADMIN_INSURER_2026-09-27.md): signs in as every platform-admin,
 * insurer-admin and insurer-staff role with the role's CATALOGUE default permissions
 * (RoleCatalogue::defaultPermissions, not '*'), reads the sidebar the role actually gets, and requests
 * every nav URL plus the first row's view page. No 500s, no 403/404 behind a visible nav item, no empty nav.
 */

use App\Application\Identity\RoleCatalogue;
use App\Models\{Role, Tenant, TenantMembership, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

uses(RefreshDatabase::class);

function crawlTenant(string $type): Tenant
{
    return Tenant::create(['type' => $type, 'legal_name' => 'Crawl '.$type.' '.Str::random(5), 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en', 'settings' => []]);
}

function crawlUser(Tenant $tenant, string $role, ?string $carrierId = null): User
{
    $user = User::factory()->create(['status' => 'ACTIVE', 'locale' => 'en']);
    $m = TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role_code' => $role, 'status' => 'ACTIVE', 'carrier_id' => $carrierId]);
    $r = Role::firstOrCreate(['tenant_id' => $tenant->id, 'code' => $role], ['id' => (string) Str::uuid(), 'permissions' => RoleCatalogue::defaultPermissions($role), 'is_system' => true]);
    $m->roles()->syncWithoutDetaching([$r->id]);

    return $user;
}

/** @return list<array{href:string,label:string,group:string}> sidebar links of the rendered page, in order, with their group. */
function crawlSidebar(string $html, string $panel): array
{
    $out = [];
    // Split on group containers; the chunk before the first group holds the ungrouped items.
    $chunks = preg_split('#(?=<li\b[^>]*data-group-label=")#', $html);
    foreach ($chunks as $chunk) {
        $group = preg_match('#^<li\b[^>]*data-group-label="([^"]*)"#', $chunk, $g) ? html_entity_decode($g[1]) : '';
        preg_match_all('#<a\b([^>]*fi-sidebar-item-btn[^>]*)>(.*?)</a>#s', $chunk, $m, PREG_SET_ORDER);
        foreach ($m as $x) {
            if (preg_match('#href="(?:https?://[^/"]+)?(/'.$panel.'(?:/[^"?\#]*)?)"#', $x[1], $h)) {
                $out[] = ['href' => $h[1], 'label' => html_entity_decode(trim(preg_replace('/\s+/', ' ', strip_tags($x[2])))), 'group' => $group];
            }
        }
    }

    return $out;
}

/** First record link of a list page (row → view page), if any. */
function crawlFirstRowLink(string $html, string $listPath): ?string
{
    $q = preg_quote($listPath, '#');
    if (preg_match('#href="(?:https?://[^/"]+)?('.$q.'/[0-9a-f-]{36}(?:/[a-z-]+)?)"#', $html, $m)) {
        return $m[1];
    }

    return null;
}

dataset('crawl_roles', [
    'SYSTEM_ADMIN (admin)' => ['admin', 'PLATFORM', 'SYSTEM_ADMIN'],
    'PLATFORM_ADMIN (admin)' => ['admin', 'PLATFORM', 'PLATFORM_ADMIN'],
    'CLAIMS_MANAGER (admin)' => ['admin', 'CARRIER', 'CLAIMS_MANAGER'],
    'CLAIMS_OFFICER (admin)' => ['admin', 'CARRIER', 'CLAIMS_OFFICER'],
    'FINANCE_MANAGER (admin)' => ['admin', 'CARRIER', 'FINANCE_MANAGER'],
    'CARRIER_SUPER_ADMIN (insurer)' => ['insurer', 'CARRIER', 'CARRIER_SUPER_ADMIN'],
    'CARRIER_ADMIN (insurer)' => ['insurer', 'CARRIER', 'CARRIER_ADMIN'],
    'CARRIER_STAFF (insurer)' => ['insurer', 'CARRIER', 'CARRIER_STAFF'],
    'UNDERWRITER (insurer)' => ['insurer', 'CARRIER', 'UNDERWRITER'],
    'SENIOR_UNDERWRITER (insurer)' => ['insurer', 'CARRIER', 'SENIOR_UNDERWRITER'],
    'REINSURANCE_OFFICER (insurer)' => ['insurer', 'CARRIER', 'REINSURANCE_OFFICER'],
    'CUSTOMER_SERVICE (insurer)' => ['insurer', 'CARRIER', 'CUSTOMER_SERVICE'],
    'ADJUSTER (insurer)' => ['insurer', 'CARRIER', 'ADJUSTER'],
    'FINANCE_OFFICER (insurer)' => ['insurer', 'CARRIER', 'FINANCE_OFFICER'],
]);

it('every nav URL renders for the role, with no 500 and no dead link', function (string $panel, string $tenantType, string $role) {
    if (($only = getenv('CRAWL_ONLY')) && ! in_array($role, explode(',', $only), true)) {
        $this->markTestSkipped('CRAWL_ONLY');
    }
    $tenant = crawlTenant($tenantType);
    $chain = makeMobileFinanceProposalChain($tenant);
    makeMobileTestPolicy($chain['proposal'], $tenant, $chain['carrier']->id, $chain['party']->id, ['policy_number' => 'POL-CRAWL-1', 'coverage_ends_at' => now()->addDays(20), 'premium_minor' => 50000, 'currency' => 'XAF']);
    $user = crawlUser($tenant, $role, $panel === 'insurer' ? $chain['carrier']->id : null);

    $home = $this->actingAs($user)->get("/{$panel}");
    expect($home->status())->toBe(200, "{$role}: /{$panel} returned {$home->status()}");

    if (getenv('UI_AUDIT_DUMP')) { @mkdir(getenv('UI_AUDIT_DUMP'), 0777, true); file_put_contents(getenv('UI_AUDIT_DUMP')."/{$panel}-{$role}.html", $home->getContent()); }
    $nav = crawlSidebar($home->getContent(), $panel);
    expect($nav)->not->toBeEmpty("{$role}: empty sidebar on /{$panel}");

    $hrefs = array_column($nav, 'href');
    $dupes = array_keys(array_filter(array_count_values($hrefs), fn ($n) => $n > 1));
    $labels = array_map(fn ($n) => $n['group'].' / '.$n['label'], $nav);
    $untranslated = array_values(array_filter([...$labels], fn ($l) => preg_match('/\b[a-z_]+\.[a-z_]+\.[a-z_]+/', $l) === 1));
    $dupeLabels = array_keys(array_filter(array_count_values($labels), fn ($n) => $n > 1));

    $failures = [];
    $report = [];
    foreach (array_unique($hrefs) as $href) {
        $res = $this->get($href);
        $status = $res->status();
        $row = ['href' => $href, 'status' => $status];
        if ($status !== 200) {
            $failures[] = "{$href} → {$status}";
        } elseif (($row_link = crawlFirstRowLink($res->getContent(), $href)) !== null) {
            $s2 = $this->get($row_link)->status();
            $row['row'] = [$row_link, $s2];
            if ($s2 >= 500 || $s2 === 404) {
                $failures[] = "{$row_link} (row of {$href}) → {$s2}";
            }
        }
        $report[] = $row;
    }

    if (getenv('UI_AUDIT_DUMP')) {
        @mkdir(getenv('UI_AUDIT_DUMP'), 0777, true);
        file_put_contents(getenv('UI_AUDIT_DUMP')."/{$panel}-{$role}.json", json_encode(['nav' => $nav, 'report' => $report, 'failures' => $failures, 'dupes' => $dupes, 'dupe_labels' => $dupeLabels], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    expect($failures)->toBe([], "{$role} on /{$panel}: ".implode('; ', $failures))
        ->and($untranslated)->toBe([], "{$role}: untranslated nav labels ".implode(', ', $untranslated))
        ->and($dupes)->toBe([], "{$role}: duplicate nav URLs ".implode(', ', $dupes))
        ->and($dupeLabels)->toBe([], "{$role}: duplicate nav labels ".implode(', ', $dupeLabels));
})->with('crawl_roles');

it('staff roles without a web panel are refused cleanly (403 page, never 500)', function () {
    $tenant = crawlTenant('CARRIER');
    foreach (['CASHIER', 'REGULATOR'] as $role) {
        $u = crawlUser($tenant, $role);
        foreach (['/admin', '/insurer'] as $p) {
            expect($this->actingAs($u)->get($p)->status())->toBeLessThan(500);
        }
    }
});

it('insurer admins and staff reach the records their carrier.* permissions allow (nav and dashboard widgets)', function () {
    $tenant = crawlTenant('CARRIER');
    $chain = makeMobileFinanceProposalChain($tenant);
    makeMobileTestPolicy($chain['proposal'], $tenant, $chain['carrier']->id, $chain['party']->id, ['policy_number' => 'POL-CRAWL-2', 'coverage_ends_at' => now()->addDays(20), 'premium_minor' => 50000, 'currency' => 'XAF']);
    $expect = [
        'CARRIER_SUPER_ADMIN' => ['/insurer/policies', '/insurer/claims', '/insurer/agreements', '/insurer/reports'],
        'CARRIER_ADMIN' => ['/insurer/policies', '/insurer/claims', '/insurer/agreements', '/insurer/reports'],
        'CARRIER_STAFF' => ['/insurer/policies', '/insurer/claims'],
        'ADJUSTER' => ['/insurer/claims'],
    ];
    foreach ($expect as $role => $paths) {
        $this->flushSession();
        app()->forgetInstance(\Filament\Navigation\NavigationManager::class); // scoped singleton: rebuild the sidebar for the next user
        $home = $this->actingAs(crawlUser($tenant, $role, $chain['carrier']->id))->get('/insurer')->assertOk();
        $hrefs = array_column(crawlSidebar($home->getContent(), 'insurer'), 'href');
        foreach ($paths as $p) {
            expect($hrefs)->toContain($p);
        }
        if (in_array('/insurer/policies', $paths, true)) {
            $home->assertSee(__('dashboards.widgets.expiring_policies'))->assertSee('POL-CRAWL-2');
            $this->get('/insurer/policies')->assertOk()->assertSee('POL-CRAWL-2');
        }
        $home->assertSee(__('dashboards.widgets.open_claims'));
    }
});

it('the sidebar is sentence case, unambiguous and French for a French user', function () {
    $tenant = crawlTenant('PLATFORM');
    $en = crawlUser($tenant, 'PLATFORM_ADMIN');
    $nav = crawlSidebar($this->actingAs($en)->get('/admin')->assertOk()->getContent(), 'admin');
    $labels = array_column($nav, 'label');
    expect($labels)->toContain('CIMA overview', 'Document register', 'Integration health', 'Compliance cases', 'Customers')
        ->not->toContain('Compliance Cases', 'Tenant Customers')
        ->and(array_unique(array_column($nav, 'group')))->toContain('Administration');
    expect(collect($nav)->where('group', '')->pluck('label')->all())->toBe(['Dashboard', 'Reports']);

    $fr = User::factory()->create(['status' => 'ACTIVE', 'locale' => 'fr']);
    TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $fr->id, 'role_code' => 'PLATFORM_ADMIN', 'status' => 'ACTIVE'])
        ->roles()->syncWithoutDetaching([Role::where('tenant_id', $tenant->id)->where('code', 'PLATFORM_ADMIN')->value('id')]);
    $this->flushSession();
        app()->forgetInstance(\Filament\Navigation\NavigationManager::class); // scoped singleton: rebuild the sidebar for the next user
    $navFr = crawlSidebar($this->actingAs($fr)->get('/admin')->assertOk()->getContent(), 'admin');
    expect(array_column($navFr, 'label'))->toContain('Tableau de bord', 'Sinistres', 'Polices', 'Registre des documents')->not->toContain('Claims', 'Policies')
        ->and(array_column($navFr, 'group'))->toContain('Opérations financières', 'Gestion des sinistres')->not->toContain('Financial operations');
});

it('every list page in the admin and insurer panels opens a record (view/edit page or a row URL)', function () {
    $inlineOnly = [\App\Filament\Admin\Resources\ApprovalRequests\ApprovalRequestResource::class]; // row "Details" slide-over with approve/reject
    $missing = [];
    foreach (['admin', 'insurer'] as $id) {
        foreach (\Filament\Facades\Filament::getPanel($id)->getResources() as $resource) {
            $pages = array_keys($resource::getPages());
            $source = file_get_contents((new ReflectionClass($resource))->getFileName());
            if (! array_intersect(['view', 'edit'], $pages) && ! str_contains($source, 'recordUrl(') && ! in_array($resource, $inlineOnly, true)) {
                $missing[] = $resource;
            }
        }
    }
    expect(array_unique($missing))->toBe([]);
});

it('insurer panel exposes the sections each insurer role\'s permissions allow (approval inbox, underwriting, reinsurance, finance)', function () {
    $tenant = crawlTenant('CARRIER');
    $chain = makeMobileFinanceProposalChain($tenant);
    $expect = [
        'CARRIER_ADMIN' => ['/insurer/approvals/inbox', '/insurer/policy-issuances', '/insurer/quotes', '/insurer/quote-requests', '/insurer/referrals', '/insurer/sticker-batches'],
        'UNDERWRITER' => ['/insurer/underwriting-cases', '/insurer/referrals', '/insurer/quote-requests', '/insurer/coinsurance'],
        'REINSURANCE_OFFICER' => ['/insurer/reinsurance-treaties', '/insurer/reinsurance-cessions', '/insurer/coinsurance', '/insurer/fx-rates'],
        'FINANCE_OFFICER' => ['/insurer/journals', '/insurer/cashier-sessions', '/insurer/fx-rates', '/insurer/carrier-settlements', '/insurer/commission-accruals', '/insurer/policy-issuances'],
    ];
    foreach ($expect as $role => $paths) {
        $this->flushSession();
        app()->forgetInstance(\Filament\Navigation\NavigationManager::class); // scoped singleton: rebuild the sidebar for the next user
        $home = $this->actingAs(crawlUser($tenant, $role, $chain['carrier']->id))->get('/insurer')->assertOk();
        $hrefs = array_column(crawlSidebar($home->getContent(), 'insurer'), 'href');
        foreach ($paths as $p) {
            expect(collect($hrefs)->contains(fn ($h) => str_starts_with($h, $p)))->toBeTrue("{$role} missing {$p}");
            $this->get(collect($hrefs)->first(fn ($h) => str_starts_with($h, $p)))->assertOk();
        }
    }
    // Read-only: no create button for quotes in the insurer panel.
    $this->flushSession();
        app()->forgetInstance(\Filament\Navigation\NavigationManager::class); // scoped singleton: rebuild the sidebar for the next user
    $this->actingAs(crawlUser($tenant, 'CARRIER_SUPER_ADMIN', $chain['carrier']->id));
    \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('insurer'));
    app(\App\Domain\Tenancy\TenantContext::class)->set($tenant->id);
    expect(\Illuminate\Support\Facades\Gate::allows('create', \App\Models\Quote::class))->toBeFalse();
});

it('shared list columns: money in FCFA, status badge tones, dates in the viewer timezone', function () {
    $row = new \App\Models\RegisterRow;
    $row->setRawAttributes(['amount_minor' => 1234500, 'currency' => 'XAF', 'status' => 'REJECTED']);
    expect(\App\Filament\Shared\Columns::humanise('PENDING_REVIEW'))->toBe('Pending review')
        ->and(\App\Filament\Shared\Components\RecordInfolist::color('REJECTED'))->toBe('danger')
        ->and(\App\Application\WebExperiences\Money::display(1234500, 'XAF', 'en'))->toBe("12,345\u{00A0}FCFA");
    \App\Filament\Shared\Columns::applyDefaults();
    expect(\Filament\Support\Facades\FilamentTimezone::get())->toBe(app(\App\Application\Temporal\TimezoneResolver::class)->forUser(null));
});