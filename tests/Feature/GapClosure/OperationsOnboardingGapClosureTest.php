<?php

declare(strict_types=1);

/**
 * Gap Closure Pack v1 files 10 (operations: case task/queue/closure/escalation types, SLA profiles, retention, notification
 * events/templates, document reasons, complaints) and 11 (private tenant onboarding templates). REQ-GAP-010 / REQ-GAP-011,
 * REQ-CAS-001, REQ-CPL-001, REQ-DOC-009, REQ-IMP-001, REQ-SET-002/003.
 */

use App\Application\Cases\CaseService;
use App\Application\Cases\Models\WorkQueue;
use App\Application\DataReadiness\DataReadinessRegistry;
use App\Application\Documents\Engine\DocumentStatusService;
use App\Application\Documents\Retention\RetentionScheduleService;
use App\Application\Import\ImportPipeline;
use App\Application\OperationsTaxonomy\OperationsCatalogue;
use App\Application\OperationsTaxonomy\OperationsSeeder;
use App\Application\PrivateOnboarding\PrivateOnboardingTemplates;
use App\Domain\Shared\Clock\Clock;
use App\Domain\Shared\Clock\FrozenClock;
use App\Domain\Tenancy\TenantContext;
use App\Models\Carrier;
use App\Models\Document;
use App\Models\Party;
use App\Providers\CasesServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Passport;

require_once __DIR__.'/../Concerns/wave_auth_helpers.php';

uses(RefreshDatabase::class);

const GP8_PERMS = ['operations.taxonomy.read', 'operations.taxonomy.manage', 'operations.cases.escalate', 'operations.notification_templates.approve',
    'onboarding.private_data.view', 'onboarding.private_data.review', 'imports.create', 'imports.approve', 'cases.view', 'cases.manage', 'cases.admin',
    'documents.retention.manage', 'documents.retention.approve'];

beforeEach(function () {
    $this->app->register(CasesServiceProvider::class);
    $this->app->instance(Clock::class, new FrozenClock('2026-10-05T10:00:00+01:00'));
    $this->tenant = makeAuthTestTenant('gp8');
    app(TenantContext::class)->set($this->tenant->id);
    $this->maker = makeAuthTestUser($this->tenant, GP8_PERMS);
    $this->checker = makeAuthTestUser($this->tenant, GP8_PERMS);
    Passport::actingAs($this->maker, [], 'api');
    $this->h = tenantHeader($this->tenant);
});

function gp8Csv(string $body): string
{
    $path = storage_path('app/gp8-'.Str::random(8).'.csv');
    file_put_contents($path, $body);

    return $path;
}

function gp8Carrier(): Carrier
{
    return Carrier::create(['party_id' => Party::create(['type' => 'ORGANIZATION', 'display_name' => 'GP8 Insurer', 'status' => 'ACTIVE'])->id,
        'cima_code' => 'GP8-'.Str::upper(Str::random(6)), 'status' => 'ACTIVE']);
}

it('REQ-GAP-010 keeps the owner 10 case families and seeds DRAFT event templates — and no gated value', function () {
    expect(DB::table('case_families')->where('active', true)->count())->toBe(10)
        ->and(OperationsCatalogue::canonicalFamily('SECURITY'))->toBe('OPERATIONS')
        ->and(DB::table('notification_templates')->whereNotNull('event_code')->whereNull('tenant_id')->count())->toBe(29 * 4)
        ->and(DB::table('notification_templates')->whereNotNull('event_code')->where('status', '<>', 'DRAFT')->count())->toBe(0)
        ->and(DB::table('retention_schedules')->count())->toBe(0)
        ->and(DB::table('signatory_authorities')->count())->toBe(0);

    // idempotent
    expect(app(OperationsSeeder::class)->run())->toBe(['templates' => 0]);

    $this->getJson('/api/v1/operations/taxonomy', $this->h)->assertOk()
        ->assertJsonPath('data.gates.retention', 'PENDING_LEGAL_VALIDATION')->assertJsonPath('data.gates.sla_profiles', 'CONFIG_REQUIRED')
        ->assertJsonPath('data.gates.signatory_authority_registry', 'PENDING_PRIVATE_SOURCE')->assertJsonCount(13, 'data.lists.task_types');
});

it('REQ-CAS-001 enforces task types, closure reasons, escalation reasons and queue types', function () {
    $svc = app(CaseService::class);
    $case = $svc->open($this->tenant->id, 'CONFIG_GAP', ['title' => 'Missing mapping'], null);

    expect(fn () => $svc->addTask($case, ['title' => 'x', 'task_type' => 'DANCE'], $this->maker))->toThrow(\App\Interfaces\Http\Errors\ApiProblemException::class);
    $task = $svc->addTask($case, ['title' => 'Check mapping', 'task_type' => 'VERIFY_DOCUMENT'], $this->maker);
    expect($task->task_type)->toBe('VERIFY_DOCUMENT');
    $this->postJson("/api/v1/cases/{$case->id}/tasks", ['title' => 'Bad', 'task_type' => 'NOPE'], $this->h)->assertStatus(422);

    $queue = WorkQueue::create(['tenant_id' => $this->tenant->id, 'code' => 'SEC', 'name' => 'Security', 'case_type_codes' => [], 'routing_rule' => 'PULL', 'active' => true]);
    $this->putJson("/api/v1/operations/queues/{$queue->id}/queue-type", ['queue_type' => 'NOT_A_QUEUE'], $this->h)->assertStatus(422);
    $this->putJson("/api/v1/operations/queues/{$queue->id}/queue-type", ['queue_type' => 'SECURITY'], $this->h)->assertOk()->assertJsonPath('data.queue_type', 'SECURITY');

    $this->postJson("/api/v1/operations/cases/{$case->id}/escalate", ['escalation_reason' => 'BORED'], $this->h)->assertStatus(422);
    $this->postJson("/api/v1/operations/cases/{$case->id}/escalate", ['escalation_reason' => 'OTHER'], $this->h)->assertStatus(422);
    $this->postJson("/api/v1/operations/cases/{$case->id}/escalate", ['escalation_reason' => 'COMPLIANCE_RISK', 'queue_id' => $queue->id], $this->h)
        ->assertOk()->assertJsonPath('data.escalation_reason', 'COMPLIANCE_RISK')->assertJsonPath('data.queue_id', $queue->id);
    expect(DB::table('case_events')->where('case_id', $case->id)->where('type', 'ESCALATED')->exists())->toBeTrue();

    $case = $svc->transition($case, 'start', $this->maker);
    $case = $svc->transition($case, 'resolve', $this->maker);
    expect(fn () => $svc->transition($case, 'close', $this->maker, null, ['closure_reason' => 'BECAUSE']))->toThrow(\App\Interfaces\Http\Errors\ApiProblemException::class);
    $closed = $svc->transition($case->fresh(), 'close', $this->maker, null, ['closure_reason' => 'COMPLETED']);
    expect($closed->closure_reason)->toBe('COMPLETED')->and($closed->status)->toBe('CLOSED');
});

it('REQ-CPL-001 complaint categories and resolution reasons come from the pack', function () {
    $case = $this->postJson('/api/v1/complaints', ['complainant_name' => 'Awa', 'channel' => 'EMAIL', 'description' => 'Claim paid late again and again.', 'idempotency_key' => 'gp8-1'], $this->h)
        ->assertCreated()->json('data.case.id');
    $this->postJson("/api/v1/complaints/{$case}/classify", ['category' => 'WEATHER', 'severity' => 'HIGH'], $this->h)->assertStatus(422);
    $this->postJson("/api/v1/complaints/{$case}/acknowledge", [], $this->h)->assertOk();
    $this->postJson("/api/v1/complaints/{$case}/classify", ['category' => 'CLAIM_PAYMENT', 'severity' => 'HIGH'], $this->h)->assertOk();
    expect(DB::table('complaints')->where('case_id', $case)->value('category'))->toBe('CLAIM_PAYMENT');
});

it('REQ-DOC-009 document status reason codes and retention class schedules are gated', function () {
    $doc = Document::create(['tenant_id' => $this->tenant->id, 'category' => 'INCOMING', 'storage_key' => 'documents/gp8/x.pdf', 'mime_type' => 'application/pdf',
        'size_bytes' => 10, 'sha256' => hash('sha256', 'gp8'), 'scan_status' => 'CLEAN', 'verification_status' => 'UNVERIFIED', 'ocr_data' => []]);
    $svc = app(DocumentStatusService::class);
    try {
        $svc->request($doc, 'REVOKE', 'Issued to the wrong party', $this->maker, null, 'LOST');
        $this->fail('LOST is a replacement reason, not a revocation reason');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey('reason_code');
    }
    try {
        $svc->request($doc, 'REVOKE', 'Issued to the wrong party', $this->maker, null, 'ISSUED_IN_ERROR');
    } catch (ValidationException $e) {
        expect($e->errors())->not->toHaveKey('reason_code'); // the code is valid; the document itself is not an issued one
    }

    $retention = app(RetentionScheduleService::class);
    expect(fn () => $retention->draft($this->tenant->id, ['code' => 'X', 'retention_years' => 5, 'retention_class' => 'FOREVER'], $this->maker))
        ->toThrow(\App\Interfaces\Http\Errors\ApiProblemException::class);
    $s = $retention->draft($this->tenant->id, ['code' => 'RC_CLAIM', 'retention_years' => 10, 'retention_class' => 'CLAIM', 'legal_basis' => null], $this->maker);
    expect(fn () => $retention->approve($this->tenant->id, $s->id, $this->checker))->toThrow(\App\Application\Documents\DocumentGovernanceProblem::class);
    DB::table('retention_schedules')->where('id', $s->id)->update(['legal_basis' => 'Validated by counsel, memo 2026-14']);
    expect($retention->approve($this->tenant->id, $s->id, $this->checker)->status)->toBe('ACTIVE');
});

it('REQ-IMP-001 imports tenant PLATFORM_SLA profiles into sla_policy_overrides and retention periods as DRAFT schedules', function () {
    $pipe = app(ImportPipeline::class);
    $csv = gp8Csv("sla_id,case_type,priority,ack_target_minutes,resolution_target_minutes,pause_states,effective_from\n".
        "CPL-STD,COMPLAINT,HIGH,240,2880,WAITING_CUSTOMER,2026-10-01\nBAD,NOPE,,10,,,2026-10-01\n");
    $b = $pipe->upload('sla_profiles', [], $csv, 'sla.csv', $this->maker, [], $this->tenant->id);
    expect($b->report['new'])->toBe(['CPL-STD'])->and($b->report['errors'])->toHaveCount(1);
    $b = $pipe->upload('sla_profiles', [], gp8Csv("sla_id,case_type,priority,ack_target_minutes,resolution_target_minutes,pause_states,effective_from
CPL-STD,COMPLAINT,HIGH,240,2880,WAITING_CUSTOMER,2026-10-01
"), 'sla2.csv', $this->maker, [], $this->tenant->id);
    $pipe->approve($pipe->submit($b, $this->maker, 'Tenant SLA'), $this->checker, 'ok');
    $rows = DB::table('sla_policy_overrides')->where('sla_profile_code', 'CPL-STD')->get();
    expect($rows)->toHaveCount(2)->and($rows->pluck('deadline_label')->unique()->all())->toBe(['PLATFORM_SLA'])
        ->and($rows->pluck('metric')->sort()->values()->all())->toBe(['FIRST_RESPONSE', 'RESOLUTION'])->and($rows->first()->priority)->toBe('HIGH');

    $csv = gp8Csv("retention_class,retention_years,trigger_event,legal_basis\nKYC_AML,10,CREATED_AT,Validated legal memo\nCLAIM,5,CREATED_AT,\n");
    $b = $pipe->upload('retention_schedules', [], $csv, 'ret.csv', $this->maker, [], $this->tenant->id);
    expect($b->report['new'])->toBe(['KYC_AML|'])->and($b->report['errors'])->toHaveCount(1);
    $b = $pipe->upload('retention_schedules', [], gp8Csv("retention_class,retention_years,trigger_event,legal_basis
KYC_AML,10,CREATED_AT,Validated legal memo
"), 'ret2.csv', $this->maker, [], $this->tenant->id);
    $pipe->approve($pipe->submit($b, $this->maker, 'Retention'), $this->checker, 'ok');
    expect(DB::table('retention_schedules')->where('retention_class', 'KYC_AML')->value('status'))->toBe('DRAFT'); // import never activates
});

it('REQ-GAP-011 stages private onboarding datasets via the ImportPipeline, reviews them maker-checker and reports setup readiness', function () {
    expect(PrivateOnboardingTemplates::datasets())->toHaveCount(8);
    $this->getJson('/api/v1/onboarding/private-datasets', $this->h)->assertOk()->assertJsonCount(8, 'data');
    $tpl = $this->get('/api/v1/onboarding/private-datasets/SIGNATORY_MANDATES/template', $this->h)->assertOk()->getContent();
    expect($tpl)->toStartWith('authority_id,organization_id,person_id');

    $carrier = gp8Carrier();
    $pipe = app(ImportPipeline::class);
    expect(fn () => $pipe->upload('private_onboarding', ['dataset' => 'TREATIES', 'organization_type' => 'BROKER', 'organization_id' => $carrier->id], gp8Csv("treaty_id\nT1\n"), 't.csv', $this->maker, [], $this->tenant->id))
        ->toThrow(ValidationException::class);

    $csv = gp8Csv("authority_id,person_id,role,document_types,signature_method,effective_from,status,source_mandate_document_id\n".
        "SIG-1,P-77,Directeur General,POLICY_SCHEDULE|ATTESTATION,MANUAL,2026-10-01,ACTIVE,DOC-MANDATE-1\nSIG-2,P-78,DGA,,MANUAL,2026-10-01,ACTIVE,\n");
    $b = $pipe->upload('private_onboarding', ['dataset' => 'SIGNATORY_MANDATES', 'organization_type' => 'INSURER', 'organization_id' => $carrier->id], $csv, 's.csv', $this->maker, [], $this->tenant->id);
    expect($b->report['new'])->toBe(['SIG-1'])->and($b->report['errors'])->toHaveCount(1);
    $b = $pipe->upload('private_onboarding', ['dataset' => 'SIGNATORY_MANDATES', 'organization_type' => 'INSURER', 'organization_id' => $carrier->id],
        gp8Csv("authority_id,person_id,role,document_types,signature_method,effective_from,status,source_mandate_document_id
SIG-1,P-77,Directeur General,POLICY_SCHEDULE|ATTESTATION,MANUAL,2026-10-01,ACTIVE,DOC-MANDATE-1
"), 's2.csv', $this->maker, [], $this->tenant->id);
    $pipe->approve($pipe->submit($b, $this->maker, 'Mandates'), $this->checker, 'ok');

    $rec = DB::table('tenant_onboarding_records')->where('record_key', 'SIG-1')->first();
    expect($rec->data_status)->toBe('PENDING_PRIVATE_SOURCE')->and($rec->review_status)->toBe('RECEIVED')->and($rec->tenant_id)->toBe($this->tenant->id);

    // maker cannot review; checker accepts -> signatory registry row, still PENDING_VERIFICATION
    $this->postJson("/api/v1/onboarding/private-records/{$rec->id}/review", ['decision' => 'ACCEPTED'], $this->h)->assertStatus(422);
    Passport::actingAs($this->checker, [], 'api');
    $this->postJson("/api/v1/onboarding/private-records/{$rec->id}/review", ['decision' => 'ACCEPTED'], $this->h)->assertOk()->assertJsonPath('data.promoted_table', 'signatory_authorities');
    expect(DB::table('signatory_authorities')->where('authority_id', 'SIG-1')->value('status'))->toBe('PENDING_VERIFICATION');

    $ready = collect($this->getJson("/api/v1/onboarding/private-readiness?organization_type=INSURER&organization_id={$carrier->id}", $this->h)->assertOk()->json('data'))->keyBy('dataset');
    expect($ready)->toHaveCount(8)->and($ready['SIGNATORY_MANDATES']['status'])->toBe('SOURCE_RECEIVED')->and($ready['SIGNATORY_MANDATES']['checklist_item'])->toBe('DOCUMENTS')
        ->and($ready['TREATIES']['status'])->toBe('PENDING_PRIVATE_SOURCE')->and($ready->every(fn ($r) => $r['production_usable'] === false))->toBeTrue();
});

it('REQ-GAP-010 registers the gates in the Data Readiness registry', function () {
    $items = collect(app(DataReadinessRegistry::class)->items())->keyBy(fn ($i) => $i['domain'].'.'.$i['item']);
    expect($items['case_management.gap_task_types']['status'])->toBe('PLATFORM_NORMALIZED')
        ->and($items['case_management.gap_sla_profiles']['status'])->toBe('CONFIG_REQUIRED')
        ->and($items['documents.gap_retention_schedules']['status'])->toBe('PENDING_SOURCE')
        ->and($items['documents.gap_signatory_authority_registry']['status'])->toBe('PENDING_SOURCE')
        ->and($items['documents.gap_document_numbering_profiles']['status'])->toBe('CONFIG_REQUIRED')
        ->and($items['notifications.gap_notification_templates']['status'])->toBe('CONFIG_REQUIRED')
        ->and($items['commission.private_commission_tables']['status'])->toBe('PENDING_SOURCE')
        ->and($items['sla_calendars.private_business_hours']['production_usable'])->toBeFalse();

    $t = DB::table('notification_templates')->where('event_code', 'POLICY_ISSUED')->where('channel', 'SMS')->where('locale', 'fr')->first();
    $this->postJson("/api/v1/operations/notification-templates/{$t->id}/approve", [], $this->h)->assertOk()->assertJsonPath('data.status', 'ACTIVE');
});
