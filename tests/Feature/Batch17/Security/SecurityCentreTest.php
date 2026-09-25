<?php

declare(strict_types=1);

use App\Application\Security\Findings\SecurityFindingService;
use App\Application\Security\Login\LoginActivityRecorder;
use App\Application\Security\PrivilegedAccessService;
use App\Models\PrivilegedAccessGrant;
use App\Models\UserDevice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';

function b7Body(string $userId, string $tenantId): array
{
    return ['user_id' => $userId, 'tenant_id' => $tenantId, 'purpose' => 'INCIDENT', 'justification' => str_repeat('Investigating production incident. ', 3),
        'starts_at' => now()->toISOString(), 'expires_at' => now()->addHour()->toISOString()];
}

it('REQ-SEC-001 retires the one-step privileged-access grant: it opens a request that a second person must approve', function () {
    $tenant = makeAuthTestTenant();
    $maker = makeAuthTestUser($tenant, ['compliance.access.grant']);
    $checker = makeAuthTestUser($tenant, ['compliance.access.approve']);
    $target = makeAuthTestUser($tenant, []);

    Passport::actingAs($maker);
    $id = $this->postJson('/api/v1/compliance/privileged-access', b7Body($target->id, $tenant->id), tenantHeader($tenant))
        ->assertCreated()->assertJsonPath('data.status', 'REQUESTED')->json('data.id');
    expect(DB::table('privileged_access_events')->where('privileged_access_grant_id', $id)->where('event_type', 'APPROVED')->exists())->toBeFalse();

    // The maker cannot approve their own request.
    $maker2 = makeAuthTestUser($tenant, ['compliance.access.approve']);
    DB::table('privileged_access_grants')->where('id', $id)->update(['requested_by' => $maker2->id]);
    Passport::actingAs($maker2);
    $this->postJson("/api/v1/compliance/privileged-access/{$id}/approve", [], tenantHeader($tenant))->assertStatus(422);

    Passport::actingAs($checker);
    $this->postJson("/api/v1/compliance/privileged-access/{$id}/approve", [], tenantHeader($tenant))->assertOk()->assertJsonPath('data.status', 'APPROVED');
    expect(PrivilegedAccessGrant::find($id)->approved_by)->toBe($checker->id);

    // Security-centre listing.
    $viewer = makeAuthTestUser($tenant, ['security.centre.read']);
    Passport::actingAs($viewer);
    $this->getJson('/api/v1/security-centre/privileged-access', tenantHeader($tenant))->assertOk()->assertJsonPath('data.0.id', $id);
});

it('REQ-SEC-001 legacy one-step grant is only available behind the rollback switch', function () {
    config(['security_centre.privileged_access.legacy_single_step_grant' => true]);
    $tenant = makeAuthTestTenant();
    $approver = makeAuthTestUser($tenant, ['compliance.access.grant']);
    $target = makeAuthTestUser($tenant, []);
    Passport::actingAs($approver);
    $this->postJson('/api/v1/compliance/privileged-access', b7Body($target->id, $tenant->id), tenantHeader($tenant))->assertCreated()->assertJsonPath('data.status', 'APPROVED');
});

it('REQ-SEC-001 caps the privileged-access window and expires grants when the window closes', function () {
    $tenant = makeAuthTestTenant();
    $maker = makeAuthTestUser($tenant, []);
    $target = makeAuthTestUser($tenant, []);
    $svc = app(PrivilegedAccessService::class);
    $d = ['purpose' => 'INCIDENT', 'justification' => str_repeat('x', 30), 'starts_at' => now(), 'expires_at' => now()->addHours(73), 'scope' => ['claims.*']];
    expect(fn () => $svc->request($tenant->id, $target, $d, $maker))->toThrow(ValidationException::class);

    $g = $svc->request($tenant->id, $target, [...$d, 'expires_at' => now()->addHour()], $maker);
    $this->travel(2)->hours();
    expect($svc->expireDue())->toBe(1)->and($g->refresh()->status)->toBe('EXPIRED');
    expect(DB::table('outbox_messages')->where('event_name', 'security.privileged_access.expired')->count())->toBe(1);
    $this->artisan('security:sweep')->assertSuccessful();
});

it('REQ-SEC-001 security findings register: lifecycle, maker-checker risk acceptance with expiry, reopen', function () {
    $tenant = makeAuthTestTenant();
    $reporter = makeAuthTestUser($tenant, ['security.findings.manage', 'security.findings.read', 'security.findings.accept_risk']);
    $risk = makeAuthTestUser($tenant, ['security.findings.manage', 'security.findings.accept_risk']);
    $plain = makeAuthTestUser($tenant, ['security.findings.manage']);

    Passport::actingAs($reporter);
    $id = $this->postJson('/api/v1/security-centre/findings', ['source' => 'PENTEST', 'severity' => 'HIGH', 'title' => 'IDOR on claims', 'description' => 'Details'], tenantHeader($tenant))
        ->assertCreated()->assertJsonPath('data.status', 'OPEN')->json('data.id');
    $url = "/api/v1/security-centre/findings/{$id}/transition";
    $this->postJson($url, ['to' => 'RESOLVED', 'notes' => 'x'], tenantHeader($tenant))->assertStatus(422); // OPEN → RESOLVED not allowed
    $this->postJson($url, ['to' => 'TRIAGED'], tenantHeader($tenant))->assertOk()->assertJsonPath('data.status', 'TRIAGED');
    // Reporter may not accept their own finding's risk.
    $this->postJson($url, ['to' => 'RISK_ACCEPTED', 'notes' => 'compensating control', 'risk_acceptance_expires_at' => now()->addMonth()->toISOString()], tenantHeader($tenant))->assertStatus(422);

    Passport::actingAs($plain);
    $this->postJson($url, ['to' => 'RISK_ACCEPTED', 'notes' => 'y', 'risk_acceptance_expires_at' => now()->addMonth()->toISOString()], tenantHeader($tenant))->assertForbidden();

    Passport::actingAs($risk);
    $this->postJson($url, ['to' => 'RISK_ACCEPTED', 'notes' => 'no expiry'], tenantHeader($tenant))->assertStatus(422);
    $this->postJson($url, ['to' => 'RISK_ACCEPTED', 'notes' => 'WAF rule in place', 'risk_acceptance_expires_at' => now()->addDay()->toISOString()], tenantHeader($tenant))
        ->assertOk()->assertJsonPath('data.status', 'RISK_ACCEPTED');

    $this->travel(2)->days();
    expect(app(SecurityFindingService::class)->reopenExpiredAcceptances())->toBe(1);

    Passport::actingAs($reporter);
    $show = $this->getJson("/api/v1/security-centre/findings/{$id}", tenantHeader($tenant))->assertOk()->assertJsonPath('data.status', 'OPEN')->json('data');
    expect(collect($show['events'])->pluck('to_status')->all())->toBe(['OPEN', 'TRIAGED', 'RISK_ACCEPTED', 'OPEN']);
    expect(DB::table('outbox_messages')->where('event_name', 'security.finding.status_changed')->count())->toBe(4);

    // Other tenants cannot see it.
    $other = makeAuthTestTenant();
    Passport::actingAs(makeAuthTestUser($other, ['security.findings.read']));
    $this->getJson("/api/v1/security-centre/findings/{$id}", tenantHeader($other))->assertNotFound();
});

it('REQ-SEC-001 records login activity with NEW_DEVICE and IMPOSSIBLE_TRAVEL flags only from real geo data', function () {
    config(['security_centre.login.geo_headers' => ['latitude' => 'X-Geo-Lat', 'longitude' => 'X-Geo-Lng', 'country' => 'X-Geo-Country']]);
    $tenant = makeAuthTestTenant();
    $user = makeAuthTestUser($tenant, []);
    $rec = app(LoginActivityRecorder::class);
    $req = fn (array $h) => tap(Request::create('/'), fn ($r) => $r->headers->add($h));

    $d1 = UserDevice::create(['user_id' => $user->id, 'device_fingerprint' => 'fp-1', 'name' => 'Phone', 'platform' => 'android', 'last_seen_at' => now()]);
    expect($rec->record($user, 'OTP', $d1->id, 'fp-1', 'Phone', 'android', true, '10.0.0.1', $req(['X-Geo-Lat' => '4.05', 'X-Geo-Lng' => '9.70', 'X-Geo-Country' => 'cm'])))->toBe([]);

    $d2 = UserDevice::create(['user_id' => $user->id, 'device_fingerprint' => 'fp-2', 'name' => 'Tablet', 'platform' => 'ios', 'last_seen_at' => now()]);
    // Douala → Paris a minute later.
    $flags = $rec->record($user, 'PASSWORD', $d2->id, 'fp-2', 'Tablet', 'ios', true, '10.0.0.2', $req(['X-Geo-Lat' => '48.85', 'X-Geo-Lng' => '2.35']));
    expect($flags)->toBe(['NEW_DEVICE', 'IMPOSSIBLE_TRAVEL']);
    // No geo headers → travel never evaluated.
    expect($rec->record($user, 'PASSWORD', $d2->id, 'fp-2', 'Tablet', 'ios', false, '10.0.0.3', $req([])))->toBe([]);

    $row = DB::table('login_activities')->where('user_id', $user->id)->where('ip_hash', hash('sha256', '10.0.0.1'))->first();
    expect($row->country_code)->toBe('CM')->and($row->device_fingerprint_hash)->toBe(hash('sha256', 'fp-1'));
    expect(DB::table('security_events')->where('user_id', $user->id)->where('type', 'LOGIN_ANOMALY')->count())->toBe(1);
    expect(DB::table('outbox_messages')->where('event_name', 'security.login.anomaly_detected')->count())->toBe(1);

    Passport::actingAs($user);
    expect($this->getJson('/api/v1/me/security/login-activity', tenantHeader($tenant))->assertOk()->json('data'))->toHaveCount(3);
    Passport::actingAs(makeAuthTestUser($tenant, ['security.centre.read']));
    $flagged = $this->getJson('/api/v1/security-centre/login-activity?flagged=1', tenantHeader($tenant))->assertOk()->json('data');
    expect($flagged)->toHaveCount(1)->and($flagged[0]['anomaly_flags'])->toBe(['NEW_DEVICE', 'IMPOSSIBLE_TRAVEL']);
});
