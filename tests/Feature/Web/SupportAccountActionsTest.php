<?php

declare(strict_types=1);

/**
 * UI coverage batches 12 (support) and 13 (account, security): SupportActions and AccountSecurityActions call the same
 * services, with the same permissions, as routes/cases.php, routes/wave8.php and the security-centre routes.
 */

use App\Application\Cases\Models\CaseTask;
use App\Application\Cases\Models\WorkCase;
use App\Application\OperationsTaxonomy\OperationsCatalogue;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Admin\Resources\CaseRecords\Pages\ListCaseRecords;
use App\Filament\Admin\Resources\CaseRecords\Pages\ViewCaseRecord;
use App\Filament\Admin\Resources\NotificationDeliveries\Pages\ListNotificationDeliveries;
use App\Filament\Admin\Resources\NotificationDeliveries\Pages\ViewNotificationDelivery;
use App\Filament\Admin\Resources\SecurityFindings\Pages\ListSecurityFindings;
use App\Filament\Admin\Resources\SecurityFindings\Pages\ViewSecurityFinding;
use App\Filament\Admin\Resources\SecurityFindings\SecurityFindingResource;
use App\Filament\Admin\Resources\SupportTickets\Pages\ListSupportTickets;
use App\Filament\Admin\Resources\SupportTickets\Pages\ViewSupportTicket;
use App\Models\Role;
use App\Models\TenantMembership;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

const SA_ALL = ['cases.view', 'cases.manage', 'cases.assign', 'cases.decide', 'cases.admin', 'support.manage', 'communications.manage',
    'security.findings.read', 'security.findings.manage', 'security.findings.accept_risk'];

function saUser(string $tenantId, array $permissions): User
{
    $u = User::create(['full_name' => 'SA '.Str::random(4), 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
    $m = TenantMembership::create(['tenant_id' => $tenantId, 'user_id' => $u->id, 'role_code' => 'COMPLIANCE_ADMIN', 'status' => 'ACTIVE']);
    $m->roles()->attach(Role::create(['tenant_id' => $tenantId, 'code' => 'SA-'.Str::random(8), 'permissions' => $permissions, 'is_system' => false])->id);

    return $u;
}

function saAs(User $u, string $tenantId): void
{
    test()->actingAs($u);
    app(TenantContext::class)->set($tenantId);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
}

function saTicket(string $tenantId, string $type = 'SUPPORT', string $status = 'OPEN'): string
{
    $id = (string) Str::uuid();
    DB::table('support_tickets')->insert(['id' => $id, 'tenant_id' => $tenantId, 'ticket_number' => 'TKT-'.Str::random(8), 'type' => $type, 'category' => 'SERVICE',
        'priority' => 'NORMAL', 'status' => $status, 'subject' => 'Refund refused', 'description' => 'My refund was refused twice without any reason.',
        'sla_due_at' => now()->addDay(), 'idempotency_key' => 'k-'.Str::random(10), 'created_at' => now(), 'updated_at' => now()]);

    return $id;
}

beforeEach(function () {
    $this->f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $this->tenant = $this->f['tenant']->id;
    app(TenantContext::class)->set($this->tenant);
    DB::table('tenant_customers')->insert(['id' => (string) Str::uuid(), 'tenant_id' => $this->tenant, 'party_id' => $this->f['party']->id, 'customer_number' => 'C-'.Str::random(6),
        'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
    $this->staff = saUser($this->tenant, SA_ALL);
    $this->other = saUser($this->tenant, SA_ALL);
    $this->reader = saUser($this->tenant, ['cases.view', 'security.findings.read']);
});

it('shows the support, notification and security actions only to holders of the API permissions', function () {
    saAs($this->staff, $this->tenant);
    Livewire::test(ListCaseRecords::class)->callAction('complaintSubmit', ['complainant_name' => 'Awa Ndiaye', 'channel' => 'EMAIL',
        'description' => 'My claim payment is three weeks late without explanation.'])->assertNotified(__('support_actions.complaintSubmit.done'));
    $case = WorkCase::where('tenant_id', $this->tenant)->where('case_type_code', 'COMPLAINT')->sole();
    $ticket = saTicket($this->tenant);
    $tpl = (string) Str::uuid();
    DB::table('notification_templates')->insert(['id' => $tpl, 'tenant_id' => $this->tenant, 'code' => 'SA_TEST', 'locale' => 'en', 'purpose' => 'TRANSACTIONAL', 'channel' => 'SMS',
        'body' => 'Hello', 'required_variables' => '[]', 'version' => 1, 'status' => 'ACTIVE', 'created_by' => $this->staff->id, 'created_at' => now(), 'updated_at' => now()]);
    $delivery = (string) Str::uuid();
    DB::table('notification_deliveries')->insert(['id' => $delivery, 'tenant_id' => $this->tenant, 'party_id' => $this->f['party']->id, 'template_id' => $tpl, 'channel' => 'SMS',
        'destination_hash' => str_repeat('a', 64), 'status' => 'FAILED', 'attempts' => 1, 'max_attempts' => 5, 'payload' => '{}', 'idempotency_key' => 'nd-1', 'created_at' => now(), 'updated_at' => now()]);

    Livewire::test(ListCaseRecords::class)->assertActionVisible('complaintSubmit')->assertActionVisible('caseLinkLegacy');
    Livewire::test(ViewCaseRecord::class, ['record' => $case->id])->assertOk()
        ->assertActionVisible('caseDecide')->assertActionVisible('caseDiary')->assertActionVisible('complaintAcknowledge')->assertActionVisible('complaintResolution');
    Livewire::test(ListSupportTickets::class)->assertActionVisible('ticketOpen');
    Livewire::test(ViewSupportTicket::class, ['record' => $ticket])->assertActionVisible('ticketTransition')->assertActionHidden('complaintFromTicket');
    Livewire::test(ListNotificationDeliveries::class)->assertActionVisible('notificationQueue');
    Livewire::test(ViewNotificationDelivery::class, ['record' => $delivery])->assertActionVisible('notificationRetry')->assertActionVisible('notificationCancel');
    Livewire::test(ListSecurityFindings::class)->assertOk()->assertActionVisible('findingReport');

    saAs($this->reader, $this->tenant);
    Livewire::test(ListCaseRecords::class)->assertActionHidden('complaintSubmit')->assertActionHidden('caseLinkLegacy');
    $page = Livewire::test(ViewCaseRecord::class, ['record' => $case->id])->assertOk();
    foreach (['caseDecide', 'caseDiary', 'caseReclassify', 'caseAddTask', 'complaintAcknowledge', 'complaintClassify', 'complaintAssign', 'complaintInvestigate',
        'complaintResolution', 'complaintCommunicate', 'complaintEscalate', 'complaintAdvance'] as $name) {
        $page->assertActionHidden($name);
    }
    Livewire::test(ListSecurityFindings::class)->assertOk()->assertActionHidden('findingReport');
    expect(SecurityFindingResource::canViewAny())->toBeTrue();

    saAs(saUser($this->tenant, ['claims.view']), $this->tenant);
    expect(SecurityFindingResource::canViewAny())->toBeFalse();
    expect(WorkCase::find($case->id)->status)->toBe('SUBMITTED');
});

it('drives a complaint through ComplaintService and refuses a final response that was never dispatched', function () {
    saAs($this->staff, $this->tenant);
    Livewire::test(ListCaseRecords::class)->callAction('complaintSubmit', ['complainant_name' => 'Awa Ndiaye', 'channel' => 'EMAIL',
        'description' => 'My claim payment is three weeks late without explanation.', 'regulatory' => false])->assertNotified(__('support_actions.complaintSubmit.done'));
    $case = WorkCase::where('tenant_id', $this->tenant)->where('case_type_code', 'COMPLAINT')->sole();
    expect(DB::table('complaints')->where('case_id', $case->id)->exists())->toBeTrue();

    $view = fn () => Livewire::test(ViewCaseRecord::class, ['record' => $case->id]);
    $view()->callAction('complaintAcknowledge')->assertNotified(__('support_actions.complaintAcknowledge.done'));
    expect($case->fresh()->status)->toBe('ACKNOWLEDGED');
    $view()->callAction('complaintClassify', ['category' => OperationsCatalogue::list('complaint_categories')[0], 'severity' => 'HIGH', 'regulatory' => true])
        ->assertNotified(__('support_actions.complaintClassify.done'));
    expect($case->fresh()->status)->toBe('CLASSIFIED')->and($case->fresh()->priority)->toBe('HIGH');
    $view()->callAction('complaintAssign', ['owner_user_id' => $this->other->id])->assertNotified(__('support_actions.complaintAssign.done'));
    expect($case->fresh()->owner_user_id)->toBe($this->other->id);
    $view()->callAction('complaintInvestigate')->assertNotified(__('support_actions.complaintInvestigate.done'));
    $view()->callAction('complaintResolution', ['outcome' => 'UPHELD', 'resolution_summary' => 'Payment was delayed by our error; paid with apology.'])
        ->assertNotified(__('support_actions.complaintResolution.done'));
    expect($case->fresh()->status)->toBe('RESOLUTION_PROPOSED')
        ->and(DB::table('case_decisions')->where('case_id', $case->id)->value('decision_type'))->toBe('COMPLAINT_RESOLUTION');

    // A drafted (not dispatched, no proof) response cannot be communicated: the complaint guard refuses, nothing moves.
    $draft = app(\App\Application\Correspondence\CorrespondenceService::class)->register($this->tenant, ['direction' => 'OUTBOUND', 'channel' => 'LETTER',
        'counterparty_type' => 'CUSTOMER', 'counterparty_name' => 'Awa Ndiaye', 'case_id' => $case->id, 'subject_line' => 'Final response'], $this->staff);
    $view()->callAction('complaintCommunicate', ['correspondence_id' => is_object($draft) ? $draft->id : $draft])->assertNotified(__('workflow_actions.failed'));
    expect($case->fresh()->status)->toBe('RESOLUTION_PROPOSED');
});

it('adds diary entries, tasks and decisions and reclassifies through the case services', function () {
    saAs($this->staff, $this->tenant);
    Livewire::test(ListCaseRecords::class)->callAction('complaintSubmit', ['complainant_name' => 'Awa Ndiaye', 'channel' => 'PHONE',
        'description' => 'The agent never called me back about my policy.']);
    $case = WorkCase::where('tenant_id', $this->tenant)->where('case_type_code', 'COMPLAINT')->sole();
    $view = fn () => Livewire::test(ViewCaseRecord::class, ['record' => $case->id]);

    $view()->callAction('caseDiary', ['entry_type' => 'CALL', 'body' => 'Called the complainant, left a voicemail.'])->assertNotified(__('support_actions.caseDiary.done'));
    expect(DB::table('diary_entries')->where('case_id', $case->id)->where('entry_type', 'CALL')->exists())->toBeTrue();

    $view()->callAction('caseAddTask', ['title' => 'Pull call recordings'])->assertNotified(__('support_actions.caseAddTask.done'));
    $task = CaseTask::where('case_id', $case->id)->where('title', 'Pull call recordings')->sole();
    $view()->callAction('caseTaskTransition', ['task_id' => $task->id, 'status' => 'IN_PROGRESS'])->assertNotified(__('support_actions.caseTaskTransition.done'));
    expect($task->fresh()->status)->toBe('IN_PROGRESS');

    $view()->callAction('caseDecide', ['decision_type' => 'GOODWILL', 'outcome' => 'APPROVED', 'rationale' => 'Long wait justified a goodwill gesture.'])
        ->assertNotified(__('support_actions.caseDecide.done'));
    expect(DB::table('case_decisions')->where('case_id', $case->id)->where('decision_type', 'GOODWILL')->where('decided_by', $this->staff->id)->exists())->toBeTrue();

    $view()->callAction('caseReclassify', ['case_subtype' => 'REGULATORY', 'reason' => 'Complainant copied the regulator.'])->assertNotified(__('support_actions.caseReclassify.done'));
    expect($case->fresh()->case_subtype)->toBe('REGULATORY');
});

it('opens and moves support tickets and consolidates a complaint ticket onto a complaint case', function () {
    saAs($this->staff, $this->tenant);
    Livewire::test(ListSupportTickets::class)->callAction('ticketOpen', ['type' => 'SUPPORT', 'category' => 'BILLING', 'priority' => 'HIGH',
        'subject' => 'Cannot pay premium', 'description' => 'The mobile money payment fails at the last step every time.'])->assertNotified(__('support_actions.ticketOpen.done'));
    $ticket = DB::table('support_tickets')->where('tenant_id', $this->tenant)->where('subject', 'Cannot pay premium')->sole();
    expect($ticket->status)->toBe('OPEN');

    // OPEN → CLOSED is not a ticket lifecycle move: refused with a danger notification.
    Livewire::test(ViewSupportTicket::class, ['record' => $ticket->id])->callAction('ticketTransition', ['to_status' => 'CLOSED', 'message' => 'Closing now.'])
        ->assertNotified(__('workflow_actions.failed'));
    Livewire::test(ViewSupportTicket::class, ['record' => $ticket->id])->callAction('ticketTransition', ['to_status' => 'TRIAGED', 'message' => 'Looked at it.', 'assigned_to' => $this->other->id])
        ->assertNotified(__('support_actions.ticketTransition.done'));
    expect(DB::table('support_tickets')->where('id', $ticket->id)->value('status'))->toBe('TRIAGED')
        ->and(DB::table('support_tickets')->where('id', $ticket->id)->value('assigned_to'))->toBe($this->other->id);

    $complaintTicket = saTicket($this->tenant, 'COMPLAINT');
    Livewire::test(ViewSupportTicket::class, ['record' => $complaintTicket])->callAction('complaintFromTicket')->assertNotified(__('support_actions.complaintFromTicket.done'));
    expect(DB::table('complaints')->where('support_ticket_id', $complaintTicket)->exists())->toBeTrue();

    $linked = saTicket($this->tenant, 'REGULATORY_COMPLAINT');
    Livewire::test(ListCaseRecords::class)->callAction('caseLinkLegacy', ['source' => 'support_tickets', 'id' => $linked])->assertNotified(__('support_actions.caseLinkLegacy.done'));
    expect(DB::table('complaints')->where('support_ticket_id', $linked)->exists())->toBeTrue();
});

it('queues, retries and cancels notifications through NotificationDeliveryService', function () {
    saAs($this->staff, $this->tenant);
    $tpl = (string) Str::uuid();
    DB::table('notification_templates')->insert(['id' => $tpl, 'tenant_id' => $this->tenant, 'code' => 'SA_TEST', 'locale' => 'en', 'purpose' => 'TRANSACTIONAL', 'channel' => 'SMS',
        'body' => 'Hello :name', 'required_variables' => '["name"]', 'version' => 1, 'status' => 'ACTIVE', 'created_by' => $this->staff->id, 'created_at' => now(), 'updated_at' => now()]);

    Livewire::test(ListNotificationDeliveries::class)->callAction('notificationQueue', ['party_id' => $this->f['party']->id, 'template_id' => $tpl,
        'destination' => '+237670000000', 'variables' => ['name' => 'Awa']])->assertNotified(__('support_actions.notificationQueue.done'));
    $d = DB::table('notification_deliveries')->where('tenant_id', $this->tenant)->where('template_id', $tpl)->sole();
    expect($d->status)->toBe('QUEUED');

    DB::table('notification_deliveries')->where('id', $d->id)->update(['status' => 'FAILED']);
    Livewire::test(ViewNotificationDelivery::class, ['record' => $d->id])->callAction('notificationRetry')->assertNotified(__('support_actions.notificationRetry.done'));
    expect(DB::table('notification_deliveries')->where('id', $d->id)->value('status'))->toBe('QUEUED')
        ->and((int) DB::table('notification_deliveries')->where('id', $d->id)->value('attempts'))->toBe(1);
    Livewire::test(ViewNotificationDelivery::class, ['record' => $d->id])->callAction('notificationCancel')->assertNotified(__('support_actions.notificationCancel.done'));
    expect(DB::table('notification_deliveries')->where('id', $d->id)->value('status'))->toBe('CANCELLED');
});

it('reports security findings and keeps the risk-acceptance maker-checker rule', function () {
    saAs($this->staff, $this->tenant);
    Livewire::test(ListSecurityFindings::class)->callAction('findingReport', ['source' => 'PENTEST', 'severity' => 'HIGH', 'title' => 'IDOR on claims endpoint',
        'description' => 'Another tenant claim can be read by id.'])->assertNotified(__('support_actions.findingReport.done'));
    $f = DB::table('security_findings')->where('tenant_id', $this->tenant)->sole();
    expect($f->status)->toBe('OPEN')->and($f->reported_by)->toBe($this->staff->id);

    Livewire::test(ViewSecurityFinding::class, ['record' => $f->id])->callAction('findingTransition', ['to' => 'TRIAGED', 'notes' => 'Confirmed.'])
        ->assertNotified(__('support_actions.findingTransition.done'));
    expect(DB::table('security_findings')->where('id', $f->id)->value('status'))->toBe('TRIAGED');

    // The reporter cannot accept the risk of their own finding.
    $accept = ['to' => 'RISK_ACCEPTED', 'notes' => 'Compensating WAF rule in place.', 'risk_acceptance_expires_at' => now()->addMonth()->toDateTimeString()];
    Livewire::test(ViewSecurityFinding::class, ['record' => $f->id])->callAction('findingTransition', $accept)->assertNotified(__('workflow_actions.failed'));
    expect(DB::table('security_findings')->where('id', $f->id)->value('status'))->toBe('TRIAGED');

    saAs($this->other, $this->tenant);
    Livewire::test(ViewSecurityFinding::class, ['record' => $f->id])->callAction('findingTransition', $accept)->assertNotified(__('support_actions.findingTransition.done'));
    expect(DB::table('security_findings')->where('id', $f->id)->value('status'))->toBe('RISK_ACCEPTED')
        ->and(DB::table('security_findings')->where('id', $f->id)->value('risk_accepted_by'))->toBe($this->other->id);
});

it('wires the account portal security card and support escalation to the same API paths', function () {
    $profile = file_get_contents(resource_path('views/public/account/partials/account-security.blade.php'));
    foreach (["'/me/email/verification'", "'/me/phone/verification'", "'/me/phone/verification/confirm'", "'/me/mfa/totp'", "'/me/password'", "'/invitations/accept'"] as $path) {
        expect($profile)->toContain($path);
    }
    expect(file_get_contents(resource_path('views/public/account/pages/profile.blade.php')))->toContain("@include('public.account.partials.account-security')");
    $support = file_get_contents(resource_path('views/public/account/pages/support.blade.php'));
    expect($support)->toContain("'/mobile/issue-reports'")->toContain("'/escalate'");
    expect(__('support_actions.portal.escalate', [], 'fr'))->not->toBe('support_actions.portal.escalate');
});
