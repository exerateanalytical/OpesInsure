<?php

declare(strict_types=1);

use App\Application\Identity\RoleCatalogue;
use App\Application\Operations\Launch\LaunchPreflight;
use App\Application\Operations\Launch\PreflightHeartbeatJob;
use App\Models\{Role, Tenant, TenantMembership, User};
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function preflightByKey(array $results): array
{
    return collect($results)->keyBy('key')->all();
}

function preflightBackupDir(int $ageHours): string
{
    $dir = storage_path('framework/testing/preflight-backups-'.Str::random(6));
    @mkdir($dir, 0775, true);
    $f = $dir.'/opesinsure-test.sql.gz';
    file_put_contents($f, str_repeat('x', 4096));
    touch($f, time() - $ageHours * 3600);

    return $dir;
}

function preflightAdmin(string $type): User
{
    $tenant = Tenant::create(['type' => $type, 'legal_name' => 'Preflight '.$type.' '.Str::random(4), 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en', 'settings' => []]);
    $user = User::factory()->create(['status' => 'ACTIVE', 'locale' => 'en']);
    $m = TenantMembership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role_code' => 'SYSTEM_ADMIN', 'status' => 'ACTIVE']);
    $r = Role::firstOrCreate(['tenant_id' => $tenant->id, 'code' => 'SYSTEM_ADMIN'], ['id' => (string) Str::uuid(), 'permissions' => RoleCatalogue::defaultPermissions('SYSTEM_ADMIN'), 'is_system' => true]);
    $m->roles()->syncWithoutDetaching([$r->id]);

    return $user;
}

it('runs every check and reports PASS/WARN/FAIL with a fix on every non-pass', function () {
    $results = app(LaunchPreflight::class)->run(['backup_path' => preflightBackupDir(2)]);

    expect(array_column($results, 'key'))->toBe(LaunchPreflight::CHECKS);
    foreach ($results as $r) {
        expect($r['status'])->toBeIn(['PASS', 'WARN', 'FAIL']);
        expect($r['detail'])->not->toContain('check crashed');
        if ($r['status'] !== 'PASS') {
            expect($r['fix'])->toBeString()->not->toStartWith('launch_preflight.');
        }
    }
    $by = preflightByKey($results);
    // The testing environment itself is not launch-ready: these must be flagged.
    expect($by['app_env']['status'])->toBe('FAIL');
    expect($by['queue']['status'])->toBe('FAIL'); // sync driver
    expect($by['database']['status'])->toBe('PASS');
    expect($by['migrations']['status'])->toBe('PASS', $by['migrations']['detail']);
    expect($by['backups']['status'])->toBe('PASS');
    expect($by['storage']['status'])->toBe('PASS');
});

it('flags production misconfiguration with the exact fix', function () {
    config(['app.env' => 'production', 'app.debug' => true, 'app.url' => 'http://localhost', 'demo.enabled' => true, 'mail.default' => 'log',
        'services.etech.sms_login' => null, 'services.twilio.account_sid' => null, 'services.clamav.host' => null, 'services.clamav.socket' => null]);
    $by = preflightByKey(app(LaunchPreflight::class)->run(['backup_path' => preflightBackupDir(2)]));

    expect($by['app_debug']['status'])->toBe('FAIL')
        ->and($by['https']['status'])->toBe('FAIL')
        ->and($by['demo_mode']['status'])->toBe('FAIL')->and($by['demo_mode']['fix'])->toContain('DEMO_MODE_ENABLED=false')
        ->and($by['mail']['status'])->toBe('FAIL')->and($by['mail']['fix'])->toContain('MAIL_MAILER=smtp')
        ->and($by['sms']['status'])->toBe('FAIL')
        ->and($by['clamav']['status'])->toBe('FAIL')->and($by['clamav']['fix'])->toContain('clamav-daemon');
});

it('treats an MTN sandbox as a launch blocker and a production target as pass', function () {
    config(['payments.providers.mtn_momo.base_url' => 'https://sandbox.momodeveloper.mtn.com', 'payments.providers.mtn_momo.target_environment' => 'sandbox']);
    expect(preflightByKey(app(LaunchPreflight::class)->run())['mtn_momo']['status'])->toBe('FAIL');

    config(['payments.providers.mtn_momo.base_url' => 'https://proxy.momoapi.mtn.com', 'payments.providers.mtn_momo.target_environment' => 'mtncameroon']);
    $mtn = preflightByKey(app(LaunchPreflight::class)->run())['mtn_momo'];
    expect($mtn['status'])->toBe('PASS')->and($mtn['detail'])->not->toContain('testing-api-key');
});

it('grades backups by age and reports the template count and RBAC drift', function () {
    expect(preflightByKey(app(LaunchPreflight::class)->run(['backup_path' => preflightBackupDir(30)]))['backups']['status'])->toBe('WARN');
    $old = preflightByKey(app(LaunchPreflight::class)->run(['backup_path' => preflightBackupDir(72)]))['backups'];
    expect($old['status'])->toBe('FAIL')->and($old['fix'])->toContain('backup.sh');
    expect(preflightByKey(app(LaunchPreflight::class)->run(['backup_path' => '/nonexistent/backups']))['backups']['status'])->toBe('FAIL');

    $by = preflightByKey(app(LaunchPreflight::class)->run());
    expect($by['templates']['status'])->toBe(DB::table('document_templates')->where('status', 'PUBLISHED')->count() === 660 ? 'PASS' : 'FAIL');

    $tenant = Tenant::create(['type' => 'CARRIER', 'legal_name' => 'Drift '.Str::random(4), 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en', 'settings' => []]);
    Role::create(['id' => (string) Str::uuid(), 'tenant_id' => $tenant->id, 'code' => 'UNDERWRITER', 'permissions' => [], 'is_system' => true]);
    $rbac = preflightByKey(app(LaunchPreflight::class)->run())['rbac'];
    expect($rbac['status'])->toBe('FAIL')->and($rbac['fix'])->toContain('rbac:sync-role-permissions');
});

it('counts ERROR lines of the last hour only', function () {
    $log = storage_path('framework/testing/preflight-'.Str::random(6).'.log');
    @mkdir(dirname($log), 0775, true);
    $old = now()->subHours(3)->format('Y-m-d H:i:s');
    $new = now()->subMinutes(5)->format('Y-m-d H:i:s');
    file_put_contents($log, "[{$old}] production.ERROR: old failure {\"x\":1}\n[{$new}] production.ERROR: MTN MoMo authentication failed. {\"e\":1}\n[{$new}] production.INFO: fine\n");
    $r = preflightByKey(app(LaunchPreflight::class)->run(['log_path' => $log]))['log_errors'];
    expect($r['status'])->toBe('WARN')->and($r['detail'])->toContain('1 ERROR')->and($r['detail'])->toContain('MTN MoMo authentication failed');
});

it('proves the queue with a heartbeat job and records the last beat', function () {
    config(['queue.default' => 'redis']);
    Queue::fake();
    Cache::forget(LaunchPreflight::LAST_BEAT_KEY);
    // No worker picks the heartbeat up → FAIL after the wait.
    expect(preflightByKey(app(LaunchPreflight::class)->run(['probe_queue' => true, 'queue_wait' => 1]))['queue']['status'])->toBe('FAIL');
    Queue::assertPushed(PreflightHeartbeatJob::class, 1);
    // A worker runs it → the screen (no probe) then reports the recent heartbeat.
    (new PreflightHeartbeatJob('t'))->handle();
    expect(preflightByKey(app(LaunchPreflight::class)->run())['queue']['status'])->toBe('PASS');
});

it('sends the test e-mail only with --send and only to a configured address', function () {
    Mail::fake();
    config(['mail.default' => 'smtp', 'mail.from.address' => 'no-reply@example.test', 'services.support.email' => 'support@example.test']);
    $this->artisan('launch:preflight', ['--no-queue-probe' => true])->assertExitCode(1);
    Mail::assertNothingOutgoing();
    $this->artisan('launch:preflight', ['--no-queue-probe' => true, '--send' => true, '--json' => true])->assertExitCode(1);
    expect(LaunchPreflight::testRecipient())->toBe('support@example.test');
});

it('prints every check with its fix and exits 1 on FAIL', function () {
    $this->artisan('launch:preflight', ['--no-queue-probe' => true])
        ->expectsOutputToContain('Migrations')
        ->expectsOutputToContain('Set APP_ENV=production')
        ->expectsOutputToContain('Summary:')
        ->assertExitCode(1);
});

it('shows the Launch readiness screen to platform admins only, in English and French', function () {
    $this->actingAs(preflightAdmin('PLATFORM'));
    $this->get('/admin/operations/launch-readiness?lang=en')->assertOk()->assertSee('Launch readiness')->assertSee('Published document templates')->assertDontSee('launch_preflight.');
    $this->get('/admin/operations/launch-readiness?lang=fr')->assertOk()->assertSee('Préparation au lancement')->assertDontSee('launch_preflight.');

    $this->actingAs(preflightAdmin('CARRIER'));
    expect($this->get('/admin/operations/launch-readiness')->status())->toBeIn([302, 403, 404]);
});
