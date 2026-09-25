<?php

declare(strict_types=1);

/*
 | Selection-first inputs (owner requirement: new insurance is SELECTED, not typed).
 | REQ-MDM-003 (Other / Not listed fallback), REQ-MDM-005 (cascading selects),
 | REQ-MOB-002 (wizard from master data). Audit: docs/audit/FREE_TEXT_FIELDS_AUDIT.md
 */

use App\Application\Catalogue\RiskSchemaCatalogue;
use App\Application\MasterData\InputFieldContract;
use App\Application\MasterData\MasterDataCatalogue;
use App\Application\MasterData\MasterDataFlows;
use App\Application\MasterData\MobileFormSchemas;
use App\Application\MasterData\RiskFactsProcessor;
use App\Application\MasterData\VehicleMasterSource;
use App\Models\MasterData\MasterDataReviewItem;
use App\Providers\InputFormsServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/** @return array<string, array<string, mixed>> every schema the app renders: catalogue lines, specialty flows, mobile forms */
function sfAllSchemas(): array
{
    $all = RiskSchemaCatalogue::all();
    foreach (app(MasterDataFlows::class)->all() as $code => $schema) {
        $all[$code] ??= InputFieldContract::annotate($schema);
    }
    foreach (MobileFormSchemas::all() as $code => $schema) {
        $all['FORM:'.$code] = $schema;
    }

    return $all;
}

/** @return array<int, array{0:string,1:array<string,mixed>}> [path, field] flattened incl. repeater items */
function sfFields(array $schemas): array
{
    $out = [];
    $walk = function (array $fields, string $prefix) use (&$walk, &$out): void {
        foreach ($fields as $f) {
            $out[] = [$prefix.$f['key'], $f];
            if (! empty($f['item_fields'])) {
                $walk($f['item_fields'], $prefix.$f['key'].'.*.');
            }
        }
    };
    foreach ($schemas as $code => $s) {
        $walk($s['fields'], "$code.");
    }

    return $out;
}

it('REQ-MDM-003 leaves no unclassified free-text field in any risk, flow or mobile form schema', function () {
    $unclassified = [];
    foreach (sfAllSchemas() as $code => $schema) {
        foreach (InputFieldContract::freeTextFields($schema) as $f) {
            expect(InputFieldContract::FREE_TEXT_REASONS)->toContain($f['reason'] === 'UNCLASSIFIED' ? 'x' : $f['reason']);
            if ($f['reason'] === 'UNCLASSIFIED') {
                $unclassified[] = "$code.{$f['key']}";
            }
        }
    }
    expect($unclassified)->toBe([]);
});

it('REQ-MDM-005 exposes every picker with source {domain,list,parent} and allow_other, numbers with a min', function () {
    foreach (sfFields(sfAllSchemas()) as [$path, $f]) {
        expect(isset($f['input']))->toBeTrue("$path has no input kind");
        if ($f['input'] === 'picker' || $f['input'] === 'multi_picker') {
            $bound = (isset($f['source']) && is_array($f['source'])) || ! empty($f['options']) || isset($f['source_ref']) || isset($f['endpoint']);
            expect($bound)->toBeTrue("$path has no list")
                ->and($f)->toHaveKey('allow_other');
            if (isset($f['source']) && is_array($f['source'])) {
                expect(array_keys($f['source']))->toBe(['domain', 'list', 'parent', 'parent_code'], $path)
                    ->and(is_bool($f['allow_other']))->toBeTrue();
            }
        }
        if ($f['input'] === 'number' || $f['input'] === 'money') {
            expect(isset($f['min']))->toBeTrue("$path has no min");
        }
    }
});

it('REQ-MDM-002 binds every picker to a list that exists in the seeded master data', function () {
    $this->artisan('opesinsure:seed-master-data')->assertExitCode(0);
    $catalogue = app(MasterDataCatalogue::class);
    $missing = [];
    foreach (sfFields(sfAllSchemas()) as [$path, $f]) {
        if (! isset($f['source']) || ! is_array($f['source'])) {
            continue;
        }
        [$domain, $list] = MasterDataFlows::resolveSource($f['source']['domain'], $f['source']['list']);
        if (in_array($domain, [VehicleMasterSource::DOMAIN, 'institutions'], true)) {
            continue; // served by /public/vehicles/* and /public/institutions
        }
        if (! $catalogue->listExists($domain, $list)) {
            $missing[] = "$path -> $domain.$list";
        }
    }
    expect(array_values(array_unique($missing)))->toBe([]);
});

it('REQ-MDM-005 cascades region -> department -> city for HOME and validates the city code', function () {
    $this->artisan('opesinsure:seed-master-data')->assertExitCode(0);
    $fields = collect(RiskSchemaCatalogue::for('HOME')['fields'])->keyBy('key');
    expect($fields['city']['type'])->toBe('select_master')
        ->and($fields['city']['source'])->toBe(['domain' => 'geography', 'list' => 'city', 'parent' => 'department', 'parent_code' => null])
        ->and($fields['city']['allow_other'])->toBeTrue()
        ->and($fields['department']['source']['parent'])->toBe('region')
        ->and($fields->has('unusual_construction'))->toBeFalse();

    $process = fn (array $facts) => app(RiskFactsProcessor::class)->process('HOME', $facts);
    expect($process(['region' => 'LITTORAL', 'department' => 'WOURI', 'city' => 'DOUALA'])['city'])->toBe('DOUALA');
    expect(fn () => $process(['region' => 'CENTRE', 'department' => 'MFOUNDI', 'city' => 'DOUALA']))->toThrow(ValidationException::class);
    expect(fn () => $process(['city' => 'Douala town']))->toThrow(ValidationException::class);

    $process(['department' => 'WOURI', 'city' => 'OTHER', 'city_other' => 'Bonabéri']);
    expect(MasterDataReviewItem::query()->where('raw_input', 'Bonabéri')->exists())->toBeTrue();
});

it('REQ-MDM-003 turns professional claims details into claim-cause picks under LIABILITY', function () {
    $this->artisan('opesinsure:seed-master-data')->assertExitCode(0);
    $field = collect(RiskSchemaCatalogue::for('PROFESSIONAL_LIABILITY')['fields'])->firstWhere('key', 'claims_causes');
    expect($field['source'])->toMatchArray(['domain' => 'claims', 'list' => 'cause_of_loss', 'parent_code' => 'LIABILITY']);

    $process = fn (array $facts) => app(RiskFactsProcessor::class)->process('PROFESSIONAL_LIABILITY', $facts);
    expect($process(['claims_history' => 'ONE', 'claims_causes' => ['LIABILITY__PROFESSIONAL_ERROR']])['claims_causes'])->toBe(['LIABILITY__PROFESSIONAL_ERROR']);
    expect(fn () => $process(['claims_history' => 'ONE', 'claims_causes' => ['MOTOR__THEFT']]))->toThrow(ValidationException::class);
});

it('REQ-MOB-002 binds MOTOR cargo and previous insurer to lists without breaking the vehicle selectors', function () {
    $fields = collect(RiskSchemaCatalogue::for('MOTOR')['fields'])->keyBy('key');
    expect($fields['cargo_type']['source'])->toMatchArray(['domain' => 'cargo', 'list' => 'cargo_category'])
        ->and($fields['previous_insurer']['endpoint'])->toBe('/api/v1/public/institutions?type=insurer')
        ->and($fields['previous_insurer']['allow_other'])->toBeTrue()
        ->and($fields['make_code']['source'])->toBe('/api/v1/public/vehicles/makes')
        ->and($fields['make_code']['source_ref'])->toBe(['domain' => 'vehicle', 'list' => 'makes', 'parent' => null])
        ->and($fields['model_code']['source_ref']['parent'])->toBe('make_code')
        ->and($fields['cover_type']['allow_other'])->toBeFalse()
        ->and(InputFieldContract::freeTextFields(RiskSchemaCatalogue::for('MOTOR')))->toBe([
            ['key' => 'registration_number', 'reason' => 'IDENTIFIER'], ['key' => 'vin', 'reason' => 'IDENTIFIER'],
        ]);
});

it('REQ-MDM-005 serves selection-first mobile forms (profile, KYC, FNOL, lead) over the API', function () {
    app()->register(InputFormsServiceProvider::class);

    $this->getJson('/api/v1/forms')->assertOk()->assertJsonPath('data.contract', InputFieldContract::VERSION);
    $fnol = $this->getJson('/api/v1/forms/claim_fnol')->assertOk()->json('data');
    $fields = collect($fnol['fields'])->keyBy('key');
    expect($fnol['submit_to'])->toBe('POST /api/v1/mobile/claims')
        ->and($fields['incident_type']['source'])->toBe(['domain' => 'claims', 'list' => 'cause_of_loss', 'parent' => 'claim_category', 'parent_code' => null])
        ->and($fields['incident_city']['source']['parent'])->toBe('incident_department')
        ->and($fields['incident_location']['free_text']['reason'])->toBe('LANDMARK')
        ->and($fields['estimated_loss_minor']['min'])->toBe(0);

    $profile = collect($this->getJson('/api/v1/forms/customer_profile')->json('data.fields'))->keyBy('key');
    expect($profile['occupation']['source']['list'])->toBe('occupation')
        ->and($profile['city']['source']['parent'])->toBe('department')
        ->and(collect($profile['beneficiaries']['item_fields'])->firstWhere('key', 'relationship')['source']['list'])->toBe('relationship');

    $this->getJson('/api/v1/forms/nope')->assertNotFound();
});

it('REQ-MDM-003 carries the contract onto disclosure questions', function () {
    $m = new ReflectionMethod(App\Interfaces\Http\Controllers\Api\V1\MobileCompletion\MobileDisclosureController::class, 'inputContract');
    expect($m->invoke(null, ['code' => 'smoker', 'type' => 'boolean']))->toBe(['input' => 'boolean'])
        ->and($m->invoke(null, ['code' => 'occupation', 'type' => 'select_master', 'source' => ['domain' => 'occupations', 'list' => 'occupation']]))
        ->toMatchArray(['input' => 'picker', 'allow_other' => true, 'source' => ['domain' => 'occupations', 'list' => 'occupation', 'parent' => null, 'parent_code' => null]]);
});
