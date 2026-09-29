<?php

declare(strict_types=1);

use App\Application\Directory\InsurerShortNames;
use App\Models\Carrier;
use Database\Seeders\CameroonInsuranceRegisterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/** Owner list 2026-09-29, in display order. */
const OWNER_SHORT_NAMES = [
    'ACTIVA', 'AFG', 'AFRI', 'AREA', 'AGC', 'AXA', 'Belife General', 'Chanas', 'CPA', 'GMC', 'LD', 'NSIA', 'Pro Assur', 'Royal Onyx', 'SAAR',
    'SanlamAllianz', 'SUNU', 'Zenithe', 'ACAM Vie', 'ACTIVA Vie', 'AFRILIFE', 'Belife', 'Chanas Vie', 'NSIA Vie', 'SAAR Vie', 'SanlamAllianz Vie',
    'SONAM Vie', 'SUNU Vie', 'Wafa Vie',
];

it('gives the 29 licensed insurers the owner short names and display order, legal names untouched', function () {
    $this->seed(CameroonInsuranceRegisterSeeder::class);

    $rows = Carrier::where('is_official_register', true)->orderBy('display_order')->get();
    expect($rows)->toHaveCount(29)
        ->and($rows->pluck('brand_short_name')->all())->toBe(OWNER_SHORT_NAMES)
        ->and($rows->pluck('display_order')->all())->toBe(range(1, 29));

    $saar = Carrier::where('insurer_code', 'SAAR')->firstOrFail();
    expect($saar->legal_name)->toBe(mb_strtoupper("Société Africaine d'Assurances et de Réassurances"))
        ->and($saar->short_name)->toBe('SAAR')
        ->and(Carrier::where('insurer_code', 'SANLAM_ALLIANZ_IARD')->value('display_order'))->toBe(16)
        ->and(Carrier::where('insurer_code', 'SANLAM_ALLIANZ_IARD')->value('regulator_sequence'))->toBe(15);

    // Idempotent: a second sync changes nothing.
    expect(InsurerShortNames::sync())->toBe(0);
});

it('exposes short_name and display_order on public institutions, ordered by display order', function () {
    $this->seed(CameroonInsuranceRegisterSeeder::class);

    $data = collect($this->getJson('/api/v1/public/institutions?type=insurer')->assertOk()->json('data'));
    expect($data->pluck('short_name')->all())->toBe(OWNER_SHORT_NAMES)
        ->and($data->pluck('display_order')->all())->toBe(range(1, 29));

    $row = $data->firstWhere('short_name', 'Pro Assur');
    expect($row['legal_name'])->toBe('PROASSUR')->and($row['register_short_name'])->toBe('PROASSUR');

    $show = $this->getJson('/api/v1/public/institutions/'.$row['id'])->assertOk();
    expect($show->json('data.short_name'))->toBe('Pro Assur')->and($show->json('data.legal_name'))->toBe('PROASSUR');
});

it('builds card payload keys from the short name with a trade-name fallback', function () {
    $this->seed(CameroonInsuranceRegisterSeeder::class);

    $c = Carrier::where('insurer_code', 'CPA')->firstOrFail();
    expect(InsurerShortNames::payload($c))->toBe(['carrier_short_name' => 'CPA', 'carrier_display_order' => 9]);

    $c->brand_short_name = null;
    expect(InsurerShortNames::shortOf($c))->toBe("Compagnie Professionnelle d'Assurances du Cameroun")
        ->and(InsurerShortNames::shortOf(null))->toBeNull();
});

it('shows short names on the public providers list cards', function () {
    $this->seed(CameroonInsuranceRegisterSeeder::class);

    $html = $this->get('/providers')->assertOk()->getContent();
    expect($html)->toContain('>Belife General</b>')
        ->and($html)->toContain('>SAAR</b>')
        ->and($html)->not->toContain(">Société Africaine d'Assurances et de Réassurances</b>")
        ->and($html)->not->toContain('>Société Africaine d&#039;Assurances et de Réassurances</b>');
    // Display order: ACTIVA before AFG before SAAR before SanlamAllianz.
    expect(strpos($html, '>SAAR</b>'))->toBeLessThan(strpos($html, '>SanlamAllianz</b>'))
        ->and(strpos($html, '>ACTIVA</b>'))->toBeLessThan(strpos($html, '>AFG</b>'));
});
