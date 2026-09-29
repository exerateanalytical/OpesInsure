<?php

declare(strict_types=1);

namespace App\Application\Operations\Launch;

use App\Application\Documents\Scanning\MalwareScannerHealth;
use App\Application\Identity\RoleCatalogue;
use App\Application\Payments\Adapters\MtnMomoCredentials;
use App\Models\PaymentProviderConnection;
use App\Models\Role;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use Throwable;

/**
 * Launch 2026-10-02 (S7): everything a real go-live needs, each check PASS / WARN / FAIL with the exact fix
 * (resources/lang/{en,fr}/launch_preflight.php → fix.<key>). Read-only except three probes: a storage
 * write/read/delete round-trip, the queue heartbeat job (only with probe_queue) and the test e-mail (only with
 * send_mail). Secrets are never returned — only "set" / "missing".
 *
 * Used by `php artisan launch:preflight` and the admin "Launch readiness" screen (platform tenant only).
 */
final class LaunchPreflight
{
    public const PASS = 'PASS';

    public const WARN = 'WARN';

    public const FAIL = 'FAIL';

    public const EXPECTED_TEMPLATES = 660;

    public const LAST_BEAT_KEY = 'launch_preflight:last_queue_beat';

    /** Keys in display order (also the lang keys checks.<key> / fix.<key>). */
    public const CHECKS = [
        'app_env', 'app_debug', 'app_key', 'https', 'database', 'migrations', 'queue', 'scheduler', 'cache', 'session',
        'mail', 'sms', 'demo_mode', 'mtn_momo', 'orange_money', 'clamav', 'storage', 'disk_space', 'backups',
        'passport_keys', 'signing_key', 'verify_url', 'activa', 'templates', 'rbac', 'failed_jobs', 'log_errors',
    ];

    /**
     * @param  array{probe_queue?:bool, queue_wait?:int, send_mail?:bool, backup_path?:?string, log_path?:?string}  $options
     * @return list<array{key:string, status:string, detail:string, fix:?string}>
     */
    public function run(array $options = []): array
    {
        $out = [];
        foreach (self::CHECKS as $key) {
            try {
                $check = fn () => $this->{Str::camel('check_'.$key)}($options) + [2 => []];
                // Inside an open transaction (tests, a caller's unit of work) a failed query would poison every later
                // check on PostgreSQL: run each one behind a savepoint instead.
                [$status, $detail, $params] = DB::transactionLevel() > 0 ? DB::transaction($check) : $check();
            } catch (Throwable $e) {
                [$status, $detail, $params] = [self::FAIL, 'check crashed: '.Str::limit($e->getMessage(), 200), []];
            }
            $out[] = ['key' => $key, 'status' => $status, 'detail' => $detail, 'fix' => $status === self::PASS ? null : __('launch_preflight.fix.'.$key, $params)];
        }

        return $out;
    }

    /** @param list<array{status:string}> $results @return array{PASS:int, WARN:int, FAIL:int} */
    public static function summary(array $results): array
    {
        $s = [self::PASS => 0, self::WARN => 0, self::FAIL => 0];
        foreach ($results as $r) {
            $s[$r['status']]++;
        }

        return $s;
    }

    private function checkAppEnv(): array
    {
        $env = (string) config('app.env');
        $cached = app()->configurationIsCached();
        if ($env !== 'production') {
            return [self::FAIL, "APP_ENV={$env}"];
        }

        return [$cached ? self::PASS : self::WARN, 'APP_ENV=production, config '.($cached ? 'cached' : 'NOT cached')];
    }

    private function checkAppDebug(): array
    {
        return config('app.debug') ? [self::FAIL, 'APP_DEBUG=true: stack traces and secrets leak on errors'] : [self::PASS, 'APP_DEBUG=false'];
    }

    private function checkAppKey(): array
    {
        $key = (string) config('app.key');
        $raw = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;

        return $raw !== false && strlen((string) $raw) >= 32 ? [self::PASS, 'APP_KEY set ('.config('app.cipher').')'] : [self::FAIL, 'APP_KEY missing or shorter than 32 bytes'];
    }

    private function checkHttps(): array
    {
        $url = (string) config('app.url');
        $host = (string) parse_url($url, PHP_URL_HOST);
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || $host === '' || in_array($host, ['localhost', '127.0.0.1'], true) || str_ends_with($host, '.test')) {
            return [self::FAIL, "APP_URL={$url}"];
        }
        if (config('session.secure') !== true) {
            return [self::WARN, "APP_URL={$url}, SESSION_SECURE_COOKIE is not true", ['url' => $url]];
        }

        return [self::PASS, "APP_URL={$url}, secure session cookie"];
    }

    private function checkDatabase(): array
    {
        $start = hrtime(true);
        DB::select('select 1');
        $ms = (int) round((hrtime(true) - $start) / 1e6);

        return [self::PASS, DB::getDriverName().' reachable ('.$ms.' ms)'];
    }

    private function checkMigrations(): array
    {
        $migrator = app('migrator');
        if (! $migrator->repositoryExists()) {
            return [self::FAIL, 'migrations table missing'];
        }
        $files = $migrator->getMigrationFiles(array_merge([database_path('migrations')], $migrator->paths()));
        $pending = array_values(array_diff(array_keys($files), $migrator->getRepository()->getRan()));

        return $pending === [] ? [self::PASS, count($files).' migrations, none pending']
            : [self::FAIL, count($pending).' pending: '.Str::limit(implode(', ', $pending), 300)];
    }

    private function checkQueue(array $o): array
    {
        $connection = (string) config('queue.default');
        $driver = (string) config("queue.connections.{$connection}.driver");
        if (in_array($driver, ['sync', 'null'], true)) {
            return [self::FAIL, "QUEUE_CONNECTION={$connection} ({$driver}): no background worker"];
        }
        if (! ($o['probe_queue'] ?? false)) {
            $last = Cache::get(self::LAST_BEAT_KEY);
            $age = $last ? (int) abs(now()->diffInSeconds(CarbonImmutable::parse($last))) : null;

            return $age !== null && $age <= 3600 ? [self::PASS, "{$driver}: last worker heartbeat {$age}s ago (launch:preflight)"]
                : [self::WARN, "{$driver}: no worker heartbeat in the last hour — run php artisan launch:preflight"];
        }
        $token = (string) Str::uuid();
        $start = microtime(true);
        PreflightHeartbeatJob::dispatch($token)->onConnection($connection);
        $wait = max(1, (int) ($o['queue_wait'] ?? 20));
        while (microtime(true) - $start < $wait) {
            if (Cache::get(PreflightHeartbeatJob::key($token)) !== null) {
                return [self::PASS, sprintf('%s: heartbeat job processed in %.1fs', $driver, microtime(true) - $start)];
            }
            usleep(250_000);
        }

        return [self::FAIL, "{$driver}: heartbeat job not processed within {$wait}s"];
    }

    private function checkScheduler(): array
    {
        $row = Schema::hasTable('ops_scheduler_heartbeats') ? DB::table('ops_scheduler_heartbeats')->where('name', 'scheduler')->first() : null;
        if (! $row) {
            return [self::FAIL, 'no schedule:run heartbeat recorded (ops:heartbeat)'];
        }
        $age = (int) abs(now()->diffInSeconds(CarbonImmutable::parse($row->last_beat_at)));

        return [$age <= 180 ? self::PASS : self::FAIL, "last schedule:run heartbeat {$age}s ago on ".($row->host ?? '?')];
    }

    private function checkCache(): array
    {
        $store = (string) config('cache.default');
        $driver = (string) config("cache.stores.{$store}.driver");
        $key = 'launch-preflight:'.Str::uuid();
        Cache::put($key, 'ok', 30);
        $ok = Cache::get($key) === 'ok';
        Cache::forget($key);
        if (! $ok) {
            return [self::FAIL, "cache {$store} ({$driver}): write/read failed"];
        }

        return [match (true) { in_array($driver, ['array', 'null'], true) => self::FAIL, $driver === 'file' => self::WARN, default => self::PASS }, "CACHE_STORE={$store} ({$driver})"];
    }

    private function checkSession(): array
    {
        $driver = (string) config('session.driver');

        return [match (true) { in_array($driver, ['array', 'cookie'], true) => self::FAIL, $driver === 'file' => self::WARN, default => self::PASS }, "SESSION_DRIVER={$driver}"];
    }

    private function checkMail(array $o): array
    {
        $mailer = (string) config('mail.default');
        $from = (string) config('mail.from.address');
        if (in_array($mailer, ['log', 'array', ''], true) || $from === '') {
            return [self::FAIL, "MAIL_MAILER={$mailer}".($from === '' ? ', MAIL_FROM_ADDRESS missing' : '').': no e-mail leaves the server'];
        }
        $detail = "MAIL_MAILER={$mailer} (".config("mail.mailers.{$mailer}.transport")."), from {$from}";
        if (! ($o['send_mail'] ?? false)) {
            return [self::PASS, $detail.' — test e-mail not sent (use --send)'];
        }
        $to = self::testRecipient();
        Mail::raw('OpesInsure launch preflight test e-mail sent at '.now()->toIso8601String().'.', fn ($m) => $m->to($to)->subject('OpesInsure launch preflight'));

        return [self::PASS, $detail." — test e-mail sent to {$to}"];
    }

    /** --send only ever mails an address already configured on the server (SUPPORT_EMAIL, else MAIL_FROM_ADDRESS). */
    public static function testRecipient(): string
    {
        return (string) (config('services.support.email') ?: config('mail.from.address'));
    }

    private function checkSms(): array
    {
        $providers = [];
        if (filled(config('services.etech.sms_login')) && filled(config('services.etech.sms_password'))) {
            $providers[] = 'etech';
        }
        if (filled(config('services.twilio.account_sid')) && filled(config('services.twilio.auth_token')) && (filled(config('services.twilio.sms_from')) || filled(config('services.twilio.whatsapp_from')))) {
            $providers[] = 'twilio';
        }
        // S14: providers configured in Admin → Integrations → SMS providers (PRIMARY/FALLBACK, ACTIVE).
        if (Schema::hasTable('sms_provider_connections')) {
            foreach (DB::table('sms_provider_connections')->where('status', 'ACTIVE')->whereIn('role', ['PRIMARY', 'FALLBACK'])->whereNotNull('secrets')->get(['provider', 'role', 'health_status']) as $c) {
                $providers[] = "{$c->provider} ({$c->role}, {$c->health_status})";
            }
        }

        return $providers === [] ? [self::FAIL, 'no SMS/OTP provider configured: real customers cannot receive login codes']
            : [self::PASS, 'OTP providers: '.implode(', ', $providers).'; delivery '.config('services.otp.delivery_mode', 'queue')];
    }

    private function checkDemoMode(): array
    {
        if (! config('demo.enabled')) {
            return [self::PASS, 'DEMO_MODE_ENABLED=false'];
        }

        return [self::FAIL, 'DEMO_MODE_ENABLED=true: fixed demo OTP and demo sign-in are live'.(config('demo.allow_in_production') ? ', DEMO_ALLOW_IN_PRODUCTION=true' : '')];
    }

    private function checkMtnMomo(): array
    {
        $c = MtnMomoCredentials::resolve();
        $host = (string) parse_url($c->get('base_url'), PHP_URL_HOST);
        if (! $c->configured()) {
            return [self::FAIL, "MTN MoMo credentials missing (host {$host})"];
        }
        if ($c->isSandbox() || str_contains($host, 'sandbox')) {
            return [self::FAIL, "MTN MoMo is on SANDBOX ({$host}, target {$c->get('target_environment')}): no real money is collected"];
        }

        return [self::PASS, "MTN MoMo production: {$host}, target {$c->get('target_environment')}"];
    }

    private function checkOrangeMoney(): array
    {
        $cfg = (array) config('payments.providers.orange_money', []);
        $env = filled($cfg['merchant_key'] ?? null) && filled($cfg['client_id'] ?? null) && filled($cfg['client_secret'] ?? null);
        $conn = PaymentProviderConnection::query()->where('provider', 'orange_money')->where('status', 'ACTIVE')->value('environment');
        if ($conn !== null) {
            return [$conn === 'PRODUCTION' ? self::PASS : self::WARN, "Orange Money connection ACTIVE ({$conn})"];
        }

        return $env ? [self::PASS, 'Orange Money credentials set in .env ('.parse_url((string) ($cfg['base_url'] ?? ''), PHP_URL_HOST).')']
            : [self::WARN, 'Orange Money not configured: customers can pay by MTN MoMo only'];
    }

    private function checkClamav(): array
    {
        $s = app(MalwareScannerHealth::class)->status();
        if (! $s['configured']) {
            return [self::FAIL, 'ClamAV not configured: every upload is held unscanned (fail-closed)'];
        }

        return $s['reachable'] ? [self::PASS, "ClamAV {$s['endpoint']}: ".trim((string) $s['version'])]
            : [self::FAIL, "ClamAV {$s['endpoint']} unreachable: ".Str::limit((string) $s['error'], 150)];
    }

    private function checkStorage(): array
    {
        $problems = [];
        foreach (['storage/app' => storage_path('app'), 'storage/logs' => storage_path('logs'), 'storage/framework/cache' => storage_path('framework/cache'), 'bootstrap/cache' => base_path('bootstrap/cache')] as $label => $dir) {
            if (! is_dir($dir) || ! is_writable($dir)) {
                $problems[] = $label;
            }
        }
        $disk = (string) config('filesystems.default');
        $probe = '.launch-preflight/'.Str::uuid().'.txt';
        Storage::disk($disk)->put($probe, 'ok');
        $read = Storage::disk($disk)->get($probe);
        Storage::disk($disk)->delete($probe);
        if ($read !== 'ok') {
            $problems[] = "disk {$disk} round-trip";
        }

        return $problems === [] ? [self::PASS, "writable; disk {$disk} round-trip ok"] : [self::FAIL, 'not writable: '.implode(', ', $problems), ['user' => get_current_user()]];
    }

    private function checkDiskSpace(): array
    {
        $free = @disk_free_space(storage_path());
        $total = @disk_total_space(storage_path());
        if ($free === false || ! $total) {
            return [self::WARN, 'free space unknown'];
        }
        $gb = $free / 1024 ** 3;
        $pct = $free / $total * 100;
        $detail = sprintf('%.1f GB free (%.0f%%)', $gb, $pct);

        return [match (true) { $gb < 2 || $pct < 5 => self::FAIL, $gb < 10 || $pct < 15 => self::WARN, default => self::PASS }, $detail];
    }

    private function checkBackups(array $o): array
    {
        $dir = (string) ($o['backup_path'] ?? null ?: config('operations.dr.restore.backup_path') ?: dirname(base_path(), 2).DIRECTORY_SEPARATOR.'backups');
        if (! is_dir($dir) || ! is_readable($dir)) {
            return [self::FAIL, "backup directory {$dir} missing or unreadable", ['dir' => $dir]];
        }
        $newest = null;
        foreach (scandir($dir) ?: [] as $f) {
            if (preg_match('/\.(sql|sql\.gz|dump|backup|gz)$/i', $f) && is_file($path = $dir.DIRECTORY_SEPARATOR.$f)) {
                $mtime = (int) filemtime($path);
                if ($newest === null || $mtime > $newest[1]) {
                    $newest = [$f, $mtime, (int) filesize($path)];
                }
            }
        }
        if ($newest === null) {
            return [self::FAIL, "no backup file in {$dir}", ['dir' => $dir]];
        }
        $hours = (time() - $newest[1]) / 3600;
        $detail = sprintf('newest %s, %.1f h old, %s KB', $newest[0], $hours, number_format($newest[2] / 1024));
        if ($newest[2] < 1024) {
            return [self::FAIL, $detail.' (suspiciously small)', ['dir' => $dir]];
        }

        return [match (true) { $hours <= 26 => self::PASS, $hours <= 48 => self::WARN, default => self::FAIL }, $detail, ['dir' => $dir]];
    }

    private function checkPassportKeys(): array
    {
        $private = filled(config('passport.private_key')) || is_readable(Passport::keyPath('oauth-private.key'));
        $public = filled(config('passport.public_key')) || is_readable(Passport::keyPath('oauth-public.key'));

        return $private && $public ? [self::PASS, 'OAuth private + public keys present'] : [self::FAIL, 'OAuth keys missing: '.implode(', ', array_keys(array_filter(['private' => ! $private, 'public' => ! $public])))];
    }

    private function checkSigningKey(): array
    {
        $s = (array) config('document_security.signing', []);
        $key = filled($s['private_key'] ?? null) || (filled($s['private_key_path'] ?? null) && is_readable((string) $s['private_key_path']));
        if (! $key || blank($s['key_id'] ?? null)) {
            return [self::FAIL, 'document signing key '.($key ? 'present' : 'MISSING').', key id '.(blank($s['key_id'] ?? null) ? 'MISSING' : 'set')];
        }
        $env = strtolower((string) ($s['key_environment'] ?? ''));
        $notes = [];
        if ($env !== '' && $env !== 'production') {
            $notes[] = "key environment {$env}";
        }
        if (blank($s['tsa_url'] ?? null)) {
            $notes[] = 'no RFC 3161 TSA (DOCUMENT_TSA_URL)';
        }

        return [$notes === [] ? self::PASS : self::WARN, 'signing key present (kid '.$s['key_id'].')'.($notes ? '; '.implode('; ', $notes) : '')];
    }

    private function checkVerifyUrl(): array
    {
        $url = (string) config('document_security.verification.url');
        $host = (string) parse_url($url, PHP_URL_HOST);
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' || $host === '') {
            return [self::FAIL, "verify URL {$url}"];
        }

        return $host === parse_url((string) config('app.url'), PHP_URL_HOST) ? [self::PASS, "verify URL {$url}"] : [self::WARN, "verify URL {$url} is not on APP_URL's host"];
    }

    private function checkActiva(): array
    {
        if (! Schema::hasTable('carrier_api_connections')) {
            return [self::WARN, 'carrier_api_connections table missing (migrate)'];
        }
        $rows = DB::table('carrier_api_connections')->where('provider', (string) config('activa.provider', 'ACTIVA_CM'))->get(['environment', 'status']);
        if ($rows->isEmpty()) {
            return [self::WARN, 'no Activa connection: connector idle (CONFIG_REQUIRED), nothing is sent to Activa'];
        }
        $summary = $rows->map(fn ($r) => "{$r->environment}:{$r->status}")->implode(', ');
        $live = $rows->contains(fn ($r) => $r->environment === 'PRODUCTION' && $r->status === 'ACTIVE');

        return [$live ? self::PASS : self::WARN, "Activa connections {$summary}"];
    }

    private function checkTemplates(): array
    {
        $n = DB::table('document_templates')->where('status', 'PUBLISHED')->count();
        $detail = "{$n} PUBLISHED document templates (expected ".self::EXPECTED_TEMPLATES.')';

        return [match (true) { $n === self::EXPECTED_TEMPLATES => self::PASS, $n > self::EXPECTED_TEMPLATES => self::WARN, default => self::FAIL }, $detail];
    }

    private function checkRbac(): array
    {
        $roles = 0;
        $missing = 0;
        Role::query()->whereIn('code', RoleCatalogue::codes())->orderBy('id')->each(function (Role $role) use (&$roles, &$missing): void {
            $perms = array_values((array) $role->permissions);
            if (in_array('*', $perms, true)) {
                return;
            }
            $gap = count(array_diff(RoleCatalogue::defaultPermissions($role->code), $perms));
            $roles += $gap > 0 ? 1 : 0;
            $missing += $gap;
        });
        $retired = 0;
        foreach (array_keys(RoleCatalogue::RENAMED_PERMISSIONS) as $old) {
            $retired += Role::query()->whereJsonContains('permissions', $old)->count();
        }

        return $missing === 0 && $retired === 0 ? [self::PASS, 'every catalogue role holds its default permissions']
            : [self::FAIL, "{$roles} roles missing {$missing} default permissions; {$retired} roles hold retired codes"];
    }

    private function checkFailedJobs(): array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return [self::WARN, 'failed_jobs table missing'];
        }
        $all = DB::table('failed_jobs')->count();
        $day = DB::table('failed_jobs')->where('failed_at', '>=', now()->subDay())->count();

        return $all === 0 ? [self::PASS, '0 failed jobs'] : [self::WARN, "{$all} failed jobs ({$day} in the last 24 h)"];
    }

    private function checkLogErrors(array $o): array
    {
        $files = isset($o['log_path']) ? [$o['log_path']] : array_merge(glob(storage_path('logs/laravel.log')) ?: [], glob(storage_path('logs/laravel-'.now()->format('Y-m-d').'.log')) ?: []);
        $since = now()->subHour();
        $tz = (string) config('app.timezone');
        $count = 0;
        $messages = [];
        foreach ($files as $file) {
            if (! is_readable($file) || ! ($h = fopen($file, 'rb'))) {
                continue;
            }
            $size = (int) filesize($file);
            fseek($h, max(0, $size - 8 * 1024 * 1024));
            while (($line = fgets($h)) !== false) {
                if (! preg_match('/^\[(\d{4}-\d\d-\d\d[ T]\d\d:\d\d:\d\d)[^\]]*\] \w+\.(ERROR|CRITICAL|ALERT|EMERGENCY): (.{0,80})/', $line, $m)) {
                    continue;
                }
                if (CarbonImmutable::parse($m[1], $tz)->lt($since)) {
                    continue;
                }
                $count++;
                $msg = trim(Str::before($m[3], ' {'));
                $messages[$msg] = ($messages[$msg] ?? 0) + 1;
            }
            fclose($h);
        }
        if ($count === 0) {
            return [self::PASS, 'no ERROR/CRITICAL log lines in the last hour'];
        }
        arsort($messages);
        $top = collect(array_slice($messages, 0, 3, true))->map(fn ($n, $m) => "{$n}× {$m}")->implode('; ');

        return [$count >= 50 ? self::FAIL : self::WARN, "{$count} ERROR/CRITICAL lines in the last hour: {$top}"];
    }
}
