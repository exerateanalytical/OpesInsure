<?php

declare(strict_types=1);

use App\Models\DataSubjectRequest;
use App\Models\Party;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Database\Seeders\CameroonInsuranceRegisterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function publicSiteSeedRegister(): void
{
    test()->seed(CameroonInsuranceRegisterSeeder::class);
}

/** Every same-site href in a rendered page, as a path (+query). */
function publicSiteLinks(string $html): array
{
    preg_match_all('/\shref="([^"]+)"/', $html, $m);

    return collect($m[1])
        ->map(fn ($h) => html_entity_decode($h))
        ->reject(fn ($h) => str_starts_with($h, '#') || str_starts_with($h, 'mailto:') || str_starts_with($h, 'tel:') || str_starts_with($h, 'https://wa.me'))
        ->map(fn ($h) => preg_replace('#^https?://[^/]+#', '', $h))
        ->filter(fn ($h) => str_starts_with($h, '/'))
        ->map(fn ($h) => strtok($h, '#') ?: '/')
        ->unique()->values()->all();
}

dataset('public pages', [
    'home' => ['/', 'Insurance from trusted providers.'],
    'about' => ['/about', 'About OpesInsure'],
    'providers' => ['/providers', 'All insurance providers'],
    'how it works' => ['/how-it-works', 'How it works'],
    'claims' => ['/claims', 'How to file a claim'],
    'faq' => ['/faq', 'Frequently asked questions'],
    'contact' => ['/contact', 'Send us a message'],
    'privacy' => ['/privacy', 'Privacy Policy'],
    'terms' => ['/terms', 'Terms &amp; Conditions'],
    'account delete' => ['/account/delete', 'Delete your OpesInsure account'],
    'partners' => ['/partners', 'Partner with OpesInsure'],
    'download' => ['/download', 'Download'],
    'app deep link fallback' => ['/app/explore?q=motor', 'Open in the OpesInsure app'],
    'verify' => ['/verify', 'Is this cover valid?'],
]);

it('renders every public page with its key text', function (string $path, string $text) {
    publicSiteSeedRegister();

    $this->get($path)->assertOk()->assertSee($text, false)->assertSee('site.css', false);
})->with('public pages');

it('serves the pages the mobile app links to', function (string $path) {
    $this->get($path)->assertOk();
})->with(['/privacy', '/terms', '/account/delete']);

it('switches to French with ?lang=fr and remembers it in the session', function () {
    $this->get('/?lang=fr')->assertOk()->assertSee('<html lang="fr">', false)->assertSee('L’assurance de prestataires de confiance.', false);
    $this->get('/about')->assertOk()->assertSee('À propos d’OpesInsure', false);
    $this->get('/about?lang=en')->assertOk()->assertSee('About OpesInsure');
});

it('shows real register names and live counts on the landing page', function () {
    publicSiteSeedRegister();

    $html = $this->get('/')->assertOk()
        ->assertSee('ACTIVA')
        ->assertSee('>29<', false)
        ->assertSee('>123<', false)
        ->assertSee('Country live — Cameroon', false)
        ->getContent();

    expect($html)->not->toContain('50+')->not->toContain('1M+')->not->toContain('People Covered');
});

it('never invents numbers when the register is empty', function () {
    $html = $this->get('/')->assertOk()
        ->assertSee('Every licensed insurer in Cameroon')
        ->assertSee('Live in Cameroon, built for Africa')
        ->getContent();

    expect($html)->not->toContain('50+')->not->toContain('1M+');
});

it('lists all 29 insurers and 123 brokers and filters them', function () {
    publicSiteSeedRegister();

    $all = $this->get('/providers')->assertOk()->getContent();
    expect(substr_count($all, '<li data-kind="insurer"'))->toBe(29)
        ->and(substr_count($all, '<li data-kind="broker"'))->toBe(123)
        ->and($all)->toContain('<b id="provider-count">152</b>');

    $this->get('/providers?type=broker')->assertOk()->assertSee('<b id="provider-count">123</b>', false);
    $this->get('/providers?type=insurer&branch=LIFE')->assertOk()->assertSee('<b id="provider-count">11</b>', false);
    $this->get('/providers?q=activa')->assertOk()->assertSee('ACTIVA Assurances');
});

it('has no broken internal links on the landing page, footer and every public page', function () {
    publicSiteSeedRegister();

    $links = collect(['/', '/about', '/providers', '/claims', '/faq', '/contact', '/partners', '/account/delete', '/privacy', '/terms', '/how-it-works'])
        ->flatMap(fn ($p) => publicSiteLinks($this->get($p)->getContent()))
        ->unique()->values();

    expect($links)->toContain('/providers', '/claims', '/how-it-works', '/about', '/privacy', '/terms', '/account/delete', '/contact', '/download', '/partners', '/faq');

    foreach ($links as $link) {
        if (preg_match('/\.(ico|png|webp|jpg|css|js|woff2)(\?.*)?$/', $link)) { // static files: served by the web server, check on disk
            expect(is_file(public_path(ltrim(strtok($link, '?'), '/'))))->toBeTrue("Missing file: {$link}");

            continue;
        }
        $status = $this->get($link)->getStatusCode();
        expect($status)->not->toBe(404, "Broken link: {$link}")->and($status)->toBeLessThan(500, "Server error on: {$link}");
    }
});

it('references only image assets that exist on disk', function () {
    publicSiteSeedRegister();
    $html = $this->get('/')->getContent().$this->get('/providers')->getContent();

    preg_match_all('#/landing/[A-Za-z0-9_./-]+\.(?:webp|png|jpg|css|js)#', $html, $m);
    expect($m[0])->not->toBeEmpty();
    foreach (array_unique($m[0]) as $asset) {
        expect(is_file(public_path(ltrim($asset, '/'))))->toBeTrue("Missing asset {$asset}");
    }
    expect(is_file(public_path('favicon.ico')))->toBeTrue();
});

it('links the Android download and marks the App Store as coming soon', function () {
    $html = $this->get('/')->assertOk()->assertSee('Download for')->assertSee('Coming soon')->getContent();

    expect($html)->toContain('<a class="store" href="/download">')
        ->and($html)->not->toMatch('/<a[^>]+class="store soon"/');
});

it('publishes sitemap.xml and robots.txt', function () {
    $this->get('/sitemap.xml')->assertOk()->assertHeader('Content-Type', 'application/xml; charset=UTF-8')
        ->assertSee('<urlset', false)->assertSee(url('/account/delete'), false)->assertSee(url('/providers'), false);
    $this->get('/robots.txt')->assertOk()->assertSee('Sitemap: '.url('/sitemap.xml'), false)->assertSee('Disallow: /admin', false);
});

it('files a support ticket from the contact form', function () {
    $this->post('/contact', ['name' => 'Awa Ndzi', 'email' => 'awa@example.com', 'phone' => '+237690000000', 'topic' => 'partner', 'message' => 'We are a broker in Douala and want to join the marketplace.'])
        ->assertRedirect('/contact')->assertSessionHas('contact_sent');

    $ticket = DB::table('support_tickets')->where('category', 'PARTNERSHIP_ENQUIRY')->first();
    expect($ticket)->not->toBeNull()
        ->and($ticket->status)->toBe('OPEN')
        ->and($ticket->description)->toContain('awa@example.com');
    expect(DB::table('support_ticket_events')->where('support_ticket_id', $ticket->id)->exists())->toBeTrue();

    $this->followingRedirects()->get('/contact')->assertSee($ticket->ticket_number);
});

it('validates the contact form', function () {
    $this->post('/contact', ['name' => '', 'email' => 'nope', 'topic' => 'support', 'message' => 'short'])
        ->assertSessionHasErrors(['name', 'email', 'message']);
    expect(DB::table('support_tickets')->count())->toBe(0);
});

it('files an erasure request for a matching account through the data-subject-request service', function () {
    $party = Party::create(['type' => 'PERSON', 'display_name' => 'Jean Mbarga', 'legal_identity' => [], 'status' => 'ACTIVE']);
    $user = User::factory()->create(['phone_e164' => '+237677001122', 'party_id' => $party->id, 'full_name' => 'Jean Mbarga']);
    $tenant = Tenant::create(['type' => 'PLATFORM', 'legal_name' => 'OpesInsure Test', 'slug' => 'opes-web-dsr', 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en', 'settings' => []]);
    TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role_code' => 'CUSTOMER', 'status' => 'ACTIVE']);

    $this->post('/account/delete', ['identifier' => '677 00 11 22', 'full_name' => 'Jean Mbarga', 'confirm' => '1'])
        ->assertRedirect('/account/delete')->assertSessionHas('deletion_received');

    $dsr = DataSubjectRequest::where('party_id', $party->id)->first();
    expect($dsr)->not->toBeNull()->and($dsr->type)->toBe('ERASURE')->and($dsr->status)->toBe('RECEIVED')->and($dsr->tenant_id)->toBe($tenant->id);
    expect($user->fresh())->not->toBeNull(); // nothing erased before compliance verifies identity

    // A second submission does not open a duplicate request.
    $this->post('/account/delete', ['identifier' => '+237677001122', 'full_name' => 'Jean Mbarga', 'confirm' => '1'])->assertRedirect('/account/delete');
    expect(DataSubjectRequest::where('party_id', $party->id)->count())->toBe(1);
});

it('answers identically when no account matches, without revealing it', function () {
    $this->post('/account/delete', ['identifier' => 'nobody@example.com', 'full_name' => 'No One', 'confirm' => '1'])
        ->assertRedirect('/account/delete')->assertSessionHas('deletion_received');

    expect(DataSubjectRequest::count())->toBe(0)
        ->and(DB::table('support_tickets')->where('category', 'ACCOUNT_DELETION_UNMATCHED')->count())->toBe(1);

    $this->followingRedirects()->get('/account/delete')->assertSee('Your request has been received.');
});

it('requires the deletion confirmation', function () {
    $this->post('/account/delete', ['identifier' => '+237677001122', 'full_name' => 'Jean'])->assertSessionHasErrors('confirm');
});
