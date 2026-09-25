<?php

declare(strict_types=1);

/**
 * Agent V1 — every acceptance_tests entry of
 * docs/spec/canonical/OpesInsure_Cameroon_Vehicle_Power_Fiscal_Power_Institutional_Master_v1.json.
 */

use App\Application\Quotes\QuoteService;
use App\Application\Rating\RatingService;
use App\Application\Temporal\ReferenceInstant;
use App\Application\Vehicles\PolicyVehicleSnapshotService;
use App\Application\Vehicles\Power\FiscalPowerBands;
use App\Application\Vehicles\Power\FiscalPowerService;
use App\Application\Vehicles\Power\PowerUnits;
use App\Application\Vehicles\Power\VehiclePowerService;
use App\Application\Vehicles\Power\VehicleStampDutyService;
use App\Models\QuoteOffer;
use App\Models\TariffVersion;
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

it('AT-01 normalizes a 100 kW vehicle without changing fiscal_power_cv', function () {
    $fp = vpwrVerified(['variant_id' => $this->variant->id, 'fiscal_power_cv' => 9]);
    $spec = app(VehiclePowerService::class)->record($this->variant, ['power_source_value' => 100, 'power_source_unit' => 'KW', 'power_source_type' => 'OFFICIAL_MANUFACTURER_SPECIFICATION', 'power_source_reference' => 'MFR-1'], $this->maker);

    expect((float) $spec->power_kw)->toBe(100.0)
        ->and((float) $spec->power_hp)->toBe(round(100 * PowerUnits::KW_TO_MECHANICAL_HP, 3))
        ->and((float) $spec->power_ps)->toBe(round(100 * PowerUnits::KW_TO_METRIC_PS, 3))
        ->and((float) $spec->power_source_value)->toBe(100.0)->and($spec->power_source_unit)->toBe('KW')
        ->and($spec->conversion_method)->toBe(PowerUnits::CONVERSION_METHOD)
        ->and(app(FiscalPowerService::class)->currentFor($this->variant->id, null)['fiscal_power_cv'])->toBe(9)
        ->and(DB::table('vehicle_fiscal_power_records')->where('id', $fp->id)->value('fiscal_power_cv'))->toBe(9);
});

it('AT-02 stores and displays mechanical hp and metric PS distinctly', function () {
    $spec = app(VehiclePowerService::class)->record($this->variant, ['power_source_value' => 150, 'power_source_unit' => 'PS'], $this->maker);
    expect((float) $spec->power_ps)->toBe(150.0)->and($spec->power_source_unit)->toBe('METRIC_PS')
        ->and((float) $spec->power_kw)->toBe(round(150 * PowerUnits::METRIC_PS_TO_KW, 3))
        ->and((float) $spec->power_hp)->not->toBe((float) $spec->power_ps);

    $show = vpwrApi($this->viewer, 'GET', "master-data/vehicles/{$this->variant->id}/power")->assertOk()->json('data.technical_power');
    expect($show['display']['ps'])->toBe('150.0')->and($show['display']['hp'])->toBe(number_format((float) $spec->power_hp, 1, '.', ''))
        ->and($show['display']['hp'])->not->toBe($show['display']['ps']);
    // VPWR-002: hp from kW uses the configured constant.
    expect(PowerUnits::normalize(100, 'KW')['power_hp'])->toBe(134.102);
});

it('AT-03 rejects writing PS / CV DIN into fiscal_power_cv', function () {
    $svc = app(FiscalPowerService::class);
    foreach (['PS', 'CV DIN', 'HP', 'KW'] as $unit) {
        expect(fn () => $svc->submit(['variant_id' => $this->variant->id, 'fiscal_power_cv' => 150, 'fiscal_power_unit' => $unit, 'source_type' => 'CIVIC', 'source_reference' => 'X'], $this->maker))
            ->toThrow(ValidationException::class, 'VPWR-003');
    }
    // Nor through the technical endpoint.
    vpwrApi($this->maker, 'POST', "master-data/vehicles/{$this->variant->id}/power", ['power_source_value' => 150, 'power_source_unit' => 'PS', 'fiscal_power_cv' => 150])
        ->assertStatus(422)->assertJsonValidationErrors('fiscal_power_cv');
    expect(DB::table('vehicle_fiscal_power_records')->count())->toBe(0);
});

it('AT-04 fiscal power cannot become VERIFIED without approved provenance', function () {
    $svc = app(FiscalPowerService::class);
    $r = $svc->submit(['variant_id' => $this->variant->id, 'fiscal_power_cv' => 8], $this->maker);
    expect($r->review_state)->toBe('DRAFT');
    expect(fn () => $svc->verify($r->id, $this->checker))->toThrow(ValidationException::class);
    // MANUAL_VERIFIED without the uploaded document stays DRAFT.
    $m = $svc->submit(['variant_id' => $this->variant->id, 'fiscal_power_cv' => 8, 'source_type' => 'MANUAL_VERIFIED', 'source_reference' => 'Carte grise'], $this->maker);
    expect($m->review_state)->toBe('DRAFT');
    // The database refuses it too (VPWR-005).
    expect(fn () => DB::transaction(fn () => DB::table('vehicle_fiscal_power_records')->where('id', $r->id)->update(['review_state' => 'VERIFIED', 'verification_status' => 'VERIFIED_CIVIC', 'verified_by' => $this->checker->id, 'verified_at' => now()])))
        ->toThrow(QueryException::class);
    // Maker cannot verify their own capture.
    $ok = $svc->submit(['variant_id' => $this->variant->id, 'fiscal_power_cv' => 8, 'source_type' => 'CIVIC', 'source_reference' => 'CIVIC-1'], $this->maker);
    expect($ok->review_state)->toBe('PENDING_REVIEW')
        ->and(fn () => $svc->verify($ok->id, $this->maker))->toThrow(ValidationException::class, 'Maker-checker');
});

it('AT-05 stores CIVIC-sourced fiscal power with source reference and verification date', function () {
    $v = vpwrVerified(['registration_number' => 'lt 123 aa', 'fiscal_power_cv' => 11, 'source_type' => 'CIVIC', 'source_reference' => 'CIVIC-2026-000123']);
    expect($v->review_state)->toBe('VERIFIED')->and($v->verification_status)->toBe('VERIFIED_CIVIC')->and($v->source_reference)->toBe('CIVIC-2026-000123')
        ->and($v->verified_at)->not->toBeNull()->and($v->verified_by)->toBe($this->checker->id)->and($v->registration_number)->toBe('LT123AA')
        ->and($v->fiscal_power_band_code)->toBe('CV_08_13')
        ->and(DB::table('vehicle_power_verification_audits')->where('subject_id', $v->id)->where('action', 'fiscal_power.verified')->exists())->toBeTrue()
        ->and(DB::table('outbox_messages')->where('event_name', 'vehicle.fiscal_power.verified')->where('aggregate_id', $v->id)->exists())->toBeTrue();
});

it('AT-06..09 maps fiscal_power_cv 7, 8, 14 and 21 to their bands', function (int $cv, string $band) {
    expect(app(FiscalPowerBands::class)->codeFor($cv))->toBe($band);
})->with([[7, 'CV_02_07'], [8, 'CV_08_13'], [14, 'CV_14_20'], [21, 'CV_GT_20'], [2, 'CV_02_07'], [13, 'CV_08_13'], [20, 'CV_14_20']]);

it('AT-10 transport-rate schedule cannot apply without a required valid transport licence', function () {
    vpwrApproveBaseline();
    vpwrVerified(['registration_number' => 'CE 100 AB', 'fiscal_power_cv' => 9]);
    $svc = app(VehicleStampDutyService::class);
    $facts = ['registration_number' => 'CE 100 AB', 'vehicle_usage' => 'PUBLIC_TRANSPORT'];

    // A PENDING (unverified) licence does not qualify.
    $pending = $svc->recordLicence($this->tenant->id, ['registration_number' => 'CE100AB', 'licence_number' => 'L-1', 'valid_from' => '2026-01-01'], $this->maker);
    $r = $svc->resolve('MOTOR', $facts, $this->tenant->id, '2026-10-10');
    expect($r['schedule_code'])->toBe('OTHER_VEHICLES')->and($r['transport_schedule_eligible'])->toBeFalse()->and($r['rate_xaf'])->toBe(50000);
    // The maker cannot verify their own licence; the DB refuses an unverified VALID.
    expect(fn () => $svc->decideLicence($this->tenant->id, $pending->id, 'VALID', $this->maker))->toThrow(ValidationException::class)
        ->and(fn () => DB::transaction(fn () => DB::table('vehicle_transport_licences')->where('id', $pending->id)->update(['status' => 'VALID'])))->toThrow(QueryException::class);
    // An expired licence does not qualify either.
    $svc->decideLicence($this->tenant->id, $pending->id, 'VALID', $this->checker);
    DB::table('vehicle_transport_licences')->where('id', $pending->id)->update(['valid_until' => '2026-06-30']);
    expect($svc->resolve('MOTOR', $facts, $this->tenant->id, '2026-10-10')['schedule_code'])->toBe('OTHER_VEHICLES');
});

it('AT-11 selects the other-vehicle schedule when transport eligibility is not met, transport when it is', function () {
    vpwrApproveBaseline();
    vpwrVerified(['registration_number' => 'CE 200 AB', 'fiscal_power_cv' => 15]);
    $svc = app(VehicleStampDutyService::class);
    $other = $svc->resolve('MOTOR', ['registration_number' => 'CE200AB'], $this->tenant->id, '2026-10-10');
    expect($other['status'])->toBe('APPLIED')->and($other['schedule_code'])->toBe('OTHER_VEHICLES')->and($other['fiscal_power_band_code'])->toBe('CV_14_20')->and($other['rate_xaf'])->toBe(75000);

    vpwrValidLicence('CE 200 AB');
    $transport = $svc->resolve('MOTOR', ['registration_number' => 'CE200AB'], $this->tenant->id, '2026-10-10');
    expect($transport['schedule_code'])->toBe('PUBLIC_PASSENGER_AND_GOODS_TRANSPORT')->and($transport['rate_xaf'])->toBe(50000);
});

it('AT-12 changing an effective-dated rate creates a new version (API, maker-checker) instead of editing history', function () {
    vpwrApproveBaseline();
    $before = DB::table('vehicle_stamp_duty_rates')->orderBy('id')->get(['id', 'schedule_id', 'band_code', 'rate_xaf'])->toArray();
    $v2 = vpwrApi($this->maker, 'POST', 'master-data/fiscal-power/rate-schedules/version', ['schedule_code' => 'OTHER_VEHICLES', 'effective_from' => '2027-01-01',
        'rates_xaf' => ['CV_02_07' => 31000, 'CV_08_13' => 51000, 'CV_14_20' => 76000, 'CV_GT_20' => 201000], 'legal_reference' => 'Finance law 2027 (test)'])->assertCreated()->json('data');
    expect($v2['version'])->toBe(2)->and($v2['status'])->toBe('DRAFT');
    vpwrApi($this->maker, 'POST', "master-data/fiscal-power/rate-schedules/{$v2['id']}/approve")->assertStatus(422);
    vpwrApi($this->checker, 'POST', "master-data/fiscal-power/rate-schedules/{$v2['id']}/approve")->assertOk()->assertJsonPath('data.status', 'APPROVED');
    $all = vpwrApi($this->viewer, 'GET', 'master-data/fiscal-power/stamp-duty-rates?all=1')->assertOk()->json('data');
    expect(collect($all)->where('schedule_code', 'OTHER_VEHICLES')->pluck('version')->sort()->values()->all())->toBe([1, 2])
        ->and(DB::table('vehicle_stamp_duty_rates')->whereIn('id', array_column($before, 'id'))->orderBy('id')->get(['id', 'schedule_id', 'band_code', 'rate_xaf'])->toArray())->toEqual($before);
    $current = vpwrApi($this->viewer, 'GET', 'master-data/fiscal-power/stamp-duty-rates?at=2027-02-01')->assertOk()->json('data');
    expect(collect($current)->firstWhere('schedule_code', 'OTHER_VEHICLES')['rates_xaf']['CV_08_13'])->toBe(51000);
});

it('AT-12b approved rates are immutable and the old version keeps applying to its own dates', function () {
    vpwrApproveBaseline();
    $svc = app(VehicleStampDutyService::class);
    vpwrVerified(['registration_number' => 'CE 300 AB', 'fiscal_power_cv' => 10]);
    $v1 = collect($svc->schedules('2026-10-10'))->firstWhere('schedule_code', 'OTHER_VEHICLES');

    // Approved rate rows cannot be edited in place.
    expect(fn () => DB::transaction(fn () => DB::table('vehicle_stamp_duty_rates')->where('schedule_id', $v1['id'])->update(['rate_xaf' => 1])))->toThrow(QueryException::class);

    $v2 = $svc->createVersion(['schedule_code' => 'OTHER_VEHICLES', 'effective_from' => '2027-01-01', 'legal_reference' => 'Finance law 2027 (test)',
        'rates_xaf' => ['CV_02_07' => 31000, 'CV_08_13' => 51000, 'CV_14_20' => 76000, 'CV_GT_20' => 201000]], $this->maker);
    expect(fn () => $svc->approve($v2['id'], $this->maker))->toThrow(ValidationException::class, 'Maker-checker');
    $svc->approve($v2['id'], $this->checker);

    expect(DB::table('vehicle_stamp_duty_rate_schedules')->where('id', $v1['id'])->value('effective_until'))->toBe('2026-12-31')
        ->and($svc->resolve('MOTOR', ['registration_number' => 'CE300AB'], $this->tenant->id, '2026-10-10')['rate_xaf'])->toBe(50000)
        ->and($svc->resolve('MOTOR', ['registration_number' => 'CE300AB'], $this->tenant->id, '2027-02-01')['rate_xaf'])->toBe(51000)
        ->and(DB::table('vehicle_stamp_duty_rates')->where('schedule_id', $v1['id'])->where('band_code', 'CV_08_13')->value('rate_xaf'))->toBe(50000)
        ->and(DB::table('outbox_messages')->where('event_name', 'vehicle.stamp_duty_schedule.approved')->count())->toBe(3);
});

it('AT-13 routes a quote with unknown fiscal power to review, and prices it once verified', function () {
    vpwrProduct();
    vpwrApproveBaseline();
    // The declared fiscal_power fact is not authoritative and is never used.
    $quote = app(QuoteService::class)->rate(vpwrQuote(['registration_number' => 'LT 777 ZZ', 'fiscal_power' => 7, 'usage_type' => 'PRIVATE']), $this->fx['user']);
    $skipped = $quote->comparison_context['skipped'];
    expect($quote->lifecycle_state)->toBe('REFERRED')->and($skipped[0]['reason'])->toBe('FISCAL_POWER_REVIEW_REQUIRED')
        ->and(json_decode((string) DB::table('rating_runs')->where('quote_id', $quote->id)->value('fiscal_power_snapshot'), true)['status'])->toBe('REVIEW_REQUIRED');

    vpwrVerified(['registration_number' => 'LT777ZZ', 'fiscal_power_cv' => 12]);
    $quote = app(QuoteService::class)->rate($quote->refresh(), $this->fx['user']);
    $offer = QuoteOffer::where('quote_id', $quote->id)->where('status', 'OFFERED')->firstOrFail();
    $line = collect($offer->calculation_breakdown)->firstWhere('code', 'AUTOMOBILE_STAMP_DUTY');
    expect($quote->lifecycle_state)->toBe('CALCULATED')->and($line['amount_minor'])->toBe(50000)->and($line['source_table'])->toBe('vehicle_stamp_duty_rate_schedules')
        ->and($offer->tax_minor)->toBe(50000);

    // Issuance gate: a motor issuance without a resolved snapshot is REVIEW_REQUIRED.
    expect(fn () => app(VehicleStampDutyService::class)->assertIssuable(null, 'MOTOR', ['registration_number' => 'UNKNOWN 1'], $this->tenant->id))
        ->toThrow(ValidationException::class, 'REVIEW_REQUIRED');
    app(VehicleStampDutyService::class)->assertIssuable($offer->rating_run_id, 'MOTOR', $quote->risk_facts, $this->tenant->id);
});

it('AT-13b leaves rating unchanged while no stamp duty schedule is approved (seeded DRAFT baseline)', function () {
    $r = app(VehicleStampDutyService::class)->resolve('MOTOR', ['registration_number' => 'X1'], $this->tenant->id, '2026-10-10');
    expect($r['status'])->toBe('NOT_CONFIGURED')
        ->and(app(VehicleStampDutyService::class)->resolve('HEALTH', [], $this->tenant->id, '2026-10-10')['status'])->toBe('NOT_APPLICABLE');
});

it('AT-14 historical policy retains the fiscal-power / rate snapshot used at issuance', function () {
    $product = vpwrProduct();
    vpwrApproveBaseline();
    vpwrVerified(['registration_number' => 'LT 555 HH', 'fiscal_power_cv' => 22]);
    $quote = app(QuoteService::class)->rate(vpwrQuote(['registration_number' => 'LT 555 HH', 'usage_type' => 'PRIVATE']), $this->fx['user']);
    $offer = QuoteOffer::where('quote_id', $quote->id)->where('status', 'OFFERED')->firstOrFail();
    $snap = json_decode((string) DB::table('rating_runs')->where('id', $offer->rating_run_id)->value('fiscal_power_snapshot'), true);
    expect($snap)->toMatchArray(['status' => 'APPLIED', 'fiscal_power_cv' => 22, 'fiscal_power_band_code' => 'CV_GT_20', 'schedule_code' => 'OTHER_VEHICLES', 'rate_xaf' => 200000, 'schedule_version' => 1]);

    // A later rate version and a new verified value do not change the stored run, and reproduction is identical.
    $svc = app(VehicleStampDutyService::class);
    $v2 = $svc->createVersion(['schedule_code' => 'OTHER_VEHICLES', 'effective_from' => '2026-11-01', 'legal_reference' => 'test', 'rates_xaf' => ['CV_02_07' => 1, 'CV_08_13' => 2, 'CV_14_20' => 3, 'CV_GT_20' => 4]], $this->maker);
    $svc->approve($v2['id'], $this->checker);
    $repro = app(RatingService::class)->reproduce($offer->rating_run_id);
    expect($repro['identical'])->toBeTrue()
        ->and(json_decode((string) DB::table('rating_runs')->where('id', $offer->rating_run_id)->value('fiscal_power_snapshot'), true)['rate_xaf'])->toBe(200000);

    // The policy vehicle snapshot freezes it.
    $proposal = $this->fx['proposal'];
    DB::table('proposals')->where('id', $proposal->id)->update(['quote_offer_id' => $offer->id]);
    $asset = \App\Models\RiskAsset::create(['tenant_id' => $this->tenant->id, 'party_id' => $this->fx['party']->id, 'type' => 'VEHICLE', 'display_name' => 'Car', 'facts' => ['registration_number' => 'LT 555 HH'], 'facts_hash' => str_repeat('a', 64), 'status' => 'ACTIVE', 'version' => 1]);
    DB::table('quotes')->where('id', $quote->id)->update(['risk_asset_id' => $asset->id]);
    $policy = makeMobileTestPolicy($proposal->refresh(), $this->tenant, $offer->carrier_id, $this->fx['party']->id);
    $ps = \App\Models\Vehicles\PolicyVehicleSnapshot::where('policy_id', $policy->id)->first() ?? app(PolicyVehicleSnapshotService::class)->capture($policy);
    expect($ps)->not->toBeNull()->and($ps->spec_snapshot['fiscal_power']['fiscal_power_cv'])->toBe(22)->and($ps->spec_snapshot['fiscal_power']['rate_xaf'])->toBe(200000);
});

it('AT-15 has no automated hp-to-fiscal-CV conversion anywhere', function () {
    // No code path accepts a technical value as fiscal power (VPWR-006).
    $svc = app(FiscalPowerService::class);
    foreach (['power_hp' => 120, 'power_kw' => 90, 'power_ps' => 122, 'displacement_cc' => 1998, 'cylinder_count' => 4] as $k => $v) {
        expect(fn () => $svc->submit(['variant_id' => $this->variant->id, $k => $v, 'fiscal_power_cv' => null, 'source_type' => 'CIVIC', 'source_reference' => 'X'], $this->maker))
            ->toThrow(ValidationException::class, 'VPWR-006');
    }
    // Recording technical power never creates fiscal power.
    app(VehiclePowerService::class)->record($this->variant, ['power_source_value' => 200, 'power_source_unit' => 'HP'], $this->maker);
    expect(DB::table('vehicle_fiscal_power_records')->count())->toBe(0)
        ->and(app(FiscalPowerService::class)->currentFor($this->variant->id, null))->toBeNull();
    // Static guard: the power / fiscal / stamp duty code has no conversion constant or method towards fiscal CV.
    foreach (glob(base_path('app/Application/Vehicles/Power/*.php')) as $file) {
        $src = file_get_contents($file);
        expect(preg_match('/(HP|KW|PS)_TO_(FISCAL|CV_FISCAL)|function\s+\w*(hp|kw|ps)To(Fiscal|Cv)\w*/i', $src))->toBe(0, $file);
    }
    expect(method_exists(PowerUnits::class, 'toFiscalCv'))->toBeFalse();
});
