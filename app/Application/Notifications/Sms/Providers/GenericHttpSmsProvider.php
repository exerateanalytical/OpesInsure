<?php

declare(strict_types=1);

namespace App\Application\Notifications\Sms\Providers;

use App\Application\Notifications\Sms\SmsDeliveryException;
use App\Application\Notifications\Sms\SmsProvider;
use App\Models\SmsProviderConnection;
use Illuminate\Support\Facades\Http;

/**
 * Any HTTP SMS gateway (local Cameroonian aggregators, MTN bulk SMS resellers, ETECH-like APIs…), described by settings:
 *  - url, method (GET|POST), format (query|form|json)
 *  - params: {name: template}, headers: {name: template}
 *  - auth: none | basic (secrets username/password) | bearer (secret token)
 *  - success_contains (optional substring the body must contain), reference_path (optional JSON path of the message id)
 * Templates may use {to} (+2376…), {to_digits} (2376…), {to_local} (6…), {message}, {sender},
 * and the secrets {username}, {password}, {api_key}, {token}.
 */
final class GenericHttpSmsProvider implements SmsProvider
{
    public function key(): string
    {
        return 'generic_http';
    }

    public function requiredSettings(): array
    {
        return ['url'];
    }

    public function requiredSecrets(): array
    {
        return [];
    }

    public function send(SmsProviderConnection $connection, string $toE164, string $message): string
    {
        $digits = ltrim($toE164, '+');
        $vars = [
            '{to}' => $toE164, '{to_digits}' => $digits, '{to_local}' => str_starts_with($digits, '237') ? substr($digits, 3) : $digits,
            '{message}' => $message, '{sender}' => (string) $connection->setting('sender', ''),
            '{username}' => $connection->secret('username'), '{password}' => $connection->secret('password'),
            '{api_key}' => $connection->secret('api_key'), '{token}' => $connection->secret('token'),
        ];
        $fill = fn (array $map): array => array_map(fn ($v) => strtr((string) $v, $vars), $map);

        $params = $fill((array) $connection->setting('params', []));
        $headers = $fill((array) $connection->setting('headers', []));
        $url = (string) $connection->setting('url');
        $method = strtoupper((string) $connection->setting('method', 'POST'));
        $format = (string) $connection->setting('format', $method === 'GET' ? 'query' : 'form');

        $http = Http::withHeaders($headers)->timeout(15);
        $http = match ((string) $connection->setting('auth', 'none')) {
            'basic' => $http->withBasicAuth($connection->secret('username'), $connection->secret('password')),
            'bearer' => $http->withToken($connection->secret('token')),
            default => $http,
        };

        $response = match (true) {
            $method === 'GET' => $http->get($url, $params),
            $format === 'json' => $http->asJson()->send($method, $url, ['json' => $params]),
            $format === 'query' => $http->send($method, $url, ['query' => $params]),
            default => $http->asForm()->send($method, $url, ['form_params' => $params]),
        };

        if (! $response->successful()) {
            throw new SmsDeliveryException('The SMS gateway refused the message (HTTP '.$response->status().').');
        }

        $needle = (string) $connection->setting('success_contains', '');
        if ($needle !== '' && ! str_contains($response->body(), $needle)) {
            throw new SmsDeliveryException('The SMS gateway answered without the expected success marker.');
        }

        $path = (string) $connection->setting('reference_path', '');
        $ref = $path !== '' ? data_get($response->json() ?? [], $path) : null;

        return is_scalar($ref) ? mb_substr((string) $ref, 0, 190) : '';
    }
}
