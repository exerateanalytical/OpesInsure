<?php

declare(strict_types=1);

use App\Application\Documents\DocumentOrigin;
use App\Models\Document;
use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';

const B89_ALL = ['documents.read', 'documents.intake.manage', 'documents.access_log.read', 'documents.retention.manage', 'documents.retention.approve',
    'documents.legal_hold.manage', 'documents.destruction.request', 'documents.destruction.approve', 'documents.signatures.manage'];

function b89Doc(Tenant $tenant, array $overrides = []): Document
{
    return Document::create(array_merge([
        'tenant_id' => $tenant->id, 'category' => 'INCOMING', 'storage_key' => 'documents/b89/'.Str::random(12).'.pdf',
        'mime_type' => 'application/pdf', 'size_bytes' => 1024, 'sha256' => hash('sha256', Str::random(20)),
        'scan_status' => 'CLEAN', 'verification_status' => 'UNVERIFIED', 'ocr_data' => [],
    ], $overrides));
}

function b89Outbox(string $event): int
{
    return DB::table('outbox_messages')->where('event_name', $event)->count();
}

it('REQ-DOC-008 keeps third-party evidence on its own read surface, separate from issued documents', function () {
    $t = makeAuthTestTenant();
    $u = makeAuthTestUser($t, B89_ALL);
    $garage = b89Doc($t, ['document_origin' => 'GARAGE', 'document_stage' => 'CLAIM']);
    b89Doc($t, ['document_origin' => 'SYSTEM', 'document_stage' => 'POLICY']);
    b89Doc($t, ['document_origin' => 'CUSTOMER']);

    expect(DocumentOrigin::isThirdPartyEvidence('GARAGE'))->toBeTrue()
        ->and(DocumentOrigin::isIssued('SYSTEM'))->toBeTrue()
        ->and(DocumentOrigin::isThirdPartyEvidence('CUSTOMER'))->toBeFalse();

    Passport::actingAs($u);
    $ids = collect($this->getJson('/api/v1/document-governance/third-party-evidence', tenantHeader($t))->assertOk()->json('data'))->pluck('id');
    expect($ids->all())->toBe([$garage->id]);

    expect(fn () => b89Doc($t, ['document_origin' => 'ALIEN']))->toThrow(\Illuminate\Database\QueryException::class);
});

it('REQ-DOC-010 classifies a declared register code and stamps type, origin on the document', function () {
    $t = makeAuthTestTenant();
    Passport::actingAs(makeAuthTestUser($t, B89_ALL));
    $doc = b89Doc($t);

    $item = $this->postJson('/api/v1/document-governance/intake', ['document_id' => $doc->id, 'channel' => 'EMAIL', 'declared_type_code' => 'DOC-151', 'stage' => 'UNDERWRITING'], tenantHeader($t))
        ->assertCreated()->json('data');

    expect($item['status'])->toBe('CLASSIFIED')->and($item['classified_type_code'])->toBe('CONSTRUCTION_RISK_QUESTIONNAIRE');
    $doc->refresh();
    expect($doc->document_type_id)->toBe('DOC-151')->and($doc->document_origin)->toBe('CUSTOMER')->and($doc->document_stage)->toBe('UNDERWRITING');
    expect(b89Outbox('document.intake.received'))->toBe(1)->and(b89Outbox('document.intake.classified'))->toBe(1);

    // one open intake per document
    $this->postJson('/api/v1/document-governance/intake', ['document_id' => $doc->id, 'channel' => 'EMAIL'], tenantHeader($t))->assertStatus(409);
});

it('REQ-DOC-010 suggests from the filename and waits for staff confirmation', function () {
    $t = makeAuthTestTenant();
    Passport::actingAs(makeAuthTestUser($t, B89_ALL));
    $doc = b89Doc($t);

    $item = $this->postJson('/api/v1/document-governance/intake', ['document_id' => $doc->id, 'channel' => 'UPLOAD', 'original_filename' => 'accident_report_scan.pdf', 'origin' => 'AUTHORITY'], tenantHeader($t))
        ->assertCreated()->json('data');
    expect($item['status'])->toBe('RECEIVED')->and($item['suggested_type_code'])->toBe('ACCIDENT_REPORT');

    $this->postJson("/api/v1/document-governance/intake/{$item['id']}/classify", ['document_type_code' => 'NOT_A_TYPE'], tenantHeader($t))->assertStatus(422);
    $done = $this->postJson("/api/v1/document-governance/intake/{$item['id']}/classify", ['document_type_code' => 'ACCIDENT_REPORT'], tenantHeader($t))->assertOk()->json('data');
    expect($done['status'])->toBe('CLASSIFIED')->and($doc->refresh()->document_type_code)->toBe('ACCIDENT_REPORT');
});

it('REQ-DOC-010 opens a DOC_INTAKE_EXCEPTION case for unclassifiable documents and closes it on classification', function () {
    $t = makeAuthTestTenant();
    Passport::actingAs(makeAuthTestUser($t, B89_ALL));
    $doc = b89Doc($t);

    $item = $this->postJson('/api/v1/document-governance/intake', ['document_id' => $doc->id, 'channel' => 'POST', 'original_filename' => 'zzqx_8812.pdf'], tenantHeader($t))
        ->assertCreated()->json('data');
    expect($item['status'])->toBe('EXCEPTION')->and($item['exception_case_id'])->not->toBeNull();
    $case = DB::table('cases')->find($item['exception_case_id']);
    expect($case->case_type_code)->toBe('DOC_INTAKE_EXCEPTION')->and($case->subject_id)->toBe($doc->id);
    expect(b89Outbox('document.intake.exception'))->toBe(1);

    $this->postJson("/api/v1/document-governance/intake/{$item['id']}/classify", ['document_type_code' => 'DOC-151'], tenantHeader($t))->assertOk();
    expect(DB::table('cases')->find($item['exception_case_id'])->status)->toBe('CLOSED');
});

it('REQ-DOC-009 enforces security levels and logs access, including denials', function () {
    $t = makeAuthTestTenant();
    $u = makeAuthTestUser($t, ['documents.access_log.read']);
    $doc = b89Doc($t, ['security_level' => 'MEDICAL_RESTRICTED']);
    Passport::actingAs($u);

    $this->getJson("/api/v1/document-governance/documents/{$doc->id}/access-log", tenantHeader($t))->assertStatus(403);
    expect(DB::table('document_access_log')->where('document_id', $doc->id)->where('action', 'DENIED')->count())->toBe(1);

    $medical = makeAuthTestUser($t, ['documents.access_log.read', 'documents.medical.read']);
    Passport::actingAs($medical);
    $log = $this->getJson("/api/v1/document-governance/documents/{$doc->id}/access-log", tenantHeader($t))->assertOk()->json('data');
    expect(collect($log)->pluck('action')->all())->toContain('AUDIT_VIEW', 'DENIED');
});

it('REQ-DOC-009 retention schedules are maker-checker and nothing is disposable without one', function () {
    $t = makeAuthTestTenant();
    $maker = makeAuthTestUser($t, B89_ALL);
    $checker = makeAuthTestUser($t, B89_ALL);
    $doc = b89Doc($t, ['document_type_code' => 'ACCIDENT_REPORT']);
    Passport::actingAs($maker);

    $this->postJson("/api/v1/document-governance/documents/{$doc->id}/destruction-requests", ['reason' => 'x'], tenantHeader($t))->assertStatus(409)->assertJsonFragment(['message' => 'No active retention schedule covers this document.']);
    $this->postJson('/api/v1/document-governance/retention-schedules', ['code' => 'BAD', 'document_type_code' => 'NOPE', 'retention_years' => 5, 'legal_basis' => 'x'], tenantHeader($t))->assertStatus(422);
    $s = $this->postJson('/api/v1/document-governance/retention-schedules', ['code' => 'EVD-10Y', 'document_type_code' => 'ACCIDENT_REPORT', 'retention_years' => 10, 'trigger_event' => 'CREATED_AT', 'legal_basis' => 'Owner decision'], tenantHeader($t))
        ->assertCreated()->json('data');
    $this->postJson("/api/v1/document-governance/retention-schedules/{$s['id']}/approve", [], tenantHeader($t))->assertStatus(403);
    Passport::actingAs($checker);
    $this->postJson("/api/v1/document-governance/retention-schedules/{$s['id']}/approve", [], tenantHeader($t))->assertOk()->assertJsonPath('data.status', 'ACTIVE');

    $status = $this->getJson("/api/v1/document-governance/documents/{$doc->id}/retention", tenantHeader($t))->assertOk()->json('data');
    expect($status['disposable_from'])->toBe(now()->addYears(10)->toDateString());
    $this->postJson("/api/v1/document-governance/documents/{$doc->id}/destruction-requests", ['reason' => 'x'], tenantHeader($t))->assertStatus(409);
});

it('REQ-DOC-009 destroys through a DOCUMENT_DESTRUCTION case, blocked by a legal hold on any linked subject', function () {
    $t = makeAuthTestTenant();
    $maker = makeAuthTestUser($t, B89_ALL);
    $checker = makeAuthTestUser($t, B89_ALL);
    $party = \App\Models\Party::create(['type' => 'PERSON', 'display_name' => 'Held Person', 'status' => 'ACTIVE']);
    $doc = b89Doc($t, ['document_type_code' => 'ACCIDENT_REPORT', 'party_id' => $party->id]);
    DB::table('documents')->where('id', $doc->id)->update(['created_at' => now()->subYears(12)]);
    $sid = DB::table('retention_schedules')->insertGetId(['id' => (string) Str::uuid(), 'tenant_id' => null, 'code' => 'DEFAULT', 'retention_years' => 10, 'trigger_event' => 'CREATED_AT', 'status' => 'DRAFT', 'created_by' => $maker->id, 'created_at' => now(), 'updated_at' => now()], 'id');
    app(\App\Application\Documents\Retention\RetentionScheduleService::class)->approve($t->id, $sid, $checker);

    // legal hold on the PARTY blocks the document
    Passport::actingAs($maker);
    $hold = $this->postJson('/api/v1/document-governance/legal-holds', ['subject_type' => 'PARTY', 'subject_id' => $party->id, 'reason_code' => 'LITIGATION', 'notes' => 'Court case'], tenantHeader($t))
        ->assertCreated()->json('data');
    $this->postJson("/api/v1/document-governance/documents/{$doc->id}/destruction-requests", ['reason' => 'expired'], tenantHeader($t))->assertStatus(409);
    $this->postJson("/api/v1/document-governance/legal-holds/{$hold['id']}/release", ['reason' => 'done'], tenantHeader($t))->assertStatus(403);
    Passport::actingAs($checker);
    $this->postJson("/api/v1/document-governance/legal-holds/{$hold['id']}/release", ['reason' => 'Case settled'], tenantHeader($t))->assertOk();

    Passport::actingAs($maker);
    $req = $this->postJson("/api/v1/document-governance/documents/{$doc->id}/destruction-requests", ['reason' => 'Retention elapsed'], tenantHeader($t))->assertCreated()->json('data');
    expect(DB::table('cases')->find($req['case_id'])->case_type_code)->toBe('DOCUMENT_DESTRUCTION');
    $this->postJson("/api/v1/document-governance/destruction-requests/{$req['id']}/decide", ['approve' => true, 'note' => 'self'], tenantHeader($t))->assertStatus(403);

    Passport::actingAs($checker);
    $this->postJson("/api/v1/document-governance/destruction-requests/{$req['id']}/decide", ['approve' => true, 'note' => 'Checked'], tenantHeader($t))
        ->assertOk()->assertJsonPath('data.status', 'DESTROYED');
    expect(DB::table('documents')->find($doc->id)->destroyed_at)->not->toBeNull()
        ->and(DB::table('cases')->find($req['case_id'])->status)->toBe('CLOSED')
        ->and(DB::table('case_decisions')->where('case_id', $req['case_id'])->value('outcome'))->toBe('APPROVED')
        ->and(b89Outbox('document.destroyed'))->toBe(1);
});

it('REQ-DOC-009 a hold placed after the request blocks destruction at approval time (legacy holds honoured)', function () {
    $t = makeAuthTestTenant();
    $maker = makeAuthTestUser($t, B89_ALL);
    $checker = makeAuthTestUser($t, B89_ALL);
    $doc = b89Doc($t);
    DB::table('documents')->where('id', $doc->id)->update(['created_at' => now()->subYears(3)]);
    $sid = DB::table('retention_schedules')->insertGetId(['id' => (string) Str::uuid(), 'tenant_id' => $t->id, 'code' => 'ALL-1Y', 'retention_years' => 1, 'trigger_event' => 'CREATED_AT', 'status' => 'DRAFT', 'created_by' => $maker->id, 'created_at' => now(), 'updated_at' => now()], 'id');
    app(\App\Application\Documents\Retention\RetentionScheduleService::class)->approve($t->id, $sid, $checker);

    Passport::actingAs($maker);
    $req = $this->postJson("/api/v1/document-governance/documents/{$doc->id}/destruction-requests", ['reason' => 'Retention elapsed'], tenantHeader($t))->assertCreated()->json('data');
    DB::table('document_retention_holds')->insert(['id' => (string) Str::uuid(), 'document_id' => $doc->id, 'reason_code' => 'REGULATOR', 'notes' => 'CIMA inspection', 'placed_by' => $maker->id, 'created_at' => now(), 'updated_at' => now()]);

    Passport::actingAs($checker);
    $this->postJson("/api/v1/document-governance/destruction-requests/{$req['id']}/decide", ['approve' => true, 'note' => 'ok'], tenantHeader($t))
        ->assertOk()->assertJsonPath('data.status', 'BLOCKED');
    expect(DB::table('documents')->find($doc->id)->destroyed_at)->toBeNull();
});

it('REQ-DOC-012 click-to-sign: ordered signers, pinned hash, evidence and completion', function () {
    $t = makeAuthTestTenant();
    $staff = makeAuthTestUser($t, B89_ALL);
    $first = makeAuthTestUser($t, []);
    $second = makeAuthTestUser($t, []);
    $doc = b89Doc($t, ['document_origin' => 'SYSTEM']);
    Passport::actingAs($staff);

    $this->postJson('/api/v1/document-governance/signature-requests', ['document_id' => $doc->id, 'consent_text' => 'I agree', 'provider' => 'DOCUSIGN', 'signers' => [['user_id' => $first->id, 'name' => 'A', 'role' => 'POLICYHOLDER']]], tenantHeader($t))->assertStatus(422);
    $req = $this->postJson('/api/v1/document-governance/signature-requests', ['document_id' => $doc->id, 'consent_text' => 'I agree to sign electronically.', 'signers' => [
        ['user_id' => $first->id, 'name' => 'First', 'role' => 'POLICYHOLDER', 'order' => 1],
        ['user_id' => $second->id, 'name' => 'Second', 'role' => 'INSURER', 'order' => 2],
    ]], tenantHeader($t))->assertCreated()->json('data');
    expect($req['status'])->toBe('PENDING')->and($req['provider'])->toBe('MANUAL');

    Passport::actingAs($second);
    $this->postJson("/api/v1/signature-requests/{$req['id']}/sign", ['consent_accepted' => true])->assertStatus(409);
    Passport::actingAs($staff);
    $this->postJson("/api/v1/signature-requests/{$req['id']}/sign", ['consent_accepted' => true])->assertStatus(404);

    Passport::actingAs($first);
    $this->postJson("/api/v1/signature-requests/{$req['id']}/sign", ['consent_accepted' => false])->assertStatus(422);
    $after = $this->postJson("/api/v1/signature-requests/{$req['id']}/sign", ['consent_accepted' => true])->assertOk()->json('data');
    expect($after['status'])->toBe('PENDING')->and($after['signers'][0]['status'])->toBe('SIGNED')
        ->and($after['signers'][0]['method'])->toBe('CLICK_TO_SIGN')
        ->and($after['signers'][0]['evidence']['document_sha256'])->toBe($doc->sha256)
        ->and($after['signers'][0]['signature_hash'])->toHaveLength(64);

    // document bytes changed after sending: second signer is refused
    DB::table('documents')->where('id', $doc->id)->update(['sha256' => str_repeat('a', 64)]);
    Passport::actingAs($second);
    $this->postJson("/api/v1/signature-requests/{$req['id']}/sign", ['consent_accepted' => true])->assertStatus(409);
    DB::table('documents')->where('id', $doc->id)->update(['sha256' => $doc->sha256]);
    $done = $this->postJson("/api/v1/signature-requests/{$req['id']}/sign", ['consent_accepted' => true])->assertOk()->json('data');
    expect($done['status'])->toBe('COMPLETED')->and(b89Outbox('document.signature.completed'))->toBe(1)->and(b89Outbox('document.signature.signed'))->toBe(2);
});

it('REQ-DOC-012 a decline ends the request', function () {
    $t = makeAuthTestTenant();
    $staff = makeAuthTestUser($t, B89_ALL);
    $signer = makeAuthTestUser($t, []);
    $doc = b89Doc($t);
    Passport::actingAs($staff);
    $req = $this->postJson('/api/v1/document-governance/signature-requests', ['document_id' => $doc->id, 'consent_text' => 'I agree', 'signers' => [['user_id' => $signer->id, 'name' => 'S', 'role' => 'POLICYHOLDER']]], tenantHeader($t))->json('data');

    Passport::actingAs($signer);
    $this->postJson("/api/v1/signature-requests/{$req['id']}/decline", ['reason' => 'Wrong premium'])->assertOk()->assertJsonPath('data.status', 'DECLINED');
    $this->postJson("/api/v1/signature-requests/{$req['id']}/sign", ['consent_accepted' => true])->assertStatus(404);
    expect(b89Outbox('document.signature.declined'))->toBe(1);
});
