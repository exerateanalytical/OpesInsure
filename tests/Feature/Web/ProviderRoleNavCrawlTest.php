<?php

declare(strict_types=1);

/**
 * UI audit 2026-09-27 (docs/UI_AUDIT_PROVIDER_2026-09-27.md): provider portal (/provider) per provider role.
 * Every navigation URL a role can see must render (no 500, no 403, no dead link, no raw translation key or raw
 * snake_case column), every page it cannot see must refuse cleanly (403, never 500), the role's core screens must be
 * in its navigation, and clinical content (diagnosis) never reaches a non-clinical role. Roles use their governed
 * default permissions (RoleCatalogue), never '*'. Runs in English and French.
 */

use App\Application\Identity\RoleCatalogue;
use App\Application\Providers\ProviderNetworkService;
use App\Application\Providers\ProviderRegistry;
use App\Application\Providers\Workspace\Filament\ProviderPanelProvider;
use App\Models\{Party, Role, Tenant, TenantMembership, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tenant = Tenant::create(['type' => 'INSURER', 'legal_name' => 'Crawl insurer '.Str::random(4), 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en', 'settings' => []]);
    $reg = app(ProviderRegistry::class);
    $this->clinic = $reg->register(['category' => 'HEALTH', 'name' => 'Clinique Crawl '.Str::random(3), 'provider_type_code' => 'CLINIC'], null);
    foreach (['APPLICATION', 'UNDER_REVIEW', 'APPROVED', 'ACTIVE'] as $to) {
        $reg->transition($this->clinic->id, $to, null, null, null);
    }
    $this->facility = $reg->addFacility($this->clinic->id, ['code' => 'MAIN', 'name' => 'Main']);
    $this->episodeId = (string) Str::uuid();
    DB::table('treatment_episodes')->insert(['id' => $this->episodeId, 'tenant_id' => $this->tenant->id, 'episode_number' => 'EP-CRAWL-1', 'provider_profile_id' => $this->clinic->id,
        'provider_facility_id' => $this->facility->id, 'member_ref' => 'MBR-CRAWL', 'episode_type' => 'OUTPATIENT', 'status' => 'OPEN', 'started_on' => '2026-09-20',
        'diagnosis_summary' => 'J06.9 URTI secret', 'attending_practitioner' => 'Dr Crawl', 'created_at' => now(), 'updated_at' => now()]);
});

/** A user of the clinic holding $role with its governed defaults; $providerRole adds a provider_users row (portal role + facility scope). */
function providerCrawlUser(object $t, string $role, ?string $providerRole = null): User
{
    // Switching user inside one test: drop the previous session (AuthenticateSession would log the new user out).
    $t->flushSession();
    auth()->forgetGuards();

    $person = Party::create(['type' => 'PERSON', 'display_name' => 'Staff '.Str::random(4), 'status' => 'ACTIVE']);
    DB::table('party_relationships')->insert(['id' => (string) Str::uuid(), 'tenant_id' => null, 'from_party_id' => $person->id, 'to_party_id' => $t->clinic->party_id,
        'type' => 'EMPLOYED_BY', 'status' => 'ACTIVE', 'details' => '{}', 'created_at' => now(), 'updated_at' => now()]);
    $user = User::factory()->create(['status' => 'ACTIVE', 'party_id' => $person->id]);
    $m = TenantMembership::create(['tenant_id' => $t->tenant->id, 'user_id' => $user->id, 'role_code' => $role, 'status' => 'ACTIVE']);
    $r = Role::firstOrCreate(['tenant_id' => $t->tenant->id, 'code' => $role], ['id' => (string) Str::uuid(), 'permissions' => RoleCatalogue::defaultPermissions($role), 'is_system' => true]);
    $m->roles()->syncWithoutDetaching([$r->id]);
    if ($providerRole !== null) {
        DB::table('provider_users')->insert(['id' => (string) Str::uuid(), 'provider_profile_id' => $t->clinic->id, 'user_id' => $user->id, 'provider_role' => $providerRole,
            'facility_scope' => 'ALL', 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
    }

    return $user;
}

/** @return list<string> paths of the links in the rendered Filament sidebar. */
function providerCrawlSidebar(string $html): array
{
    $nav = Str::before(Str::after($html, 'fi-sidebar-nav'), '</nav>');
    preg_match_all('#href="([^"]+)"#', $nav, $m);
    $paths = array_map(fn (string $u): string => (string) parse_url(html_entity_decode($u), PHP_URL_PATH), $m[1]);

    return array_values(array_unique(array_filter($paths, fn (string $p): bool => str_starts_with($p, '/provider/'))));
}

/** GET each URL; returns the failures ("status url", "raw-key …", "raw-column …"). */
function providerCrawlUrls($test, array $urls, string $lang): array
{
    $bad = [];
    foreach ($urls as $url) {
        $res = $test->get($url.'?lang='.$lang);
        if ($res->getStatusCode() !== 200) {
            $bad[] = $res->getStatusCode().' '.$url;

            continue;
        }
        $text = strip_tags($res->getContent());
        if (preg_match('/\b(provider_workspace|filament-[a-z-]+)(::|\.)[a-z_]+\.[a-z_.]+/', $text, $raw)) {
            $bad[] = 'raw-key '.$raw[0].' '.$url;
        }
        if (preg_match('/\b[a-z]+_minor\b/', $text, $raw)) {
            $bad[] = 'raw-column '.$raw[0].' '.$url;
        }
    }

    return $bad;
}

dataset('provider crawl roles', [
    // role, provider_users role (null = legacy organisation employee), nav paths that must be present, paths that must NOT be present
    'PROVIDER_ADMIN' => ['PROVIDER_ADMIN', 'PROVIDER_ADMIN', ['/provider/dashboard', '/provider/eligibility', '/provider/preauthorizations', '/provider/claims', '/provider/accounts', '/provider/users', '/provider/audit', '/provider/reports'], []],
    'PROVIDER_FRONT_DESK' => ['PROVIDER_FRONT_DESK', 'FRONT_DESK', ['/provider/dashboard', '/provider/eligibility', '/provider/preauthorizations', '/provider/admissions'], ['/provider/treatment-episodes', '/provider/documents', '/provider/accounts', '/provider/claims', '/provider/users']],
    'PROVIDER_DOCTOR' => ['PROVIDER_DOCTOR', 'PRACTITIONER', ['/provider/dashboard', '/provider/eligibility', '/provider/preauthorizations', '/provider/treatment-episodes', '/provider/documents'], ['/provider/accounts', '/provider/settlements', '/provider/users']],
    'PROVIDER_BILLING' => ['PROVIDER_BILLING', 'BILLING_OFFICER', ['/provider/dashboard', '/provider/claims', '/provider/disputes', '/provider/contracts', '/provider/treatment-episodes'], ['/provider/accounts', '/provider/users', '/provider/documents']],
    'PROVIDER_PHARMACY' => ['PROVIDER_PHARMACY', 'PHARMACIST', ['/provider/dashboard', '/provider/eligibility', '/provider/preauthorizations', '/provider/claims'], ['/provider/accounts', '/provider/users']],
    'PROVIDER_LAB' => ['PROVIDER_LAB', 'LAB_TECHNICIAN', ['/provider/dashboard', '/provider/eligibility', '/provider/preauthorizations', '/provider/claims'], ['/provider/accounts', '/provider/users']],
    'PROVIDER_FINANCE' => ['PROVIDER_FINANCE', 'FINANCE_OFFICER', ['/provider/dashboard', '/provider/accounts', '/provider/settlements', '/provider/reconciliations', '/provider/reports'], ['/provider/eligibility', '/provider/treatment-episodes', '/provider/users']],
    'FRONT_DESK' => ['FRONT_DESK', null, ['/provider/dashboard', '/provider/eligibility', '/provider/preauthorizations'], ['/provider/treatment-episodes', '/provider/documents', '/provider/accounts']],
    'DOCTOR' => ['DOCTOR', null, ['/provider/dashboard', '/provider/preauthorizations', '/provider/treatment-episodes'], ['/provider/accounts']],
    'BILLING_OFFICER' => ['BILLING_OFFICER', null, ['/provider/dashboard', '/provider/claims', '/provider/contracts'], ['/provider/users']],
    'PHARMACY_USER' => ['PHARMACY_USER', null, ['/provider/dashboard', '/provider/eligibility', '/provider/claims'], ['/provider/accounts']],
    'LAB_USER' => ['LAB_USER', null, ['/provider/dashboard', '/provider/eligibility', '/provider/claims'], ['/provider/accounts']],
    'FINANCE_USER' => ['FINANCE_USER', null, ['/provider/dashboard', '/provider/accounts', '/provider/settlements'], ['/provider/eligibility', '/provider/treatment-episodes']],
]);

it('crawls every provider-portal nav URL for the role (EN and FR): no 500/403/dead links, no raw keys; hidden screens refuse with 403', function (string $role, ?string $providerRole, array $expected, array $absent) {
    $this->actingAs(providerCrawlUser($this, $role, $providerRole));
    $all = array_map(fn (string $p): string => '/provider/'.$p::getSlug(), ProviderPanelProvider::pages());

    foreach (['fr', 'en'] as $lang) {
        $paths = providerCrawlSidebar($this->get('/provider/dashboard?lang='.$lang)->assertOk()->getContent());
        foreach ($expected as $p) {
            expect($paths)->toContain($p);
        }
        foreach ($absent as $p) {
            expect($paths)->not->toContain($p);
        }
        expect(providerCrawlUrls($this, $paths, $lang))->toBe([]);
        if ($lang === 'fr') {
            foreach (array_diff($all, $paths) as $hidden) {
                expect($this->get($hidden)->getStatusCode())->toBe(403, $role.' '.$hidden);
            }
        }
    }
})->with('provider crawl roles');

it('labels the provider portal in French (navigation, columns, dashboard cards) and formats money and dates', function () {
    $this->actingAs(providerCrawlUser($this, 'PROVIDER_ADMIN', 'PROVIDER_ADMIN'));

    $this->get('/provider/dashboard?lang=fr')->assertOk()
        ->assertSee('Tableau de bord prestataire')->assertSee('Vérification d’éligibilité', false)->assertSee('Épisodes de soins')
        ->assertSee('Demandes soumises')->assertDontSee('submitted claims')->assertDontSee('Submitted claims');
    $this->get('/provider/treatment-episodes')->assertOk()->assertSee('N° d’épisode', false)->assertSee('Statut')->assertSee('20/09/2026')->assertDontSee('episode number');
    $this->get('/provider/dashboard?lang=en')->assertOk()->assertSee('Submitted claims')->assertSee('Eligibility check');
    $this->get('/provider/treatment-episodes')->assertOk()->assertSee('Episode no.')->assertSee('Status');
});

it('never shows a diagnosis to non-clinical roles (front desk, billing, finance); clinical roles see it', function () {
    // Opened episode detail (Livewire), billing officer: no diagnosis card; a practitioner sees it.
    app(\App\Domain\Tenancy\TenantContext::class)->set($this->tenant->id);
    app()->instance(\App\Application\Providers\Portal\ProviderScope::class.'@panel',
        new \App\Application\Providers\Portal\ProviderScope($this->clinic->id, [$this->clinic->id], $this->clinic->party_id, null));
    $this->actingAs(providerCrawlUser($this, 'PROVIDER_BILLING', 'BILLING_OFFICER'));
    \Livewire\Livewire::test(\App\Application\Providers\Workspace\Filament\Pages\TreatmentEpisodesPage::class)->call('open', $this->episodeId)
        ->assertSee('EP-CRAWL-1')->assertSee('Dr Crawl')->assertDontSee('J06.9')->assertDontSee('Diagnosis summary');
    $this->actingAs(providerCrawlUser($this, 'PROVIDER_DOCTOR', 'PRACTITIONER'));
    \Livewire\Livewire::test(\App\Application\Providers\Workspace\Filament\Pages\TreatmentEpisodesPage::class)->call('open', $this->episodeId)->assertSee('J06.9 URTI secret');

    foreach ([['PROVIDER_FRONT_DESK', 'FRONT_DESK'], ['FRONT_DESK', null], ['PROVIDER_FINANCE', 'FINANCE_OFFICER'], ['PROVIDER_ADMIN', 'PROVIDER_ADMIN']] as [$role, $pr]) {
        $this->actingAs(providerCrawlUser($this, $role, $pr));
        foreach (['/provider/dashboard', '/provider/preauthorizations', '/provider/admissions', '/provider/treatment-episodes', '/provider/documents'] as $url) {
            $res = $this->get($url);
            if ($res->getStatusCode() === 200) {
                $res->assertDontSee('J06.9');
                // The preauthorization request form asks for a diagnosis code (input), but no list or detail shows one.
                if ($url !== '/provider/preauthorizations') {
                    $res->assertDontSee('Diagnosis')->assertDontSee('diagnostic');
                }
            }
        }
    }
    // Billing opens episodes to generate the claim, but the diagnosis stays hidden (no column, no value).
    $this->actingAs(providerCrawlUser($this, 'PROVIDER_BILLING', 'BILLING_OFFICER'));
    $this->get('/provider/treatment-episodes?lang=en')->assertOk()->assertSee('EP-CRAWL-1')->assertDontSee('J06.9')->assertDontSee('Diagnosis');

    $this->actingAs(providerCrawlUser($this, 'PROVIDER_DOCTOR', 'PRACTITIONER'));
    $this->get('/provider/treatment-episodes?lang=en')->assertOk()->assertSee('J06.9 URTI secret')->assertSee('Diagnosis');
});

it('shows each role only the dashboard widgets of its desk (spec dashboards)', function () {
    $this->actingAs(providerCrawlUser($this, 'PROVIDER_FRONT_DESK', 'FRONT_DESK'));
    $this->get('/provider/dashboard?lang=en')->assertOk()->assertSee('Eligibility checks today')->assertDontSee('Outstanding amount')->assertDontSee('Claims ready to submit');

    $this->actingAs(providerCrawlUser($this, 'PROVIDER_FINANCE', 'FINANCE_OFFICER'));
    $this->get('/provider/dashboard?lang=en')->assertOk()->assertSee('Outstanding amount')->assertSee('Unreconciled payments')->assertDontSee('Eligibility checks today');

    $this->actingAs(providerCrawlUser($this, 'PROVIDER_BILLING', 'BILLING_OFFICER'));
    $this->get('/provider/dashboard?lang=en')->assertOk()->assertSee('Claims ready to submit')->assertDontSee('Eligibility checks today')->assertDontSee('Outstanding amount');
});
