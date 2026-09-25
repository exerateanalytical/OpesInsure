<?php

declare(strict_types=1);

/**
 * REQ-RUL-001 REQ-RUL-002 REQ-RUL-003 REQ-RUL-004 REQ-DUP-020 — Batch 5B rules engine:
 * question_sets/product_questions as THE risk-question source (PHP schemas = seed data, mobile risk-schema output
 * unchanged), versioned + effective-dated structured rule sets with maker-checker, explainable eligibility outcomes on
 * the EngineResult envelope logged in engine_evaluations, completeness gates, legacy eligibility_rules adapter.
 */

use App\Application\Catalogue\RiskSchemaCatalogue;
use App\Application\MasterData\InputFieldContract;
use App\Application\Rules\Models\QuestionSet;
use App\Application\Rules\Models\RuleSet;
use App\Application\Rules\QuestionSetCatalogue;
use App\Application\Rules\RuleEngine;
use App\Domain\Rules\EligibilityOutcome;
use App\Models\Carrier;
use App\Models\InsuranceLine;
use App\Models\InsuranceProduct;
use App\Models\Party;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Concerns/wave_auth_helpers.php';

beforeEach(function () {
    $this->tenant = Tenant::create(['type' => 'CARRIER', 'legal_name' => 'Rules Test '.Str::random(6), 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en']);
    $this->maker = makeAuthTestUser($this->tenant, ['rules.view', 'rules.manage', 'rules.evaluate'], 'RULES_MAKER');
    $this->checker = makeAuthTestUser($this->tenant, ['rules.view', 'rules.approve', 'rules.evaluate'], 'RULES_CHECKER');
});

function b5bAs($user, string $method, string $uri, array $body = [])
{
    Passport::actingAs($user);

    return test()->json($method, '/api/v1/'.$uri, $body, ['X-Tenant-Id' => test()->tenant->id]);
}

function b5bProduct(string $line = 'MOTOR', array $eligibility = ['conditions' => []]): InsuranceProduct
{
    $party = Party::create(['type' => 'ORGANIZATION', 'display_name' => 'Rules Assurances '.Str::random(4), 'status' => 'ACTIVE']);
    $carrier = Carrier::create(['party_id' => $party->id, 'cima_code' => 'R5B-'.Str::random(6), 'status' => 'ACTIVE', 'capabilities' => []]);

    return InsuranceProduct::create(['carrier_id' => $carrier->id, 'line_code' => $line, 'code' => $line.'-'.Str::random(5), 'name' => 'Rules product', 'version' => 1,
        'effective_from' => '2026-01-01', 'status' => 'ACTIVE', 'coverages' => [], 'eligibility_rules' => $eligibility]);
}

/** Creates, submits and approves (maker ≠ checker) a rule set through the API. */
function b5bApprovedRuleSet(array $body): string
{
    $id = b5bAs(test()->maker, 'POST', 'rule-sets', $body)->assertCreated()->json('data.id');
    b5bAs(test()->maker, 'POST', "rule-sets/{$id}/submit")->assertOk()->assertJsonPath('data.status', 'IN_REVIEW');
    b5bAs(test()->checker, 'POST', "rule-sets/{$id}/approve", ['note' => 'Reviewed thresholds.'])->assertOk()->assertJsonPath('data.status', 'APPROVED');

    return $id;
}

function b5bMotorEligibility(string $productId, int $maxAge = 25, string $from = '2026-01-01'): array
{
    return ['code' => 'MOTOR_ELIG_'.strtoupper(substr(str_replace('-', '', $productId), 0, 8)), 'domain' => 'ELIGIBILITY', 'insurance_product_id' => $productId, 'effective_from' => $from, 'rules' => [
        ['code' => 'VEHICLE_TOO_OLD', 'priority' => 10, 'stop_processing' => true,
            'condition' => ['op' => 'GT', 'left' => ['fact' => 'vehicle_age'], 'right' => ['value' => $maxAge]],
            'outcome' => ['result' => 'INELIGIBLE', 'reason_code' => 'VEHICLE_TOO_OLD', 'message_key' => 'elig.vehicle_too_old'],
            'explanation_en' => "Vehicles older than {$maxAge} years are not accepted.", 'explanation_fr' => "Les véhicules de plus de {$maxAge} ans ne sont pas acceptés."],
        ['code' => 'HIGH_VALUE_REFER', 'priority' => 50,
            'condition' => ['op' => 'GTE', 'left' => ['fact' => 'vehicle_value'], 'right' => ['value' => 50000000]],
            'outcome' => ['result' => 'UNDERWRITING_REFERRAL', 'reason_code' => 'HIGH_VALUE']],
        ['code' => 'TAXI_CONDITIONAL', 'priority' => 60,
            'condition' => ['op' => 'EQUAL', 'left' => ['fact' => 'usage_type'], 'right' => ['value' => 'TAXI']],
            'outcome' => ['result' => 'CONDITIONAL', 'reason_code' => 'TAXI_GPS', 'conditions' => ['GPS_TRACKER_REQUIRED']]],
    ]];
}

// ------------------------------------------------------------------------------------------ REQ-DUP-020 / REQ-RUL-001

it('REQ-DUP-020 REQ-RUL-001 seeds every PHP wizard schema into a versioned question set and rebuilds it unchanged', function () {
    $catalogue = app(QuestionSetCatalogue::class);
    foreach (RiskSchemaCatalogue::all() as $code => $schema) {
        $set = QuestionSet::whereNull('insurance_product_id')->where('line_code', $code)->where('stage', 'QUOTE')->where('source', 'SEED_CATALOGUE')->first();
        expect($set)->not->toBeNull($code)
            ->and($set->status)->toBe('APPROVED')
            ->and($set->schema_hash)->toBe(QuestionSetCatalogue::hash($schema))
            ->and($set->questions()->count())->toBe(count($schema['fields']));
        expect(json_decode(json_encode($catalogue->lineSchema($code)), true))->toEqual(json_decode(json_encode($schema), true));
        foreach ($set->questions as $q) {
            expect(QuestionSetCatalogue::QUESTION_TYPES)->toContain($q->question_type);
        }
    }
    // Rating keys are flagged as risk factors; visible_if becomes a structured (REQ-RUL-002) expression.
    $motor = QuestionSet::where('line_code', 'MOTOR')->where('source', 'SEED_CATALOGUE')->first();
    expect($motor->questions()->where('code', 'fiscal_power')->value('risk_factor'))->toBeTrue()
        ->and($motor->questions()->where('code', 'make_code')->value('question_type'))->toBe('ENTITY_REFERENCE');
    $withVisibility = DB::table('product_questions')->whereNotNull('visibility')->first();
    expect($withVisibility)->not->toBeNull()
        ->and((new \App\Domain\Rules\Expression\ExpressionValidator)->validate(json_decode($withVisibility->visibility, true)))->toBe([]);
});

it('REQ-DUP-020 re-syncs a seed only when its content changes (idempotent, new version otherwise)', function () {
    $catalogue = app(QuestionSetCatalogue::class);
    expect($catalogue->syncSeedCatalogue())->toBe(0);
    $home = QuestionSet::where('line_code', 'HOME')->where('source', 'SEED_CATALOGUE')->orderByDesc('version')->first();
    DB::table('question_sets')->where('id', $home->id)->update(['schema_hash' => str_repeat('0', 64)]); // simulate an older seed
    expect(app(QuestionSetCatalogue::class)->syncSeedCatalogue())->toBe(1);
    expect(QuestionSet::where('line_code', 'HOME')->where('stage', 'QUOTE')->max('version'))->toBe($home->version + 1);
});

it('REQ-DUP-020 keeps GET /mobile/catalogue/lines/{code}/risk-schema byte-compatible with the pre-5B output', function () {
    $user = makeAuthTestUser($this->tenant, []);
    foreach (['MOTOR', 'HOME', 'HEALTH', 'CARGO'] as $code) {
        InsuranceLine::firstOrCreate(['code' => $code], ['name' => ['en' => $code, 'fr' => $code], 'status' => 'ACTIVE', 'risk_schema' => ['required' => ['zone']]]);
        Passport::actingAs($user);
        $res = $this->getJson("/api/v1/mobile/catalogue/lines/{$code}/risk-schema", ['X-Tenant-Id' => $this->tenant->id])->assertOk();
        // Pre-5B controller logic, recomputed from the PHP catalogue.
        $defaults = RiskSchemaCatalogue::for($code);
        $legacy = InputFieldContract::annotate(array_merge($defaults, ['required' => ['zone']]));
        expect($res->json('data.version'))->toBe((int) ($legacy['version'] ?? 1))
            ->and($res->json('data.steps'))->toEqual(json_decode(json_encode($legacy['steps']), true))
            ->and($res->json('data.fields'))->toEqual(json_decode(json_encode($legacy['fields']), true))
            ->and($res->json('data.required'))->toBe(['zone'])
            ->and($res->json('data.contract'))->toBe(InputFieldContract::VERSION);
    }
});

it('REQ-RUL-001 versions questions per product version with maker-checker, product set overriding the line default', function () {
    $product = b5bProduct('HOME');
    $schema = ['steps' => [['key' => 'risk', 'label' => 'Risk']], 'required' => ['building_value_minor'], 'fields' => [
        ['key' => 'building_value_minor', 'label' => 'Building value', 'label_fr' => 'Valeur du bâtiment', 'type' => 'money', 'step' => 'risk', 'required' => true, 'min' => 0],
        ['key' => 'has_alarm', 'label' => 'Alarm?', 'type' => 'boolean', 'step' => 'risk', 'required' => false],
        ['key' => 'alarm_brand', 'label' => 'Alarm brand', 'type' => 'select', 'step' => 'risk', 'required' => true, 'options' => [['value' => 'A', 'label' => 'A']], 'visible_if' => ['has_alarm' => true]],
    ]];
    $id = b5bAs($this->maker, 'POST', 'question-sets', ['insurance_product_id' => $product->id, 'stage' => 'QUOTE', 'effective_from' => '2026-01-01', 'schema' => $schema])
        ->assertCreated()->assertJsonPath('data.status', 'DRAFT')->assertJsonPath('data.scope_type', 'PRODUCT_VERSION')->json('data.id');
    $q = QuestionSet::find($id)->questions()->get()->keyBy('code');
    expect($q['building_value_minor']->question_type)->toBe('CURRENCY')->and($q['building_value_minor']->risk_factor)->toBeTrue()
        ->and($q['alarm_brand']->visibility)->toBe(['op' => 'EQUAL', 'left' => ['fact' => 'has_alarm'], 'right' => ['value' => true]]);

    // Before approval the product still gets the line default (seeded HOME wizard).
    expect(b5bAs($this->maker, 'GET', "products/{$product->id}/questionnaire")->assertOk()->json('data.question_set.source'))->toBe('SEED_CATALOGUE');

    b5bAs($this->maker, 'POST', "question-sets/{$id}/submit")->assertOk()->assertJsonPath('data.status', 'IN_REVIEW');
    b5bAs($this->maker, 'POST', "question-sets/{$id}/approve")->assertForbidden(); // maker lacks rules.approve
    b5bAs($this->checker, 'POST', "question-sets/{$id}/approve")->assertOk()->assertJsonPath('data.status', 'APPROVED');

    $res = b5bAs($this->maker, 'GET', "products/{$product->id}/questionnaire")->assertOk();
    expect($res->json('data.question_set.id'))->toBe($id)
        ->and(collect($res->json('data.schema.fields'))->pluck('key')->all())->toBe(['building_value_minor', 'has_alarm', 'alarm_brand'])
        ->and(collect($res->json('data.schema.fields'))->firstWhere('key', 'alarm_brand')['input'])->toBe('picker'); // InputFieldContract applied
    expect(DB::table('approval_requests')->where('source_table', 'question_sets')->where('source_id', $id)->value('status'))->toBe('APPROVED');
});

it('REQ-RUL-001 REQ-DUP-020 serves PROPOSAL questions from the legacy disclosure schema until a PROPOSAL set exists', function () {
    $line = InsuranceLine::firstOrCreate(['code' => 'HOME'], ['name' => ['en' => 'Home', 'fr' => 'Habitation'], 'status' => 'ACTIVE', 'risk_schema' => []]);
    DB::table('disclosure_schema_versions')->insert(['id' => (string) Str::uuid(), 'insurance_line_id' => $line->id, 'version' => 1, 'status' => 'APPROVED',
        'questions' => json_encode([['code' => 'prior_losses', 'label' => ['en' => 'Prior losses?', 'fr' => 'Sinistres ?'], 'type' => 'boolean', 'required' => true, 'referral_values' => [true], 'referral_code' => 'PRIOR_LOSSES']]),
        'schema_hash' => str_repeat('a', 64), 'effective_from' => '2026-01-01', 'created_by' => $this->maker->id, 'created_at' => now(), 'updated_at' => now()]);
    $product = b5bProduct('HOME');
    $res = b5bAs($this->maker, 'GET', "products/{$product->id}/questionnaire?stage=PROPOSAL")->assertOk();
    expect($res->json('data.question_set.source'))->toBe('LEGACY_DISCLOSURE_SCHEMA')
        ->and($res->json('data.schema.fields.0.key'))->toBe('prior_losses')
        ->and($res->json('data.schema.fields.0.referral_code'))->toBe('PRIOR_LOSSES');
});

// ------------------------------------------------------------------------------------------ REQ-RUL-002 / REQ-RUL-003

it('REQ-RUL-002 REQ-RUL-003 returns explainable eligibility outcomes on the EngineResult envelope, logged per check', function () {
    $product = b5bProduct();
    b5bApprovedRuleSet(b5bMotorEligibility($product->id));

    $check = fn (array $facts) => b5bAs($this->maker, 'POST', 'insurance/eligibility/check', ['insurance_product_id' => $product->id, 'facts' => $facts, 'reference_date' => '2026-09-24'])->assertOk()->json('data.0');

    $old = $check(['vehicle_age' => 30, 'vehicle_value' => 90000000, 'usage_type' => 'TAXI']);
    expect($old['outcome'])->toBe('INELIGIBLE')->and($old['quotable'])->toBeFalse()
        ->and($old['result']['engine'])->toBe('RULES')->and($old['result']['blocking'])->toBeTrue()
        ->and($old['result']['reasons'])->toBe(['VEHICLE_TOO_OLD'])
        ->and(array_column($old['result']['trace'], 'rule_code'))->toBe(['VEHICLE_TOO_OLD']) // stop_processing
        ->and($old['explanations'][0]['explanation_fr'])->toBe('Les véhicules de plus de 25 ans ne sont pas acceptés.')
        ->and($old['result']['trace'][0]['input_values'])->toBe(['vehicle_age' => 30]);

    expect($check(['vehicle_age' => 5, 'vehicle_value' => 90000000, 'usage_type' => 'PRIVATE'])['outcome'])->toBe('REFER_TO_UNDERWRITING');
    $taxi = $check(['vehicle_age' => 5, 'vehicle_value' => 1000000, 'usage_type' => 'TAXI']);
    expect($taxi['outcome'])->toBe('CONDITIONAL')->and($taxi['quotable'])->toBeTrue()->and($taxi['conditions'])->toBe(['GPS_TRACKER_REQUIRED']);
    expect($check(['vehicle_age' => 5, 'vehicle_value' => 1000000, 'usage_type' => 'PRIVATE'])['outcome'])->toBe('ELIGIBLE');
    $missing = $check(['vehicle_value' => 1000000, 'usage_type' => 'PRIVATE']);
    expect($missing['outcome'])->toBe('MORE_INFORMATION_REQUIRED')->and($missing['result']['warnings'])->toBe(['MISSING_FACT:vehicle_age']);

    $log = DB::table('engine_evaluations')->where('engine', 'RULES')->where('operation', 'eligibility.check')->orderBy('created_at')->get();
    expect($log)->toHaveCount(5)
        ->and($log->pluck('outcome')->all())->toBe(['INELIGIBLE', 'REFER_TO_UNDERWRITING', 'CONDITIONAL', 'ELIGIBLE', 'MORE_INFORMATION_REQUIRED']);
    expect(json_decode($log[0]->resolved_versions, true))->toHaveKey('rule_set:'.b5bMotorEligibility($product->id)['code']);
});

it('REQ-RUL-002 versions rule sets: effective dating, new version supersedes, retirement rolls back non-destructively', function () {
    $product = b5bProduct();
    $engine = app(RuleEngine::class);
    $v1 = b5bApprovedRuleSet(b5bMotorEligibility($product->id, 25, '2026-01-01'));
    $v2 = b5bApprovedRuleSet(b5bMotorEligibility($product->id, 15, '2026-07-01'));
    expect(RuleSet::find($v2)->version)->toBe(2);

    $facts = ['vehicle_age' => 20, 'vehicle_value' => 1, 'usage_type' => 'PRIVATE'];
    expect($engine->eligibility($product, $facts, new DateTimeImmutable('2026-03-01'))['outcome'])->toBe(EligibilityOutcome::ELIGIBLE) // v1 (max 25)
        ->and($engine->eligibility($product, $facts, new DateTimeImmutable('2026-09-01'))['outcome'])->toBe(EligibilityOutcome::INELIGIBLE); // v2 (max 15)

    b5bAs($this->checker, 'POST', "rule-sets/{$v2}/retire", ['reason' => 'Threshold too strict.'])->assertOk()->assertJsonPath('data.status', 'RETIRED');
    expect($engine->eligibility($product, $facts, new DateTimeImmutable('2026-09-01'))['outcome'])->toBe(EligibilityOutcome::ELIGIBLE);
    // Same inputs, same reference date → same inputs_hash (determinism).
    expect($engine->eligibility($product, $facts, new DateTimeImmutable('2026-09-01'))['result']->inputsHash)
        ->toBe($engine->eligibility($product, $facts, new DateTimeImmutable('2026-09-01'))['result']->inputsHash);
});

it('REQ-RUL-002 enforces maker-checker and rejects invalid expressions and immutable-after-submit drift', function () {
    $product = b5bProduct();
    $body = b5bMotorEligibility($product->id);
    $bad = $body;
    $bad['rules'][0]['condition'] = ['op' => 'EVAL', 'left' => ['value' => 'php']];
    b5bAs($this->maker, 'POST', 'rule-sets', $bad)->assertStatus(422);
    $bad = $body;
    $bad['rules'][0]['outcome']['result'] = 'MAYBE';
    b5bAs($this->maker, 'POST', 'rule-sets', $bad)->assertStatus(422);

    $id = b5bAs($this->maker, 'POST', 'rule-sets', $body)->assertCreated()->json('data.id');
    b5bAs($this->maker, 'POST', "rule-sets/{$id}/submit")->assertOk();
    // The maker holding rules.approve still cannot approve their own set (approval engine maker ≠ checker).
    $both = makeAuthTestUser($this->tenant, ['rules.view', 'rules.manage', 'rules.approve'], 'RULES_BOTH');
    $own = b5bAs($both, 'POST', 'rule-sets', ['code' => 'OWN_SET'] + $body)->assertCreated()->json('data.id');
    b5bAs($both, 'POST', "rule-sets/{$own}/submit")->assertOk();
    b5bAs($both, 'POST', "rule-sets/{$own}/approve")->assertStatus(422);

    // Content changed after submission → the checker is refused.
    DB::table('rules')->where('rule_set_id', $id)->where('code', 'VEHICLE_TOO_OLD')->update(['condition' => json_encode(['op' => 'GT', 'left' => ['fact' => 'vehicle_age'], 'right' => ['value' => 99]])]);
    b5bAs($this->checker, 'POST', "rule-sets/{$id}/approve")->assertStatus(422);

    b5bAs($this->maker, 'POST', 'rule-sets/validate-expression', ['condition' => ['op' => 'GT', 'left' => ['fact' => 'a'], 'right' => ['value' => 1]]])->assertOk()->assertJsonPath('data.valid', true);
});

it('REQ-RUL-003 adapts legacy insurance_products.eligibility_rules and logs quote-time eligibility per product', function () {
    $product = b5bProduct('MOTOR', ['conditions' => [['fact' => 'usage_type', 'operator' => 'IN', 'value' => ['PRIVATE', 'COMMERCIAL']], ['fact' => 'fiscal_power', 'operator' => 'BETWEEN', 'value' => [1, 20]]]]);
    $engine = app(RuleEngine::class);
    expect($engine->eligibility($product, ['usage_type' => 'PRIVATE', 'fiscal_power' => 8])['outcome'])->toBe(EligibilityOutcome::ELIGIBLE);
    $taxi = $engine->eligibility($product, ['usage_type' => 'TAXI', 'fiscal_power' => 8], null, ['type' => 'quote', 'id' => (string) Str::uuid()]);
    expect($taxi['outcome'])->toBe(EligibilityOutcome::INELIGIBLE)
        ->and($taxi['result']->reasons)->toBe(['PRODUCT_ELIGIBILITY_CONDITIONS_NOT_MET'])
        ->and($taxi['result']->trace[0]['source_table'])->toBe('insurance_products')
        ->and($taxi['evaluation_id'])->not->toBeNull();
    expect($engine->eligibility($product, ['usage_type' => 'PRIVATE'])['outcome'])->toBe(EligibilityOutcome::MORE_INFORMATION_REQUIRED);
});

// ------------------------------------------------------------------------------------------ REQ-RUL-004

it('REQ-RUL-004 blocks an operation on configured completeness rules and passes when none apply', function () {
    $engine = app(RuleEngine::class);
    expect($engine->completeness('BIND', 'MOTOR', null, [])['result']->outcome)->toBe('COMPLETE'); // no rules configured = no gate

    b5bApprovedRuleSet(['code' => 'MOTOR_BIND_COMPLETENESS', 'domain' => 'COMPLETENESS', 'line_code' => 'MOTOR', 'operation' => 'BIND', 'effective_from' => '2026-01-01', 'rules' => [
        ['code' => 'VIN_REQUIRED', 'condition' => ['op' => 'NOT_EXISTS', 'left' => ['fact' => 'vin']], 'outcome' => ['result' => 'BLOCK', 'reason_code' => 'VIN_REQUIRED']],
        ['code' => 'PHOTO_RECOMMENDED', 'condition' => ['op' => 'NOT_EXISTS', 'left' => ['fact' => 'photo_document_id']], 'outcome' => ['result' => 'WARN']],
    ]]);

    $res = b5bAs($this->maker, 'POST', 'insurance/completeness/check', ['operation' => 'BIND', 'line_code' => 'MOTOR', 'facts' => ['registration_number' => 'LT-1']])->assertOk();
    expect($res->json('data.outcome'))->toBe('INCOMPLETE')->and($res->json('data.blocking'))->toBeTrue()
        ->and($res->json('data.result.reasons'))->toBe(['VIN_REQUIRED'])->and($res->json('data.result.warnings'))->toBe(['PHOTO_RECOMMENDED'])
        ->and($res->json('data.evaluation_id'))->not->toBeNull();
    expect(b5bAs($this->maker, 'POST', 'insurance/completeness/check', ['operation' => 'BIND', 'line_code' => 'MOTOR', 'facts' => ['vin' => 'VF1']])->json('data.outcome'))->toBe('COMPLETE_WITH_WARNINGS');
    // Other operations are not gated by a BIND rule set.
    expect($engine->completeness('ISSUE', 'MOTOR', null, [])['result']->outcome)->toBe('COMPLETE');
    expect(fn () => $engine->assertComplete('BIND', 'MOTOR', null, []))->toThrow(ValidationException::class);
});
