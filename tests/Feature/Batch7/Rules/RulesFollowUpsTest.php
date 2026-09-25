<?php

declare(strict_types=1);

/**
 * Batch 5/6 rules follow-ups (REQ-RUL-001 / REQ-RUL-002):
 *  - DocumentRequirementService::applicable reads the DOCUMENTS domain of the rules engine (legacy conditions unchanged
 *    when no DOCUMENTS rule set is in force);
 *  - RiskFactsProcessor / RiskAssetTypes / ProposalService read risk questions through QuestionSetCatalogue.
 */

use App\Application\Catalogue\RiskSchemaCatalogue;
use App\Application\MasterData\RiskFactsProcessor;
use App\Application\Rules\Models\QuestionSet;
use App\Application\Rules\Models\RuleSet;
use App\Application\Rules\QuestionSetCatalogue;
use App\Application\Rules\RuleSetService;
use App\Application\Risks\RiskAssetTypes;
use App\Application\Underwriting\DocumentRequirementService;
use App\Models\DocumentRequirementVersion;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';

beforeEach(function () {
    $this->tenant = Tenant::create(['type' => 'CARRIER', 'legal_name' => 'B7 Rules '.Str::random(6), 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en']);
    $this->user = makeAuthTestUser($this->tenant, ['rules.manage'], 'B7_RULES');
});

function b7Requirement(string $code, array $conditions = []): DocumentRequirementVersion
{
    return DocumentRequirementVersion::create(['line_code' => 'MOTOR', 'code' => $code, 'version' => 1, 'name' => ['en' => $code], 'rules' => ['conditions' => $conditions],
        'mandatory' => true, 'status' => 'APPROVED', 'effective_from' => '2026-01-01', 'created_by' => test()->user->id]);
}

function b7DocumentsRuleSet(array $rules): RuleSet
{
    $set = app(RuleSetService::class)->createDraft(['code' => 'MOTOR_DOCS_'.Str::upper(Str::random(4)), 'domain' => 'DOCUMENTS', 'line_code' => 'MOTOR', 'effective_from' => '2026-01-01', 'rules' => $rules], test()->user);
    $set->update(['status' => 'APPROVED']);

    return $set;
}

function b7Codes($c): array
{
    return $c->pluck('code')->sort()->values()->all();
}

it('keeps the legacy requirement conditions when no DOCUMENTS rule set is in force', function () {
    b7Requirement('ID_CARD');
    b7Requirement('TAXI_LICENCE', [['fact' => 'usage', 'equals' => 'TAXI']]);
    $svc = app(DocumentRequirementService::class);

    expect(b7Codes($svc->applicable('MOTOR', ['usage' => 'PRIVATE'])))->toBe(['ID_CARD'])
        ->and(b7Codes($svc->applicable('MOTOR', ['usage' => 'TAXI'])))->toBe(['ID_CARD', 'TAXI_LICENCE'])
        ->and(b7Codes($svc->applicable('MOTOR', [])))->toBe(['ID_CARD']);
});

it('applies DOCUMENTS rules from the rules engine: REQUIRE adds, WAIVE removes, unknown never waives', function () {
    b7Requirement('ID_CARD');
    b7Requirement('VALUATION_REPORT', [['fact' => 'never', 'equals' => 'set']]);
    b7DocumentsRuleSet([
        ['code' => 'HIGH_VALUE_NEEDS_VALUATION', 'condition' => ['op' => 'GTE', 'left' => ['fact' => 'vehicle_value'], 'right' => ['value' => 50000000]],
            'outcome' => ['result' => 'REQUIRE', 'document_codes' => ['VALUATION_REPORT']]],
        ['code' => 'RENEWAL_WAIVES_ID', 'condition' => ['op' => 'EQUAL', 'left' => ['fact' => 'renewal'], 'right' => ['value' => true]],
            'outcome' => ['result' => 'WAIVE', 'document_codes' => ['ID_CARD']]],
    ]);
    $svc = app(DocumentRequirementService::class);

    expect(b7Codes($svc->applicable('MOTOR', ['vehicle_value' => 1000, 'renewal' => false])))->toBe(['ID_CARD'])
        ->and(b7Codes($svc->applicable('MOTOR', ['vehicle_value' => 60000000, 'renewal' => false])))->toBe(['ID_CARD', 'VALUATION_REPORT'])
        ->and(b7Codes($svc->applicable('MOTOR', ['vehicle_value' => 1000, 'renewal' => true])))->toBe([])
        // renewal unknown → ID_CARD is not waived; vehicle_value unknown → valuation conservatively required.
        ->and(b7Codes($svc->applicable('MOTOR', [])))->toBe(['ID_CARD', 'VALUATION_REPORT']);
});

it('validates DOCUMENTS rule outcomes', function () {
    expect(fn () => b7DocumentsRuleSet([['code' => 'BAD', 'condition' => ['op' => 'EXISTS', 'left' => ['fact' => 'x']], 'outcome' => ['result' => 'BLOCK', 'document_codes' => ['A']]]]))
        ->toThrow(ValidationException::class)
        ->and(fn () => b7DocumentsRuleSet([['code' => 'BAD', 'condition' => ['op' => 'EXISTS', 'left' => ['fact' => 'x']], 'outcome' => ['result' => 'REQUIRE']]]))
        ->toThrow(ValidationException::class);
});

it('RiskFactsProcessor validates against the approved question set, falling back to the seed schema', function () {
    $catalogue = app(QuestionSetCatalogue::class);
    expect($catalogue->lineSchema('HOME'))->not->toBeNull(); // seeds the line set
    $processor = app(RiskFactsProcessor::class);
    $facts = ['b7_occupants' => [['name' => 'A'], ['name' => 'B']]];
    $processor->process('HOME', $facts); // unknown to the seed schema → untouched

    $set = $catalogue->createDraft(['line_code' => 'HOME', 'stage' => 'QUOTE', 'effective_from' => '2026-01-01', 'schema' => ['steps' => [['key' => 'risk', 'label' => 'Risk']], 'fields' => [
        ['key' => 'b7_occupants', 'label' => 'Occupants', 'type' => 'repeater', 'max_items' => 1, 'item_fields' => [['key' => 'name', 'label' => 'Name', 'type' => 'text']]],
    ]]], $this->user);
    $set->update(['status' => 'APPROVED']);

    expect(fn () => app(RiskFactsProcessor::class)->process('HOME', $facts))->toThrow(ValidationException::class);
});

it('RiskAssetTypes reports schema availability through the question set catalogue (same result as the seeds)', function () {
    foreach (RiskAssetTypes::describe() as $t) {
        expect($t['schema_available'])->toBe(RiskSchemaCatalogue::for($t['line_code']) !== null);
    }
    expect(QuestionSet::where('source', 'SEED_CATALOGUE')->exists())->toBeTrue();
});

it('no longer reads the legacy question sources directly', function () {
    foreach (['Application/MasterData/RiskFactsProcessor.php', 'Application/Risks/RiskAssetTypes.php'] as $f) {
        expect(file_get_contents(app_path($f)))->not->toContain('RiskSchemaCatalogue::');
    }
    foreach (['Application/Underwriting/ProposalService.php', 'Application/Underwriting/Proposal/ProposalQuestions.php'] as $f) {
        expect(file_get_contents(app_path($f)))->not->toContain('DisclosureSchemaVersion::');
    }
});
