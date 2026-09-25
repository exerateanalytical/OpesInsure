<?php

declare(strict_types=1);

/**
 * REQ-API-004 — API families missing at cbcbecb (docs/audit/API_FAMILY_COVERAGE.md): underwriting referrals queue and
 * notifications list/detail. Happy path, 403 without permission, tenant isolation.
 */

use App\Application\Identity\RoleCatalogue;
use App\Models\UnderwritingCase;
use App\Models\UnderwritingReferralTask;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';
require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';

function b4Referral(string $phone): array
{
    $f = makeMobileCustomerFixture($phone);
    $case = UnderwritingCase::create(['tenant_id' => $f['tenant']->id, 'proposal_id' => $f['proposal']->id, 'carrier_id' => $f['carrier']->id, 'status' => 'QUEUED', 'priority' => 'HIGH', 'referral_reasons' => ['PRIOR_CLAIMS']]);
    $f['referral'] = UnderwritingReferralTask::create(['underwriting_case_id' => $case->id, 'reason_code' => 'PRIOR_CLAIMS', 'status' => 'OPEN', 'severity' => 'HIGH', 'due_at' => now()->addDay()]);
    $f['uw'] = makeAuthTestUser($f['tenant'], RoleCatalogue::defaultPermissions('UNDERWRITER'), 'UNDERWRITER');

    return $f;
}

function b4Delivery(array $f): string
{
    $tpl = (string) Str::uuid();
    DB::table('notification_templates')->insert(['id' => $tpl, 'tenant_id' => $f['tenant']->id, 'code' => 'B4_'.Str::random(6), 'purpose' => 'TRANSACTIONAL', 'channel' => 'SMS', 'locale' => 'en',
        'version' => 1, 'status' => 'ACTIVE', 'body' => 'Hello', 'required_variables' => '[]', 'created_at' => now(), 'updated_at' => now()]);
    $id = (string) Str::uuid();
    DB::table('notification_deliveries')->insert(['id' => $id, 'tenant_id' => $f['tenant']->id, 'party_id' => $f['party']->id, 'template_id' => $tpl, 'channel' => 'SMS',
        'destination_hash' => hash('sha256', '+237600000000'), 'status' => 'FAILED', 'attempts' => 1, 'max_attempts' => 5, 'payload' => '{}', 'idempotency_key' => 'b4-'.Str::random(8),
        'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

it('REQ-API-004 lists and shows the underwriting referrals queue for an underwriter', function () {
    $f = b4Referral('+237674400001');
    Passport::actingAs($f['uw']);
    $h = tenantHeaderFor($f['tenant']);
    $this->getJson('/api/v1/underwriting/referrals', $h)->assertOk()->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $f['referral']->id)->assertJsonPath('data.0.case.priority', 'HIGH')->assertJsonPath('meta.total', 1);
    $this->getJson('/api/v1/underwriting/referrals?status=RESOLVED', $h)->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/underwriting/referrals/{$f['referral']->id}", $h)->assertOk()->assertJsonPath('data.reason_code', 'PRIOR_CLAIMS');
});

it('REQ-API-004 refuses the referrals queue without carrier.referrals.read', function () {
    $f = b4Referral('+237674400002');
    Passport::actingAs(makeAuthTestUser($f['tenant'], ['policies.read']));
    $h = tenantHeaderFor($f['tenant']);
    $this->getJson('/api/v1/underwriting/referrals', $h)->assertForbidden();
    $this->getJson("/api/v1/underwriting/referrals/{$f['referral']->id}", $h)->assertForbidden();
});

it('REQ-API-004 isolates the referrals queue per tenant', function () {
    $a = b4Referral('+237674400003');
    $b = b4Referral('+237674400004');
    Passport::actingAs($a['uw']);
    $h = tenantHeaderFor($a['tenant']);
    $this->getJson('/api/v1/underwriting/referrals', $h)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $a['referral']->id);
    $this->getJson("/api/v1/underwriting/referrals/{$b['referral']->id}", $h)->assertNotFound();
});

it('REQ-API-004 lists and shows notification deliveries without the destination hash', function () {
    $f = makeMobileCustomerFixture('+237674400011');
    $id = b4Delivery($f);
    Passport::actingAs(makeAuthTestUser($f['tenant'], ['communications.manage']));
    $h = tenantHeaderFor($f['tenant']);
    $this->getJson('/api/v1/notifications?status=failed&channel=SMS', $h)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id)
        ->assertJsonMissingPath('data.0.destination_hash');
    $this->getJson('/api/v1/notifications?channel=EMAIL', $h)->assertOk()->assertJsonCount(0, 'data');
    $this->getJson("/api/v1/notifications/{$id}", $h)->assertOk()->assertJsonPath('data.status', 'FAILED')->assertJsonPath('data.attempts', 1);
});

it('REQ-API-004 refuses notification reads without communications.manage', function () {
    $f = makeMobileCustomerFixture('+237674400012');
    $id = b4Delivery($f);
    Passport::actingAs(makeAuthTestUser($f['tenant'], ['policies.read']));
    $h = tenantHeaderFor($f['tenant']);
    $this->getJson('/api/v1/notifications', $h)->assertForbidden();
    $this->getJson("/api/v1/notifications/{$id}", $h)->assertForbidden();
});

it('REQ-API-004 isolates notification deliveries per tenant', function () {
    $a = makeMobileCustomerFixture('+237674400013');
    $b = makeMobileCustomerFixture('+237674400014');
    $mine = b4Delivery($a);
    $other = b4Delivery($b);
    Passport::actingAs(makeAuthTestUser($a['tenant'], ['communications.manage']));
    $h = tenantHeaderFor($a['tenant']);
    $this->getJson('/api/v1/notifications', $h)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine);
    $this->getJson("/api/v1/notifications/{$other}", $h)->assertNotFound();
});
