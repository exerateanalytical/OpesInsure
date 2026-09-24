<?php

declare(strict_types=1);

namespace App\Application\Settings;

use App\Models\PlatformSetting;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Admin-editable runtime settings (Filament "Platform settings" page).
 *
 * One row in platform_settings, cached forever and flushed whenever the row
 * is saved. Every accessor falls back to the matching .env-backed config
 * value, so a blank admin field never wipes out a working .env setting —
 * the admin value only overrides when it is actually filled in.
 */
final class PlatformSettings
{
    private const CACHE_KEY = 'platform_settings.row';

    private const VERSION_KEY = 'platform_settings.version';

    private ?PlatformSetting $row = null;

    private ?int $loadedVersion = null;

    /**
     * The cached row. Uses the shared Laravel cache with a version key, so a
     * save in the admin panel is seen by every web process and long-running
     * queue worker on its next read (they compare the version each time).
     */
    public function row(): ?PlatformSetting
    {
        try {
            $version = (int) Cache::get(self::VERSION_KEY, 0);

            if ($this->loadedVersion === $version) {
                return $this->row;
            }

            $attributes = Cache::rememberForever(self::CACHE_KEY.':'.$version, fn () => PlatformSetting::query()->orderBy('id')->first()?->getAttributes() ?? []);
            $this->row = $attributes === [] ? null : (new PlatformSetting)->newFromBuilder($attributes);
            $this->loadedVersion = $version;
        } catch (Throwable) {
            // Table not migrated yet (fresh install / boot before
            // migrations): behave as if nothing is set, don't cache.
            return null;
        }

        return $this->row;
    }

    /** The row for editing; created on first save. */
    public function editable(): PlatformSetting
    {
        return PlatformSetting::query()->orderBy('id')->first() ?? new PlatformSetting;
    }

    public function flush(): void
    {
        $next = (int) Cache::get(self::VERSION_KEY, 0) + 1;
        Cache::forever(self::VERSION_KEY, $next);
        Cache::forget(self::CACHE_KEY.':'.($next - 1));
        $this->row = null;
        $this->loadedVersion = null;
        $this->applyMailConfig();
    }

    /** Admin value when filled in, otherwise $fallback. */
    public function get(string $key, mixed $fallback = null): mixed
    {
        $value = $this->row()?->getAttribute($key);

        return ($value === null || $value === '') ? $fallback : $value;
    }

    /** @return array{email: ?string, phone: ?string, whatsapp: ?string, whatsapp_url: ?string, partner_email: ?string} */
    public function supportContacts(): array
    {
        $whatsapp = $this->get('whatsapp_number', config('services.support.whatsapp'));
        $digits = $whatsapp ? preg_replace('/\D+/', '', (string) $whatsapp) : '';

        return [
            'email' => $this->get('support_email', config('services.support.email')),
            'phone' => $this->get('support_phone', config('services.support.phone')),
            'whatsapp' => $whatsapp,
            'whatsapp_url' => $digits !== '' ? 'https://wa.me/'.$digits : null,
            'partner_email' => $this->get('partner_email', config('services.support.partner_email')),
        ];
    }

    /** @return array{account_sid: string, auth_token: string, sms_from: string, whatsapp_from: string, enabled: bool} */
    public function twilio(): array
    {
        return [
            'account_sid' => (string) $this->get('twilio_account_sid', config('services.twilio.account_sid')),
            'auth_token' => (string) $this->get('twilio_auth_token', config('services.twilio.auth_token')),
            'sms_from' => (string) $this->get('twilio_sms_from', config('services.twilio.sms_from')),
            'whatsapp_from' => (string) $this->get('twilio_whatsapp_from', config('services.twilio.whatsapp_from')),
            'enabled' => (bool) $this->get('twilio_enabled', true),
        ];
    }

    /** @return array<string, mixed> */
    public function etech(): array
    {
        return [
            'sms_login' => (string) $this->get('etech_sms_login', config('services.etech.sms_login')),
            'sms_password' => (string) $this->get('etech_sms_password', config('services.etech.sms_password')),
            'sms_sender' => (string) $this->get('etech_sms_sender', config('services.etech.sms_sender')),
            'rest_token' => (string) $this->get('etech_rest_token', config('services.etech.rest_token')),
            'whatsapp_template_name' => (string) $this->get('etech_whatsapp_template_name', config('services.etech.whatsapp_template_name')),
            'whatsapp_template_language' => (string) $this->get('etech_whatsapp_template_language', config('services.etech.whatsapp_template_language', 'fr')),
            'sms_enabled' => (bool) $this->get('etech_sms_enabled', true),
            'whatsapp_enabled' => (bool) $this->get('etech_whatsapp_enabled', true),
        ];
    }

    /** @return list<string> e.g. ['whatsapp', 'sms'] */
    public function otpChannelPriority(): array
    {
        return $this->csv((string) $this->get('otp_channel_priority', config('services.otp.channel_priority', 'whatsapp,sms')), ['whatsapp', 'sms']);
    }

    /** @return list<string> e.g. ['etech', 'twilio'] */
    public function otpProviderPriority(): array
    {
        return $this->csv((string) $this->get('otp_provider_priority', config('services.otp.provider_priority', 'etech,twilio')), ['etech', 'twilio']);
    }

    public function requireContactVerification(): bool
    {
        return (bool) $this->get('require_contact_verification', false);
    }

    public function mailEnabled(): bool
    {
        return (bool) $this->get('mail_enabled', false) && filled($this->get('mail_host'));
    }

    /** Points the smtp mailer at the admin SMTP settings when they are enabled. */
    public function applyMailConfig(bool $purge = true): void
    {
        if (! $this->mailEnabled()) {
            return;
        }

        config([
            'mail.default' => 'smtp',
            'mail.mailers.smtp.host' => $this->get('mail_host'),
            'mail.mailers.smtp.port' => (int) $this->get('mail_port', 587),
            'mail.mailers.smtp.username' => $this->get('mail_username'),
            'mail.mailers.smtp.password' => $this->get('mail_password'),
            'mail.mailers.smtp.scheme' => $this->get('mail_encryption') === 'ssl' ? 'smtps' : null,
            'mail.from.address' => $this->get('mail_from_address', config('mail.from.address')),
            'mail.from.name' => $this->get('mail_from_name', config('mail.from.name')),
        ]);

        if ($purge && app()->resolved('mail.manager')) {
            app('mail.manager')->purge('smtp');
        }
    }

    /** @return list<string> */
    private function csv(string $value, array $allowed): array
    {
        $items = array_values(array_unique(array_filter(array_map(fn ($v) => strtolower(trim($v)), explode(',', $value)), fn ($v) => in_array($v, $allowed, true))));

        return $items === [] ? $allowed : $items;
    }
}
