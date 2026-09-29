<?php

declare(strict_types=1);

/*
 * UI batches 24 / 26 — operations console, tariffs, adjuster workspace, calendars, rating, reconciliation, release
 * assurance and KPI catalogue. Screens open with the API's read permission and 403 without it; every action is hidden
 * without the API permission and changes state through the same service with it; service refusals (maker-checker)
 * are shown as a failure notification.
 */

use App\Application\Cases\Models\CalendarBusinessHours;
use App\Application\Cases\Models\CalendarException;
use App\Application\Cases\Models\WorkQueue;
use App\Application\OperationsTaxonomy\OperationsCatalogue;
use App\Application\Rating\TariffGovernanceService;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Admin\Pages\OperationsDesk\CalendarBreaks;
use App\Filament\Admin\Pages\OperationsDesk\ChargeTables;
use App\Filament\Admin\Pages\OperationsDesk\FailedJobs;
use App\Filament\Admin\Pages\OperationsDesk\KpiCatalogue;
use App\Filament\Admin\Pages\OperationsDesk\PlatformNotificationTemplates;
use App\Filament\Admin\Pages\OperationsDesk\ReconciliationExceptions;
use App\Filament\Admin\Resources\BusinessHours\Pages\CreateBusinessHours;
use App\Filament\Admin\Resources\CalendarExceptions\Pages\CreateCalendarException;
use App\Filament\Shared\Actions\AdjusterActions;
use App\Filament\Shared\Actions\CalendarActions;
use App\Filament\Shared\Actions\OperationsConsoleActions;
use App\Filament\Shared\Actions\RatingActions;
use App\Filament\Shared\Actions\ReconciliationActions;
use App\Filament\Shared\Actions\ReleaseAssuranceActions;
use App\Filament\Shared\Actions\TariffActions;
use App\Models\ReconciliationImport;
use App\Models\ReconciliationItem;
use App\Models\ReleaseCandidate;
use App\Models\Role;
use App\Models\TariffVersion;
use App\Models\TenantMembership;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Livewire\Livewire;
use Tests\Support\WorkflowActionHarness;

uses(RefreshDatabase::class);

require_once __DIR__.'/../Wave12/Concerns/mobile_customer_helpers.php';

function opsUser(string $tenantId, array $permissions): User
{
    $u = User::create(['full_name' => 'Ops '.Str::random(5), 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
    $m = TenantMembership::create(['tenant_id' => $tenantId, 'user_id' => $u->id, 'role_code' => 'COMPLIANCE_ADMIN', 'status' => 'ACTIVE']);
    $m->roles()->attach(Role::create(['tenant_id' => $tenantId, 'code' => 'OPS-'.Str::random(8), 'permissions' => $permissions, 'is_system' => false])->id);

    return $u;
}

function opsAs(User $u, string $tenantId): void
{
    test()->actingAs($u);
    app(TenantContext::class)->set($tenantId);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
}

function opsHarness(array $actions, ?object $record = null)
{
    WorkflowActionHarness::$actions = $actions;

    return Livewire::test(WorkflowActionHarness::class, $record ? ['model' => $record::class, 'recordId' => $record->getKey()] : []);
}

const OPS_ALL = ['operations.platform.view', 'operations.jobs.manage', 'operations.taxonomy.read', 'operations.taxonomy.manage', 'operations.notification_templates.approve',
    'tariff.manage', 'tariff.approve', 'tariff.publish', 'claims.experts.work', 'cases.calendar.manage',
    'rating.charges.view', 'rating.charges.manage', 'rating.charges.approve', 'rating.charges.verify', 'rating.runs.view',
    'reconciliation.read', 'reconciliation.import', 'reconciliation.resolve', 'reconciliation.approve',
    'releases.create', 'releases.assess', 'releases.certify', 'releases.security-findings.create', 'releases.recovery-exercises.create',
    'reporting.kpis.view', 'reporting.kpis.manage', 'reporting.kpis.approve'];

beforeEach(function () {
    $this->f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
    $this->tenant = $this->f['tenant']->id;
    app(TenantContext::class)->set($this->tenant);
});

it('renders the batch 24 / 26 screens for the read permission and 403s without it', function () {
    $pages = ['/admin/operations/failed-jobs', '/admin/operations/notification-templates', '/admin/adjuster/assignments', '/admin/calendars/breaks',
        '/admin/rating/charge-tables', '/admin/rating/runs', '/admin/reconciliation/exceptions', '/admin/release-assurance', '/admin/reporting/kpi-catalogue'];
    $this->actingAs(opsUser($this->tenant, OPS_ALL));
    foreach ($pages as $url) {
        $this->get($url)->assertOk();
    }
    $this->flushSession();
    $this->actingAs(opsUser($this->tenant, ['claims.view']));
    foreach ($pages as $url) {
        $this->get($url)->assertForbidden();
    }
});

it('hides every action from a user without the API permission', function () {
    $tariff = app(TariffGovernanceService::class)->create($this->f['product'], ['effective_from' => now()->addDays(40)->toDateString(), 'input_schema' => [],
        'rules' => ['base_premium_minor' => 50000], 'regulatory_reference' => 'UI-TEST'], opsUser($this->tenant, []));
    $queue = WorkQueue::create(['tenant_id' => $this->tenant, 'code' => 'UIQ', 'name' => 'UI queue', 'routing_rule' => 'PULL']);
    $hours = CalendarBusinessHours::create(['jurisdiction' => 'CM', 'weekday' => 1, 'opens' => '08:00', 'closes' => '17:00', 'valid_from' => '2026-01-01']);
    $candidate = ReleaseCandidate::create(['version' => '9.9.9', 'commit_sha' => str_repeat('a', 40), 'environment' => 'staging', 'status' => 'DRAFT', 'lock_version' => 1, 'created_by' => $this->f['user']->id]);
    opsAs(opsUser($this->tenant, ['claims.view']), $this->tenant);

    foreach (['submit' => 'tariffSubmit', 'reject' => 'tariffReject'] as $m => $name) {
        opsHarness([fn () => TariffActions::$m()], $tariff)->assertActionHidden($name);
    }
    opsHarness([fn () => OperationsConsoleActions::queueSetType()], $queue)->assertActionHidden('queueSetType');
    opsHarness([fn () => CalendarActions::hoursEnd()], $hours)->assertActionHidden('hoursEnd');
    opsHarness([fn () => ReleaseAssuranceActions::recordGate()], $candidate)->assertActionHidden('releaseGateRecord');
    opsHarness([fn () => ReleaseAssuranceActions::certify()], $candidate)->assertActionHidden('releaseCertify');
    foreach (['breakAdd' => fn () => CalendarActions::breakAdd(), 'chargeTableCreate' => fn () => RatingActions::chargeTableCreate(),
        'reconciliationImport' => fn () => ReconciliationActions::import(), 'releaseCandidateCreate' => fn () => ReleaseAssuranceActions::createCandidate(),
        'securityFindingRecord' => fn () => ReleaseAssuranceActions::recordFinding(), 'recoveryExercisePlan' => fn () => ReleaseAssuranceActions::planRecovery(),
        'kpiDraft' => fn () => App\Filament\Shared\Actions\KpiActions::draft()] as $name => $factory) {
        opsHarness([$factory])->assertActionHidden($name);
    }

    // Read-only holders see the screens but not the write actions.
    DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default', 'payload' => '{"displayName":"App\\\\Jobs\\\\X"}', 'exception' => 'boom', 'failed_at' => now()]);
    $job = DB::table('failed_jobs')->first();
    opsAs(opsUser($this->tenant, ['operations.platform.view', 'rating.charges.view', 'reporting.kpis.view']), $this->tenant);
    Livewire::test(FailedJobs::class)->assertOk()->assertActionHidden(TestAction::make('failedJobRetry')->table($job->uuid))
        ->assertActionHidden(TestAction::make('failedJobForget')->table($job->uuid));
    Livewire::test(ChargeTables::class)->assertOk()->assertActionHidden(TestAction::make('chargeTableCreate')->table());
    Livewire::test(KpiCatalogue::class)->assertOk()->assertActionHidden(TestAction::make('kpiDraft')->table());
    expect(AdjusterActions::accept()->isAuthorized())->toBeFalse();
});

it('runs the tariff lifecycle under maker-checker through TariffGovernanceService', function () {
    $maker = opsUser($this->tenant, OPS_ALL);
    $checker = opsUser($this->tenant, OPS_ALL);
    $svc = app(TariffGovernanceService::class);
    // The fixture's open-ended APPROVED tariff would overlap every new version: close it first.
    TariffVersion::where('insurance_product_id', $this->f['product']->id)->update(['status' => 'EXPIRED', 'effective_until' => now()->subDay()->toDateString()]);
    $tariff = $svc->create($this->f['product'], ['effective_from' => now()->addDays(40)->toDateString(), 'input_schema' => [],
        'rules' => ['base_premium_minor' => 50000], 'regulatory_reference' => 'UI-TEST-1'], $maker);

    opsAs($maker, $this->tenant);
    opsHarness([fn () => TariffActions::submit()], $tariff)->callAction('tariffSubmit', ['notes' => 'Ready for independent review'])
        ->assertNotified(__('operations_actions.tariffSubmit.done'));
    expect($tariff->refresh()->status)->toBe('IN_REVIEW');
    // The author cannot approve (refused by the service, shown as a failure).
    opsHarness([fn () => TariffActions::approve()], $tariff)->callAction('tariffApprove', ['reason' => 'Self approval must be refused here'])
        ->assertNotified(__('workflow_actions.failed'));
    expect($tariff->refresh()->status)->toBe('IN_REVIEW');

    opsAs($checker, $this->tenant);
    opsHarness([fn () => TariffActions::approve()], $tariff)->callAction('tariffApprove', ['reason' => 'Rates reviewed against the filing, approved'])
        ->assertNotified(__('operations_actions.tariffApprove.done'));
    expect($tariff->refresh()->status)->toBe('APPROVED');
    opsHarness([fn () => TariffActions::schedule()], $tariff)->callAction('tariffSchedule', ['notes' => 'Schedule for effective date'])
        ->assertNotified(__('operations_actions.tariffSchedule.done'));
    expect($tariff->refresh()->status)->toBe('SCHEDULED');
    opsHarness([fn () => TariffActions::expire()], $tariff)->callAction('tariffExpire', ['notes' => 'Withdrawn before taking effect', 'effective_until' => now()->addDays(41)->toDateString()])
        ->assertNotified(__('operations_actions.tariffExpire.done'));
    expect($tariff->refresh()->status)->toBe('EXPIRED');

    // Reject + activate on a second version.
    $second = $svc->create($this->f['product'], ['effective_from' => now()->toDateString(), 'input_schema' => [], 'rules' => ['base_premium_minor' => 60000], 'regulatory_reference' => 'UI-TEST-2'], $maker);
    $svc->submit($second, $maker, 'Second version ready');
    opsHarness([fn () => TariffActions::reject()], $second)->callAction('tariffReject', ['notes' => 'Base premium not justified'])
        ->assertNotified(__('operations_actions.tariffReject.done'));
    expect($second->refresh()->status)->toBe('REJECTED');
    $third = $svc->create($this->f['product'], ['effective_from' => now()->toDateString(), 'effective_until' => now()->addDays(30)->toDateString(), 'input_schema' => [], 'rules' => ['base_premium_minor' => 60000], 'regulatory_reference' => 'UI-TEST-3'], $maker);
    $svc->submit($third, $maker, 'Third version ready');
    $svc->approve($third->refresh(), $checker, 'Approved for immediate activation');
    opsHarness([fn () => TariffActions::activate()], $third)->callAction('tariffActivate', ['notes' => 'Activate immediately'])
        ->assertNotified(__('operations_actions.tariffActivate.done'));
    expect($third->refresh()->status)->toBe('ACTIVE');
});

it('manages business hours, exceptions and breaks through CalendarAdminService', function () {
    opsAs(opsUser($this->tenant, OPS_ALL), $this->tenant);
    Livewire::test(CreateBusinessHours::class)->fillForm(['jurisdiction' => 'CM', 'weekday' => 2, 'opens' => '08:00', 'closes' => '16:00', 'valid_from' => '2026-01-01'])
        ->call('create')->assertHasNoFormErrors();
    $hours = CalendarBusinessHours::where('weekday', 2)->where('opens', 'like', '08:00%')->firstOrFail();
    expect(DB::table('audit_log')->where('action', 'calendar.business_hours.added')->where('subject_id', $hours->id)->exists())->toBeTrue();
    opsHarness([fn () => CalendarActions::hoursEnd()], $hours)->callAction('hoursEnd', ['valid_to' => '2026-12-31'])->assertNotified(__('operations_actions.hoursEnd.done'));
    expect($hours->refresh()->valid_to->toDateString())->toBe('2026-12-31');

    Livewire::test(CreateCalendarException::class)->fillForm(['jurisdiction' => 'CM', 'date' => '2026-12-25', 'kind' => 'HOLIDAY', 'label' => 'Christmas'])
        ->call('create')->assertHasNoFormErrors();
    expect(CalendarException::where('label', 'Christmas')->value('created_by'))->not->toBeNull();

    Livewire::test(CalendarBreaks::class)->callAction(TestAction::make('breakAdd')->table(), ['jurisdiction' => 'CM', 'starts' => '12:00', 'ends' => '13:00', 'valid_from' => '2026-01-01', 'label' => 'Lunch'])
        ->assertNotified(__('operations_actions.breakAdd.done'));
    $break = DB::table('calendar_breaks')->where('label', 'Lunch')->first();
    expect($break)->not->toBeNull();
    Livewire::test(CalendarBreaks::class)->callAction(TestAction::make('breakEnd')->table($break->id), ['valid_to' => '2026-06-30'])
        ->assertNotified(__('operations_actions.breakEnd.done'));
    expect((string) DB::table('calendar_breaks')->where('id', $break->id)->value('valid_to'))->toStartWith('2026-06-30');
});

it('types a queue, approves a platform template and forgets a failed job through the services', function () {
    $author = opsUser($this->tenant, OPS_ALL);
    opsAs(opsUser($this->tenant, OPS_ALL), $this->tenant);
    $queue = WorkQueue::create(['tenant_id' => $this->tenant, 'code' => 'UIQ', 'name' => 'UI queue', 'routing_rule' => 'PULL']);
    $type = OperationsCatalogue::list('queue_types')[0];
    opsHarness([fn () => OperationsConsoleActions::queueSetType()], $queue)->callAction('queueSetType', ['queue_type' => $type])
        ->assertNotified(__('operations_actions.queueSetType.done'));
    expect($queue->refresh()->queue_type)->toBe($type);

    $tid = (string) Str::uuid();
    DB::table('notification_templates')->insert(['id' => $tid, 'tenant_id' => null, 'code' => 'ui.test.event', 'event_code' => 'UI_TEST_EVENT', 'purpose' => 'SERVICE', 'channel' => 'SMS',
        'locale' => 'en', 'version' => 1, 'status' => 'DRAFT', 'body' => 'Hello', 'required_variables' => '[]', 'created_by' => $author->id, 'created_at' => now(), 'updated_at' => now()]);
    // The author cannot approve their own template.
    opsAs($author, $this->tenant);
    Livewire::test(PlatformNotificationTemplates::class)->callAction(TestAction::make('templateApprove')->table($tid))->assertNotified(__('workflow_actions.failed'));
    expect(DB::table('notification_templates')->where('id', $tid)->value('status'))->toBe('DRAFT');
    opsAs(opsUser($this->tenant, OPS_ALL), $this->tenant);
    Livewire::test(PlatformNotificationTemplates::class)->callAction(TestAction::make('templateApprove')->table($tid))
        ->assertNotified(__('operations_actions.templateApprove.done'));
    expect(DB::table('notification_templates')->where('id', $tid)->value('status'))->toBe('ACTIVE');

    $uuid = (string) Str::uuid();
    DB::table('failed_jobs')->insert(['uuid' => $uuid, 'connection' => 'database', 'queue' => 'default', 'payload' => '{"displayName":"App\\\\Jobs\\\\X"}', 'exception' => 'boom', 'failed_at' => now()]);
    Livewire::test(FailedJobs::class)->assertActionVisible(TestAction::make('failedJobRetry')->table($uuid))
        ->callAction(TestAction::make('failedJobForget')->table($uuid), ['reason' => 'Obsolete test job'])
        ->assertNotified(__('operations_actions.failedJobForget.done'));
    expect(DB::table('failed_jobs')->where('uuid', $uuid)->exists())->toBeFalse();
});

it('creates, approves and verifies a charge table under maker-checker through ChargeTableService', function () {
    $maker = opsUser($this->tenant, OPS_ALL);
    $checker = opsUser($this->tenant, OPS_ALL);
    opsAs($maker, $this->tenant);
    Livewire::test(ChargeTables::class)->callAction(TestAction::make('chargeTableCreate')->table(), ['kind' => 'fee', 'code' => 'PLATFORM_FEE', 'tenant_only' => true,
        'effective_from' => '2031-01-01', 'charges' => [['code' => 'PLATFORM_FEE', 'basis' => 'FIXED', 'fixed_minor' => 1500]]])
        ->assertNotified(__('operations_actions.chargeTableCreate.done'));
    $row = DB::table('fee_schedule_versions')->where('code', 'PLATFORM_FEE')->where('tenant_id', $this->tenant)->firstOrFail();
    expect($row->status)->toBe('DRAFT')->and($row->data_status)->toBe('DEMO_UNVERIFIED');
    // Maker cannot approve their own table.
    Livewire::test(ChargeTables::class)->callAction(TestAction::make('chargeTableApprove')->table($row->id))->assertNotified(__('workflow_actions.failed'));
    Livewire::test(ChargeTables::class)->callAction(TestAction::make('chargeVerificationRequest')->table($row->id), ['legal_basis' => 'Owner fee schedule 2031', 'source_reference' => 'OWNER-2031-01'])
        ->assertNotified(__('operations_actions.chargeVerificationRequest.done'));

    opsAs($checker, $this->tenant);
    Livewire::test(ChargeTables::class)->callAction(TestAction::make('chargeTableApprove')->table($row->id))->assertNotified(__('operations_actions.chargeTableApprove.done'))
        ->callAction(TestAction::make('chargeVerificationDecide')->table($row->id), ['decision' => 'CONFIRM', 'notes' => 'Checked against the owner schedule'])
        ->assertNotified(__('operations_actions.chargeVerificationDecide.done'));
    $row = DB::table('fee_schedule_versions')->where('id', $row->id)->first();
    expect($row->status)->toBe('APPROVED')->and($row->verification_status)->toBe('VERIFIED')->and($row->data_status)->toBe('OWNER_CONFIRMED');
});

it('drafts, submits, approves, rejects and retires KPI versions through KpiCatalogueService', function () {
    $maker = opsUser($this->tenant, OPS_ALL);
    $checker = opsUser($this->tenant, OPS_ALL);
    opsAs($maker, $this->tenant);
    $draft = ['code' => 'ui.active_policies', 'name' => 'Active policies (UI)', 'definition' => 'Count of active policies', 'query_key' => 'policies.active', 'owner' => 'UNDERWRITING'];
    Livewire::test(KpiCatalogue::class)->callAction(TestAction::make('kpiDraft')->table(), $draft)->assertNotified(__('operations_actions.kpiDraft.done'))
        ->callAction(TestAction::make('kpiDraft')->table(), [...$draft, 'name' => 'Second version'])->assertNotified(__('operations_actions.kpiDraft.done'));
    [$v1, $v2] = DB::table('kpi_definitions')->where('tenant_id', $this->tenant)->orderBy('version')->pluck('id')->all();
    Livewire::test(KpiCatalogue::class)->callAction(TestAction::make('kpiSubmit')->table($v1))->assertNotified(__('operations_actions.kpiSubmit.done'))
        ->callAction(TestAction::make('kpiSubmit')->table($v2))->assertNotified(__('operations_actions.kpiSubmit.done'))
        ->callAction(TestAction::make('kpiApprove')->table($v1))->assertNotified(__('workflow_actions.failed'));

    opsAs($checker, $this->tenant);
    Livewire::test(KpiCatalogue::class)->callAction(TestAction::make('kpiApprove')->table($v1))->assertNotified(__('operations_actions.kpiApprove.done'))
        ->callAction(TestAction::make('kpiReject')->table($v2), ['reason' => 'Duplicate of version 1'])->assertNotified(__('operations_actions.kpiReject.done'))
        ->callAction(TestAction::make('kpiRetire')->table($v1))->assertNotified(__('operations_actions.kpiRetire.done'));
    expect(DB::table('kpi_definitions')->where('id', $v1)->value('status'))->toBe('RETIRED')
        ->and(DB::table('kpi_definitions')->where('id', $v2)->value('status'))->toBe('REJECTED');
});

it('imports a statement, resolves its exception and approves it under maker-checker through ReconciliationService', function () {
    $maker = opsUser($this->tenant, OPS_ALL);
    $checker = opsUser($this->tenant, OPS_ALL);
    opsAs($maker, $this->tenant);
    opsHarness([fn () => ReconciliationActions::import()])->callAction('reconciliationImport', ['source_type' => 'PAYMENT_PROVIDER', 'provider' => 'MTN_MOMO',
        'statement_reference' => 'STMT-UI-1', 'period_start' => '2026-09-01', 'period_end' => '2026-09-28', 'currency' => 'XAF',
        'items' => [['external_reference' => 'UNKNOWN-REF-1', 'transaction_at' => '2026-09-10 10:00:00', 'gross_minor' => 5000, 'fee_minor' => 0, 'net_minor' => 5000]]])
        ->assertNotified(__('operations_actions.reconciliationImport.done'));
    $import = ReconciliationImport::where('statement_reference', 'STMT-UI-1')->firstOrFail();
    $item = ReconciliationItem::where('reconciliation_import_id', $import->id)->firstOrFail();
    expect($item->status)->toBe('EXCEPTION');

    Livewire::test(ReconciliationExceptions::class)->assertActionVisible(TestAction::make('manualMatchRequest')->table($item->id))
        ->callAction(TestAction::make('reconciliationResolve')->table($item->id), ['resolution' => 'IGNORED', 'notes' => 'Test transfer, not a premium payment'])
        ->assertNotified(__('operations_actions.reconciliationResolve.done'));
    expect($item->refresh()->status)->toBe('IGNORED');
    // The uploader cannot approve.
    opsHarness([fn () => ReconciliationActions::approve()], $import->refresh())->callAction('reconciliationApprove')->assertNotified(__('workflow_actions.failed'));

    opsAs($checker, $this->tenant);
    opsHarness([fn () => ReconciliationActions::approve()], $import->refresh())->callAction('reconciliationApprove')->assertNotified(__('operations_actions.reconciliationApprove.done'));
    expect($import->refresh()->status)->toBe('APPROVED');
});

it('creates a release candidate, records a gate, a finding and a recovery exercise through ReleaseCertificationService', function () {
    $maker = opsUser($this->tenant, OPS_ALL);
    opsAs($maker, $this->tenant);
    opsHarness([fn () => ReleaseAssuranceActions::createCandidate()])->callAction('releaseCandidateCreate', ['version' => '2.0.0', 'commit_sha' => str_repeat('b', 40), 'environment' => 'staging'])
        ->assertNotified(__('operations_actions.releaseCandidateCreate.done'));
    $c = ReleaseCandidate::where('version', '2.0.0')->firstOrFail();
    expect($c->status)->toBe('DRAFT')->and($c->created_by)->toBe($maker->id);
    opsHarness([fn () => ReleaseAssuranceActions::recordGate()], $c)->callAction('releaseGateRecord', ['gate' => 'SECURITY', 'status' => 'PASS', 'evidence' => ['report' => 'pentest-2026-09']])
        ->assertNotified(__('operations_actions.releaseGateRecord.done'));
    expect($c->refresh()->status)->toBe('ASSESSING');
    // The creator cannot certify (policy + maker-checker): refused and shown.
    opsHarness([fn () => ReleaseAssuranceActions::certify()], $c)->callAction('releaseCertify')->assertNotified(__('workflow_actions.failed'));
    expect($c->refresh()->status)->toBe('ASSESSING');

    opsHarness([fn () => ReleaseAssuranceActions::recordFinding()])->callAction('securityFindingRecord', ['release_candidate_id' => $c->id, 'source' => 'PENTEST', 'severity' => 'HIGH',
        'title' => 'Weak TLS configuration', 'description' => 'TLS 1.0 still accepted on the API edge.'])->assertNotified(__('operations_actions.securityFindingRecord.done'));
    opsHarness([fn () => ReleaseAssuranceActions::planRecovery()])->callAction('recoveryExercisePlan', ['environment' => 'production', 'exercise_type' => 'BACKUP_RESTORE',
        'target_rto_minutes' => 60, 'target_rpo_minutes' => 15])->assertNotified(__('operations_actions.recoveryExercisePlan.done'));
    expect(DB::table('security_findings')->where('title', 'Weak TLS configuration')->exists())->toBeTrue()
        ->and(DB::table('recovery_exercises')->where('status', 'PLANNED')->where('target_rto_minutes', 60)->exists())->toBeTrue()
        ->and(DB::table('audit_log')->where('action', 'releases.candidates.create')->where('subject_id', $c->id)->exists())->toBeTrue();
});
