<?php

declare(strict_types=1);

/**
 * REQ-KYC-001 REQ-KYC-003 REQ-AML-002 REQ-KYC-004 — owner decision 27 (2026-09-25): risk-based, audited KYC. Customer / country / product /
 * channel risk from configuration only (NULL = UNVERIFIED → UNRATED), PEP / sanctions hard triggers, EDD, source
 * of funds / wealth, periodic refresh and rescreening, screening_mode MANUAL_AUDITED (legacy MANUAL accepted).
 */

use App\Application\Kyc\Models\KycRiskAssessment;
use App\Application\Kyc\Models\ScreeningCheck;
use App\Application\Kyc\Screening\ScreeningMode;
use App\Models\KycSubmission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';
require_once __DIR__.'/../Wave12/Concerns/mobile_auth_helpers.php';
require_once __DIR__.'/../Concerns/wave_auth_helpers.php';

beforeEach(function () {
    $this->fx = makeMobileCustomerFixture();
    $this->tenant = $this->fx['tenant'];
    $this->maker = makeAuthTestUser($this->tenant, ['kyc.view', 'kyc.manage', 'kyc.review', 'kyc.screen'], 'KYC_MAKER');
    $this->checker = makeAuthTestUser($this->tenant, ['kyc.view', 'kyc.decide'], 'KYC_CHECKER');
});

function rkAs($user, string $method, string $uri, array $body = [])
{
    Passport::actingAs($user);

    return test()->json($method, '/api/v1/'.$uri, $body, tenantHeaderFor(test()->tenant));
}

function rkSubmit(): string
{
    $fx = test()->fx;
    foreach (['ID_FRONT', 'PROOF_OF_ADDRESS'] as $purpose) {
        rkAs($fx['user'], 'POST', 'mobile/kyc/documents', ['document_id' => makeMobileTestDocument($fx['tenant'], $fx['party'])->id, 'purpose' => $purpose])->assertStatus(201);
    }
    Passport::actingAs($fx['user']);

    return test()->postJson('/api/v1/mobile/kyc/submission', [], tenantHeaderFor($fx['tenant']) + ['Idempotency-Key' => (string) Str::uuid()])->assertStatus(201)->json('data.id');
}

function rkScreen(string $id, string $status = 'CLEAR'): void
{
    foreach (ScreeningCheck::where('subject_id', $id)->where('status', 'PENDING')->get() as $c) {
        rkAs(test()->maker, 'POST', "kyc/submissions/{$id}/screenings/{$c->id}", ['status' => $status, 'list_reference' => 'Reviewer manual check (test)'])->assertOk();
    }
}

it('REQ-KYC-001: with UNVERIFIED (null) configuration the rating is UNRATED, gaps are listed and nothing is invented', function () {
    $id = rkSubmit();
    $show = rkAs($this->maker, 'GET', "kyc/submissions/{$id}")->assertOk();
    $ra = $show->json('data.risk_assessment');
    expect($ra['rating'])->toBe('UNRATED')->and($ra['score'])->toBeNull()->and($ra['edd_required'])->toBeFalse()
        ->and($ra['configuration_gaps'])->toContain('factors.customer.weight', 'factors.customer.scores.INDIVIDUAL', 'inputs.channel')
        ->and($ra['screening_mode'])->toBe('MANUAL_AUDITED')->and($ra['automated'])->toBeFalse()
        ->and($show->json('data.screening.automated'))->toBeFalse()
        ->and($show->json('data.screenings.0.screening_mode'))->toBe('MANUAL_AUDITED');
    expect(DB::table('audit_log')->where('action', 'kyc_submission.risk_assessed')->where('subject_id', $id)->exists())->toBeTrue();

    $cfg = rkAs($this->maker, 'GET', 'kyc/risk-configuration')->assertOk();
    expect($cfg->json('data.risk.factors.country.weight'))->toBeNull()->and($cfg->json('data.risk.bands.LOW'))->toBeNull()
        ->and($cfg->json('data.screening.screening_mode'))->toBe('MANUAL_AUDITED');
})->group('REQ-KYC-001', 'REQ-AML-002');

it('REQ-KYC-001: configured weights, scores and bands rate customer, country, product and channel risk', function () {
    config(['kyc.risk.factors' => [
        'customer' => ['weight' => 1, 'scores' => ['INDIVIDUAL' => 1, 'CORPORATE' => 2]],
        'country' => ['weight' => 1, 'scores' => ['CM' => 1, 'XX' => 3]],
        'product' => ['weight' => 1, 'scores' => ['MOTOR' => 1, 'LIFE_SAVINGS' => 3]],
        'channel' => ['weight' => 1, 'scores' => ['MOBILE_APP' => 2]],
    ], 'kyc.risk.bands' => ['LOW' => 1.5, 'MEDIUM' => 2.2]]);
    $id = rkSubmit();
    rkAs($this->maker, 'POST', "kyc/submissions/{$id}/risk-assessment", ['country_code' => 'cm', 'product_codes' => ['MOTOR'], 'channel' => 'mobile_app', 'reason' => 'Onboarding facts'])
        ->assertOk()->assertJsonPath('data.risk_assessment.rating', 'LOW')->assertJsonPath('data.risk_assessment.score', 1.25)
        ->assertJsonPath('data.risk_assessment.version', 2)->assertJsonPath('data.kyc_level', 'STANDARD');

    // Riskiest product counts; the new score is above MEDIUM → HIGH → EDD → ENHANCED, source of funds / wealth required.
    rkAs($this->maker, 'POST', "kyc/submissions/{$id}/risk-assessment", ['country_code' => 'XX', 'product_codes' => ['MOTOR', 'LIFE_SAVINGS'], 'reason' => 'Life savings product added'])
        ->assertOk()->assertJsonPath('data.risk_assessment.rating', 'HIGH')->assertJsonPath('data.risk_assessment.edd_required', true)
        ->assertJsonPath('data.risk_assessment.source_of_funds_required', true)->assertJsonPath('data.kyc_level', 'ENHANCED');
    expect(KycRiskAssessment::where('kyc_submission_id', $id)->count())->toBe(3);   // append-only history
})->group('REQ-KYC-001', 'REQ-AML-002');

it('REQ-KYC-001: a PEP match is a hard trigger (HIGH, EDD) and approval needs source of funds and wealth', function () {
    $id = rkSubmit();
    rkAs($this->maker, 'POST', "kyc/submissions/{$id}/start-review")->assertOk();
    $pep = ScreeningCheck::where('subject_id', $id)->where('check_type', 'PEP')->first();
    rkAs($this->maker, 'POST', "kyc/submissions/{$id}/screenings/{$pep->id}", ['status' => 'POSSIBLE_MATCH', 'list_reference' => 'Reviewer consulted list'])->assertOk();
    rkScreen($id);
    $ra = KycRiskAssessment::where('kyc_submission_id', $id)->orderByDesc('version')->first();
    expect($ra->rating)->toBe('HIGH')->and($ra->edd_required)->toBeTrue()->and($ra->triggers['hard'])->toBe(['PEP_POSSIBLE_MATCH']);

    rkAs($this->maker, 'POST', "kyc/submissions/{$id}/recommend", ['outcome' => 'APPROVE', 'rationale' => 'ok'])
        ->assertStatus(422)->assertJsonPath('blocking', ['TAX_ID', 'SOURCE_OF_FUNDS_REQUIRED', 'SOURCE_OF_WEALTH_REQUIRED']);   // TAX_ID: ENHANCED-level document

    $doc = makeMobileTestDocument($this->tenant, $this->fx['party']);
    rkAs($this->maker, 'POST', "kyc/submissions/{$id}/sources", ['reason' => 'EDD interview',
        'source_of_funds' => ['description' => 'Salary', 'evidence_document_ids' => [$doc->id]],
        'source_of_wealth' => ['description' => 'Inherited property']])->assertOk()
        ->assertJsonPath('data.risk_assessment.source_of_funds.description', 'Salary');
    rkAs($this->maker, 'POST', "kyc/submissions/{$id}/recommend", ['outcome' => 'APPROVE', 'rationale' => 'EDD complete'])
        ->assertStatus(422)->assertJsonPath('blocking', ['TAX_ID']);   // only the ENHANCED document remains
})->group('REQ-KYC-001', 'REQ-AML-002');

it('REQ-KYC-003: periodic refresh and rescreening follow the configured months per rating; rescreening is manual and audited', function () {
    config(['kyc.risk.refresh_months.UNRATED' => 12, 'kyc.risk.rescreen_months.UNRATED' => 6]);
    $id = rkSubmit();
    rkAs($this->maker, 'POST', "kyc/submissions/{$id}/start-review")->assertOk();
    rkScreen($id);
    rkAs($this->maker, 'POST', "kyc/submissions/{$id}/recommend", ['outcome' => 'APPROVE', 'rationale' => 'ok'])->assertOk();
    rkAs($this->checker, 'POST', "kyc/submissions/{$id}/decision", ['confirm' => true, 'reason' => 'ok'])->assertOk();
    $s = KycSubmission::find($id);
    expect($s->expiry_basis)->toBe('RISK_REFRESH_POLICY')->and((int) round(now()->diffInMonths($s->expires_at)))->toBe(12);

    // Not due yet → nothing; due → a new MANUAL_AUDITED round of PENDING checks.
    $this->artisan('kyc:rescreen-due')->assertSuccessful();
    expect(ScreeningCheck::where('subject_id', $id)->where('screening_round', 2)->count())->toBe(0);
    $this->travel(7)->months();
    $this->artisan('kyc:rescreen-due')->assertSuccessful();
    $round2 = ScreeningCheck::where('subject_id', $id)->where('screening_round', 2)->get();
    expect($round2)->toHaveCount(2)->and($round2->pluck('status')->unique()->all())->toBe(['PENDING'])
        ->and($round2->pluck('provider')->unique()->all())->toBe(['MANUAL_AUDITED'])->and($round2->first()->trigger)->toBe('PERIODIC_RESCREEN');
    rkAs($this->maker, 'POST', "kyc/submissions/{$id}/rescreen", ['reason' => 'again'])->assertStatus(409)->assertJsonPath('code', 'KYC_RESCREEN_IN_PROGRESS');

    // A rescreening match on an approved KYC is recorded, re-rated and raised — the approval is not silently changed.
    $sanctions = $round2->firstWhere('check_type', 'SANCTIONS');
    rkAs($this->maker, 'POST', "kyc/submissions/{$id}/screenings/{$sanctions->id}", ['status' => 'POSSIBLE_MATCH', 'list_reference' => 'Reviewer consulted list'])
        ->assertOk()->assertJsonPath('data.status', 'APPROVED')->assertJsonPath('data.risk_assessment.rating', 'HIGH');
    expect(DB::table('outbox_messages')->where('event_name', 'kyc_submission.rescreen_match')->where('aggregate_id', $id)->exists())->toBeTrue()
        ->and(DB::table('audit_log')->where('action', 'kyc_submission.rescreen_started')->where('subject_id', $id)->exists())->toBeTrue();
})->group('REQ-KYC-003', 'REQ-AML-002');

it('REQ-KYC-001: legacy screening mode MANUAL is read as MANUAL_AUDITED and screening is never claimed automated', function () {
    expect(ScreeningMode::normalize('MANUAL'))->toBe('MANUAL_AUDITED')->and(ScreeningMode::normalize(null))->toBe('MANUAL_AUDITED')
        ->and(ScreeningMode::isAutomated('MANUAL_AUDITED'))->toBeFalse();
    $id = rkSubmit();
    DB::table('screening_checks')->where('subject_id', $id)->update(['provider' => 'MANUAL']);   // row stored before the rename
    expect(rkAs($this->maker, 'GET', "kyc/submissions/{$id}")->assertOk()->json('data.screenings.0.screening_mode'))->toBe('MANUAL_AUDITED');
    config(['kyc.screening.mode' => 'MANUAL']);
    expect(ScreeningMode::describe()['screening_mode'])->toBe('MANUAL_AUDITED');
})->group('REQ-KYC-001', 'REQ-AML-002');
