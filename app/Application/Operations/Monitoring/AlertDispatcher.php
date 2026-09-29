<?php

declare(strict_types=1);

namespace App\Application\Operations\Monitoring;

use App\Application\Notifications\Otp\EtechSmsDriver;
use App\Application\Notifications\Otp\TwilioSmsDriver;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * S12: runs AlertChecks (ops:alerts, every 5 minutes) and pages the holders of operations.alerts.receive.
 *  - A check that starts firing is sent at once (e-mail + SMS when an SMS provider is configured).
 *  - While it keeps firing it is re-sent at most once per cooldown (monitoring.alerts.cooldown_minutes).
 *  - When it clears, one recovery e-mail (no SMS).
 * State lives in the cache (not the database), so a database outage can still be alerted on and deduplicated.
 * Recipients: ACTIVE users with an ACTIVE membership in a PLATFORM tenant whose role holds the EXPLICIT string
 * operations.alerts.receive (a wildcard '*' role is not paged), plus MONITORING_ALERT_EMAILS / _PHONES.
 */
final class AlertDispatcher
{
    public function __construct(private readonly AlertChecks $checks) {}

    /** @return list<array{key:string, kind:string, emails:int, sms:int}> what was sent */
    public function evaluate(): array
    {
        $sent = [];
        $cooldown = max(1, (int) config('monitoring.alerts.cooldown_minutes', 60)) * 60;
        foreach ($this->checks->run() as $key => $result) {
            if ($result === null) {
                continue;
            }
            $state = (array) Cache::get(self::stateKey($key), []);
            $wasFiring = (bool) ($state['firing'] ?? false);
            $now = now()->getTimestamp();
            if ($result['firing']) {
                $due = ! $wasFiring || ($now - (int) ($state['notified_at'] ?? 0)) >= $cooldown;
                $state = ['firing' => true, 'since' => $wasFiring ? ($state['since'] ?? $now) : $now, 'notified_at' => $state['notified_at'] ?? null,
                    'severity' => $result['severity'], 'params' => $result['params']];
                if ($due) {
                    $sent[] = $this->notify($key, 'firing', $result);
                    $state['notified_at'] = $now;
                }
            } elseif ($wasFiring) {
                $sent[] = $this->notify($key, 'resolved', $result);
                $state = ['firing' => false, 'resolved_at' => $now];
            } else {
                continue;
            }
            Cache::put(self::stateKey($key), $state, 7 * 86400);
        }

        return $sent;
    }

    /** @return array<string, array<string, mixed>> current firing alerts (for /status) */
    public static function firing(): array
    {
        $out = [];
        foreach (AlertChecks::KEYS as $key) {
            $s = (array) rescue(fn () => Cache::get(self::stateKey($key), []), [], false);
            if ($s['firing'] ?? false) {
                $out[$key] = ['severity' => $s['severity'] ?? null, 'since' => isset($s['since']) ? date(DATE_ATOM, (int) $s['since']) : null];
            }
        }

        return $out;
    }

    public static function stateKey(string $key): string
    {
        return 'monitoring:alert:'.$key;
    }

    /** @return list<array{email:?string, phone:?string, locale:string}> */
    public function recipients(): array
    {
        $out = [];
        try {
            $rows = DB::table('tenant_memberships as m')
                ->join('tenants as t', 't.id', '=', 'm.tenant_id')
                ->join('membership_roles as mr', 'mr.membership_id', '=', 'm.id')
                ->join('roles as r', 'r.id', '=', 'mr.role_id')
                ->join('users as u', 'u.id', '=', 'm.user_id')
                ->where('t.type', 'PLATFORM')->where('m.status', 'ACTIVE')->where('u.status', 'ACTIVE')
                ->whereJsonContains('r.permissions', (string) config('monitoring.alerts.permission', 'operations.alerts.receive'))
                ->select('u.id', 'u.email', 'u.phone_e164', 'u.locale')->distinct()->get();
            foreach ($rows as $u) {
                $out[(string) $u->id] = ['email' => $u->email ?: null, 'phone' => $u->phone_e164 ?: null, 'locale' => in_array($u->locale, ['en', 'fr'], true) ? $u->locale : 'fr'];
            }
        } catch (Throwable) {
            // Database unreachable: the configured fallback list still gets the alert.
        }
        $known = array_filter(array_column($out, 'email'));
        foreach ((array) config('monitoring.alerts.fallback_emails', []) as $email) {
            if (! in_array($email, $known, true)) {
                $out['email:'.$email] = ['email' => $email, 'phone' => null, 'locale' => 'fr'];
            }
        }
        $phones = array_filter(array_column($out, 'phone'));
        foreach ((array) config('monitoring.alerts.fallback_phones', []) as $phone) {
            if (! in_array($phone, $phones, true)) {
                $out['phone:'.$phone] = ['email' => null, 'phone' => $phone, 'locale' => 'fr'];
            }
        }

        return array_values($out);
    }

    /** @param array{firing:bool, severity:string, params:array<string,scalar>} $result */
    private function notify(string $key, string $kind, array $result): array
    {
        $emails = 0;
        $sms = 0;
        $release = ReleaseId::current();
        $host = parse_url((string) config('app.url'), PHP_URL_HOST) ?: (string) config('app.url');
        $smsDriver = $kind === 'firing' && (bool) config('monitoring.alerts.sms', true) ? $this->smsDriver() : null;

        foreach ($this->recipients() as $r) {
            $loc = $r['locale'];
            $title = __('monitoring.alerts.'.$key.'.title', [], $loc);
            $subject = __('monitoring.alert_mail.subject_'.$kind, ['title' => $title, 'severity' => strtoupper($result['severity']), 'host' => $host], $loc);
            $body = __('monitoring.alerts.'.$key.'.body', $result['params'], $loc);
            if ($r['email']) {
                try {
                    Mail::raw(implode("\n\n", [
                        $subject, $body,
                        __('monitoring.alert_mail.footer', ['release' => $release, 'host' => $host, 'time' => now()->toIso8601String()], $loc),
                    ]), fn ($m) => $m->to($r['email'])->subject($subject));
                    $emails++;
                } catch (Throwable $e) {
                    Log::warning('monitoring.alert.email_failed', ['alert' => $key, 'error' => $e->getMessage()]);
                }
            }
            if ($smsDriver !== null && $r['phone']) {
                try {
                    $smsDriver->send($r['phone'], '', mb_substr($subject.' — '.$body, 0, 300));
                    $sms++;
                } catch (Throwable $e) {
                    Log::warning('monitoring.alert.sms_failed', ['alert' => $key, 'error' => $e->getMessage()]);
                }
            }
        }
        Log::warning('monitoring.alert.'.$kind, ['alert' => $key, 'severity' => $result['severity'], 'params' => $result['params'], 'emails' => $emails, 'sms' => $sms]);

        return ['key' => $key, 'kind' => $kind, 'emails' => $emails, 'sms' => $sms];
    }

    private function smsDriver(): ?object
    {
        foreach ([EtechSmsDriver::class, TwilioSmsDriver::class] as $class) {
            $driver = rescue(fn () => app($class), null, false);
            if ($driver !== null && rescue(fn () => $driver->isConfigured(), false, false)) {
                return $driver;
            }
        }

        return null;
    }
}
