<?php

declare(strict_types=1);

namespace App\Application\Integrations\Activa;

use App\Application\Audit\AuditWriter;
use App\Models\Carrier;
use App\Models\CarrierApiConnection;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Activa connection settings: the encrypted credential store and "Test connection".
 *
 * Secrets are write-only: save() keeps the stored value when a secret field is left blank, present() only says whether
 * each one is set. Status: CONFIG_REQUIRED until the subscription key and at least one service login are present,
 * then PENDING_VERIFICATION → ACTIVE / AUTH_FAILED from the real auth answers (ActivaGateway::markService).
 */
final class ActivaConnections
{
    public const ENVIRONMENTS = ['SANDBOX', 'PRODUCTION'];

    public function __construct(private readonly ActivaGateway $gateway, private readonly AuditWriter $audit) {}

    public function provider(): string
    {
        return (string) config('activa.provider', 'ACTIVA_CM');
    }

    /** The Activa Cameroun carrier (carriers.insurer_code = config activa.insurer_code). */
    public function defaultCarrier(): ?Carrier
    {
        return Carrier::query()->where('insurer_code', (string) config('activa.insurer_code', 'ACTIVA'))->orderByDesc('status')->first();
    }

    /** The connection used for live traffic of a carrier: the non-disabled one (PRODUCTION preferred). */
    public function forCarrier(?string $carrierId): ?CarrierApiConnection
    {
        if ($carrierId === null || $carrierId === '') {
            return null;
        }

        return CarrierApiConnection::query()->where('carrier_id', $carrierId)->where('provider', $this->provider())->where('status', '<>', 'DISABLED')
            ->orderByRaw("CASE environment WHEN 'PRODUCTION' THEN 0 ELSE 1 END")->first();
    }

    /** @return list<CarrierApiConnection> */
    public function all(): array
    {
        return CarrierApiConnection::query()->where('provider', $this->provider())->orderBy('environment')->get()->all();
    }

    /**
     * @param  array{carrier_id: string, environment: string, tenant_id?: ?string, subscription_key?: ?string, credentials?: array<string, array<string, ?string>>, settings?: array, disabled?: bool}  $data
     */
    public function save(array $data, User $actor): CarrierApiConnection
    {
        $env = strtoupper((string) ($data['environment'] ?? 'SANDBOX'));
        if (! in_array($env, self::ENVIRONMENTS, true)) {
            throw ValidationException::withMessages(['environment' => 'Environment must be SANDBOX or PRODUCTION.']);
        }
        if (! Carrier::whereKey($data['carrier_id'] ?? '')->exists()) {
            throw ValidationException::withMessages(['carrier_id' => 'Unknown carrier.']);
        }
        if (isset($data['settings']['gateway_url']) && $data['settings']['gateway_url'] !== '' && ! str_starts_with((string) $data['settings']['gateway_url'], 'https://')) {
            throw ValidationException::withMessages(['gateway_url' => 'The gateway URL must use https.']);
        }

        return DB::transaction(function () use ($data, $env, $actor) {
            $c = CarrierApiConnection::query()->where(['carrier_id' => $data['carrier_id'], 'provider' => $this->provider(), 'environment' => $env])->lockForUpdate()->first()
                ?? new CarrierApiConnection(['carrier_id' => $data['carrier_id'], 'provider' => $this->provider(), 'environment' => $env, 'status' => 'CONFIG_REQUIRED', 'settings' => [], 'service_health' => []]);

            $changed = [];
            if (array_key_exists('tenant_id', $data)) {
                $c->tenant_id = $data['tenant_id'] ?: null;
            }
            if (trim((string) ($data['subscription_key'] ?? '')) !== '') {
                $c->subscription_key = trim((string) $data['subscription_key']);
                $changed[] = 'subscription_key';
            }
            $creds = $c->service_credentials ?? [];
            foreach (ActivaServices::ALL as $service) {
                foreach ([...ActivaServices::CREDENTIAL_FIELDS[$service], 'subscription_key'] as $field) {
                    $value = $data['credentials'][$service][$field] ?? null;
                    if ($value !== null && trim((string) $value) !== '') {
                        $creds[$service][$field] = trim((string) $value);
                        $changed[] = "{$service}.{$field}";
                    }
                }
                foreach ((array) ($data['clear'][$service] ?? []) as $field) {
                    unset($creds[$service][$field]);
                    $changed[] = "{$service}.{$field}:cleared";
                }
            }
            $c->service_credentials = $creds;

            $settings = $c->settings ?? [];
            foreach ((array) ($data['settings'] ?? []) as $k => $v) {
                if ($v === null || $v === '' || $v === []) {
                    unset($settings[$k]);
                } else {
                    $settings[$k] = $v;
                }
            }
            $c->settings = $settings;
            if (array_key_exists('disabled', $data)) {
                $c->status = $data['disabled'] ? 'DISABLED' : ($c->status === 'DISABLED' ? 'CONFIG_REQUIRED' : $c->status);
            }
            if ($changed !== []) {
                // New secrets: previous per-service verdicts no longer apply.
                $c->service_health = [];
                $c->last_error_code = null;
            }
            $c->updated_by = $actor->id;
            $c->status = ActivaGateway::deriveStatus($c, $this->gateway);
            $c->save();

            // Field NAMES only, never values.
            $this->audit->record('integration.carrier_api.configured', 'carrier_api_connection', $c->id,
                ['carrier_id' => $c->carrier_id, 'provider' => $c->provider, 'environment' => $c->environment, 'status' => $c->status, 'fields_set' => array_values(array_unique($changed))]);

            return $c->refresh();
        });
    }

    /**
     * Calls every configured service's auth operation. @return array<string, array{state: string, http_status: ?int, message: string}>
     */
    public function test(CarrierApiConnection $c, ?User $actor = null): array
    {
        $out = [];
        foreach (ActivaServices::ALL as $service) {
            if (! $this->gateway->configured($c, $service)) {
                $out[$service] = ['state' => ActivaException::CONFIG_REQUIRED, 'http_status' => null, 'message' => ActivaException::make(ActivaException::CONFIG_REQUIRED, $service)->getMessage()];

                continue;
            }
            $this->gateway->forgetToken($c, $service);
            try {
                [$token, $ttl] = $this->gateway->authenticate($c, $service);
                $out[$service] = ['state' => 'OK', 'http_status' => 200, 'message' => "Authenticated; token valid ~{$ttl}s."];
            } catch (ActivaException $e) {
                $out[$service] = ['state' => $e->errorCode, 'http_status' => $e->httpStatus, 'message' => $e->getMessage()];
            }
        }
        $c->refresh();
        $c->forceFill(['last_tested_at' => now()])->save();
        $this->audit->record('integration.carrier_api.tested', 'carrier_api_connection', $c->id,
            ['status' => $c->status, 'services' => array_map(fn ($r) => ['state' => $r['state'], 'http_status' => $r['http_status']], $out)]);

        return $out;
    }

    /** Admin view of a connection: flags for secrets, never values. */
    public function present(CarrierApiConnection $c): array
    {
        $creds = $c->service_credentials ?? [];
        $services = [];
        foreach (ActivaServices::ALL as $service) {
            $fields = [];
            foreach ([...ActivaServices::CREDENTIAL_FIELDS[$service], 'subscription_key'] as $f) {
                $fields[$f] = in_array($f, [...ActivaServices::SECRET_FIELDS], true) ? (($creds[$service][$f] ?? '') !== '') : ($creds[$service][$f] ?? null);
            }
            $services[$service] = ['configured' => $this->gateway->configured($c, $service), 'fields' => $fields, 'health' => $c->service_health[$service] ?? null,
                'base_url' => $this->gateway->baseUrl($c, $service)];
        }

        return ['id' => $c->id, 'carrier_id' => $c->carrier_id, 'environment' => $c->environment, 'status' => $c->status, 'has_subscription_key' => (string) $c->subscription_key !== '',
            'settings' => $c->settings ?? [], 'services' => $services, 'last_tested_at' => $c->last_tested_at, 'last_success_at' => $c->last_success_at,
            'last_reference_sync_at' => $c->last_reference_sync_at, 'last_reconciled_at' => $c->last_reconciled_at, 'last_error_code' => $c->last_error_code];
    }
}
