<?php

declare(strict_types=1);

use App\Models\Carrier;
use App\Models\Partner;
use Database\Seeders\CameroonInsuranceRegisterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/** A demo copy of a register row: is_demo, synthetic origin, not on the official register. */
function demoCopy(Carrier|Partner $row, string $name, array $overrides = []): Carrier|Partner
{
    $copy = $row->replicate();
    $copy->forceFill($overrides + [
        'id' => (string) Str::uuid(), 'legal_name' => $name, 'trade_name' => $name, 'slug' => Str::slug($name).'-'.Str::random(6),
        'canonical_id' => null, 'is_official_register' => false, 'is_demo' => true, 'data_origin' => 'DEMO_SYNTHETIC',
    ]);
    $copy->saveQuietly();

    return $copy;
}

it('never lists demo or synthetic institutions in the public directory', function () {
    test()->seed(CameroonInsuranceRegisterSeeder::class);
    $before = $this->getJson('/api/v1/public/institutions')->assertOk()->json('meta.counts');

    $broker = Partner::where('type', 'BROKER')->where('is_official_register', true)->firstOrFail();
    $carrier = Carrier::where('is_official_register', true)->firstOrFail();
    $demoBroker = demoCopy($broker, 'Demo Mobile Broker', ['licence_number' => 'DEMO-'.Str::random(6)]);
    $syntheticOnly = demoCopy($broker, 'OpesInsure Demo', ['is_demo' => false, 'licence_number' => 'DEMO-'.Str::random(6)]);
    $demoCarrier = demoCopy($carrier, 'Demo Assurances', ['cima_code' => 'DEMO'.random_int(100, 999), 'insurer_code' => null]);

    $res = $this->getJson('/api/v1/public/institutions')->assertOk();
    $ids = collect($res->json('data'))->pluck('id');
    expect($ids)->not->toContain($demoBroker->id)
        ->and($ids)->not->toContain($syntheticOnly->id)
        ->and($ids)->not->toContain($demoCarrier->id)
        ->and($ids)->toContain($broker->id)
        ->and(collect($res->json('data'))->where('is_demo', true))->toBeEmpty()
        ->and($res->json('meta.counts'))->toEqual($before);

    $this->getJson("/api/v1/public/institutions/{$demoBroker->id}")->assertNotFound();
    $this->getJson("/api/v1/public/institutions/{$syntheticOnly->id}")->assertNotFound();
    $this->getJson("/api/v1/public/institutions/{$demoCarrier->id}")->assertNotFound();
    $this->getJson("/api/v1/public/institutions/{$broker->id}")->assertOk();
});
