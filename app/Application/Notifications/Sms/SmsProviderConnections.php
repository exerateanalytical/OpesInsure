<?php

declare(strict_types=1);

namespace App\Application\Notifications\Sms;

use App\Application\Audit\AuditWriter;
use App\Application\Identity\Rbac\PlatformAuthority;
use App\Application\Notifications\Sms\Providers\OrangeSmsProvider;
use App\Models\SmsMessage;
use App\Models\SmsProviderConnection;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Admin side of the SMS providers (platform admins only). Secrets are write-only: a blank secret keeps the stored one,
 * and present() never returns them — only whether each one is set. One PRIMARY and one FALLBACK at a time;
 * promoting a connection moves the previous holder of that role to STANDBY.
 */
final class SmsProviderConnections
{
    /** Secret keys accepted per provider (anything else is dropped). */
    public const SECRETS = [
        'orange' => ['client_id', 'client_secret'],
        'twilio' => ['account_sid', 'auth_token'],
        'africastalking' => ['api_key'],
        'generic_http' => ['username', 'password', 'api_key', 'token'],
    ];

    /** Non-secret settings accepted per provider. */
    public const SETTINGS = [
        'orange' => ['sender_address', 'sender_name', 'base_url'],
        'twilio' => ['from'],
        'africastalking' => ['username', 'sender_id', 'sandbox'],
        'generic_http' => ['url', 'method', 'format', 'params', 'headers', 'auth', 'success_contains', 'reference_path', 'sender'],
    ];

    public function __construct(private SmsGateway $gateway, private AuditWriter $audit)
    {
    }

    public function assertPlatformAdmin(?User $user): void
    {
        if ($user === null || ! app(PlatformAuthority::class)->isPlatformAdmin($user)) {
            abort(403, __('sms_providers.platform_only'));
        }
    }

    /** @param array{id?: string|null, provider: string, label: string, role?: string, status?: string, settings?: array, secrets?: array} $data */
    public function save(array $data, ?User $actor): SmsProviderConnection
    {
        $this->assertPlatformAdmin($actor);

        $provider = (string) ($data['provider'] ?? '');
        if (! in_array($provider, SmsGateway::PROVIDERS, true)) {
            throw ValidationException::withMessages(['provider' => __('sms_providers.errors.provider')]);
        }
        $role = in_array($data['role'] ?? 'STANDBY', SmsProviderConnection::ROLES, true) ? $data['role'] ?? 'STANDBY' : 'STANDBY';

        return DB::transaction(function () use ($data, $provider, $role, $actor) {
            $c = ! empty($data['id']) ? SmsProviderConnection::findOrFail($data['id']) : new SmsProviderConnection(['created_by' => $actor->id]);

            $settings = array_intersect_key((array) ($data['settings'] ?? []), array_flip(self::SETTINGS[$provider]));
            if ($provider === 'generic_http') {
                $url = (string) ($settings['url'] ?? '');
                if (! preg_match('#^https://#i', $url) && ! app()->environment('local', 'testing')) {
                    throw ValidationException::withMessages(['settings.url' => __('sms_providers.errors.https')]);
                }
            }

            $secrets = $c->provider === $provider ? ($c->secrets ?? []) : [];
            foreach (self::SECRETS[$provider] as $k) {
                $v = trim((string) (($data['secrets'] ?? [])[$k] ?? ''));
                if ($v !== '') {
                    $secrets[$k] = $v;
                }
            }

            $c->fill(['provider' => $provider, 'label' => mb_substr(trim((string) ($data['label'] ?? '')) ?: $provider, 0, 120),
                'status' => ($data['status'] ?? 'ACTIVE') === 'DISABLED' ? 'DISABLED' : 'ACTIVE',
                'settings' => array_filter($settings, fn ($v) => $v !== null && $v !== '' && $v !== []), 'secrets' => $secrets, 'updated_by' => $actor->id]);
            $c->save();
            $this->assignRole($c, $role);
            Cache::forget(OrangeSmsProvider::tokenCacheKey($c));

            $this->audit->record('sms_provider.saved', 'sms_provider_connection', $c->id, ['provider' => $provider, 'role' => $role,
                'secrets_set' => array_keys($secrets)]);

            return $c;
        });
    }

    public function setRole(string $id, string $role, ?User $actor): void
    {
        $this->assertPlatformAdmin($actor);
        $c = SmsProviderConnection::findOrFail($id);
        DB::transaction(fn () => $this->assignRole($c, $role));
        $this->audit->record('sms_provider.role_changed', 'sms_provider_connection', $c->id, ['role' => $role]);
    }

    public function setStatus(string $id, bool $active, ?User $actor): void
    {
        $this->assertPlatformAdmin($actor);
        $c = SmsProviderConnection::findOrFail($id);
        $c->update(['status' => $active ? 'ACTIVE' : 'DISABLED', 'updated_by' => $actor->id]);
        $this->audit->record($active ? 'sms_provider.enabled' : 'sms_provider.disabled', 'sms_provider_connection', $c->id, []);
    }

    /** Sends a test SMS through one provider (or the whole chain when $id is null) to a number typed by the admin. */
    public function test(?string $id, string $phone, ?User $actor): array
    {
        $this->assertPlatformAdmin($actor);
        $only = $id !== null ? SmsProviderConnection::findOrFail($id) : null;
        $this->audit->record('sms_provider.test', 'sms_provider_connection', $only?->id, ['destination' => SmsGateway::mask($phone)]);

        return $this->gateway->send($phone, (string) __('sms_providers.test_message'), 'TEST', $only);
    }

    private function assignRole(SmsProviderConnection $c, string $role): void
    {
        if (! in_array($role, SmsProviderConnection::ROLES, true)) {
            throw ValidationException::withMessages(['role' => __('sms_providers.errors.role')]);
        }
        if ($role !== 'STANDBY') {
            SmsProviderConnection::where('role', $role)->where('id', '!=', $c->id)->update(['role' => 'STANDBY']);
        }
        $c->forceFill(['role' => $role])->save();
    }

    /** @return list<array<string, mixed>> safe view: never contains a secret value */
    public function present(): array
    {
        $since = now()->subDay();
        $stats = SmsMessage::query()->where('created_at', '>=', $since)->whereNotNull('connection_id')
            ->selectRaw("connection_id, count(*) as total, sum(case when status = 'SENT' then 1 else 0 end) as sent")
            ->groupBy('connection_id')->get()->keyBy('connection_id');

        return SmsProviderConnection::query()->orderByRaw("CASE role WHEN 'PRIMARY' THEN 0 WHEN 'FALLBACK' THEN 1 ELSE 2 END")->orderBy('label')->get()
            ->map(function (SmsProviderConnection $c) use ($stats) {
                $s = $stats->get($c->id);

                return ['__key' => $c->id, 'id' => $c->id, 'provider' => $c->provider, 'label' => $c->label, 'role' => $c->role, 'status' => $c->status,
                    'complete' => $this->gateway->isComplete($c), 'health_status' => $c->health_status, 'last_success_at' => $c->last_success_at,
                    'last_failure_at' => $c->last_failure_at, 'last_error' => $c->last_error, 'consecutive_failures' => $c->consecutive_failures,
                    'sent_24h' => (int) ($s->sent ?? 0), 'total_24h' => (int) ($s->total ?? 0),
                    'settings' => $c->settings ?? [], 'secrets_set' => array_keys(array_filter($c->secrets ?? [], fn ($v) => (string) $v !== ''))];
            })->all();
    }
}
