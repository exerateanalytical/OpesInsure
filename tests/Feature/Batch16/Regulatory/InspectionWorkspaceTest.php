<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';
require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';

const B1_INSP_ADMIN = ['regulatory.inspections.manage', 'regulatory.inspections.approve', 'regulatory.inspections.view'];

function b1InsPolicy(array $f, int $premium, string $issued): string
{
    $id = (string) Str::uuid();
    DB::table('policies')->insert(['id' => $id, 'tenant_id' => $f['tenant']->id, 'proposal_id' => $f['proposal']->id, 'carrier_id' => $f['carrier']->id,
        'party_id' => $f['party']->id, 'status' => 'ACTIVE', 'coverage_starts_at' => $issued, 'coverage_ends_at' => '2026-01-01 00:00:00+00', 'issued_at' => $issued,
        'terms_snapshot' => json_encode(['line_code' => 'AUTO']), 'version' => 1, 'currency' => 'XAF', 'premium_minor' => $premium, 'is_demo' => false,
        'created_at' => $issued, 'updated_at' => $issued]);

    return $id;
}

it('opens a time-boxed, maker-checker approved, read-only inspection workspace with logged reads and exports', function () {
    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $h = tenantHeader($f['tenant']);
    $requester = makeAuthTestUser($f['tenant'], B1_INSP_ADMIN);
    $approver = makeAuthTestUser($f['tenant'], B1_INSP_ADMIN);
    $inspector = makeAuthTestUser($f['tenant'], ['regulatory.inspections.access']);
    b1InsPolicy($f, 1000, '2025-02-01 00:00:00+00');
    b1InsPolicy($f, 2000, '2025-03-01 00:00:00+00');

    Passport::actingAs($requester);
    $body = ['inspector_user_id' => $inspector->id, 'authority' => 'Regulator', 'reference' => 'INS-1', 'justification' => 'On-site inspection',
        'resources' => ['policies', 'audit'], 'starts_at' => now()->subMinute()->toIso8601String(), 'expires_at' => now()->addDay()->toIso8601String()];
    $this->postJson('/api/v1/regulatory/inspections', [...$body, 'resources' => ['users']], $h)->assertStatus(422);
    $ins = $this->postJson('/api/v1/regulatory/inspections', $body, $h)->assertCreated()->json('data');
    expect($ins['status'])->toBe('REQUESTED');
    $this->postJson("/api/v1/regulatory/inspections/{$ins['id']}/approve", [], $h)->assertStatus(422); // requester cannot approve

    Passport::actingAs($inspector);
    $this->getJson("/api/v1/regulatory/inspections/{$ins['id']}/workspace/policies", $h)->assertForbidden(); // not approved yet

    Passport::actingAs($approver);
    $this->postJson("/api/v1/regulatory/inspections/{$ins['id']}/approve", [], $h)->assertOk()->assertJsonPath('data.status', 'ACTIVE');

    Passport::actingAs($inspector);
    $read = $this->getJson("/api/v1/regulatory/inspections/{$ins['id']}/workspace/policies", $h)->assertOk()->json('data');
    expect($read['read_only'])->toBeTrue()->and($read['rows'])->toHaveCount(2)->and($read['rows'][0])->not->toHaveKey('terms_snapshot');
    $this->getJson("/api/v1/regulatory/inspections/{$ins['id']}/workspace/claims", $h)->assertForbidden(); // outside scope
    $csv = $this->get("/api/v1/regulatory/inspections/{$ins['id']}/workspace/policies/export", $h)->assertOk();
    expect($csv->headers->get('X-Content-Hash'))->toBe(hash('sha256', $csv->getContent()));
    expect(substr_count(trim($csv->getContent()), "\n"))->toBe(2);
    // read-only: no write route exists in the workspace
    $this->postJson("/api/v1/regulatory/inspections/{$ins['id']}/workspace/policies", [], $h)->assertStatus(405);

    Passport::actingAs($requester);
    $show = $this->getJson("/api/v1/regulatory/inspections/{$ins['id']}", $h)->assertOk()->json();
    expect($show['exports'])->toHaveCount(1)->and($show['exports'][0]['row_count'])->toBe(2)->and($show['exports'][0]['exported_by'])->toBe($inspector->id);
    expect(DB::table('privileged_access_events')->where(['privileged_access_grant_id' => $ins['privileged_access_grant_id'], 'event_type' => 'USED'])->count())->toBe(2);

    $this->postJson("/api/v1/regulatory/inspections/{$ins['id']}/close", ['reason' => 'done'], $h)->assertOk()->assertJsonPath('data.status', 'CLOSED');
    Passport::actingAs($inspector);
    $this->getJson("/api/v1/regulatory/inspections/{$ins['id']}/workspace/policies", $h)->assertForbidden();
    expect(DB::table('outbox_messages')->whereIn('event_name', ['regulatory.inspection.opened', 'regulatory.inspection.closed'])->count())->toBe(2);
});

it('denies access after the time box expires', function () {
    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $h = tenantHeader($f['tenant']);
    [$requester, $approver] = [makeAuthTestUser($f['tenant'], B1_INSP_ADMIN), makeAuthTestUser($f['tenant'], B1_INSP_ADMIN)];
    $inspector = makeAuthTestUser($f['tenant'], ['regulatory.inspections.access']);
    Passport::actingAs($requester);
    $ins = $this->postJson('/api/v1/regulatory/inspections', ['inspector_user_id' => $inspector->id, 'authority' => 'R', 'justification' => 'j', 'resources' => ['policies'],
        'starts_at' => now()->subMinute()->toIso8601String(), 'expires_at' => now()->addHour()->toIso8601String()], $h)->assertCreated()->json('data');
    Passport::actingAs($approver);
    $this->postJson("/api/v1/regulatory/inspections/{$ins['id']}/approve", [], $h)->assertOk();
    $this->travel(2)->hours();
    Passport::actingAs($inspector);
    $this->getJson("/api/v1/regulatory/inspections/{$ins['id']}/workspace/policies", $h)->assertForbidden();
});

it('reports per-policy profitability from the technical accounting bases', function () {
    $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $h = tenantHeader($f['tenant']);
    $p = b1InsPolicy($f, 36500, '2025-01-01 00:00:00+00');
    DB::table('policies')->where('id', $p)->update(['coverage_ends_at' => '2026-01-01 00:00:00+00']);
    Passport::actingAs(makeAuthTestUser($f['tenant'], ['regulatory.profitability.view']));
    $rows = $this->getJson("/api/v1/regulatory/profitability/policies?from=2025-01-01&to=2025-01-31&policy_id={$p}", $h)->assertOk()->json('data');
    expect($rows)->toHaveCount(1)->and($rows[0]['written_minor'])->toBe(36500)->and($rows[0]['earned_minor'])->toBe(3100)
        ->and($rows[0]['result_minor'])->toBe(3100)->and($rows[0]['loss_ratio_bp'])->toBe(0);
    Passport::actingAs(makeAuthTestUser($f['tenant'], []));
    $this->getJson('/api/v1/regulatory/profitability/policies?from=2025-01-01&to=2025-01-31', $h)->assertForbidden();
});
