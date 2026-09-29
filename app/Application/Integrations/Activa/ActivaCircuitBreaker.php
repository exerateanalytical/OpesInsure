<?php

declare(strict_types=1);

namespace App\Application\Integrations\Activa;

use Illuminate\Support\Facades\Cache;

/**
 * Per connection + service breaker. After `failure_threshold` transient failures (timeouts / 5xx, after retries) within
 * `window_seconds` the circuit opens for `open_seconds`: calls fail fast with CIRCUIT_OPEN instead of piling onto a
 * struggling gateway. After that one trial call goes through (half-open); a success closes the circuit.
 */
final class ActivaCircuitBreaker
{
    public function isOpen(string $connectionId, string $service): bool
    {
        $state = Cache::get($this->key($connectionId, $service));

        return is_array($state) && isset($state['open_until']) && $state['open_until'] > now()->getTimestamp();
    }

    public function recordFailure(string $connectionId, string $service): bool
    {
        $cfg = (array) config('activa.circuit_breaker');
        $now = now()->getTimestamp();
        $key = $this->key($connectionId, $service);
        $state = Cache::get($key);
        if (! is_array($state) || ($now - (int) ($state['first_at'] ?? 0)) > (int) $cfg['window_seconds']) {
            $state = ['failures' => 0, 'first_at' => $now];
        }
        $state['failures']++;
        if ($state['failures'] >= (int) $cfg['failure_threshold']) {
            $state['open_until'] = $now + (int) $cfg['open_seconds'];
        }
        Cache::put($key, $state, (int) $cfg['window_seconds'] + (int) $cfg['open_seconds']);

        return isset($state['open_until']);
    }

    public function recordSuccess(string $connectionId, string $service): void
    {
        Cache::forget($this->key($connectionId, $service));
    }

    /** @return array{failures: int, open: bool, open_until: ?int} */
    public function state(string $connectionId, string $service): array
    {
        $s = Cache::get($this->key($connectionId, $service));

        return ['failures' => (int) ($s['failures'] ?? 0), 'open' => $this->isOpen($connectionId, $service), 'open_until' => $s['open_until'] ?? null];
    }

    private function key(string $connectionId, string $service): string
    {
        return "activa:circuit:{$connectionId}:{$service}";
    }
}
