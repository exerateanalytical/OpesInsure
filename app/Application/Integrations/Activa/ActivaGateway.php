<?php

declare(strict_types=1);

namespace App\Application\Integrations\Activa;

use App\Application\Audit\AuditWriter;
use App\Models\CarrierApiConnection;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

/**
 * HTTP client of the Activa APIs behind Azure API Management.
 *
 *  - base URL: gateway host + the service's API path for the connection's environment (SANDBOX / PRODUCTION);
 *  - every call: Ocp-Apim-Subscription-Key + the service's bearer token + x-correlation-id;
 *  - tokens: one per connection + service, cached encrypted until shortly before expiry (refresh skew), dropped and
 *    re-acquired once when a data call answers 401;
 *  - retries with exponential backoff for connection errors / timeouts / 5xx only (never 4xx);
 *  - circuit breaker per connection + service (ActivaCircuitBreaker);
 *  - every call (auth included) is recorded in carrier_api_calls and the audit log with service, operation, status,
 *    attempts, duration and correlation id — never a header, a body, a secret or personal data.
 */
final class ActivaGateway
{
    public function __construct(private readonly ActivaCircuitBreaker $breaker, private readonly AuditWriter $audit) {}

    /**
     * @param  array{json?: mixed, form?: array, query?: array, headers?: array<string,string>, subject_type?: ?string, subject_id?: ?string, accept?: string}  $options
     */
    public function send(CarrierApiConnection $c, string $service, string $operation, string $method, string $path, array $options = []): Response
    {
        $this->assertConfigured($c, $service, $operation);
        if ($this->breaker->isOpen($c->id, $service)) {
            $this->log($c, $service, $operation, $method, $path, null, 'CIRCUIT_OPEN', 0, 0, (string) Str::uuid(), $options, ActivaException::CIRCUIT_OPEN);
            throw ActivaException::make(ActivaException::CIRCUIT_OPEN, $service, $operation);
        }

        $token = $this->token($c, $service);
        $correlation = (string) Str::uuid();
        $started = hrtime(true);
        [$response, $attempts, $transport] = $this->attempt($c, $service, $method, $path, $options, $token, $correlation);

        if ($response !== null && $response->status() === 401 && ! $this->isSubscriptionRejection($response)) {
            // Expired / revoked token: re-authenticate once and replay.
            $this->forgetToken($c, $service);
            $token = $this->token($c, $service);
            [$response, $more, $transport] = $this->attempt($c, $service, $method, $path, $options, $token, $correlation);
            $attempts += $more;
        }
        $ms = (int) ((hrtime(true) - $started) / 1_000_000);

        return $this->conclude($c, $service, $operation, $method, $path, $options, $response, $transport, $attempts, $ms, $correlation);
    }

    /** Service is usable: subscription key + the service's required login fields present. */
    public function configured(CarrierApiConnection $c, string $service): bool
    {
        if ($c->status === 'DISABLED' || $this->subscriptionKey($c, $service) === '') {
            return false;
        }
        $creds = (array) (($c->service_credentials ?? [])[$service] ?? []);
        foreach (ActivaServices::REQUIRED_FIELDS[$service] ?? [] as $field) {
            if (trim((string) ($creds[$field] ?? '')) === '') {
                return false;
            }
        }

        return true;
    }

    /** Bearer token of a service (cached; acquired on miss). */
    public function token(CarrierApiConnection $c, string $service): string
    {
        $key = $this->tokenKey($c, $service);
        $cached = Cache::get($key);
        if (is_string($cached) && $cached !== '') {
            try {
                return Crypt::decryptString($cached);
            } catch (\Throwable) {
                Cache::forget($key);
            }
        }
        [$token, $ttl] = $this->authenticate($c, $service);
        Cache::put($key, Crypt::encryptString($token), max(30, $ttl));

        return $token;
    }

    public function forgetToken(CarrierApiConnection $c, string $service): void
    {
        Cache::forget($this->tokenKey($c, $service));
    }

    /**
     * Calls the service's auth operation. @return array{0: string, 1: int} token and cache TTL in seconds.
     *
     * @throws ActivaException
     */
    public function authenticate(CarrierApiConnection $c, string $service): array
    {
        $this->assertConfigured($c, $service, 'auth');
        $creds = (array) (($c->service_credentials ?? [])[$service] ?? []);
        $auth = ActivaServices::auth($service, $creds, $this->apiVersion($c));
        $options = isset($auth['form']) ? ['form' => $auth['form']] : ['json' => $auth['json']];
        $correlation = (string) Str::uuid();
        $started = hrtime(true);
        [$response, $attempts, $transport] = $this->attempt($c, $service, 'POST', $auth['path'], $options, null, $correlation);
        $ms = (int) ((hrtime(true) - $started) / 1_000_000);

        $response = $this->conclude($c, $service, $auth['operation'], 'POST', $auth['path'], $options, $response, $transport, $attempts, $ms, $correlation, true);
        [$token, $expiresIn] = self::parseToken($response);
        if ($token === null) {
            $this->markService($c, $service, 'INVALID_RESPONSE', $response->status());
            throw ActivaException::make(ActivaException::INVALID_RESPONSE, $service, $auth['operation'], $response->status(), 'No token in the authentication response.');
        }
        $skew = (int) config('activa.token.refresh_skew_seconds', 120);
        $ttl = ($expiresIn ?? (int) config('activa.token.default_ttl_seconds', 1800)) - $skew;

        return [$token, max(30, $ttl)];
    }

    /**
     * Token + expiry from an auth response. Travel: {access_token, token_type, expires_in}. The other services' 200 has
     * no documented representation, so the usual shapes are accepted (token / accessToken / jwt / data.token / a bare
     * JWT string) and the expiry comes from expires_in / expiresIn / expiration / the JWT exp claim.
     *
     * @return array{0: ?string, 1: ?int}
     */
    public static function parseToken(Response $response): array
    {
        $json = $response->json();
        $token = null;
        if (is_array($json)) {
            foreach (['access_token', 'accessToken', 'token', 'jwt', 'jwtToken', 'data.token', 'data.accessToken', 'data.access_token', 'result.token', 'result.accessToken'] as $k) {
                $v = data_get($json, $k);
                if (is_string($v) && $v !== '') {
                    $token = $v;
                    break;
                }
            }
        } elseif (is_string($json) && $json !== '') {
            $token = $json;
        } else {
            $body = trim($response->body(), " \t\n\r\0\x0B\"");
            if ($body !== '' && ! str_contains($body, ' ') && strlen($body) < 8192) {
                $token = $body;
            }
        }
        if ($token === null) {
            return [null, null];
        }

        $expires = null;
        if (is_array($json)) {
            foreach (['expires_in', 'expiresIn', 'data.expires_in', 'data.expiresIn'] as $k) {
                if (is_numeric(data_get($json, $k))) {
                    $expires = (int) data_get($json, $k);
                    break;
                }
            }
            if ($expires === null) {
                foreach (['expiration', 'expires', 'expiresAt', 'expires_at', 'data.expiration', 'validTo'] as $k) {
                    $v = data_get($json, $k);
                    if (is_string($v) && ($ts = strtotime($v)) !== false) {
                        $expires = $ts - now()->getTimestamp();
                        break;
                    }
                }
            }
        }
        if ($expires === null && substr_count($token, '.') === 2) {
            $claims = json_decode((string) base64_decode(strtr(explode('.', $token)[1], '-_', '+/')), true);
            if (is_array($claims) && isset($claims['exp']) && is_numeric($claims['exp'])) {
                $expires = (int) $claims['exp'] - now()->getTimestamp();
            }
        }

        return [$token, $expires];
    }

    public function baseUrl(CarrierApiConnection $c, string $service): string
    {
        $host = rtrim((string) ($c->setting('gateway_url') ?: config('activa.gateway_url')), '/');
        $env = strtoupper((string) $c->environment) === 'PRODUCTION' ? 'PRODUCTION' : 'SANDBOX';
        $path = trim((string) ($c->setting("paths.{$service}") ?: config("activa.paths.{$env}.{$service}")), '/');

        return $host.'/'.$path;
    }

    public function apiVersion(CarrierApiConnection $c): string
    {
        return (string) ($c->setting('api_version') ?: config('activa.api_version', '1'));
    }

    /** Records the outcome of a service on the connection (per-service health) and derives the connection status. */
    public function markService(CarrierApiConnection $c, string $service, string $state, ?int $httpStatus, ?string $error = null): void
    {
        DB::transaction(function () use ($c, $service, $state, $httpStatus, $error) {
            $row = CarrierApiConnection::whereKey($c->id)->lockForUpdate()->first();
            if ($row === null) {
                return;
            }
            $health = $row->service_health ?? [];
            $health[$service] = ['state' => $state, 'http_status' => $httpStatus, 'error_code' => $error, 'at' => now()->toIso8601String()]
                + ($state === 'OK' ? ['last_ok_at' => now()->toIso8601String()] : ['last_ok_at' => $health[$service]['last_ok_at'] ?? null]);
            $row->service_health = $health;
            if ($state === 'OK') {
                $row->last_success_at = now();
            }
            $row->last_error_code = $state === 'OK' ? $row->last_error_code : ($error ?? $state);
            $row->status = self::deriveStatus($row, $this);
            $row->save();
            $c->setRawAttributes($row->getAttributes(), true);
        });
    }

    public static function deriveStatus(CarrierApiConnection $c, self $gateway): string
    {
        if ($c->status === 'DISABLED') {
            return 'DISABLED';
        }
        $configured = array_values(array_filter(ActivaServices::ALL, fn ($s) => $gateway->configured($c, $s)));
        if ($configured === []) {
            return 'CONFIG_REQUIRED';
        }
        $states = array_map(fn ($s) => $c->service_health[$s]['state'] ?? null, $configured);
        if (in_array('OK', $states, true)) {
            return 'ACTIVE';
        }
        if (array_intersect($states, [ActivaException::AUTH_FAILED, ActivaException::SUBSCRIPTION_KEY_REJECTED]) !== []) {
            return 'AUTH_FAILED';
        }

        return 'PENDING_VERIFICATION';
    }

    /** @return array{0: ?Response, 1: int, 2: ?string} response, attempts, transport error (TIMEOUT / CONNECTION) */
    private function attempt(CarrierApiConnection $c, string $service, string $method, string $path, array $options, ?string $token, string $correlation): array
    {
        $max = max(1, (int) config('activa.http.retries', 3) + 1);
        $base = (int) config('activa.http.retry_base_ms', 250);
        $response = null;
        $transport = null;
        for ($i = 1; $i <= $max; $i++) {
            $transport = null;
            try {
                $response = $this->request($c, $service, $options, $token, $correlation)->send($method, $this->baseUrl($c, $service).'/'.ltrim($path, '/'), $this->payload($options));
            } catch (ConnectionException $e) {
                $response = null;
                $transport = str_contains(strtolower($e->getMessage()), 'timed out') ? 'TIMEOUT' : 'CONNECTION';
            }
            $retryable = $response === null || $response->serverError();
            if (! $retryable || $i === $max) {
                return [$response, $i, $transport];
            }
            if ($base > 0) {
                usleep((int) (($base * (2 ** ($i - 1))) + random_int(0, $base)) * 1000);
            }
        }

        return [$response, $max, $transport];
    }

    private function request(CarrierApiConnection $c, string $service, array $options, ?string $token, string $correlation): PendingRequest
    {
        $headers = ['Ocp-Apim-Subscription-Key' => $this->subscriptionKey($c, $service), 'x-correlation-id' => $correlation, 'Accept' => $options['accept'] ?? 'application/json']
            + (array) ($options['headers'] ?? []);
        $req = Http::withHeaders($headers)
            ->connectTimeout((int) config('activa.http.connect_timeout', 5))
            ->timeout((int) config('activa.http.timeout', 30));
        if ($token !== null) {
            $req = $req->withToken($token);
        }
        if (isset($options['form'])) {
            $req = $req->asForm();
        }

        return $req;
    }

    private function payload(array $options): array
    {
        $out = [];
        if (isset($options['query'])) {
            $out['query'] = $options['query'];
        }
        if (isset($options['form'])) {
            $out['form_params'] = $options['form'];
        } elseif (array_key_exists('json', $options)) {
            $out['json'] = $options['json'];
        }

        return $out;
    }

    /** Classifies, logs, audits, feeds the breaker and the service health; returns the 2xx response or throws. */
    private function conclude(CarrierApiConnection $c, string $service, string $operation, string $method, string $path, array $options, ?Response $response,
        ?string $transport, int $attempts, int $ms, string $correlation, bool $isAuth = false): Response
    {
        $status = $response?->status();
        if ($response !== null && $response->successful()) {
            $this->breaker->recordSuccess($c->id, $service);
            $this->log($c, $service, $operation, $method, $path, $status, 'OK', $attempts, $ms, $correlation, $options, null);
            if ($isAuth || ($c->service_health[$service]['state'] ?? null) !== 'OK') {
                $this->markService($c, $service, 'OK', $status);
            }

            return $response;
        }

        $code = match (true) {
            $response === null, $response->serverError() => ActivaException::UNAVAILABLE,
            $status === 401 && $this->isSubscriptionRejection($response) => ActivaException::SUBSCRIPTION_KEY_REJECTED,
            $status === 401, $status === 403 => ActivaException::AUTH_FAILED,
            $status === 404 => ActivaException::NOT_FOUND,
            $status === 408, $status === 429 => ActivaException::UNAVAILABLE,
            default => ActivaException::REJECTED,
        };
        $outcome = $transport ?? ($code === ActivaException::UNAVAILABLE ? 'SERVER_ERROR' : 'HTTP_ERROR');
        $this->log($c, $service, $operation, $method, $path, $status, $outcome, $attempts, $ms, $correlation, $options, $code);

        if ($code === ActivaException::UNAVAILABLE) {
            $this->breaker->recordFailure($c->id, $service);
            $this->markService($c, $service, 'UNAVAILABLE', $status, $code);
        } elseif (in_array($code, [ActivaException::AUTH_FAILED, ActivaException::SUBSCRIPTION_KEY_REJECTED], true)) {
            $this->forgetToken($c, $service);
            $this->markService($c, $service, $code, $status, $code);
        }

        throw ActivaException::make($code, $service, $operation, $status, $code === ActivaException::REJECTED ? self::safeReason($response) : null);
    }

    /** APIM answers 401 {"statusCode":401,"message":"Access denied due to invalid subscription key…"} before the API sees the call. */
    private function isSubscriptionRejection(?Response $response): bool
    {
        return $response !== null && $response->status() === 401 && str_contains(strtolower($response->body()), 'subscription key');
    }

    /** A short, non-personal validation hint from a 4xx body: field names only (ProblemDetails "errors" keys). */
    private static function safeReason(?Response $response): ?string
    {
        $json = $response?->json();
        if (is_array($json) && isset($json['errors']) && is_array($json['errors'])) {
            $fields = array_slice(array_map(fn ($k) => preg_replace('/[^A-Za-z0-9_.\[\]$-]/', '', (string) $k), array_keys($json['errors'])), 0, 10);

            return $fields ? 'Fields: '.implode(', ', $fields).'.' : null;
        }

        return null;
    }

    private function log(CarrierApiConnection $c, string $service, string $operation, string $method, string $path, ?int $status, string $outcome, int $attempts,
        int $ms, string $correlation, array $options, ?string $error): void
    {
        $row = [
            'id' => (string) Str::uuid(), 'connection_id' => $c->id, 'carrier_id' => $c->carrier_id, 'service' => $service, 'operation' => mb_substr($operation, 0, 96),
            'method' => strtoupper($method), 'path' => mb_substr(strtok($path, '?') ?: $path, 0, 255), 'http_status' => $status, 'outcome' => $outcome,
            'attempts' => $attempts, 'duration_ms' => $ms, 'correlation_id' => $correlation,
            'subject_type' => $options['subject_type'] ?? null, 'subject_id' => $options['subject_id'] ?? null, 'error_code' => $error, 'created_at' => now(),
        ];
        DB::table('carrier_api_calls')->insert($row);
        $this->audit->record('integration.carrier_api.call', 'carrier_api_connection', $c->id, array_diff_key($row, array_flip(['id', 'connection_id', 'created_at'])), $error);
    }

    private function subscriptionKey(CarrierApiConnection $c, string $service): string
    {
        $override = (string) ((($c->service_credentials ?? [])[$service] ?? [])['subscription_key'] ?? '');

        return $override !== '' ? $override : (string) ($c->subscription_key ?? '');
    }

    private function assertConfigured(CarrierApiConnection $c, string $service, string $operation): void
    {
        if (! $this->configured($c, $service)) {
            throw ActivaException::make(ActivaException::CONFIG_REQUIRED, $service, $operation);
        }
    }

    private function tokenKey(CarrierApiConnection $c, string $service): string
    {
        return "activa:token:{$c->id}:{$service}:".$c->secretsFingerprint();
    }
}
