<?php

declare(strict_types=1);

use App\Models\Carrier;
use App\Models\InsuranceProduct;
use App\Models\Partner;
use Database\Seeders\CameroonInsuranceRegisterSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/** Public broker profile: insurers from ACTIVE agreements, the products they cover, and the featured flag. */
function brokerAgreement(Partner $broker, Carrier $carrier, array $lines, array $overrides = []): string
{
    $id = (string) Str::uuid();
    DB::table('carrier_broker_agreements')->insert($overrides + [
        'id' => $id, 'carrier_id' => $carrier->id, 'partner_id' => $broker->id, 'agreement_number' => 'AGR-'.Str::random(8),
        'effective_from' => now()->subMonth()->toDateString(), 'effective_until' => null, 'status' => 'ACTIVE',
        'territories' => '[]', 'channels' => '[]', 'is_demo' => false, 'created_at' => now(), 'updated_at' => now(),
    ]);
    foreach ($lines as $line => $productId) {
        DB::table('carrier_broker_agreement_products')->insert([
            'id' => (string) Str::uuid(), 'agreement_id' => $id, 'line_code' => $line, 'insurance_product_id' => $productId,
            'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    return $id;
}

function registerProduct(Carrier $carrier, string $line, string $name): InsuranceProduct
{
    return InsuranceProduct::create(['carrier_id' => $carrier->id, 'line_code' => $line, 'code' => 'P-'.Str::random(6), 'name' => $name, 'version' => 1, 'effective_from' => '2020-01-01', 'status' => 'ACTIVE']);
}

it('lists the insurers a broker is appointed by and the products it offers', function () {
    test()->seed(CameroonInsuranceRegisterSeeder::class);
    $broker = Partner::where('type', 'BROKER')->where('regulator_sequence', 18)->firstOrFail();
    [$a, $b, $c] = Carrier::where('is_official_register', true)->orderBy('regulator_sequence')->take(3)->get()->all();
    $motorA = registerProduct($a, 'MOTOR', 'Auto Plus');
    $motorA2 = registerProduct($a, 'MOTOR', 'Auto Tiers');
    $healthB = registerProduct($b, 'HEALTH', 'Santé Famille');
    registerProduct($b, 'HEALTH', 'Santé Entreprise');
    $motorC = registerProduct($c, 'MOTOR', 'Moto');

    brokerAgreement($broker, $a, ['MOTOR' => null]);                  // whole line
    brokerAgreement($broker, $b, ['HEALTH' => $healthB->id]);         // one product
    brokerAgreement($broker, $c, ['MOTOR' => $motorC->id], ['status' => 'SUSPENDED']);
    brokerAgreement($broker, $c, ['MOTOR' => $motorC->id], ['is_demo' => true]);
    brokerAgreement($broker, $c, ['MOTOR' => $motorC->id], ['effective_until' => now()->subDay()->toDateString()]);

    $d = $this->getJson("/api/v1/public/institutions/{$broker->id}")->assertOk()->json('data');

    expect(collect($d['affiliated_insurers'])->pluck('id')->sort()->values()->all())->toBe(collect([$a->id, $b->id])->sort()->values()->all());
    expect(collect($d['affiliated_insurers'])->firstWhere('id', $a->id)['lines'])->toBe(['MOTOR']);
    expect(collect($d['products'])->pluck('id')->sort()->values()->all())
        ->toBe(collect([$motorA->id, $motorA2->id, $healthB->id])->sort()->values()->all());
    expect(collect($d['products'])->firstWhere('id', $healthB->id))
        ->toMatchArray(['line_code' => 'HEALTH', 'carrier_id' => $b->id]);
});

it('returns empty affiliations for a broker without active agreements', function () {
    test()->seed(CameroonInsuranceRegisterSeeder::class);
    $broker = Partner::where('type', 'BROKER')->where('regulator_sequence', 1)->firstOrFail();

    $d = $this->getJson("/api/v1/public/institutions/{$broker->id}")->assertOk()->json('data');
    expect($d['affiliated_insurers'])->toBe([])->and($d['products'])->toBe([])->and($d['featured'])->toBeFalse();
});

it('features ASSUR EXPERT D&G SARL without changing register order', function () {
    test()->seed(CameroonInsuranceRegisterSeeder::class);

    $brokers = collect($this->getJson('/api/v1/public/institutions?type=broker')->assertOk()->json('data'));
    expect($brokers->where('featured', true)->pluck('name')->all())->toBe(['ASSUR EXPERT D&G SARL'])
        ->and($brokers->first()['regulator_number'])->toBe(1);

    $expert = $brokers->firstWhere('name', 'ASSUR EXPERT D&G SARL');
    expect($this->getJson("/api/v1/public/institutions/{$expert['id']}")->json('data.featured'))->toBeTrue();
});
