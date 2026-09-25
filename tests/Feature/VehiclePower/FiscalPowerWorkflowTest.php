<?php

declare(strict_types=1);

/**
 * Agent V1 — Vehicle Power master: validation rules VPWR-001..009, master_data_review_workflow, conflict cases,
 * rule-engine exemptions and the api_contract endpoints (RBAC).
 */

use App\Application\Rules\Models\RuleSet;
use App\Application\Rules\RuleSetService;
use App\Application\Vehicles\Power\FiscalPowerBands;
use App\Application\Vehicles\Power\FiscalPowerService;
use App\Application\Vehicles\Power\PowerUnits;
use App\Application\Vehicles\Power\VehicleStampDutyService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/Concerns/vehicle_power_helpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->travelTo(now()->setDate(2026, 10, 10)->setTime(10, 0));
    vpwrSetUp();
});

it('VPWR-001 technical power must be > 0 when known; VPWR-002 hp uses the configured constant', function () {
    expect(fn () => PowerUnits::normalize(0, 'KW'))->toThrow(ValidationException::class, 'VPWR-001');
    vpwrApi($this->maker, 'POST', "master-data/vehicles/{$this->variant->id}/power", ['power_source_value' => -5, 'power_source_unit' => 'KW'])->assertStatus(422);
    expect(PowerUnits::normalize(75, 'KW')['power_hp'])->toBe(round(75 * PowerUnits::KW_TO_MECHANICAL_HP, 3))
        ->and(PowerUnits::normalize(100, 'HP')['power_kw'])->toBe(round(100 * PowerUnits::MECHANICAL_HP_TO_KW, 3))
        ->and(fn () => PowerUnits::unit('CV'))->toThrow(ValidationException::class);
    expect(fn () => DB::transaction(fn () => DB::table('vehicle_power_specs')->insert(['id' => (string) \Illuminate\Support\Str::uuid(), 'variant_id' => $this->variant->id, 'version' => 99,
        'power_kw' => 0, 'conversion_method' => 'X', 'effective_from' => '2026-01-01', 'created_at' => now(), 'updated_at' => now()])))->toThrow(QueryException::class);
});

it('VPWR-004 fiscal_power_cv must be a positive integer', function () {
    $svc = app(FiscalPowerService::class);
    foreach ([0, -3, '7.5', 'seven'] as $bad) {
        expect(fn () => $svc->submit(['variant_id' => $this->variant->id, 'fiscal_power_cv' => $bad], $this->maker))->toThrow(ValidationException::class);
    }
    expect(fn () => app(FiscalPowerBands::class)->codeFor(0))->toThrow(InvalidArgumentException::class);
});

it('runs the review workflow DRAFT → SOURCE_ATTACHED → PENDING_REVIEW → VERIFIED through the API', function () {
    $draft = vpwrApi($this->maker, 'POST', "master-data/vehicles/{$this->variant->id}/fiscal-power", ['fiscal_power_cv' => 9])->assertCreated()->json('data');
    expect($draft['review_state'])->toBe('DRAFT');
    // MANUAL_VERIFIED needs the uploaded document before it can leave DRAFT.
    $att = vpwrApi($this->maker, 'POST', "master-data/fiscal-power/records/{$draft['id']}/source", ['source_type' => 'MANUAL_VERIFIED', 'source_reference' => 'Carte grise scan'])->assertOk()->json('data');
    expect($att['review_state'])->toBe('DRAFT');
    $att = vpwrApi($this->maker, 'POST', "master-data/fiscal-power/records/{$draft['id']}/source", ['source_document_id' => (string) \Illuminate\Support\Str::uuid()])->assertOk()->json('data');
    expect($att['review_state'])->toBe('PENDING_REVIEW');
    $audit = DB::table('vehicle_power_verification_audits')->where('subject_id', $draft['id'])->orderBy('created_at')->pluck('to_state')->all();
    expect($audit)->toContain('DRAFT', 'SOURCE_ATTACHED', 'PENDING_REVIEW');

    vpwrApi($this->maker, 'POST', "master-data/vehicles/{$this->variant->id}/fiscal-power/verify", ['record_id' => $draft['id']])->assertStatus(422);
    vpwrApi($this->viewer, 'POST', "master-data/vehicles/{$this->variant->id}/fiscal-power/verify", ['record_id' => $draft['id']])->assertForbidden();
    $v = vpwrApi($this->checker, 'POST', "master-data/vehicles/{$this->variant->id}/fiscal-power/verify", ['record_id' => $draft['id']])->assertOk()->json('data');
    expect($v['review_state'])->toBe('VERIFIED')->and($v['verification_status'])->toBe('VERIFIED_MANUAL_DOCUMENT')->and($v['fiscal_power_band_code'])->toBe('CV_08_13');

    $show = vpwrApi($this->viewer, 'GET', "master-data/vehicles/{$this->variant->id}/power")->assertOk()->json('data');
    expect($show['fiscal_power']['fiscal_power_cv'])->toBe(9);
    $found = vpwrApi($this->viewer, 'GET', 'master-data/vehicles/power/search?fiscal_power_band_code=CV_08_13')->assertOk()->json('data');
    expect(collect($found)->pluck('variant_id'))->toContain($this->variant->id);
    expect(vpwrApi($this->viewer, 'GET', 'master-data/fiscal-power/bands')->assertOk()->json('data'))->toHaveCount(4);
});

it('VPWR-007 a verified value is never overwritten: a new version supersedes it and keeps provenance', function () {
    $v1 = vpwrVerified(['variant_id' => $this->variant->id, 'fiscal_power_cv' => 9, 'source_reference' => 'CIVIC-A']);
    expect(fn () => DB::transaction(fn () => DB::table('vehicle_fiscal_power_records')->where('id', $v1->id)->update(['fiscal_power_cv' => 10])))->toThrow(QueryException::class, 'VPWR-007')
        ->and(fn () => DB::transaction(fn () => DB::table('vehicle_fiscal_power_records')->where('id', $v1->id)->delete()))->toThrow(QueryException::class, 'VPWR-007');

    // The same value re-confirmed by a later source supersedes (history kept).
    $v2 = vpwrVerified(['variant_id' => $this->variant->id, 'fiscal_power_cv' => 9, 'source_type' => 'CAMEROON_REGISTRATION_DOCUMENT', 'source_reference' => 'REG-B', 'effective_from' => '2026-10-10']);
    $old = app(FiscalPowerService::class)->find($v1->id);
    expect($v2->version)->toBe(2)->and($v2->supersedes_id)->toBe($v1->id)->and($old->review_state)->toBe('SUPERSEDED')
        ->and($old->source_reference)->toBe('CIVIC-A')->and($old->fiscal_power_cv)->toBe(9);
});

it('opens a conflict case when authoritative sources disagree and resolves it by maker-checker', function () {
    $v1 = vpwrVerified(['variant_id' => $this->variant->id, 'fiscal_power_cv' => 9]);
    $c = vpwrApi($this->maker, 'POST', "master-data/vehicles/{$this->variant->id}/fiscal-power/conflict", ['fiscal_power_cv' => 11, 'source_type' => 'CAMEROON_AUTHORITY_DATA', 'source_reference' => 'DGI-99'])
        ->assertCreated()->json('data');
    expect($c['review_state'])->toBe('CONFLICT_REVIEW_REQUIRED');
    $conflict = DB::table('vehicle_fiscal_power_conflicts')->where('record_id', $c['id'])->first();
    expect($conflict->status)->toBe('OPEN')->and($conflict->case_id)->not->toBeNull()
        ->and(DB::table('cases')->where('id', $conflict->case_id)->value('case_type_code'))->toBe('DATA_STEWARD')
        ->and(DB::table('outbox_messages')->where('event_name', 'vehicle.fiscal_power.conflict_detected')->exists())->toBeTrue();
    // The verified value is untouched while the conflict is open.
    expect(app(FiscalPowerService::class)->currentFor($this->variant->id, null)['fiscal_power_cv'])->toBe(9);

    // A second verification of a different value also goes to conflict rather than overwriting.
    $svc = app(FiscalPowerService::class);
    $other = $svc->submit(['variant_id' => $this->variant->id, 'fiscal_power_cv' => 12, 'source_type' => 'CIVIC', 'source_reference' => 'CIVIC-Z'], $this->maker, $this->tenant->id);
    expect($svc->verify($other->id, $this->checker, null, $this->tenant->id)->review_state)->toBe('CONFLICT_REVIEW_REQUIRED');

    vpwrApi($this->maker, 'POST', "master-data/fiscal-power/conflicts/{$conflict->id}/resolve", ['accept_challenger' => true, 'reason' => 'self'])->assertStatus(422);
    vpwrApi($this->checker, 'POST', "master-data/fiscal-power/conflicts/{$conflict->id}/resolve", ['accept_challenger' => true, 'reason' => 'Authority data confirmed by DGI'])->assertOk();
    expect($svc->currentFor($this->variant->id, null)['fiscal_power_cv'])->toBe(11)
        ->and($svc->find($v1->id)->review_state)->toBe('SUPERSEDED');
});

it('VPWR-008 derives the stamp duty band from the verified fiscal CV, never from hp or the declared fact', function () {
    vpwrApproveBaseline();
    app(\App\Application\Vehicles\Power\VehiclePowerService::class)->record($this->variant, ['power_source_value' => 400, 'power_source_unit' => 'HP'], $this->maker);
    vpwrVerified(['variant_id' => $this->variant->id, 'fiscal_power_cv' => 6]);
    $r = app(VehicleStampDutyService::class)->resolve('MOTOR', ['vehicle_variant_code' => $this->variant->code, 'fiscal_power' => 25, 'power_hp' => 400], $this->tenant->id, '2026-10-10');
    expect($r['fiscal_power_band_code'])->toBe('CV_02_07')->and($r['rate_xaf'])->toBe(30000)->and($r['declared_fiscal_power'])->toBe(25);

    // A vehicle-specific (registration) CIVIC value wins over the variant value.
    vpwrVerified(['registration_number' => 'LT 1 A', 'fiscal_power_cv' => 16]);
    $reg = app(VehicleStampDutyService::class)->resolve('MOTOR', ['vehicle_variant_code' => $this->variant->code, 'registration_number' => 'LT1A'], $this->tenant->id, '2026-10-10');
    expect($reg['fiscal_power_band_code'])->toBe('CV_14_20')->and($reg['rate_xaf'])->toBe(75000);
});

it('VPWR-009 the transport schedule requires a verified valid licence (API)', function () {
    vpwrApproveBaseline();
    vpwrVerified(['registration_number' => 'CE 9 T', 'fiscal_power_cv' => 30]);
    $lic = vpwrApi($this->maker, 'POST', 'master-data/vehicles/transport-licences', ['registration_number' => 'CE 9 T', 'licence_number' => 'TL-9', 'valid_from' => '2026-01-01'])->assertCreated()->json('data');
    expect(app(VehicleStampDutyService::class)->resolve('MOTOR', ['registration_number' => 'CE9T'], $this->tenant->id, '2026-10-10')['rate_xaf'])->toBe(200000);
    vpwrApi($this->maker, 'POST', "master-data/vehicles/transport-licences/{$lic['id']}/decision", ['status' => 'VALID'])->assertStatus(422);
    vpwrApi($this->checker, 'POST', "master-data/vehicles/transport-licences/{$lic['id']}/decision", ['status' => 'VALID'])->assertOk();
    expect(app(VehicleStampDutyService::class)->resolve('MOTOR', ['registration_number' => 'CE9T'], $this->tenant->id, '2026-10-10')['rate_xaf'])->toBe(150000);
    vpwrApi($this->checker, 'POST', "master-data/vehicles/transport-licences/{$lic['id']}/decision", ['status' => 'SUSPENDED'])->assertOk();
    expect(app(VehicleStampDutyService::class)->resolve('MOTOR', ['registration_number' => 'CE9T'], $this->tenant->id, '2026-10-10')['schedule_code'])->toBe('OTHER_VEHICLES');
});

it('applies exemptions only through an approved TAX_EXEMPTION rule set (never from make / model)', function () {
    vpwrApproveBaseline();
    vpwrVerified(['registration_number' => 'AMB 1', 'fiscal_power_cv' => 10]);
    $svc = app(VehicleStampDutyService::class);
    // No rule: a vehicle described as an ambulance is still charged.
    expect($svc->resolve('MOTOR', ['registration_number' => 'AMB1', 'model_name' => 'Ambulance'], $this->tenant->id, '2026-10-10')['status'])->toBe('APPLIED');

    $rules = app(RuleSetService::class);
    expect(fn () => $rules->createDraft(['code' => 'CM_STAMP_EXEMPT_BAD', 'domain' => 'TAX_EXEMPTION', 'line_code' => 'MOTOR', 'effective_from' => '2026-01-01',
        'rules' => [['code' => 'NO_BASIS', 'condition' => ['op' => 'EQUAL', 'left' => ['fact' => 'vehicle.legal_status'], 'right' => ['value' => 'AMBULANCE_VERIFIED']], 'outcome' => ['result' => 'EXEMPT', 'charge_codes' => ['AUTOMOBILE_STAMP_DUTY']]]]], $this->maker))
        ->toThrow(ValidationException::class);
    $set = $rules->createDraft(['code' => 'CM_STAMP_EXEMPT', 'domain' => 'TAX_EXEMPTION', 'line_code' => 'MOTOR', 'effective_from' => '2026-01-01',
        'rules' => [['code' => 'VERIFIED_AMBULANCE', 'condition' => ['op' => 'EQUAL', 'left' => ['fact' => 'vehicle.legal_status'], 'right' => ['value' => 'AMBULANCE_VERIFIED']],
            'outcome' => ['result' => 'EXEMPT', 'charge_codes' => ['AUTOMOBILE_STAMP_DUTY'], 'legal_basis' => 'Owner-confirmed exemption (test)']]]], $this->maker);
    // Draft rule sets never apply.
    expect($svc->resolve('MOTOR', ['registration_number' => 'AMB1', 'vehicle.legal_status' => 'AMBULANCE_VERIFIED'], $this->tenant->id, '2026-10-10')['status'])->toBe('APPLIED');
    RuleSet::whereKey($set->id)->update(['status' => 'APPROVED']);

    $ex = $svc->resolve('MOTOR', ['registration_number' => 'AMB1', 'vehicle.legal_status' => 'AMBULANCE_VERIFIED'], $this->tenant->id, '2026-10-10');
    expect($ex['status'])->toBe('EXEMPT')->and($ex['rate_xaf'])->toBe(0)->and($ex['exemption']['rule_code'])->toBe('VERIFIED_AMBULANCE')
        ->and(VehicleStampDutyService::chargeTable($ex))->toBeNull();
    // Unknown status (fact missing) never exempts.
    expect($svc->resolve('MOTOR', ['registration_number' => 'AMB1'], $this->tenant->id, '2026-10-10')['status'])->toBe('APPLIED');
});

it('requires RBAC on every endpoint and refuses unknown schedules / incomplete rate tables', function () {
    $noPerm = makeAuthTestUser($this->tenant, [], 'VPWR_NONE');
    vpwrApi($noPerm, 'GET', 'master-data/fiscal-power/bands')->assertForbidden();
    vpwrApi($noPerm, 'GET', 'master-data/fiscal-power/stamp-duty-rates')->assertForbidden();
    vpwrApi($this->viewer, 'POST', "master-data/vehicles/{$this->variant->id}/power", ['power_source_value' => 100, 'power_source_unit' => 'KW'])->assertForbidden();
    vpwrApi($this->viewer, 'POST', 'master-data/fiscal-power/rate-schedules/version', [])->assertForbidden();
    vpwrApi($this->maker, 'POST', 'master-data/fiscal-power/rate-schedules/version', ['schedule_code' => 'OTHER_VEHICLES', 'effective_from' => '2027-01-01', 'legal_reference' => 'x',
        'rates_xaf' => ['CV_02_07' => 1]])->assertStatus(422);
    vpwrApi($this->maker, 'POST', 'master-data/fiscal-power/rate-schedules/version', ['schedule_code' => 'LUXURY', 'effective_from' => '2027-01-01', 'legal_reference' => 'x',
        'rates_xaf' => ['CV_02_07' => 1, 'CV_08_13' => 1, 'CV_14_20' => 1, 'CV_GT_20' => 1]])->assertStatus(422);
    // Seeded baseline carries the owner's XAF rates, DRAFT until a checker approves them.
    $all = vpwrApi($this->viewer, 'GET', 'master-data/fiscal-power/stamp-duty-rates?all=1')->assertOk()->json('data');
    expect(collect($all)->firstWhere('schedule_code', 'PUBLIC_PASSENGER_AND_GOODS_TRANSPORT'))->toMatchArray(['status' => 'DRAFT', 'transport_license_required' => true,
        'rates_xaf' => ['CV_02_07' => 15000, 'CV_08_13' => 25000, 'CV_14_20' => 50000, 'CV_GT_20' => 150000]])
        ->and(collect($all)->firstWhere('schedule_code', 'OTHER_VEHICLES')['rates_xaf'])->toBe(['CV_02_07' => 30000, 'CV_08_13' => 50000, 'CV_14_20' => 75000, 'CV_GT_20' => 200000]);
});
