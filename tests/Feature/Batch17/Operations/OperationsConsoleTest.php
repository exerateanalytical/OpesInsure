<?php

declare(strict_types=1);

use App\Application\Operations\OperationalExceptionQueue;
use App\Application\Operations\ProductionReadinessReport;
use App\Application\Operations\RestoreVerificationService;
use App\Application\Operations\SystemHealthService;
use App\Application\Finance\ExceptionCentre\FinanceExceptionCentre;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;

uses(RefreshDatabase::class);

require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';

function opsFailedJob(): string
{
    $uuid = (string) Str::uuid();
    DB::table('failed_jobs')->insert(['uuid' => $uuid, 'connection' => 'database', 'queue' => 'default',
        'payload' => json_encode(['uuid' => $uuid, 'displayName' => 'App\\Jobs\\ExampleJob', 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call', 'attempts' => 3, 'data' => ['secret' => 'x']]),
        'exception' => "RuntimeException: boom\n#0 trace", 'failed_at' => now()]);

    return $uuid;
}

it('REQ-OPS-001: health reports db/queue/scheduler/storage checks, UNKNOWN without heartbeat and OK after ops:heartbeat', function () {
    $tenant = makeAuthTestTenant();
    Passport::actingAs(makeAuthTestUser($tenant, []));
    $this->getJson('/api/v1/operations/health', tenantHeader($tenant))->assertForbidden();

    Passport::actingAs(makeAuthTestUser($tenant, ['operations.console.view']));
    $res = $this->getJson('/api/v1/operations/health', tenantHeader($tenant))->assertOk();
    expect($res->json('data.checks.database.status'))->toBe('OK')
        ->and($res->json('data.checks.scheduler.status'))->toBe(SystemHealthService::UNKNOWN)
        ->and(array_keys($res->json('data.checks')))->toContain('queue', 'storage', 'mail', 'sms');

    Artisan::call('ops:heartbeat');
    Artisan::call('ops:heartbeat');
    $res = $this->getJson('/api/v1/operations/health', tenantHeader($tenant))->assertOk();
    expect($res->json('data.checks.scheduler.status'))->toBe('OK')->and($res->json('data.checks.scheduler.beats'))->toBe(2);

    DB::table('ops_scheduler_heartbeats')->update(['last_beat_at' => now()->subHour()]);
    expect($this->getJson('/api/v1/operations/health', tenantHeader($tenant))->json('data.checks.scheduler.status'))->toBe('DOWN');
});

it('REQ-OPS-001: failed jobs are listed without payload bodies, retried and forgotten with audit', function () {
    $tenant = makeAuthTestTenant();
    $a = opsFailedJob();
    $b = opsFailedJob();

    Passport::actingAs(makeAuthTestUser($tenant, ['operations.platform.view']));
    $list = $this->getJson('/api/v1/operations/failed-jobs', tenantHeader($tenant))->assertOk();
    expect($list->json('total'))->toBe(2)->and($list->json('data.0.job'))->toBe('App\\Jobs\\ExampleJob')
        ->and($list->json('data.0'))->not->toHaveKey('payload')->and($list->json('data.0.exception'))->toBe('RuntimeException: boom');
    $this->postJson("/api/v1/operations/failed-jobs/{$a}/retry", [], tenantHeader($tenant))->assertForbidden();

    Passport::actingAs(makeAuthTestUser($tenant, ['operations.jobs.manage']));
    Queue::fake();
    $this->postJson("/api/v1/operations/failed-jobs/{$a}/retry", [], tenantHeader($tenant))->assertOk()->assertJsonPath('data.still_failed', false);
    $this->postJson("/api/v1/operations/failed-jobs/{$b}/forget", [], tenantHeader($tenant))->assertUnprocessable();
    $this->postJson("/api/v1/operations/failed-jobs/{$b}/forget", ['reason' => 'obsolete job'], tenantHeader($tenant))->assertOk()->assertJsonPath('data.forgotten', true);
    $this->postJson('/api/v1/operations/failed-jobs/'.Str::uuid().'/retry', [], tenantHeader($tenant))->assertUnprocessable();
    expect(DB::table('failed_jobs')->count())->toBe(0)
        ->and(DB::table('audit_log')->whereIn('action', ['operations.failed_job.retried', 'operations.failed_job.forgotten'])->count())->toBe(2);
});

it('REQ-OPS-001: correlation view gathers tenant rows by correlation_id and never another tenant\'s', function () {
    $tenant = makeAuthTestTenant();
    $other = makeAuthTestTenant();
    Passport::actingAs(makeAuthTestUser($tenant, ['operations.console.view', 'operations.incidents.manage']));
    $corr = 'corr-'.Str::random(10);
    $id = $this->postJson('/api/v1/operations/incidents', ['title' => 'Traced', 'severity' => 'LOW', 'detected_at' => now()->toIso8601String()], tenantHeader($tenant) + ['X-Request-Id' => $corr])
        ->assertCreated()->json('data.id');

    $res = $this->getJson("/api/v1/operations/correlations/{$corr}", tenantHeader($tenant))->assertOk();
    expect($res->json('data.total'))->toBeGreaterThan(0)->and(collect($res->json('data.sources.audit_log'))->pluck('subject_id'))->toContain($id)
        ->and($res->json('data.timeline.0.source'))->not->toBeNull();

    Passport::actingAs(makeAuthTestUser($other, ['operations.console.view']));
    expect($this->getJson("/api/v1/operations/correlations/{$corr}", tenantHeader($other))->assertOk()->json('data.total'))->toBe(0);
});

it('REQ-OPS-005: unified exception queue reuses the finance centre and hides platform sources without operations.platform.view', function () {
    $tenant = makeAuthTestTenant();
    opsFailedJob();

    Passport::actingAs(makeAuthTestUser($tenant, ['operations.console.view']));
    $res = $this->getJson('/api/v1/operations/exceptions', tenantHeader($tenant))->assertOk();
    $sources = $res->json('data.sources');
    expect(array_keys($sources))->toEqual(OperationalExceptionQueue::sources())
        ->and($sources['issuance_exceptions']['domain'])->toBe('finance')
        ->and($sources['failed_jobs']['available'])->toBeFalse()->and($sources['failed_jobs']['count'])->toBe(0);
    foreach (FinanceExceptionCentre::SOURCES as $s) {
        expect($sources[$s]['domain'])->toBe('finance');
    }

    Passport::actingAs(makeAuthTestUser($tenant, ['operations.console.view', 'operations.platform.view']));
    $res = $this->getJson('/api/v1/operations/exceptions?sources[]=failed_jobs&sources[]=stuck_outbox', tenantHeader($tenant))->assertOk();
    expect(array_keys($res->json('data.sources')))->toEqual(['failed_jobs', 'stuck_outbox'])
        ->and($res->json('data.sources.failed_jobs.count'))->toBe(1)->and($res->json('data.by_domain.operations'))->toBe(1);
    $this->getJson('/api/v1/operations/exceptions?sources[]=nope', tenantHeader($tenant))->assertUnprocessable();
});

it('REQ-OPS-001: integrations monitor shows tenant payment providers; webhook detail only for platform operators', function () {
    $tenant = makeAuthTestTenant();
    Passport::actingAs(makeAuthTestUser($tenant, ['operations.console.view']));
    $res = $this->getJson('/api/v1/operations/integrations', tenantHeader($tenant))->assertOk();
    expect($res->json('data'))->toHaveKey('payment_providers')->not->toHaveKey('webhooks');

    Passport::actingAs(makeAuthTestUser($tenant, ['operations.console.view', 'operations.platform.view']));
    $res = $this->getJson('/api/v1/operations/integrations', tenantHeader($tenant))->assertOk();
    expect($res->json('data'))->toHaveKeys(['webhooks', 'integrations', 'failed_webhooks', 'dead_lettered_deliveries']);
});

it('REQ-OPS-001: incidents register reuses the ICT incident register', function () {
    $tenant = makeAuthTestTenant();
    Passport::actingAs(makeAuthTestUser($tenant, ['operations.console.view']));
    $this->postJson('/api/v1/operations/incidents', ['title' => 'Queue outage', 'severity' => 'HIGH', 'detected_at' => now()->toIso8601String()], tenantHeader($tenant))->assertForbidden();

    Passport::actingAs(makeAuthTestUser($tenant, ['operations.console.view', 'operations.incidents.manage']));
    $id = $this->postJson('/api/v1/operations/incidents', ['title' => 'Queue outage', 'severity' => 'HIGH', 'detected_at' => now()->toIso8601String()], tenantHeader($tenant))
        ->assertCreated()->json('data.id');
    expect(DB::table('governance_ict_incidents')->where('id', $id)->value('tenant_id'))->toBe($tenant->id);
    $this->patchJson("/api/v1/operations/incidents/{$id}", ['status' => 'RESOLVED', 'resolved_at' => now()->toIso8601String()], tenantHeader($tenant))->assertOk()->assertJsonPath('data.status', 'RESOLVED');
    expect($this->getJson('/api/v1/operations/incidents?status=RESOLVED', tenantHeader($tenant))->assertOk()->json('total'))->toBe(1);
});

function opsScratchConnection(): string
{
    $pg = config('database.connections.'.config('database.default'));
    $name = $pg['database'].'_scratch';
    config(['database.connections.ops_admin' => ['database' => 'postgres'] + $pg]);
    try {
        DB::connection('ops_admin')->statement('CREATE DATABASE "'.$name.'"');
    } catch (Throwable) {
        // already exists
    }
    DB::purge('ops_admin');
    config(['database.connections.ops_scratch' => ['database' => $name] + $pg]);
    DB::connection('ops_scratch')->statement('DROP TABLE IF EXISTS ops_restore_probe');
    DB::connection('ops_scratch')->statement('CREATE TABLE ops_restore_probe (id uuid primary key, created_at timestamptz)');

    return 'ops_scratch';
}

it('REQ-OPS-002: restore verification refuses the primary DB, records PASSED/FAILED and never invents RPO/RTO targets', function () {
    $file = tempnam(sys_get_temp_dir(), 'opsbk').'.dump';
    file_put_contents($file, 'backup');
    touch($file, time() + 5);
    DB::statement('CREATE TABLE IF NOT EXISTS ops_restore_probe (id uuid primary key, created_at timestamptz)');
    config(['operations.dr.target_rpo_minutes' => null, 'operations.dr.target_rto_minutes' => null, 'operations.dr.restore.restore_command' => 'true',
        'operations.dr.restore.tables' => ['ops_restore_probe' => ['key' => 'id', 'created' => 'created_at']], 'operations.dr.restore.scratch_connection' => config('database.default')]);

    $refused = app(RestoreVerificationService::class)->run(['backup_file' => $file]);
    expect($refused->status)->toBe('FAILED')->and($refused->evidence['error'])->toContain('primary');

    config(['operations.dr.restore.scratch_connection' => opsScratchConnection()]);
    $x = app(RestoreVerificationService::class)->run(['backup_file' => $file]);
    expect($x->status)->toBe('PASSED')->and($x->target_rpo_minutes)->toBeNull()->and($x->target_rto_minutes)->toBeNull()
        ->and($x->evidence['targets'])->toBe(['rto' => 'target not configured', 'rpo' => 'target not configured'])
        ->and($x->evidence['tables']['ops_restore_probe']['match'])->toBeTrue();

    DB::table('ops_restore_probe')->insert(['id' => (string) Str::uuid(), 'created_at' => now()->subMinute()]);
    config(['operations.dr.target_rpo_minutes' => 60, 'operations.dr.target_rto_minutes' => 30]);
    $y = app(RestoreVerificationService::class)->run(['backup_file' => $file]);
    expect($y->status)->toBe('FAILED')->and($y->target_rpo_minutes)->toBe(60)->and($y->evidence['targets']['rto'])->toBe('met')
        ->and($y->evidence['tables']['ops_restore_probe']['source_rows'])->toBe(1)->and($y->evidence['tables']['ops_restore_probe']['restored_rows'])->toBe(0);

    config(['operations.dr.restore.restore_command' => 'false']);
    expect(Artisan::call('ops:verify-restore', ['--file' => $file]))->toBe(1);

    $tenant = makeAuthTestTenant();
    Passport::actingAs(makeAuthTestUser($tenant, ['operations.console.view']));
    $this->getJson('/api/v1/operations/restore-verifications', tenantHeader($tenant))->assertOk()->assertJsonCount(4, 'data')->assertJsonPath('targets.rpo_minutes', 60);
    DB::connection('ops_scratch')->statement('DROP TABLE IF EXISTS ops_restore_probe');
    @unlink($file);
});

it('REQ-OPS-004: readiness report evaluates automatable per-module criteria and writes markdown', function () {
    $report = app(ProductionReadinessReport::class)->build();
    expect($report['modules'])->toHaveKey('Operations')
        ->and($report['modules']['Operations']['routes_permissioned']['status'])->toBe('PASS')
        ->and($report['modules']['Operations']['tests']['status'])->toBe('PASS')
        ->and(collect($report['platform'])->firstWhere('criterion', 'RPO/RTO targets configured')['status'])->toBe(config('operations.dr.target_rpo_minutes') === null ? 'FAIL' : 'PASS');

    $path = 'storage/app/readiness-test.md';
    expect(Artisan::call('ops:readiness-report', ['--path' => $path]))->toBe(0);
    $md = file_get_contents(base_path($path));
    expect($md)->toContain('# Production readiness report')->toContain('| Operations |')->toContain('## Platform');
    @unlink(base_path($path));
});
