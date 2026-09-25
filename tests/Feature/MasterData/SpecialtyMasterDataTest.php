<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

function specialtyMasterData(string $file = 'specialty_2026'): array
{
    return json_decode(
        file_get_contents(base_path("database/data/master_data/{$file}.json")),
        true,
        flags: JSON_THROW_ON_ERROR,
    );
}

/** Minimum value counts per domain (guards against accidental data loss). */
const SPECIALTY_MIN_VALUES = [
    'life_insurance' => 35, 'education_savings' => 10, 'retirement' => 15, 'credit_life' => 15, 'funeral' => 10,
    'group_life' => 15, 'surety' => 25, 'credit_insurance' => 20, 'legal_protection' => 20, 'cyber' => 30,
    'aviation_insurance' => 120, 'marine_hull' => 20, 'fleet' => 10 /* vehicle_class & usage reference the vehicle master */, 'school' => 15, 'hospital' => 20, 'hotel' => 15,
    'mining' => 30, 'oil_gas_energy' => 30, 'telecom' => 25, 'electronic_equipment' => 120,
    'mobile_device' => 300, 'renewable_energy' => 40, 'household_contents' => 15, 'valuable_items' => 60,
    'public_liability' => 25, 'employer_liability' => 12, 'event' => 35,
];

it('covers domains 14 to 40 with platform-normalized provenance', function () {
    $data = specialtyMasterData();
    $domains = collect($data['domains']);

    expect($data['provenance_default'])->toBe('PLATFORM_NORMALIZED')
        ->and($domains->pluck('number')->sort()->values()->all())->toBe(range(14, 40))
        ->and($domains->pluck('code')->duplicates())->toBeEmpty();

    foreach ($domains as $domain) {
        expect($domain['provenance'])->toBe('PLATFORM_NORMALIZED')
            ->and($domain['protected'])->toBeTrue();
        $count = collect($domain['lists'])->sum(fn ($l) => count($l['values']));
        expect($count)->toBeGreaterThanOrEqual(SPECIALTY_MIN_VALUES[$domain['code']], $domain['code']);
    }
});

it('has EN and FR labels and unique codes everywhere', function () {
    foreach (specialtyMasterData()['domains'] as $domain) {
        expect($domain['label_en'])->not->toBeEmpty()->and($domain['label_fr'])->not->toBeEmpty();
        foreach ($domain['lists'] as $list) {
            expect($list['label_fr'])->not->toBeEmpty();
            $codes = array_column($list['values'], 'code');
            expect(array_unique($codes))->toHaveCount(count($codes), "{$domain['code']}.{$list['code']}");
            foreach ($list['values'] as $v) {
                expect($v['code'])->toMatch('/^[A-Z0-9_]+$/')
                    ->and($v['label_en'])->not->toBeEmpty()
                    ->and($v['label_fr'])->not->toBeEmpty();
            }
        }
    }
});

it('keeps hierarchies consistent and offers an Other fallback on open lists', function () {
    foreach (specialtyMasterData()['domains'] as $domain) {
        $lists = collect($domain['lists'])->keyBy('code');
        foreach ($lists as $list) {
            if (isset($list['parent_list'])) {
                $parents = array_column($lists[$list['parent_list']]['values'], 'code');
                foreach ($list['values'] as $v) {
                    if ($v['code'] !== 'OTHER') {
                        expect($parents)->toContain($v['parent_code']);
                    }
                }
            }
        }
    }

    $mobile = collect(specialtyMasterData()['domains'])->firstWhere('code', 'mobile_device');
    $models = array_column(collect($mobile['lists'])->firstWhere('code', 'model')['values'], 'code');
    expect($models)->toContain('SMARTPHONE__APPLE__IPHONE_16_PRO');
});

it('has flows whose master-data fields reference existing lists', function () {
    $data = specialtyMasterData();
    $known = [];
    foreach ([...$data['domains'], ...specialtyMasterData('reference_2026')['domains'], ...specialtyMasterData('core_2026')['domains']] as $d) {
        foreach ($d['lists'] as $l) {
            $known["{$d['code']}.{$l['code']}"] = array_column($l['values'], 'code');
        }
    }
    $types = ['SELECT_MASTER', 'MULTI_SELECT_MASTER', 'NUMBER', 'CURRENCY', 'DATE', 'TEXT', 'BOOLEAN', 'FILE', 'REPEATER'];

    $check = function (array $fields, string $dom) use (&$check, $known, $types) {
        foreach ($fields as $f) {
            expect($types)->toContain($f['type']);
            if (isset($f['source']) && $f['source']['domain'] !== 'vehicle') {
                expect(array_key_exists("{$f['source']['domain']}.{$f['source']['list']}", $known))->toBeTrue("$dom.{$f['key']}");
                if (($f['other_allowed'] ?? false) === true) {
                    expect($known["{$f['source']['domain']}.{$f['source']['list']}"])->toContain('OTHER');
                }
            }
            $check($f['item_fields'] ?? [], $dom);
        }
    };

    foreach ($data['domains'] as $d) {
        expect($d['flow']['steps'])->not->toBeEmpty();
        foreach ($d['flow']['steps'] as $step) {
            $check($step['fields'], $d['code']);
        }
    }
});

it('ships reference domains with EN/FR labels, unique codes and no duplicated lists', function () {
    $ref = specialtyMasterData('reference_2026');
    $spec = specialtyMasterData();
    $codes = collect($ref['domains'])->pluck('code');
    expect($codes->intersect(collect($spec['domains'])->pluck('code')))->toBeEmpty();
    foreach (['property', 'health', 'life', 'cargo', 'construction', 'equipment', 'agriculture', 'livestock', 'marine',
        'aviation', 'partners', 'financial_institutions', 'payments', 'documents', 'claims', 'fraud_indicators',
        'accounting', 'communications', 'customers', 'distribution', 'policy_admin', 'finance', 'reinsurance', 'provider'] as $c) {
        expect($codes)->toContain($c);
    }
    $v = [];
    foreach ($ref['domains'] as $d) {
        foreach ($d['lists'] as $l) {
            $list = array_column($l['values'], 'code');
            expect(array_unique($list))->toHaveCount(count($list), "{$d['code']}.{$l['code']}");
            foreach ($l['values'] as $x) {
                expect($x['label_en'])->not->toBeEmpty()->and($x['label_fr'])->not->toBeEmpty();
            }
            $v["{$d['code']}.{$l['code']}"] = $list;
        }
    }
    expect($v['property.construction_material'])->toContain('STONE', 'EARTH')
        ->and($v['property.roof_type'])->toContain('STEEL_SHEET', 'THATCH')
        ->and($v['health.benefit_category'])->toContain('PHYSIOTHERAPY', 'MENTAL_HEALTH')
        ->and($v['cargo.transport_mode'])->toContain('INLAND_WATER')
        ->and($v['cargo.incoterm'])->toHaveCount(11)
        ->and($v['cargo.airport'])->toContain('DLA', 'NSI', 'GOU')
        ->and($v['agriculture.crop'])->toContain('SUGARCANE', 'YAM')
        ->and($v['aviation.aircraft_type'])->toContain('DRONE')
        ->and($v['finance.aging_bucket'])->toBe(['CURRENT', '1_30_DAYS', '31_60_DAYS', '61_90_DAYS', '90_PLUS'])
        ->and($v['reinsurance.reinsurance_type'])->toBe(['TREATY', 'FACULTATIVE', 'FACULTATIVE_OBLIGATORY'])
        ->and($v['provider.provider_type'])->toContain('HEALTH_CENTRE', 'PHYSIOTHERAPY', 'AMBULANCE_PROVIDER');
    foreach (['CATTLE', 'GOAT', 'SHEEP', 'PIG', 'POULTRY'] as $animal) {
        expect($v['livestock.breed'])->toContain("{$animal}__LOCAL");
    }
    expect(collect($ref['domains'])->firstWhere('code', 'fraud_indicators'))->toHaveKey('disclaimer_en');
});

describe('engine load', function () {
    uses(RefreshDatabase::class);

    beforeEach(function () {
        if (! Schema::hasTable('master_data_values') || ! array_key_exists('opesinsure:seed-master-data', Artisan::all())) {
            $this->markTestSkipped('Master-data engine not installed yet.');
        }
    });

    it('seeds specialty values idempotently', function () {
        Artisan::call('opesinsure:seed-master-data');
        $first = DB::table('master_data_values')->count();
        Artisan::call('opesinsure:seed-master-data');
        expect(DB::table('master_data_values')->count())->toBe($first);

        foreach (specialtyMasterData()['domains'] as $d) {
            expect(DB::table('master_data_domains')->where('code', $d['code'])->exists())->toBeTrue($d['code']);
        }
    });

    it('refuses to delete seeded specialty values', function () {
        Artisan::call('opesinsure:seed-master-data');
        $id = DB::table('master_data_values')->where('code', 'SMARTPHONE__APPLE__IPHONE_16_PRO')->value('id');
        expect($id)->not->toBeNull();
        expect(fn () => DB::table('master_data_values')->where('id', $id)->delete())->toThrow(Exception::class);
    });
});
