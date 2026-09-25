<?php

declare(strict_types=1);

/**
 * Batch 12C — REQ-CPL-001 complaints, REQ-COR-001 correspondence register, REQ-CAS-002 queue board / ticket
 * mirroring, REQ-DUP-022 consolidation of complaint tickets. Everything rides the case engine (REQ-CAS-001).
 */

use App\Application\Cases\CaseService;
use App\Application\Cases\Models\CaseType;
use App\Application\Cases\Models\WorkCase;
use App\Application\Cases\Models\WorkQueue;
use App\Domain\Shared\Clock\Clock;
use App\Domain\Shared\Clock\FrozenClock;
use App\Providers\CasesServiceProvider;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->app->register(CasesServiceProvider::class);
    $this->app->instance(Clock::class, new FrozenClock('2026-10-05T10:00:00+01:00'));
    $this->tenant = makeAuthTestTenant('b12c');
    $this->me = makeAuthTestUser($this->tenant, ['cases.view', 'cases.manage', 'cases.assign', 'cases.decide', 'cases.admin']);
    $this->investigator = makeAuthTestUser($this->tenant, ['cases.view', 'cases.manage']);
    Passport::actingAs($this->me, [], 'api');
    $this->h = tenantHeader($this->tenant);
});

function b12cSubmit(array $over = []): array
{
    return test()->postJson('/api/v1/complaints', $over + [
        'complainant_name' => 'Awa Ndiaye', 'complainant_contact' => 'awa@example.test', 'channel' => 'EMAIL',
        'description' => 'My claim payment is three weeks late without any explanation.', 'idempotency_key' => 'cpl-1',
    ], test()->h)->assertCreated()->json('data');
}

function b12cResponse(string $caseId, bool $dispatch = true): string
{
    $id = test()->postJson('/api/v1/correspondence', [
        'direction' => 'OUTBOUND', 'channel' => 'LETTER', 'counterparty_type' => 'CUSTOMER', 'counterparty_name' => 'Awa Ndiaye',
        'case_id' => $caseId, 'subject_line' => 'Final response to your complaint',
    ], test()->h)->assertCreated()->assertJsonPath('data.status', 'DRAFT')->json('data.id');
    if ($dispatch) {
        test()->postJson("/api/v1/correspondence/{$id}/dispatch", ['proof_type' => 'REGISTERED_MAIL', 'proof_reference' => 'RR123456CM'], test()->h)
            ->assertOk()->assertJsonPath('data.status', 'DISPATCHED');
    }

    return $id;
}

it('REQ-CPL-001: COMPLAINT v2 carries the Submitted→…→Closed/Escalated lifecycle; v1 is superseded, not deleted; no invented deadline', function () {
    $v2 = CaseType::where('code', 'COMPLAINT')->where('status', 'EFFECTIVE')->sole();
    expect(collect($v2->states)->pluck('code')->all())->toBe(['SUBMITTED', 'ACKNOWLEDGED', 'CLASSIFIED', 'ASSIGNED', 'INVESTIGATING', 'WAITING_CUSTOMER',
        'RESOLUTION_PROPOSED', 'COMMUNICATED', 'ESCALATED_NATIONAL', 'ESCALATED_CIMA', 'CLOSED'])
        ->and($v2->version)->toBe(2)->and($v2->family_code)->toBe('COMPLAINT')->and($v2->sla_policies)->toBe([])
        ->and(CaseType::where('code', 'COMPLAINT')->where('version', 1)->value('status'))->toBe('SUPERSEDED');
});

it('REQ-CPL-001 + REQ-COR-001: full lifecycle through the complaint API, each step gated by complaint facts', function () {
    $out = b12cSubmit();
    $caseId = $out['case']['id'];
    expect($out['case']['status'])->toBe('SUBMITTED')->and($out['case']['case_type_code'])->toBe('COMPLAINT')
        ->and($out['case']['case_family'])->toBe('COMPLAINT')->and($out['complaint']['complaint_number'])->toStartWith('CPL-');
    // idempotent submit, proof of receipt registered as inbound correspondence
    $this->postJson('/api/v1/complaints', ['complainant_name' => 'Awa Ndiaye', 'channel' => 'EMAIL', 'description' => 'duplicate submit text', 'idempotency_key' => 'cpl-1'], $this->h)
        ->assertOk()->assertJsonPath('data.case.id', $caseId);
    expect(DB::table('correspondence_register')->where('case_id', $caseId)->where('direction', 'INBOUND')->where('status', 'RECEIVED')->count())->toBe(1);

    $this->postJson("/api/v1/complaints/{$caseId}/acknowledge", [], $this->h)->assertOk()->assertJsonPath('data.case.status', 'ACKNOWLEDGED');
    // generic case API cannot skip the facts: classify without category/severity is refused by the engine guard
    $this->postJson("/api/v1/cases/{$caseId}/transitions", ['event' => 'classify'], $this->h)->assertStatus(422);
    $this->postJson("/api/v1/complaints/{$caseId}/classify", ['category' => 'CLAIM_DELAY', 'severity' => 'HIGH', 'regulatory' => true], $this->h)
        ->assertOk()->assertJsonPath('data.case.status', 'CLASSIFIED')->assertJsonPath('data.case.priority', 'HIGH')
        ->assertJsonPath('data.case.case_subtype', 'REGULATORY')->assertJsonPath('data.complaint.severity', 'HIGH');

    $this->postJson("/api/v1/complaints/{$caseId}/assign", ['owner_user_id' => $this->investigator->id], $this->h)
        ->assertOk()->assertJsonPath('data.case.status', 'ASSIGNED')->assertJsonPath('data.case.owner_user_id', $this->investigator->id);
    $this->postJson("/api/v1/complaints/{$caseId}/investigate", [], $this->h)->assertOk()->assertJsonPath('data.case.status', 'INVESTIGATING');
    // resolution cannot be proposed through the generic API without a decision
    $this->postJson("/api/v1/cases/{$caseId}/transitions", ['event' => 'propose_resolution'], $this->h)->assertStatus(422);
    $this->postJson("/api/v1/complaints/{$caseId}/resolution", ['outcome' => 'UPHELD', 'resolution_summary' => 'Payment was delayed by our error; paid with apology.', 'root_cause' => 'PROCESS', 'redress_amount' => 25000], $this->h)
        ->assertOk()->assertJsonPath('data.case.status', 'RESOLUTION_PROPOSED');
    expect(DB::table('case_decisions')->where('case_id', $caseId)->value('decision_type'))->toBe('COMPLAINT_RESOLUTION');

    // communicate needs an outbound response WITH proof of dispatch on this case
    $draft = b12cResponse($caseId, false);
    $this->postJson("/api/v1/complaints/{$caseId}/communicate", ['correspondence_id' => $draft], $this->h)->assertStatus(422);
    expect(WorkCase::find($caseId)->status)->toBe('RESOLUTION_PROPOSED')
        ->and(DB::table('complaints')->where('case_id', $caseId)->value('response_correspondence_id'))->toBeNull(); // rolled back
    $this->postJson("/api/v1/correspondence/{$draft}/dispatch", ['proof_type' => 'REGISTERED_MAIL'], $this->h)->assertStatus(422)->assertJsonPath('code', 'PROOF_REQUIRED');
    $this->postJson("/api/v1/correspondence/{$draft}/dispatch", ['proof_type' => 'COURIER_WAYBILL', 'proof_reference' => 'WB-1'], $this->h)->assertOk();
    $this->postJson("/api/v1/correspondence/{$draft}/dispatch", ['proof_type' => 'COURIER_WAYBILL', 'proof_reference' => 'WB-2'], $this->h)->assertStatus(409); // write-once
    $this->postJson("/api/v1/complaints/{$caseId}/communicate", ['correspondence_id' => $draft], $this->h)
        ->assertOk()->assertJsonPath('data.case.status', 'COMMUNICATED')->assertJsonPath('data.complaint.response_correspondence_id', $draft);

    $this->postJson("/api/v1/complaints/{$caseId}/escalate", ['level' => 'NATIONAL', 'reason' => 'Customer rejected the response', 'reference' => 'NAT-9'], $this->h)
        ->assertOk()->assertJsonPath('data.case.status', 'ESCALATED_NATIONAL')->assertJsonPath('data.complaint.escalation_level', 'NATIONAL');
    $this->postJson("/api/v1/complaints/{$caseId}/escalate", ['level' => 'CIMA', 'reason' => 'Referred to the regional regulator'], $this->h)
        ->assertOk()->assertJsonPath('data.case.status', 'ESCALATED_CIMA');
    $this->postJson("/api/v1/complaints/{$caseId}/transition", ['event' => 'close'], $this->h)->assertOk()->assertJsonPath('data.case.status', 'CLOSED');

    $show = $this->getJson("/api/v1/complaints/{$caseId}", $this->h)->assertOk();
    expect(collect($show->json('data.correspondence'))->pluck('direction')->all())->toBe(['INBOUND', 'OUTBOUND']);
    $types = DB::table('case_events')->where('case_id', $caseId)->orderBy('seq')->pluck('type')->all();
    expect($types)->toContain('COMPLAINT_REGISTERED', 'CORRESPONDENCE_RECEIVED', 'CORRESPONDENCE_DISPATCHED', 'PRIORITY_CHANGED', 'DECIDED', 'ASSIGNED');
    $this->getJson('/api/v1/complaints?regulatory=1', $this->h)->assertOk()->assertJsonPath('total', 1);
});

it('REQ-COR-001: database refuses dispatched rows without proof and inbound rows without receipt; delivery outcome is tracked', function () {
    $base = ['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'reference_number' => 'X-'.Str::random(6), 'direction' => 'OUTBOUND', 'channel' => 'EMAIL',
        'counterparty_type' => 'CUSTOMER', 'counterparty_name' => 'A', 'subject_line' => 's', 'created_at' => now(), 'updated_at' => now()];
    expect(fn () => DB::transaction(fn () => DB::table('correspondence_register')->insert($base + ['status' => 'DISPATCHED', 'dispatched_at' => now()])))->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('correspondence_register')->insert(['direction' => 'INBOUND', 'status' => 'RECEIVED'] + $base)))->toThrow(QueryException::class);

    $id = $this->postJson('/api/v1/correspondence', ['direction' => 'OUTBOUND', 'channel' => 'EMAIL', 'counterparty_type' => 'REGULATOR', 'counterparty_name' => 'Regulator',
        'subject_line' => 'Quarterly complaint return'], $this->h)->assertCreated()->json('data.id');
    $this->postJson("/api/v1/correspondence/{$id}/outcome", ['delivered' => true], $this->h)->assertStatus(409);
    $this->postJson("/api/v1/correspondence/{$id}/dispatch", ['proof_type' => 'EMAIL_MESSAGE_ID', 'proof_reference' => '<m1@x>'], $this->h)->assertOk();
    $this->postJson("/api/v1/correspondence/{$id}/outcome", ['delivered' => false], $this->h)->assertStatus(422);
    $this->postJson("/api/v1/correspondence/{$id}/outcome", ['delivered' => true], $this->h)->assertOk()->assertJsonPath('data.status', 'DELIVERED');
    $this->getJson('/api/v1/correspondence?direction=OUTBOUND', $this->h)->assertOk()->assertJsonPath('total', 1);

    $other = makeAuthTestTenant('other');
    DB::table('correspondence_register')->where('id', $id)->update(['tenant_id' => $other->id]);
    $this->getJson("/api/v1/correspondence/{$id}", $this->h)->assertNotFound();
});

it('REQ-DUP-022 + REQ-CAS-002: a complaint ticket is consolidated onto one COMPLAINT case (either entry point) and mirrors its status', function () {
    $ticket = (string) Str::uuid();
    DB::table('support_tickets')->insert(['id' => $ticket, 'tenant_id' => $this->tenant->id, 'ticket_number' => 'TCK-9', 'type' => 'REGULATORY_COMPLAINT', 'category' => 'SERVICE',
        'priority' => 'HIGH', 'status' => 'OPEN', 'subject' => 'Refund refused', 'description' => 'My refund was refused twice without reason.', 'sla_due_at' => now()->addDay(),
        'idempotency_key' => 'tck-9', 'created_at' => now(), 'updated_at' => now()]);

    $first = $this->postJson('/api/v1/admin/cases/links', ['source' => 'support_tickets', 'id' => $ticket], $this->h)->assertCreated();
    $caseId = $first->json('data.case_id');
    $this->postJson('/api/v1/complaints/from-ticket', ['support_ticket_id' => $ticket], $this->h)->assertOk()->assertJsonPath('data.case.id', $caseId);
    expect(DB::table('complaints')->where('support_ticket_id', $ticket)->count())->toBe(1)
        ->and(DB::table('complaints')->where('support_ticket_id', $ticket)->value('regulatory'))->toBeTrue()
        ->and(WorkCase::where('case_type_code', 'COMPLAINT')->count())->toBe(1);

    $this->postJson("/api/v1/complaints/{$caseId}/acknowledge", [], $this->h)->assertOk();
    $t = DB::table('support_tickets')->where('id', $ticket)->first();
    expect($t->status)->toBe('TRIAGED')->and($t->acknowledged_at)->not->toBeNull()
        ->and(DB::table('support_ticket_events')->where('support_ticket_id', $ticket)->where('type', 'CASE_MIRROR')->count())->toBe(1);

    $this->postJson('/api/v1/complaints/from-ticket', ['support_ticket_id' => (string) Str::uuid()], $this->h)->assertNotFound();
});

it('REQ-CAS-002: operational queue board counts open/unassigned/overdue per queue and the unbridged legacy backlog', function () {
    $q = WorkQueue::create(['tenant_id' => $this->tenant->id, 'code' => 'COMPLAINTS', 'name' => 'Complaints desk', 'case_type_codes' => ['COMPLAINT'], 'active' => true]);
    $svc = app(CaseService::class);
    $svc->open($this->tenant->id, 'COMPLAINT', ['title' => 'a', 'queue_id' => $q->id], null);
    $b = $svc->open($this->tenant->id, 'COMPLAINT', ['title' => 'b', 'queue_id' => $q->id], null);
    $svc->assign($b, $this->me->id, $q->id, null);
    DB::table('cases')->where('id', $b->id)->update(['due_at' => now()->subDay()]);
    DB::table('support_tickets')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant->id, 'ticket_number' => 'TCK-2', 'type' => 'COMPLAINT', 'category' => 'X',
        'priority' => 'NORMAL', 'status' => 'OPEN', 'subject' => 's', 'description' => 'd', 'sla_due_at' => now(), 'idempotency_key' => 'k2', 'created_at' => now(), 'updated_at' => now()]);

    $board = $this->getJson('/api/v1/queues/board', $this->h)->assertOk()->json('data');
    expect($board['queues'][0])->toMatchArray(['code' => 'COMPLAINTS', 'open' => 2, 'unassigned' => 1, 'overdue' => 1])
        ->and($board['queues'][0]['by_case_type'])->toBe(['COMPLAINT' => 2])
        ->and($board['legacy_unbridged']['support_tickets_complaints'])->toBe(1);

    Passport::actingAs(makeAuthTestUser($this->tenant, []), [], 'api');
    $this->getJson('/api/v1/queues/board', $this->h)->assertForbidden();
});
