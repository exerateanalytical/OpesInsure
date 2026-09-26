<?php

declare(strict_types=1);

use App\Models\User;
use Database\Seeders\CameroonInsuranceRegisterSeeder;
use Database\Seeders\PlatformCatalogueSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

function desktopSeedCatalogue(): void
{
    test()->seed(CameroonInsuranceRegisterSeeder::class);
    // PlatformCatalogueSeeder stamps created_by with the oldest user.
    User::firstOrCreate(['phone_e164' => '+237600000001'], ['full_name' => 'System']);
    test()->seed(PlatformCatalogueSeeder::class);
}

dataset('desktop pages', [
    'marketplace' => ['/insurance', 'Find the right insurance for what matters most.'],
    'motor' => ['/insurance/motor', 'Motor Insurance'],
    'life' => ['/insurance/life', 'Life Insurance'],
    'health' => ['/insurance/health', 'Health Insurance'],
    'travel' => ['/insurance/travel', 'Travel Insurance'],
    'home' => ['/insurance/home', 'Home Insurance'],
    'business' => ['/insurance/business', 'Business Insurance'],
    'accident' => ['/insurance/accident', 'Personal Accident Insurance'],
    'compare' => ['/compare', 'Compare Insurance'],
    'login' => ['/login', 'Sign In'],
    'signup' => ['/signup', 'Create Account'],
    'about' => ['/about', 'Built in Africa, for Africa'],
    'contact' => ['/contact', 'We’re here to help.'],
]);

it('renders every desktop page', function (string $path, string $text) {
    desktopSeedCatalogue();

    $this->get($path)->assertOk()->assertSee($text, false)->assertSee('site.css', false);
})->with('desktop pages');

it('renders every desktop page in French', function (string $path) {
    desktopSeedCatalogue();

    $this->get($path.(str_contains($path, '?') ? '&' : '?').'lang=fr')->assertOk()->assertSee('lang="fr"', false);
})->with(['/insurance', '/insurance/motor', '/compare', '/login', '/signup', '/about', '/contact']);

it('lists only published products with their approved base premium', function () {
    desktopSeedCatalogue();

    // Seeded: Chanas Assur AUTO, base tariff 48,500 FCFA.
    $this->get('/insurance/motor')->assertOk()
        ->assertSee('Chanas Assur AUTO')->assertSee('48,500 FCFA')
        ->assertDontSee('Chanas Assur Voyage');

    DB::table('insurance_products')->where('code', 'CHANAS-AUTO')->update(['status' => 'RETIRED']);
    cache()->flush();
    $this->get('/insurance/motor')->assertOk()->assertDontSee('Chanas Assur AUTO');
});

it('filters the marketplace by category, price band and search', function () {
    desktopSeedCatalogue();

    $this->get('/insurance?cat=travel')->assertOk()->assertSee('NSIA Voyage Sérénité')->assertDontSee('AXA Auto Essentiel');
    $this->get('/insurance?price=gt150')->assertOk()->assertSee('AXA Pro Commerce')->assertDontSee('Chanas Assur AUTO');
    $this->get('/insurance?q=hospicare')->assertOk()->assertSee('HospiCare')->assertDontSee('AXA Auto Essentiel');
});

it('shows an honest empty state when no product is published', function () {
    $this->get('/insurance')->assertOk()->assertSee('0 Insurance Products')->assertSee('Products are being added by our partner insurers');
});

it('compares selected products side by side and marks the cheapest', function () {
    desktopSeedCatalogue();

    $html = $this->get('/compare?view=table&p[]=AXA-AUTO&p[]=NSIA-AUTO')->assertOk()
        ->assertSee('2 Insurance Quotes')->assertSee('AXA Auto Essentiel')->assertSee('NSIA Auto Liberté')
        ->assertSee('Best Value')->assertSee('/account/buy?product=NSIA-AUTO', false)->getContent();

    expect(substr_count($html, 'class="ctable"'))->toBe(1);
    // Unknown codes are ignored, and at most four products are compared.
    $this->get('/compare?p[]=NOPE&p[]=AXA-AUTO')->assertOk()->assertSee('1 Insurance Quotes');
});

it('404s an unknown insurance line', function () {
    $this->get('/insurance/pets')->assertNotFound();
});

it('points sign-in and sign-up at the real account API', function () {
    $this->get('/login')->assertOk()->assertSee('/landing/auth.js', false)->assertSee('api\/v1', false)->assertDontSee('Continue with Google');
    $this->get('/signup')->assertOk()->assertSee('name="password_confirmation"', false);
    expect(is_file(public_path('landing/auth.js')))->toBeTrue();
});

it('has no broken internal links or missing images on the desktop pages', function () {
    desktopSeedCatalogue();

    $pages = ['/insurance', '/insurance/motor', '/insurance/accident', '/compare?p[]=AXA-AUTO&p[]=SAAR-AUTO', '/compare?view=table&p[]=AXA-AUTO', '/about', '/contact', '/login', '/signup'];
    $html = collect($pages)->map(fn ($p) => $this->get($p)->assertOk()->getContent())->implode('');

    preg_match_all('#/landing/[A-Za-z0-9_./-]+\.(?:webp|png|jpg|css|js)#', $html, $m);
    foreach (array_unique($m[0]) as $asset) {
        expect(is_file(public_path(ltrim($asset, '/'))))->toBeTrue("Missing asset {$asset}");
    }

    preg_match_all('/\shref="(\/[^"#]*)"/', $html, $l);
    foreach (array_unique(array_map('html_entity_decode', $l[1])) as $link) {
        if (preg_match('/\.(ico|png|webp|jpg|css|js|woff2)(\?.*)?$/', $link) || str_starts_with($link, '/admin')) {
            continue;
        }
        $status = $this->get($link)->getStatusCode();
        expect($status)->not->toBe(404, "Broken link: {$link}")->and($status)->toBeLessThan(500, "Server error on: {$link}");
    }
});
