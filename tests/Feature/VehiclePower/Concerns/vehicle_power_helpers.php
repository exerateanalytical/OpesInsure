<?php

declare(strict_types=1);

use App\Application\Vehicles\Power\FiscalPowerService;
use App\Application\Vehicles\Power\VehicleStampDutyService;
use App\Models\Carrier;
use App\Models\InsuranceProduct;
use App\Models\Party;
use App\Models\Quote;
use App\Models\TariffVersion;
use App\Models\User;
use App\Models\Vehicles\VehicleMake;
use App\Models\Vehicles\VehicleModel;
use App\Models\Vehicles\VehicleVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';
require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';

if (! defined('VPWR_PERMS')) {
    define('VPWR_PERMS',['vehicle_power.view', 'vehicle_power.manage', 'vehicle_power.fiscal.submit', 'vehicle_power.fiscal.verify', 'vehicle_power.stamp_duty.manage', 'vehicle_power.stamp_duty.approve']);
}

if (! function_exists('vpwrSetUp')) {
    function vpwrSetUp(): void
    {
        $t = test();
        $t->fx = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
        $t->tenant = $t->fx['tenant'];
        $t->maker = makeAuthTestUser($t->tenant, VPWR_PERMS, 'VPWR_MAKER');
        $t->checker = makeAuthTestUser($t->tenant, VPWR_PERMS, 'VPWR_CHECKER');
        $t->viewer = makeAuthTestUser($t->tenant, ['vehicle_power.view'], 'VPWR_VIEWER');
        $make = VehicleMake::create(['code' => 'VP_'.Str::upper(Str::random(5)), 'name' => 'Make', 'normalized_name' => 'MAKE'.Str::random(4), 'provenance' => 'MANUAL_VERIFIED']);
        $model = VehicleModel::create(['code' => $make->code.'_M', 'make_id' => $make->id, 'name' => 'Model', 'normalized_name' => 'MODEL', 'provenance' => 'MANUAL_VERIFIED']);
        $t->variant = VehicleVariant::forceCreate(['id' => (string) Str::uuid(), 'model_id' => $model->id, 'code' => $make->code.'_V1', 'name' => '2.0 petrol', 'engine_capacity_cc' => 1998, 'provenance' => 'MANUAL_VERIFIED', 'active' => true]);
    }

    function vpwrApi(User $user, string $method, string $uri, array $body = [])
    {
        Passport::actingAs($user);

        return test()->json($method, '/api/v1/'.$uri, $body, tenantHeaderFor(test()->tenant));
    }

    /** Maker submits (with provenance), checker verifies. */
    function vpwrVerified(array $d, ?User $maker = null, ?User $checker = null): object
    {
        $svc = app(FiscalPowerService::class);
        $r = $svc->submit($d + ['source_type' => 'CIVIC', 'source_reference' => 'CIVIC-'.Str::random(8)], $maker ?? test()->maker, test()->tenant->id);

        return $svc->verify($r->id, $checker ?? test()->checker, null, test()->tenant->id);
    }

    /** Approve the seeded DRAFT baseline schedules (checker step required in each environment). */
    function vpwrApproveBaseline(): void
    {
        foreach (DB::table('vehicle_stamp_duty_rate_schedules')->where('status', 'DRAFT')->pluck('id') as $id) {
            app(VehicleStampDutyService::class)->approve($id, test()->checker);
        }
    }

    function vpwrValidLicence(string $registration): object
    {
        $svc = app(VehicleStampDutyService::class);
        $l = $svc->recordLicence(test()->tenant->id, ['registration_number' => $registration, 'licence_number' => 'LIC-'.Str::random(6), 'valid_from' => now()->subMonth()->toDateString(),
            'valid_until' => now()->addYear()->toDateString()], test()->maker);

        return $svc->decideLicence(test()->tenant->id, $l->id, 'VALID', test()->checker);
    }

    function vpwrProduct(): InsuranceProduct
    {
        $carrier = Carrier::create(['party_id' => Party::create(['type' => 'ORGANIZATION', 'display_name' => 'VP Carrier', 'status' => 'ACTIVE'])->id, 'cima_code' => 'CIMA-'.Str::random(6), 'status' => 'ACTIVE']);
        $product = InsuranceProduct::create(['carrier_id' => $carrier->id, 'line_code' => 'MOTOR', 'code' => 'MOTOR-VP-'.Str::random(4), 'name' => 'Motor VP', 'version' => 1, 'effective_from' => '2026-01-01', 'status' => 'ACTIVE']);
        $rules = ['base_premium_minor' => 100000];
        TariffVersion::create(['insurance_product_id' => $product->id, 'version' => 1, 'effective_from' => '2026-01-01', 'status' => 'ACTIVE', 'input_schema' => [], 'rules' => $rules,
            'rules_hash' => app(\App\Application\Shared\CanonicalJson::class)->hash($rules)]);

        return $product;
    }

    function vpwrQuote(array $facts): Quote
    {
        return Quote::create(['tenant_id' => test()->tenant->id, 'party_id' => test()->fx['party']->id, 'line_code' => 'MOTOR', 'status' => 'SUBMITTED', 'currency' => 'XAF', 'risk_facts' => $facts]);
    }
}
