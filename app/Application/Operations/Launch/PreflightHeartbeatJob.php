<?php

declare(strict_types=1);

namespace App\Application\Operations\Launch;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;

/** launch:preflight queue probe: a worker picking this up proves the queue is consumed end to end. */
final class PreflightHeartbeatJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public function __construct(public readonly string $token) {}

    public static function key(string $token): string
    {
        return 'launch_preflight:beat:'.$token;
    }

    public function handle(): void
    {
        $now = now()->toIso8601String();
        Cache::put(self::key($this->token), $now, 600);
        Cache::put(LaunchPreflight::LAST_BEAT_KEY, $now, 86400);
    }
}
