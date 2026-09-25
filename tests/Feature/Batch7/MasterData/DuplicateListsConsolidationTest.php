<?php

declare(strict_types=1);

use App\Application\Customers\Roles\PartyRoleService;
use App\Application\FinancialDistribution\ReinsuranceReference;
use App\Application\MasterData\MasterDataCatalogue;
use App\Application\MasterData\MasterDataFlows;
use Database\Seeders\MasterDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

uses(RefreshDatabase::class);

beforeEach(fn () => $this->artisan('opesinsure:seed-master-data')->assertExitCode(0));

it('supersedes duplicate lists without deleting them and redirects to the canonical list', function () {
    foreach (['life_insurance.relationship' => 'persons.relationship', 'aviation.manufacturer' => 'aviation_insurance.manufacturer'] as $old => $new) {
        expect(MasterDataSeeder::SUPERSEDED_LISTS[$old])->toBe($new)
            ->and(implode('.', MasterDataFlows::resolveSource(...explode('.', $old))))->toBe($new);
        [$d, $l] = explode('.', $old);
        $row = DB::table('master_data_lists')->where(['domain_code' => $d, 'code' => $l])->first();
        expect($row->status)->toBe('INACTIVE')
            ->and(DB::table('master_data_values')->where(['domain_code' => $d, 'list_code' => $l])->count())->toBeGreaterThan(0);
    }
    $this->getJson('/api/v1/public/master-data/life_insurance/relationship')->assertOk()->assertJsonPath('data.label.en', 'Relationship');
    $this->getJson('/api/v1/public/master-data/aviation/manufacturer?parent=HELICOPTER')->assertOk()
        ->assertJsonFragment(['code' => 'HELICOPTER__BELL']);
});

it('resolves every code of a superseded list in its canonical list (no stored value lost)', function () {
    $catalogue = app(MasterDataCatalogue::class);
    foreach (['life_insurance.relationship', 'aviation.manufacturer'] as $old) {
        [$d, $l] = explode('.', $old);
        foreach (DB::table('master_data_values')->where(['domain_code' => $d, 'list_code' => $l])->pluck('code') as $code) {
            expect($catalogue->value($d, $l, $code))->not->toBeNull("$old:$code");
        }
    }
    expect($catalogue->value('life_insurance', 'relationship', 'LEGAL_HEIRS')['code'])->toBe('ESTATE')
        ->and($catalogue->value('aviation', 'manufacturer', 'BELL')['code'])->toBe('HELICOPTER__BELL')
        ->and($catalogue->value('aviation', 'manufacturer', 'NOT_A_BRAND'))->toBeNull();
});

it('points life flows at persons.relationship', function () {
    $fields = collect(app(MasterDataFlows::class)->all())->flatMap(fn ($s) => $s['fields'])
        ->flatMap(fn ($f) => [$f, ...($f['item_fields'] ?? [])])->filter(fn ($f) => isset($f['source']));
    expect($fields->contains(fn ($f) => $f['source'] === ['domain' => 'life_insurance', 'list' => 'relationship']))->toBeFalse()
        ->and($fields->contains(fn ($f) => $f['source'] === ['domain' => 'persons', 'list' => 'relationship']))->toBeTrue();
});

it('derives persons.person_role from the party role registry', function () {
    $values = app(MasterDataCatalogue::class)->list('persons', 'person_role')['values'];
    expect(array_column($values, 'code'))->toBe(['POLICYHOLDER', 'LIFE_ASSURED', 'BENEFICIARY', 'PAYER']);
    foreach ($values as $v) {
        expect($v['label'])->toBe(['en' => PartyRoleService::ROLES[$v['code']]['en'], 'fr' => PartyRoleService::ROLES[$v['code']]['fr']]);
    }
    // The copy kept in the JSON for file readers must not drift from the registry.
    $core = json_decode(file_get_contents(database_path('data/master_data/core_2026.json')), true);
    $expanded = MasterDataSeeder::expandValuesFrom($core);
    $find = fn ($doc) => collect($doc['domains'])->firstWhere('code', 'persons')['lists'];
    expect(collect($find($core))->firstWhere('code', 'person_role')['values'])->toBe(collect($find($expanded))->firstWhere('code', 'person_role')['values']);
});

it('uses one bordereau type set for both endpoints', function () {
    $ok = fn (string $t) => Validator::make(['type' => ReinsuranceReference::bordereauType($t)], ['type' => ['required', ReinsuranceReference::bordereauTypeRule()]])->passes();
    foreach (['PREMIUM', 'CLAIM', 'claims', 'ENDORSEMENT', 'CANCELLATION', 'COMMISSION'] as $t) {
        expect($ok($t))->toBeTrue($t);
    }
    expect($ok('RISK'))->toBeFalse()->and($ok('BOGUS'))->toBeFalse();
    foreach (['BrokerOperations/BrokerOperationsController.php', 'FinancialDistribution/FinancialDistributionController.php'] as $c) {
        expect(file_get_contents(app_path("Interfaces/Http/Controllers/Api/V1/$c")))->toContain('ReinsuranceReference::bordereauTypeRule()');
    }
});
