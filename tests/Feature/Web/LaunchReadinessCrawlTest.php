<?php

declare(strict_types=1);

/**
 * Launch QA 2026-10-02 (agent P10, docs/LAUNCH_SCREEN_GAPS_2026-09-29.md): for EVERY role in RoleCatalogue, sign in
 * with the role's governed default permissions (never '*'), open every panel the role can enter (/admin, /insurer,
 * /broker, /provider), and request every sidebar link — plus the first row of each list — in English and French.
 * Hard failures: any 500, any 403/404 behind a visible link, raw translation keys in the page text.
 * French leaks (a nav label or page heading still in English in FR where resources/lang/fr.json has no entry) are
 * collected and reported (LAUNCH_CRAWL_DUMP=<dir> writes one JSON per role); they fail only with LAUNCH_STRICT_FR=1.
 * The /account area is crawled for customer, agent, broker and officer roles in EN and FR.
 * Split runs: LAUNCH_CRAWL_ONLY=ROLE1,ROLE2.
 */

use App\Application\Identity\RoleCatalogue;
use App\Application\Providers\ProviderRegistry;
use App\Models\{Party, Role, Tenant, TenantMembership, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

uses(RefreshDatabase::class);

const LAUNCH_PROVIDER_ROLE = [
    'PROVIDER_ADMIN' => 'PROVIDER_ADMIN', 'PROVIDER_FRONT_DESK' => 'FRONT_DESK', 'PROVIDER_DOCTOR' => 'PRACTITIONER', 'PROVIDER_BILLING' => 'BILLING_OFFICER',
    'PROVIDER_PHARMACY' => 'PHARMACIST', 'PROVIDER_LAB' => 'LAB_TECHNICIAN', 'PROVIDER_FINANCE' => 'FINANCE_OFFICER',
];

function launchTenantType(string $role): string
{
    return match (true) {
        in_array($role, ['SYSTEM_ADMIN', 'DEVELOPER', 'PLATFORM_ADMIN', 'REGULATOR'], true) => 'PLATFORM',
        in_array($role, ['BROKER_ADMIN', 'BROKER_SUPERVISOR', 'BROKER_STAFF', 'AGENT', 'BRANCH_MANAGER'], true) => 'BROKER',
        in_array($role, RoleCatalogue::PROVIDER_ROLES, true) => 'INSURER',
        default => 'CARRIER',
    };
}

function launchTenant(string $type): Tenant
{
    return Tenant::create(['type' => $type, 'legal_name' => 'Launch '.$type.' '.Str::random(5), 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en', 'settings' => []]);
}

function launchUser(Tenant $tenant, string $role, ?string $carrierId = null, ?string $partyId = null): User
{
    $user = User::factory()->create(['status' => 'ACTIVE', 'locale' => 'en', 'party_id' => $partyId]);
    $m = TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role_code' => $role, 'status' => 'ACTIVE',
        'carrier_id' => in_array($role, RoleCatalogue::CARRIER_LINKABLE_ROLES, true) || in_array($role, ['UNDERWRITER', 'SENIOR_UNDERWRITER', 'REINSURANCE_OFFICER', 'CUSTOMER_SERVICE', 'ADJUSTER'], true) ? $carrierId : null]);
    $r = Role::firstOrCreate(['tenant_id' => $tenant->id, 'code' => $role], ['id' => (string) Str::uuid(), 'permissions' => RoleCatalogue::defaultPermissions($role), 'is_system' => true]);
    $m->roles()->syncWithoutDetaching([$r->id]);

    return $user;
}

/** A provider-side user attached to an ACTIVE clinic (party relationship + provider_users row when the role maps). */
function launchProviderUser(Tenant $tenant, string $role): User
{
    $reg = app(ProviderRegistry::class);
    $clinic = $reg->register(['category' => 'HEALTH', 'name' => 'Clinique Launch '.Str::random(3), 'provider_type_code' => 'CLINIC'], null);
    foreach (['APPLICATION', 'UNDER_REVIEW', 'APPROVED', 'ACTIVE'] as $to) {
        $reg->transition($clinic->id, $to, null, null, null);
    }
    $reg->addFacility($clinic->id, ['code' => 'MAIN', 'name' => 'Main']);
    $person = Party::create(['type' => 'PERSON', 'display_name' => 'Staff '.Str::random(4), 'status' => 'ACTIVE']);
    DB::table('party_relationships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => null, 'from_party_id' => $person->id, 'to_party_id' => $clinic->party_id,
        'type' => 'EMPLOYED_BY', 'status' => 'ACTIVE', 'details' => '{}', 'created_at' => now(), 'updated_at' => now()]);
    $user = launchUser($tenant, $role, null, $person->id);
    if ($pr = LAUNCH_PROVIDER_ROLE[$role] ?? null) {
        DB::table('provider_users')->insert(['id' => (string) Str::uuid(), 'provider_profile_id' => $clinic->id, 'user_id' => $user->id, 'provider_role' => $pr,
            'facility_scope' => 'ALL', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
    }

    return $user;
}

/** @return list<array{href:string,label:string,group:string}> the sidebar links of a Filament page, with their group. */
function launchSidebar(string $html, string $panel): array
{
    $out = [];
    foreach (preg_split('#(?=<li\b[^>]*data-group-label=")#', $html) as $chunk) {
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

function launchHeading(string $html): ?string
{
    return preg_match('#<h1\b[^>]*>(.*?)</h1>#s', $html, $m) ? html_entity_decode(trim(preg_replace('/\s+/', ' ', strip_tags($m[1])))) : null;
}

/** Visible text of the page (scripts, styles and attributes removed). */
function launchText(string $html): string
{
    return html_entity_decode(strip_tags(preg_replace(['#<script\b[^>]*>.*?</script>#s', '#<style\b[^>]*>.*?</style>#s', '#<template\b[^>]*>.*?</template>#s'], ' ', $html)));
}

/** A raw translation key in the visible text ("group.key", "group.a.b" for a known lang group, or "vendor::group.key"). */
function launchRawKey(string $text): ?string
{
    static $groups = null;
    $groups ??= implode('|', array_map(fn ($f) => preg_quote(basename($f, '.php'), '#'), glob(lang_path('en/*.php')) ?: glob(resource_path('lang/en/*.php'))));
    if (preg_match('#(?<![\w/@.-])(?:[a-z-]+::[a-z_-]+\.[a-z0-9_.-]+|(?:'.$groups.')\.[a-z][a-z0-9_]*(?:\.[a-z0-9_]+)*)(?![\w/(@-])#', $text, $m)) {
        return $m[0];
    }

    return null;
}

/** True when an English label shown to a French user has no fr.json entry (and is a real word, not a code/acronym). */
function launchEnglishOnly(string $label): bool
{
    static $fr = null;
    static $same = null;
    $fr ??= json_decode((string) file_get_contents(lang_path('fr.json')), true) ?: [];
    // Words spelled the same in both languages ("Documents", "Administration", "Bordereaux") are French already.
    $same ??= array_flip([...array_values($fr), ...array_values((array) trans('navigation.groups', [], 'fr')), ...array_values((array) trans('navigation.labels', [], 'fr')), 'Documents', 'Audit', 'Bordereaux', 'Administration']);

    return preg_match('/[a-z]{3,}/', $label) === 1 && ! array_key_exists($label, $fr) && ! isset($same[$label]);
}

function launchFirstRow(string $html, string $listPath): ?string
{
    $q = preg_quote($listPath, '#');

    return preg_match('#href="(?:https?://[^/"]+)?('.$q.'/[0-9a-f-]{36}(?:/[a-z-]+)?)"#', $html, $m) ? $m[1] : null;
}

dataset('launch_roles', array_combine(
    $r = array_values(array_diff(array_keys(RoleCatalogue::LABELS), ['CUSTOMER'])),
    array_map(fn ($x) => [$x], $r),
));

it('crawls every panel nav link for the role in EN and FR: no 500, no 403 behind a link, no raw keys', function (string $role) {
    if (($only = getenv('LAUNCH_CRAWL_ONLY')) && ! in_array($role, explode(',', $only), true)) {
        $this->markTestSkipped('LAUNCH_CRAWL_ONLY');
    }
    $tenant = launchTenant(launchTenantType($role));
    $chain = makeMobileFinanceProposalChain($tenant);
    makeMobileTestPolicy($chain['proposal'], $tenant, $chain['carrier']->id, $chain['party']->id, ['policy_number' => 'POL-LAUNCH-1', 'coverage_ends_at' => now()->addDays(20), 'premium_minor' => 50000, 'currency' => 'XAF']);
    $user = in_array($role, RoleCatalogue::PROVIDER_ROLES, true) ? launchProviderUser($tenant, $role) : launchUser($tenant, $role, $chain['carrier']->id);

    $failures = [];
    $frLeaks = [];
    $report = [];
    foreach (['admin', 'insurer', 'broker', 'provider'] as $panel) {
        $this->flushSession();
        auth()->forgetGuards();
        app()->forgetInstance(\Filament\Navigation\NavigationManager::class);
        $this->actingAs($user);
        $entry = $panel === 'provider' ? '/provider/dashboard' : "/{$panel}"; // /provider only redirects to its dashboard
        $home = $this->get("{$entry}?lang=en");
        $status = $home->status();
        if ($status >= 500) {
            $failures[] = "/{$panel} → {$status}";

            continue;
        }
        if ($status !== 200) {
            continue; // the role has no entry to this panel (403 / login redirect) — nothing visible to crawl
        }
        $navEn = launchSidebar($home->getContent(), $panel);
        $headingsEn = [];
        foreach (array_unique(array_column($navEn, 'href')) as $href) {
            $res = $this->get($href);
            $s = $res->status();
            if ($s !== 200) {
                $failures[] = "EN {$href} → {$s}";

                continue;
            }
            $html = $res->getContent();
            $headingsEn[$href] = launchHeading($html);
            if ($k = launchRawKey(launchText($html))) {
                $failures[] = "EN {$href} raw key {$k}";
            }
            if (($row = launchFirstRow($html, $href)) !== null) {
                $s2 = $this->get($row)->status();
                if ($s2 >= 500 || in_array($s2, [403, 404], true)) {
                    $failures[] = "EN {$row} (row of {$href}) → {$s2}";
                }
            }
        }

        // French pass: same links, remembered locale.
        app()->forgetInstance(\Filament\Navigation\NavigationManager::class);
        $homeFr = $this->get("{$entry}?lang=fr");
        if ($homeFr->status() !== 200) {
            $failures[] = "FR /{$panel} → {$homeFr->status()}";

            continue;
        }
        $navFr = launchSidebar($homeFr->getContent(), $panel);
        $enByHref = collect($navEn)->keyBy('href');
        foreach ($navFr as $item) {
            $en = $enByHref->get($item['href']);
            if ($en && $en['label'] === $item['label'] && launchEnglishOnly($item['label'])) {
                $frLeaks[] = "nav {$item['href']}: \"{$item['label']}\"";
            }
            if ($item['group'] !== '' && $en && $en['group'] === $item['group'] && launchEnglishOnly($item['group'])) {
                $frLeaks[] = "group \"{$item['group']}\"";
            }
        }
        foreach (array_unique(array_column($navFr, 'href')) as $href) {
            $res = $this->get($href);
            $s = $res->status();
            if ($s !== 200) {
                $failures[] = "FR {$href} → {$s}";

                continue;
            }
            $html = $res->getContent();
            if ($k = launchRawKey(launchText($html))) {
                $failures[] = "FR {$href} raw key {$k}";
            }
            $h = launchHeading($html);
            if ($h !== null && $h === ($headingsEn[$href] ?? null) && launchEnglishOnly($h)) {
                $frLeaks[] = "heading {$href}: \"{$h}\"";
            }
        }
        $report[$panel] = count($navEn);
    }
    $frLeaks = array_values(array_unique($frLeaks));

    if ($dir = getenv('LAUNCH_CRAWL_DUMP')) {
        @mkdir($dir, 0777, true);
        file_put_contents("{$dir}/{$role}.json", json_encode(['role' => $role, 'panels' => $report, 'failures' => $failures, 'fr_leaks' => $frLeaks], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    expect($failures)->toBe([], "{$role}: ".implode('; ', $failures));
    if (getenv('LAUNCH_STRICT_FR')) {
        expect($frLeaks)->toBe([], "{$role} English-only in FR: ".implode('; ', $frLeaks));
    }
})->with('launch_roles');

it('crawls every /account page for customer, agent, broker and officer roles in EN and FR', function () {
    $tenant = launchTenant('BROKER');
    $html = $this->get('/account')->assertOk()->getContent();
    $side = Str::before(Str::after($html, 'class="acct-side"'), '</nav>');
    preg_match_all('#href="(/account[^"]*)"#', $side, $m);
    $urls = array_values(array_unique($m[1]));
    expect($urls)->not->toBeEmpty();

    $failures = [];
    foreach ([null, 'CUSTOMER', 'AGENT', 'BROKER_ADMIN', 'BROKER_STAFF', 'CLAIMS_OFFICER'] as $role) {
        $this->flushSession();
        auth()->forgetGuards();
        if ($role !== null) {
            $this->actingAs(launchUser($tenant, $role));
        }
        foreach (['en', 'fr'] as $lang) {
            foreach ($urls as $url) {
                $u = $url.(str_contains($url, '?') ? '&' : '?').'lang='.$lang;
                $res = $this->get($u);
                if ($res->status() !== 200) {
                    $failures[] = ($role ?? 'guest')." {$u} → {$res->status()}";

                    continue;
                }
                if ($k = launchRawKey(launchText($res->getContent()))) {
                    $failures[] = ($role ?? 'guest')." {$u} raw key {$k}";
                }
            }
        }
    }
    expect(array_values(array_unique($failures)))->toBe([]);
});

it('opens the launch staff screens for the roles holding the API permission and refuses the others', function () {
    $tenant = launchTenant('CARRIER');
    $screens = [
        '/admin/operations/system-health' => 'operations.console.view',
        '/admin/operations/backup-recovery' => 'operations.console.view',
        '/admin/finance/exception-centre' => 'finance.exceptions.view',
        '/admin/finance/account-statements' => 'statements.read',
        '/admin/compliance/audit-trail' => 'audit.read',
        '/admin/security/login-activity' => 'security.centre.read',
    ];
    foreach (['en', 'fr'] as $lang) {
        foreach (['PLATFORM_ADMIN', 'FINANCE_MANAGER', 'CLAIMS_OFFICER', 'COMPLIANCE_ADMIN'] as $role) {
            $this->flushSession();
            auth()->forgetGuards();
            $user = launchUser($tenant, $role);
            $this->actingAs($user);
            foreach ($screens as $url => $perm) {
                app(\App\Domain\Tenancy\TenantContext::class)->set($tenant->id);
                $allowed = $user->hasPermission($perm);
                $res = $this->get($url.'?lang='.$lang);
                expect($res->status())->toBe($allowed ? 200 : 403, "{$role} {$url}");
                if ($allowed) {
                    expect(launchRawKey(launchText($res->getContent())))->toBeNull("{$role} {$url} raw key");
                    if ($lang === 'fr') {
                        $res->assertDontSee('launch_screens.');
                    }
                }
            }
        }
    }
});

it('builds a customer account statement from the launch statements screen', function () {
    $tenant = launchTenant('CARRIER');
    $chain = makeMobileFinanceProposalChain($tenant);
    $user = launchUser($tenant, 'FINANCE_MANAGER');
    if (! DB::table('tenant_customers')->where('tenant_id', $tenant->id)->where('party_id', $chain['party']->id)->exists()) {
        DB::table('tenant_customers')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'party_id' => $chain['party']->id, 'customer_number' => 'CUS-LAUNCH-1', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
    }
    $this->actingAs($user);
    \Filament\Facades\Filament::setCurrentPanel(\Filament\Facades\Filament::getPanel('admin'));
    app(\App\Domain\Tenancy\TenantContext::class)->set($tenant->id);

    \Livewire\Livewire::test(\App\Filament\Admin\Pages\Launch\AccountStatements::class)
        ->callAction('build', ['subject_type' => 'customer', 'subject_id' => $chain['party']->id, 'from' => now()->subYear()->toDateString(), 'to' => now()->toDateString(), 'currency' => 'XAF'])
        ->assertHasNoActionErrors()
        ->assertSet('statement.subject.id', $chain['party']->id)
        ->assertSee('AST-CUS-');
});
