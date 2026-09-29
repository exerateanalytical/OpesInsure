<?php

declare(strict_types=1);

use App\Application\Identity\RoleCatalogue;
use App\Application\Operations\Monitoring\AlertChecks;
use App\Application\Operations\Monitoring\AlertDispatcher;
use App\Application\Operations\Monitoring\ErrorEventRecorder;
use App\Application\Operations\Monitoring\MonitoringSignals;
use App\Application\Operations\Monitoring\ReleaseId;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Admin\Pages\OperationsDesk\ErrorEvents;
use App\Models\ErrorEvent;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Livewire\Livewire;

uses(RefreshDatabase::class);

/** S12 monitoring & alerting: error events, Errors screen, 5-minute alerts, /up + /status, release footer. */
function s12Tenant(string $type = 'PLATFORM'): string
{
    return Tenant::create(['type' => $type, 'legal_name' => 'S12 '.Str::random(5), 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en', 'settings' => []])->id;
}

function s12User(string $tenantId, array $permissions, string $roleCode = 'SYSTEM_ADMIN', ?string $email = null): User
{
    $u = User::create(['full_name' => 'S12 '.Str::random(4), 'email' => $email, 'phone_e164' => '+2376'.random_int(10000000, 99999999), 'password' => 'x', 'locale' => 'en', 'status' => 'ACTIVE']);
    $m = TenantMembership::create(['tenant_id' => $tenantId, 'user_id' => $u->id, 'role_code' => $roleCode, 'status' => 'ACTIVE']);
    $m->roles()->attach(Role::create(['tenant_id' => $tenantId, 'code' => 'S12-'.Str::random(8), 'permissions' => $permissions, 'is_system' => false])->id);

    return $u;
}

function s12Sent(): array
{
    return collect(app('mailer')->getSymfonyTransport()->messages())
        ->map(fn ($m) => ['to' => $m->getEnvelope()->getRecipients()[0]->getAddress(), 'subject' => $m->getOriginalMessage()->getSubject()])->all();
}

beforeEach(function () {
    config(['mail.default' => 'array', 'monitoring.release' => 'r20261002-120000', 'app.url' => 'https://opesinsure.test', 'monitoring.alerts.thresholds.disk_free_percent' => 0]);
    ReleaseId::flush();
    app('mail.manager')->forgetMailers();
    Artisan::call('ops:heartbeat'); // only the alerts a test provokes should fire
});

it('captures unhandled exceptions by fingerprint without PII, counts them and reopens a resolved one', function () {
    Route::get('/_s12/boom/{id}', fn () => throw new RuntimeException('Payment 123456 failed for jane.doe@example.com "secret body"'))->name('s12.boom');
    $user = s12User(s12Tenant(), []);
    $this->actingAs($user);

    $this->get('/_s12/boom/1')->assertStatus(500);
    $this->get('/_s12/boom/2')->assertStatus(500);

    $e = ErrorEvent::sole();
    expect($e->occurrences)->toBe(2)
        ->and($e->exception_class)->toBe(RuntimeException::class)
        ->and($e->route)->toBe('s12.boom')->and($e->method)->toBe('GET')
        ->and($e->release_id)->toBe('r20261002-120000')->and($e->first_release_id)->toBe('r20261002-120000')
        ->and($e->message)->not->toContain('jane.doe')->not->toContain('123456')->not->toContain('secret body')
        ->and($e->user_id_hash)->not->toBeNull()->not->toBe((string) $user->id)
        ->and(json_encode($e->toArray()))->not->toContain((string) $user->id)
        ->and((int) DB::table('error_event_counts')->sum('total'))->toBe(2);

    $e->update(['status' => ErrorEvent::RESOLVED, 'resolved_at' => now()]);
    $this->get('/_s12/boom/3')->assertStatus(500);
    expect($e->fresh()->status)->toBe(ErrorEvent::OPEN)->and($e->fresh()->occurrences)->toBe(3)->and($e->fresh()->resolved_at)->toBeNull();

    $e->update(['status' => ErrorEvent::IGNORED]);
    $this->get('/_s12/boom/4')->assertStatus(500);
    expect($e->fresh()->status)->toBe(ErrorEvent::IGNORED)->and($e->fresh()->occurrences)->toBe(4);

    expect(ErrorEventRecorder::isDatabaseConnectionError(new PDOException('SQLSTATE[08006] could not connect to server')))->toBeTrue()
        ->and(ErrorEventRecorder::isDatabaseConnectionError(new RuntimeException('nope')))->toBeFalse();
});

it('shows the Errors screen to the platform tenant only, EN + FR, with audited resolve / ignore / reopen', function () {
    $platform = s12Tenant();
    $carrier = s12Tenant('CARRIER');
    report(new LogicException('Something broke 42'));
    $event = ErrorEvent::sole();

    $this->actingAs(s12User($carrier, ['operations.platform.view', 'operations.incidents.manage']));
    app(TenantContext::class)->set($carrier);
    expect(ErrorEvents::canAccess())->toBeFalse();

    $reader = s12User($platform, ['operations.platform.view'], 'PLATFORM_ADMIN');
    $this->actingAs($reader);
    app(TenantContext::class)->set($platform);
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    expect(ErrorEvents::canAccess())->toBeTrue();
    foreach (['en' => 'Errors', 'fr' => 'Erreurs'] as $lang => $title) {
        $this->get('/admin/operations/errors?lang='.$lang)->assertOk()->assertSee($title)->assertSee('LogicException')->assertDontSee('monitoring.');
        app(TenantContext::class)->set($platform);
    }
    Livewire::test(ErrorEvents::class)->assertTableActionHidden('errorResolve', $event->id);

    $manager = s12User($platform, ['operations.platform.view', 'operations.incidents.manage'], 'PLATFORM_ADMIN');
    $this->actingAs($manager);
    Livewire::test(ErrorEvents::class)->callTableAction('errorResolve', $event->id);
    expect($event->fresh()->status)->toBe(ErrorEvent::RESOLVED)->and($event->fresh()->resolved_by)->toBe($manager->id);
    Livewire::test(ErrorEvents::class)->filterTable('status', ErrorEvent::RESOLVED)->callTableAction('errorReopen', $event->id);
    expect($event->fresh()->status)->toBe(ErrorEvent::OPEN);
    Livewire::test(ErrorEvents::class)->callTableAction('errorIgnore', $event->id);
    expect($event->fresh()->status)->toBe(ErrorEvent::IGNORED);
    expect(DB::table('audit_log')->where('action', 'monitoring.error.resolved')->exists())->toBeTrue();
});

it('grants operations.alerts.receive to SYSTEM_ADMIN and PLATFORM_ADMIN only, explicitly', function () {
    expect(RoleCatalogue::defaultPermissions('SYSTEM_ADMIN'))->toContain('operations.alerts.receive')
        ->and(RoleCatalogue::defaultPermissions('PLATFORM_ADMIN'))->toContain('operations.alerts.receive')
        ->and(RoleCatalogue::defaultPermissions('FINANCE_ADMIN'))->not->toContain('operations.alerts.receive')
        ->and(config('permissions'))->toBeArray();
    expect(json_encode(config('permissions')))->toContain('operations.alerts.receive');
});

it('pages explicit alert holders on failed-job growth with cooldown, then sends one recovery', function () {
    $platform = s12Tenant();
    s12User($platform, ['*', 'operations.alerts.receive'], 'SYSTEM_ADMIN', 'ops@opesinsure.test');
    s12User($platform, ['*'], 'FINANCE_ADMIN', 'finance@opesinsure.test');            // wildcard: not paged
    s12User(s12Tenant('CARRIER'), ['operations.alerts.receive'], 'CARRIER_ADMIN', 'carrier@opesinsure.test'); // not the platform
    config(['monitoring.alerts.fallback_emails' => ['oncall@opesinsure.test']]);

    for ($i = 0; $i < 5; $i++) {
        DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default', 'payload' => '{}', 'exception' => 'x', 'failed_at' => now()]);
    }
    Artisan::call('ops:alerts');
    $sent = s12Sent();
    expect(collect($sent)->pluck('to')->sort()->values()->all())->toBe(['oncall@opesinsure.test', 'ops@opesinsure.test'])
        ->and($sent[0]['subject'])->toContain('Failed jobs growing')->toContain('[WARNING]');
    expect(AlertDispatcher::firing())->toHaveKey('failed_jobs');

    Artisan::call('ops:alerts');
    expect(s12Sent())->toHaveCount(2); // dedup inside the cooldown

    $this->travel(61)->minutes();
    Artisan::call('ops:heartbeat');
    DB::table('failed_jobs')->update(['failed_at' => now()]);
    Artisan::call('ops:alerts');
    expect(s12Sent())->toHaveCount(4); // re-sent after the cooldown

    DB::table('failed_jobs')->delete();
    Artisan::call('ops:alerts');
    $sent = s12Sent();
    expect($sent)->toHaveCount(6)->and(collect($sent)->slice(4)->pluck('subject')->implode(' | '))->toContain('[RESOLVED]')->toContain('[RÉSOLU]');
    expect(AlertDispatcher::firing())->not->toHaveKey('failed_jobs');
});

it('evaluates every check: errors, queue, scheduler, payments, carrier API, scanner, disk, database', function () {
    config(['monitoring.alerts.thresholds.error_spike' => 2]);
    $checks = app(AlertChecks::class);
    $quiet = $checks->run();
    foreach (AlertChecks::KEYS as $k) {
        expect($quiet)->toHaveKey($k);
    }
    expect($quiet['database']['firing'])->toBeFalse()->and($quiet['scheduler_heartbeat']['firing'])->toBeFalse()
        ->and($quiet['error_spike']['firing'])->toBeFalse()->and($quiet['payment_auth_failures']['firing'])->toBeFalse()
        ->and($quiet['carrier_api_failures']['firing'])->toBeFalse()->and($quiet['scanner_backlog']['firing'])->toBeFalse();

    report(new RuntimeException('a'));
    report(new RuntimeException('b'));
    foreach (range(1, 3) as $_) {
        MonitoringSignals::record(MonitoringSignals::PAYMENT_AUTH_FAILURE, 'mtn_momo');
        MonitoringSignals::record(MonitoringSignals::DB_CONNECTION_ERROR, 'pgsql');
    }
    $carrierId = (string) Str::uuid();
    foreach (range(1, 10) as $i) {
        DB::table('carrier_api_calls')->insert(['id' => (string) Str::uuid(), 'connection_id' => (string) Str::uuid(), 'carrier_id' => $carrierId, 'service' => 'contract', 'operation' => 'issue',
            'method' => 'POST', 'path' => '/x', 'http_status' => $i <= 7 ? 503 : 200, 'outcome' => $i <= 7 ? 'SERVER_ERROR' : 'OK', 'correlation_id' => 'c', 'created_at' => now()]);
    }
    foreach (range(1, 10) as $_) {
        DB::table('document_scan_queue')->insert(['document_id' => (string) Str::uuid(), 'tenant_id' => (string) Str::uuid(), 'status' => 'SCAN_UNAVAILABLE', 'created_at' => now(), 'updated_at' => now()]);
    }
    DB::table('ops_scheduler_heartbeats')->update(['last_beat_at' => now()->subHour()]);
    if (\Illuminate\Support\Facades\Schema::hasTable('jobs')) {
        DB::table('jobs')->insert(['queue' => 'default', 'payload' => '{}', 'attempts' => 0, 'available_at' => now()->subHour()->getTimestamp(), 'created_at' => now()->subHour()->getTimestamp()]);
    }

    $r = $checks->run();
    expect($r['error_spike']['firing'])->toBeTrue()
        ->and($r['payment_auth_failures']['firing'])->toBeTrue()->and($r['payment_auth_failures']['params']['providers'])->toBe('mtn_momo')
        ->and($r['database']['firing'])->toBeTrue()->and($r['database']['params']['reachable'])->toBe('yes')
        ->and($r['carrier_api_failures']['firing'])->toBeTrue()->and($r['carrier_api_failures']['params']['rate'])->toBe(70)
        ->and($r['scanner_backlog']['firing'])->toBeTrue()
        ->and($r['scheduler_heartbeat']['firing'])->toBeTrue()
        ->and($r['disk_space'])->toHaveKey('params');
    config(['monitoring.alerts.thresholds.disk_free_percent' => 100.1]);
    expect($checks->run()['disk_space']['firing'])->toBeTrue();
    if (\Illuminate\Support\Facades\Schema::hasTable('jobs')) {
        expect($r['queue_backlog']['firing'])->toBeTrue();
    }

    // Every alert renders in both languages without raw keys.
    foreach (['en', 'fr'] as $loc) {
        foreach ($r as $key => $res) {
            if ($res !== null) {
                expect(__('monitoring.alerts.'.$key.'.body', $res['params'], $loc))->not->toContain('monitoring.')->not->toMatch('/:[a-z_]+/');
            }
        }
    }
});

it('serves public /up and a token- or admin-protected /status JSON', function () {
    $this->get('/up')->assertOk();

    config(['monitoring.status_token' => 'uptime-token-s12']);
    $this->getJson('/status')->assertStatus(401);
    $this->getJson('/status?token=wrong')->assertStatus(401);
    $res = $this->getJson('/status', ['Authorization' => 'Bearer uptime-token-s12'])->assertOk();
    expect($res->json('status'))->toBe('ok')->and($res->json('release'))->toBe('r20261002-120000')
        ->and($res->json('checks.database.status'))->toBe('ok')->and($res->json('checks.scheduler.status'))->toBe('ok');

    $platform = s12Tenant();
    \Laravel\Passport\Passport::actingAs(s12User($platform, ['*'], 'PLATFORM_ADMIN'), [], 'api');
    $this->getJson('/status')->assertOk();
    \Laravel\Passport\Passport::actingAs(s12User(s12Tenant('CARRIER'), ['*'], 'CARRIER_ADMIN'), [], 'api');
    $this->getJson('/status')->assertStatus(401);
});

it('shows the release id in the admin footer', function () {
    $platform = s12Tenant();
    $this->actingAs(s12User($platform, ['operations.platform.view'], 'PLATFORM_ADMIN'));
    app(TenantContext::class)->set($platform);
    $this->get('/admin/operations/errors')->assertOk()->assertSee('OpesInsure release r20261002-120000');
    app(TenantContext::class)->set($platform);
    $this->get('/admin/operations/errors?lang=fr')->assertOk()->assertSee('Version OpesInsure r20261002-120000');
});
