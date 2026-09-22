<?php

declare(strict_types=1);

use App\Models\ReleaseCandidate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Concerns/wave_auth_helpers.php';

it('rejects an unauthenticated security-finding request with 401', function () {
    $tenant = makeAuthTestTenant();

    $this->postJson('/api/v1/release-assurance/security-findings', [], tenantHeader($tenant))
        ->assertStatus(401);
});

it('blocks security-finding creation without the permission, and audits the denial', function () {
    $tenant = makeAuthTestTenant();
    $user = makeAuthTestUser($tenant, ['some.unrelated.permission']);
    Passport::actingAs($user);

    $this->postJson('/api/v1/release-assurance/security-findings', [
        'source' => 'DAST', 'severity' => 'HIGH', 'title' => 'Reflected XSS', 'description' => 'Found via automated scan.',
    ], tenantHeader($tenant))->assertStatus(403);

    expect(DB::table('audit_log')->where('action', 'authorization.denied')->where('actor_id', $user->id)->exists())->toBeTrue();
});

it('allows security-finding creation with the permission, and audits the success', function () {
    $tenant = makeAuthTestTenant();
    $user = makeAuthTestUser($tenant, ['releases.security-findings.create']);
    Passport::actingAs($user);

    $response = $this->postJson('/api/v1/release-assurance/security-findings', [
        'source' => 'DAST', 'severity' => 'HIGH', 'title' => 'Reflected XSS', 'description' => 'Found via automated scan.',
    ], tenantHeader($tenant));

    $response->assertStatus(201);
    expect(DB::table('audit_log')->where('action', 'releases.security-findings.create')->exists())->toBeTrue();
});

it('blocks recovery-exercise creation without the permission, and allows it with the permission', function () {
    $tenant = makeAuthTestTenant();
    $payload = ['environment' => 'staging', 'exercise_type' => 'BACKUP_RESTORE', 'target_rto_minutes' => 60, 'target_rpo_minutes' => 15];

    $unauthorized = makeAuthTestUser($tenant, []);
    Passport::actingAs($unauthorized);
    $this->postJson('/api/v1/release-assurance/recovery-exercises', $payload, tenantHeader($tenant))->assertStatus(403);

    $authorized = makeAuthTestUser($tenant, ['releases.recovery-exercises.create']);
    Passport::actingAs($authorized);
    $this->postJson('/api/v1/release-assurance/recovery-exercises', $payload, tenantHeader($tenant))->assertStatus(201);
});

it('does not let a release creator certify their own release', function () {
    // ReleaseCandidatePolicy::certify() folds the maker-checker rule into the ability
    // itself (hasPermission() && created_by !== user), so a self-certify attempt is
    // rejected by Gate::authorize as a 403 before ReleaseCertificationService's own
    // maker-checker check is ever reached — an earlier, stricter rejection point.
    $tenant = makeAuthTestTenant();
    $creator = makeAuthTestUser($tenant, ['releases.create', 'releases.certify']);
    Passport::actingAs($creator);

    $candidateId = $this->postJson('/api/v1/release-assurance/candidates', [
        'version' => '1.0.0', 'commit_sha' => str_repeat('a', 40), 'environment' => 'staging',
    ], tenantHeader($tenant))->json('id');

    $this->postJson("/api/v1/release-assurance/candidates/{$candidateId}/certify", [
        'expected_version' => 1,
    ], tenantHeader($tenant))->assertStatus(403);
});

it('rejects an unauthorized release certify attempt with 403 before the maker-checker rule is even reached', function () {
    $tenant = makeAuthTestTenant();
    $candidate = ReleaseCandidate::create([
        'id' => (string) Str::uuid(), 'version' => '1.0.0', 'commit_sha' => str_repeat('a', 40),
        'environment' => 'staging', 'status' => 'ASSESSING', 'created_by' => (string) Str::uuid(), 'lock_version' => 1,
    ]);
    $user = makeAuthTestUser($tenant, []);
    Passport::actingAs($user);

    $this->postJson("/api/v1/release-assurance/candidates/{$candidate->id}/certify", [
        'expected_version' => 1,
    ], tenantHeader($tenant))->assertStatus(403);
});
