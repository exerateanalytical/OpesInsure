<?php

declare(strict_types=1);

use App\Application\Approvals\ApprovalActionCatalogue;
use App\Application\Approvals\ApprovalMatrixResolver;
use App\Application\Approvals\ApprovalService;
use App\Application\Approvals\Handlers\ApprovalMatrixChangeHandler;
use App\Application\Configuration\ConfigurationGovernanceService;
use App\Application\Documents\Engine\DocumentStatusService;
use App\Application\Overrides\OverrideService;
use App\Domain\Tenancy\TenantContext;
use App\Models\ApprovalMatrixRule;
use App\Models\ApprovalRequest;
use App\Models\Document;
use App\Models\User;
use App\Providers\ApprovalServiceProvider;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';

function apUser(): User
{
    return User::create(['full_name' => 'Approver '.Str::random(4), 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
}

function apDbRejects(callable $fn): void
{
    $thrown = null;
    try {
        DB::transaction($fn);
    } catch (QueryException $e) {
        $thrown = $e;
    }
    expect($thrown)->not->toBeNull();
}

beforeEach(function () {
    if (! app()->providerIsLoaded(ApprovalServiceProvider::class)) {
        app()->register(ApprovalServiceProvider::class);
    }
});

it('REQ-RBAC-005 seeds one matrix row per catalogued action — the union of WF-081, FRP VI, AOM, SCF, ICE lists', function () {
    foreach (['payment.manual_confirmation', 'document.status_change', 'permission.change', 'write_off.approve', 'refund.approve', 'journal.manual.approve',
        'claim.payment.approve', 'claim.reserve.change', 'tariff.approve', 'privileged_access.grant', 'da_agreement.approve', 'premium.override', 'engine.override',
        'commission.adjust', 'settlement.reinsurance.approve', 'configuration.publish'] as $required) {
        expect(ApprovalActionCatalogue::has($required))->toBeTrue($required);
    }
    $seeded = ApprovalMatrixRule::whereNull('tenant_id')->pluck('action_code')->all();
    expect(array_diff(array_keys(ApprovalActionCatalogue::ACTIONS), $seeded))->toBe([]);
    expect(DB::table('sod_conflict_rules')->count())->toBe(count(ApprovalActionCatalogue::SOD_CONFLICTS));
    expect(fn () => app(ApprovalService::class)->open(apUser(), ['action_code' => 'not.catalogued', 'subject_type' => 'x']))->toThrow(ValidationException::class);
});

it('REQ-RBAC-005 enforces maker ≠ checker in the service and at the database', function () {
    $svc = app(ApprovalService::class);
    [$maker, $checker] = [apUser(), apUser()];
    $req = $svc->open($maker, ['action_code' => 'write_off.approve', 'subject_type' => 'receivable', 'subject_id' => (string) Str::uuid(), 'amount' => 50000, 'currency' => 'XAF', 'reason' => 'Uncollectable']);
    expect($req->status)->toBe('PENDING');

    expect(fn () => $svc->approve($req, $maker))->toThrow(ValidationException::class);
    apDbRejects(fn () => DB::table('approval_decisions')->insert(['id' => (string) Str::uuid(), 'approval_request_id' => $req->id, 'decided_by' => $maker->id, 'decision' => 'APPROVED', 'created_at' => now()]));
    apDbRejects(fn () => DB::table('approval_requests')->where('id', $req->id)->update(['decided_by' => $maker->id]));

    $done = $svc->approve($req, $checker, 'ok');
    expect($done->status)->toBe('APPROVED')->and($done->decided_by)->toBe($checker->id);
    expect(fn () => $svc->reject($done, apUser(), 'too late'))->toThrow(ValidationException::class);
    apDbRejects(fn () => DB::table('approval_decisions')->where('approval_request_id', $req->id)->delete());
    expect(DB::table('audit_log')->where('subject_type', 'approval_request')->where('subject_id', $req->id)->pluck('action')->all())
        ->toBe(['approval.requested', 'approval.approved']);
});

it('REQ-RBAC-006 resolves the most specific matrix rule (tenant, amount band) and applies levels, checker permission and roles', function () {
    $tenant = makeAuthTestTenant();
    app(TenantContext::class)->set($tenant->id);
    ApprovalMatrixRule::create(['tenant_id' => $tenant->id, 'action_code' => 'refund.approve', 'workflow' => 'PAYMENT', 'category' => 'FINANCIAL',
        'min_amount' => 1000000, 'required_approvals' => 2, 'checker_permission' => 'payments.refunds.approve', 'priority' => 10, 'status' => 'ACTIVE']);
    $resolver = app(ApprovalMatrixResolver::class);
    expect($resolver->resolve('refund.approve', ['amount' => 5000, 'tenant_id' => $tenant->id])->tenant_id)->toBeNull();
    expect($resolver->resolve('refund.approve', ['amount' => 2000000, 'tenant_id' => $tenant->id])->required_approvals)->toBe(2);

    $svc = app(ApprovalService::class);
    $maker = makeAuthTestUser($tenant, ['payments.refunds.request']);
    $c1 = makeAuthTestUser($tenant, ['payments.refunds.approve']);
    $c2 = makeAuthTestUser($tenant, ['payments.refunds.approve']);
    $noPerm = makeAuthTestUser($tenant, ['something.else']);
    $req = $svc->open($maker, ['action_code' => 'refund.approve', 'subject_type' => 'payment', 'subject_id' => (string) Str::uuid(), 'amount' => 2000000]);
    expect($req->required_approvals)->toBe(2);

    expect($svc->canDecide($req, $noPerm))->toBeFalse();
    expect(fn () => $svc->approve($req, $noPerm))->toThrow(ValidationException::class);
    $req = $svc->approve($req, $c1);
    expect($req->status)->toBe('PENDING')->and($req->approvals_count)->toBe(1);
    expect(fn () => $svc->approve($req, $c1))->toThrow(ValidationException::class); // same checker twice
    expect($svc->approve($req, $c2)->status)->toBe('APPROVED');
});

it('REQ-RBAC-006 segregation of duties: excluded subject parties and conflicting action pairs on the same subject', function () {
    $svc = app(ApprovalService::class);
    [$maker, $grantee, $checker] = [apUser(), apUser(), apUser()];
    $grant = $svc->open($maker, ['action_code' => 'privileged_access.grant', 'subject_type' => 'user', 'subject_id' => $grantee->id, 'excluded_user_ids' => [$grantee->id]]);
    expect(fn () => $svc->approve($grant, $grantee))->toThrow(ValidationException::class);

    // collector (manual payment confirmation) may not approve the refund of the same payment (ICE gap 30)
    $payment = (string) Str::uuid();
    [$collector, $other] = [apUser(), apUser()];
    $svc->approve($svc->open($collector, ['action_code' => 'payment.manual_confirmation', 'subject_type' => 'payment', 'subject_id' => $payment]), $other);
    $refund = $svc->open($checker, ['action_code' => 'refund.approve', 'subject_type' => 'payment', 'subject_id' => $payment, 'amount' => 1000]);
    expect($svc->blockers($refund, $collector))->toContain('Segregation of duties: you already acted on payment.manual_confirmation for this subject.');
    expect(fn () => $svc->approve($refund, $collector))->toThrow(ValidationException::class);
    expect(fn () => $svc->approve($refund, $other))->toThrow(ValidationException::class); // confirmed the collection too
    expect($svc->approve($refund, apUser())->status)->toBe('APPROVED');
});

it('REQ-RBAC-005 migrated caller: engine overrides open an approval request and can be decided from the inbox', function () {
    $svc = app(ApprovalService::class);
    [$maker, $checker] = [apUser(), apUser()];
    $subject = (string) Str::uuid();
    $o = app(OverrideService::class)->request($maker->id, ['override_type' => 'PREMIUM_OVERRIDE', 'subject_type' => 'quote', 'subject_id' => $subject, 'field' => 'premium_minor',
        'previous_value' => 150000, 'new_value' => 120000, 'reason_code' => 'COMPETITIVE_MATCH', 'justification' => 'Matching a verified competitor quote.']);
    $req = ApprovalRequest::where('source_table', 'engine_overrides')->where('source_id', $o->id)->firstOrFail();
    expect($req->action_code)->toBe('engine.override')->and($req->status)->toBe('PENDING');

    expect(fn () => $svc->approve($req, $maker))->toThrow(ValidationException::class);
    $svc->approve($req, $checker, 'fine');
    expect(DB::table('engine_overrides')->where('id', $o->id)->value('status'))->toBe('APPROVED')->and($req->refresh()->status)->toBe('APPROVED');

    // legacy override created before the engine: approval request is opened lazily on decision
    $legacyId = (string) Str::uuid();
    DB::table('engine_overrides')->insert(['id' => $legacyId, 'override_type' => 'X', 'subject_type' => 'quote', 'subject_id' => $subject, 'reason_code' => 'R', 'justification' => 'Legacy request row', 'requested_by' => $maker->id, 'status' => 'REQUESTED', 'new_value' => '1', 'created_at' => now()]);
    app(OverrideService::class)->reject($legacyId, $checker->id, 'not justified');
    expect(ApprovalRequest::where('source_id', $legacyId)->value('status'))->toBe('REJECTED');
});

it('REQ-RBAC-005 migrated caller: document status changes go through the approval engine', function () {
    $svc = app(ApprovalService::class);
    [$maker, $checker] = [apUser(), apUser()];
    $doc = Document::forceCreate(['id' => (string) Str::uuid(), 'category' => 'POLICY', 'storage_key' => 'k/'.Str::random(8), 'mime_type' => 'application/pdf', 'size_bytes' => 10,
        'sha256' => hash('sha256', Str::random()), 'status' => 'VALID', 'document_origin' => 'SYSTEM']);
    $change = app(DocumentStatusService::class)->request($doc, 'REVOKE', 'Issued on wrong vehicle', $maker);
    $req = ApprovalRequest::where('source_table', 'document_status_changes')->where('source_id', $change->id)->firstOrFail();
    expect($req->action_code)->toBe('document.status_change')->and($req->subject_id)->toBe($doc->id);

    expect(fn () => $svc->approve($req, $maker))->toThrow(ValidationException::class);
    $svc->approve($req, $checker);
    expect($doc->refresh()->status)->toBe('REVOKED')->and($change->refresh()->status)->toBe('APPROVED')->and($req->refresh()->decided_by)->toBe($checker->id);
});

it('REQ-SET-005 configuration change sets go Draft → Review → Approved → Published with maker-checker and effective dating', function () {
    $config = app(ConfigurationGovernanceService::class);
    $svc = app(ApprovalService::class);
    [$maker, $checker] = [apUser(), apUser()];
    $cs = $config->draft($maker, 'feature_flag', 'claims.fast_track', ['enabled' => true], 'Enable fast-track pilot', now()->addDay()->toDateString());
    expect($cs->status)->toBe('DRAFT');
    expect(fn () => $config->submit($cs, $checker))->toThrow(ValidationException::class);
    $cs = $config->submit($cs, $maker);
    expect($cs->status)->toBe('IN_REVIEW');
    expect(fn () => $config->approve($cs, $maker))->toThrow(ValidationException::class);
    expect(fn () => $config->publish($cs, $checker))->toThrow(ValidationException::class);

    $svc->approve(ApprovalRequest::findOrFail($cs->approval_request_id), $checker); // decided from the inbox
    expect($cs->refresh()->status)->toBe('APPROVED');
    expect(fn () => $config->publish($cs, $maker))->toThrow(ValidationException::class);
    $cs = $config->publish($cs, $checker);
    expect($cs->status)->toBe('PUBLISHED');
    expect($config->effectiveValue('feature_flag', 'claims.fast_track'))->toBeNull(); // effective tomorrow
    expect($config->effectiveValue('feature_flag', 'claims.fast_track', now()->addDays(2)->toDateString()))->toBe(['enabled' => true]);
    expect(DB::table('audit_log')->where('action', 'configuration.published')->where('subject_id', $cs->id)->exists())->toBeTrue();
});

it('REQ-RBAC-006 matrix changes are themselves maker-checker governed', function () {
    $svc = app(ApprovalService::class);
    [$maker, $checker] = [apUser(), apUser()];
    $rule = ApprovalMatrixRule::whereNull('tenant_id')->where('action_code', 'write_off.approve')->firstOrFail();
    $req = ApprovalMatrixChangeHandler::propose($svc, $maker, $rule->id, ['required_approvals' => 2, 'bogus' => 'x'], 'Two approvers for write-offs');
    expect($rule->refresh()->required_approvals)->toBe(1);
    expect(fn () => $svc->approve($req, $maker))->toThrow(ValidationException::class);
    $svc->approve($req, $checker);
    expect($rule->refresh()->required_approvals)->toBe(2);
});

it('REQ-RBAC-005 exposes the inbox API and decides through the engine', function () {
    $tenant = makeAuthTestTenant();
    $maker = makeAuthTestUser($tenant, ['approvals.inbox.view', 'approvals.decide']);
    $checker = makeAuthTestUser($tenant, ['approvals.inbox.view', 'approvals.decide']);
    app(TenantContext::class)->set($tenant->id);
    $req = app(ApprovalService::class)->open($maker, ['action_code' => 'journal.manual.approve', 'subject_type' => 'journal', 'subject_id' => (string) Str::uuid(), 'amount' => 10]);
    app(TenantContext::class)->clear();

    Passport::actingAs($maker);
    $this->getJson('/api/v1/approvals', tenantHeader($tenant))->assertOk()->assertJsonPath('data.0.id', $req->id)->assertJsonPath('data.0.can_decide', false);
    $this->postJson("/api/v1/approvals/{$req->id}/approve", [], tenantHeader($tenant))->assertStatus(422);
    Passport::actingAs($checker);
    $this->postJson("/api/v1/approvals/{$req->id}/approve", ['note' => 'ok'], tenantHeader($tenant))->assertOk()->assertJsonPath('data.status', 'APPROVED');
    $this->getJson('/api/v1/approvals/actions', tenantHeader($tenant))->assertOk()->assertJsonCount(count(ApprovalActionCatalogue::ACTIONS), 'data');
});
