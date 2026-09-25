<?php

declare(strict_types=1);

use App\Application\Cases\Models\CaseTask;
use App\Application\Cases\Models\WorkCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';

const E10_ALL = ['compliance.cases.create', 'compliance.cases.read', 'compliance.cases.transition', 'compliance.findings.manage',
    'compliance.actions.manage', 'compliance.actions.verify', 'compliance.evidence.link'];

function e10OpenCase($test, $tenant, array $extra = []): array
{
    return $test->postJson('/api/v1/compliance/cases', [
        'type' => 'AML_REVIEW', 'subject_type' => 'CUSTOMER', 'subject_id' => (string) Str::uuid(), 'severity' => 'HIGH',
        'idempotency_key' => (string) Str::uuid(), ...$extra,
    ], tenantHeader($tenant))->assertCreated()->json('data');
}

it('opens a compliance case linked to a COMPLIANCE_INVESTIGATION work case and records structured findings', function () {
    $tenant = makeAuthTestTenant();
    Passport::actingAs(makeAuthTestUser($tenant, E10_ALL));

    $data = e10OpenCase($this, $tenant, ['findings' => [['title' => 'Missing source of funds', 'severity' => 'CRITICAL']]]);

    expect($data['case']['status'])->toBe('OPEN')
        ->and($data['work_case']['status'])->toBe('OPEN')
        ->and($data['findings'])->toHaveCount(1)
        ->and($data['findings'][0]['severity'])->toBe('CRITICAL');
    $work = WorkCase::withoutGlobalScopes()->find($data['work_case']['id']);
    expect($work->case_type_code)->toBe('COMPLIANCE_INVESTIGATION')->and($work->source_type)->toBe('compliance_cases')->and($work->priority)->toBe('HIGH');
    expect(DB::table('compliance_cases')->where('id', $data['case']['id'])->value('findings'))->toBe('[]');
    expect(DB::table('outbox_messages')->where('event_name', 'compliance.case.opened')->where('aggregate_id', $data['case']['id'])->exists())->toBeTrue();
});

it('runs findings → corrective action (owner, due date, case task) → maker-checker verification → closure, driving the work case', function () {
    $tenant = makeAuthTestTenant();
    $opener = makeAuthTestUser($tenant, E10_ALL);
    $owner = makeAuthTestUser($tenant, E10_ALL);
    $checker = makeAuthTestUser($tenant, E10_ALL);

    Passport::actingAs($opener);
    $caseId = e10OpenCase($this, $tenant)['case']['id'];
    $h = tenantHeader($tenant);

    $this->postJson("/api/v1/compliance/cases/{$caseId}/transition", ['to_status' => 'UNDER_REVIEW', 'reason_code' => 'TRIAGED'], $h)
        ->assertOk()->assertJsonPath('data.work_case.status', 'IN_PROGRESS');

    $finding = $this->postJson("/api/v1/compliance/cases/{$caseId}/findings", ['title' => 'KYC refresh overdue', 'severity' => 'MEDIUM'], $h)->assertCreated()->json('data');
    $this->postJson("/api/v1/compliance/cases/{$caseId}/findings", ['title' => 'Bad', 'severity' => 'SEVERE'], $h)->assertStatus(422);

    $action = $this->postJson("/api/v1/compliance/findings/{$finding['id']}/corrective-actions", [
        'description' => 'Refresh KYC for all affected customers', 'owner_user_id' => $owner->id, 'due_on' => now()->addDays(10)->toDateString(),
    ], $h)->assertCreated()->json('data');
    expect($action['status'])->toBe('PLANNED')->and($action['case_task_id'])->not->toBeNull();
    expect(CaseTask::find($action['case_task_id'])->assignee_user_id)->toBe($owner->id);
    expect(DB::table('compliance_findings')->where('id', $finding['id'])->value('status'))->toBe('REMEDIATING');

    // cannot close with an open finding
    Passport::actingAs($checker);
    $this->postJson("/api/v1/compliance/cases/{$caseId}/transition", ['to_status' => 'CLOSED', 'reason_code' => 'DONE'], $h)->assertStatus(422);

    Passport::actingAs($owner);
    $this->postJson("/api/v1/compliance/corrective-actions/{$action['id']}/events", ['event' => 'start'], $h)->assertOk()->assertJsonPath('data.status', 'IN_PROGRESS');
    $this->postJson("/api/v1/compliance/corrective-actions/{$action['id']}/events", ['event' => 'complete', 'notes' => 'Refreshed 12 customers'], $h)->assertOk()->assertJsonPath('data.status', 'COMPLETED');
    // the completer cannot verify their own work
    $this->postJson("/api/v1/compliance/corrective-actions/{$action['id']}/verify", ['accepted' => true, 'notes' => 'ok'], $h)->assertStatus(422);

    Passport::actingAs($checker);
    // rejection sends it back
    $this->postJson("/api/v1/compliance/corrective-actions/{$action['id']}/verify", ['accepted' => false, 'notes' => 'Two customers missing'], $h)->assertOk()->assertJsonPath('data.status', 'IN_PROGRESS');
    Passport::actingAs($owner);
    $this->postJson("/api/v1/compliance/corrective-actions/{$action['id']}/events", ['event' => 'complete', 'notes' => 'All 14 done'], $h)->assertOk();
    Passport::actingAs($checker);
    $this->postJson("/api/v1/compliance/corrective-actions/{$action['id']}/verify", ['accepted' => true, 'notes' => 'Sampled 5, all fine'], $h)->assertOk()->assertJsonPath('data.status', 'VERIFIED');

    expect(DB::table('compliance_findings')->where('id', $finding['id'])->value('status'))->toBe('CLOSED');
    expect(CaseTask::find($action['case_task_id'])->status)->toBe('DONE');

    // opener cannot close (separation), checker can
    Passport::actingAs($opener);
    $this->postJson("/api/v1/compliance/cases/{$caseId}/transition", ['to_status' => 'CLOSED', 'reason_code' => 'DONE'], $h)->assertStatus(422);
    Passport::actingAs($checker);
    $this->postJson("/api/v1/compliance/cases/{$caseId}/transition", ['to_status' => 'CLOSED', 'reason_code' => 'REMEDIATED'], $h)
        ->assertOk()->assertJsonPath('data.case.status', 'CLOSED')->assertJsonPath('data.work_case.status', 'RESOLVED');

    // reopen mirrors onto the work case
    $this->postJson("/api/v1/compliance/cases/{$caseId}/transition", ['to_status' => 'REOPENED', 'reason_code' => 'NEW_EVIDENCE'], $h)
        ->assertOk()->assertJsonPath('data.work_case.status', 'IN_PROGRESS');
    expect(DB::table('compliance_case_events')->where('compliance_case_id', $caseId)->count())->toBe(4);
});

it('links evidence by tenant document (sha256 pinned) or external reference, never another tenant\'s document', function () {
    $tenant = makeAuthTestTenant();
    $other = makeAuthTestTenant();
    Passport::actingAs(makeAuthTestUser($tenant, E10_ALL));
    $caseId = e10OpenCase($this, $tenant)['case']['id'];
    $doc = fn ($t) => tap((string) Str::uuid(), fn ($id) => DB::table('documents')->insert(['id' => $id, 'tenant_id' => $t->id, 'category' => 'EVIDENCE',
        'storage_key' => 'k/'.$id, 'mime_type' => 'application/pdf', 'size_bytes' => 10, 'sha256' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now()]));
    $h = tenantHeader($tenant);

    $this->postJson("/api/v1/compliance/cases/{$caseId}/evidence", ['description' => 'Bank letter', 'document_id' => $doc($tenant)], $h)
        ->assertCreated()->assertJsonPath('data.sha256', str_repeat('a', 64));
    $this->postJson("/api/v1/compliance/cases/{$caseId}/evidence", ['description' => 'Ticket', 'external_reference' => 'JIRA-1'], $h)->assertCreated();
    $this->postJson("/api/v1/compliance/cases/{$caseId}/evidence", ['description' => 'Foreign', 'document_id' => $doc($other)], $h)->assertStatus(422);
    $this->postJson("/api/v1/compliance/cases/{$caseId}/evidence", ['description' => 'Nothing'], $h)->assertStatus(422);

    $this->getJson("/api/v1/compliance/cases/{$caseId}", $h)->assertOk()->assertJsonCount(2, 'data.evidence');
});

it('keeps compliance cases tenant-isolated and permission-gated', function () {
    $a = makeAuthTestTenant();
    $b = makeAuthTestTenant();
    Passport::actingAs(makeAuthTestUser($a, E10_ALL));
    $caseId = e10OpenCase($this, $a)['case']['id'];

    Passport::actingAs(makeAuthTestUser($b, E10_ALL));
    $this->getJson("/api/v1/compliance/cases/{$caseId}", tenantHeader($b))->assertNotFound();

    Passport::actingAs(makeAuthTestUser($a, ['compliance.cases.read']));
    $this->postJson('/api/v1/compliance/cases', ['type' => 'X', 'subject_type' => 'Y', 'subject_id' => (string) Str::uuid(), 'severity' => 'LOW', 'idempotency_key' => 'k'], tenantHeader($a))->assertForbidden();
});

it('serves trust/compliance-cases as a deprecated alias of compliance/cases', function () {
    $tenant = makeAuthTestTenant();
    Passport::actingAs(makeAuthTestUser($tenant, ['trust.compliance-cases.create']));
    $res = $this->postJson('/api/v1/trust/compliance-cases', ['type' => 'AML_REVIEW', 'subject_type' => 'CUSTOMER', 'subject_id' => (string) Str::uuid(),
        'severity' => 'LOW', 'idempotency_key' => (string) Str::uuid()], tenantHeader($tenant))->assertCreated();
    $res->assertHeader('Deprecation', 'true');
    expect($res->headers->get('Link'))->toContain('/api/v1/compliance/cases');
    expect(DB::table('compliance_cases')->where('id', $res->json('id'))->value('case_id'))->not->toBeNull();
});
