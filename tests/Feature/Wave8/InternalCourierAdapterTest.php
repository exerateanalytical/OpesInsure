<?php

declare(strict_types=1);

use App\Application\Logistics\Adapters\InternalCourierAdapter;
use App\Models\Courier;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Concerns/wave_auth_helpers.php';

it('returns only ACTIVE couriers whose service_areas include the requested city', function () {
    $tenant = makeAuthTestTenant();

    $douala = Courier::create(['tenant_id' => $tenant->id, 'name' => 'Douala Courier', 'type' => 'INTERNAL', 'phone_hash' => 'x', 'status' => 'ACTIVE', 'service_areas' => ['Douala', 'Edea']]);
    Courier::create(['tenant_id' => $tenant->id, 'name' => 'Yaounde Only', 'type' => 'INTERNAL', 'phone_hash' => 'x', 'status' => 'ACTIVE', 'service_areas' => ['Yaounde']]);
    Courier::create(['tenant_id' => $tenant->id, 'name' => 'Inactive Douala', 'type' => 'INTERNAL', 'phone_hash' => 'x', 'status' => 'SUSPENDED', 'service_areas' => ['Douala']]);

    $result = (new InternalCourierAdapter)->checkAvailability($tenant->id, ['city' => 'douala']);

    expect($result)->toHaveCount(1);
    expect($result[0]['id'])->toBe($douala->id);
});

it('returns an empty array when no city is given', function () {
    $tenant = makeAuthTestTenant();
    Courier::create(['tenant_id' => $tenant->id, 'name' => 'Any', 'type' => 'INTERNAL', 'phone_hash' => 'x', 'status' => 'ACTIVE', 'service_areas' => ['Douala']]);

    expect((new InternalCourierAdapter)->checkAvailability($tenant->id, []))->toBe([]);
});

it('does not return couriers belonging to a different tenant', function () {
    $tenant = makeAuthTestTenant();
    $otherTenant = Tenant::create(['type' => 'CARRIER', 'legal_name' => 'Other Tenant', 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en']);
    Courier::create(['tenant_id' => $otherTenant->id, 'name' => 'Other Tenant Courier', 'type' => 'INTERNAL', 'phone_hash' => 'x', 'status' => 'ACTIVE', 'service_areas' => ['Douala']]);

    expect((new InternalCourierAdapter)->checkAvailability($tenant->id, ['city' => 'Douala']))->toBe([]);
});
