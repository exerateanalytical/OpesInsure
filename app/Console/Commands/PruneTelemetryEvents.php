<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\TelemetryEvent;
use Illuminate\Console\Command;

/**
 * Retention control for POST /mobile/runtime/telemetry (CLAUDE_MERGE_GUIDE.md,
 * Patch 7: "Apply retention limits and access controls"). Scheduled daily —
 * see routes/console.php.
 */
final class PruneTelemetryEvents extends Command
{
    protected $signature = 'telemetry:prune';

    protected $description = 'Delete telemetry_events rows older than mobile_runtime.telemetry.retention_days.';

    public function handle(): int
    {
        $cutoff = now()->subDays((int) config('mobile_runtime.telemetry.retention_days'));
        $deleted = TelemetryEvent::where('created_at', '<', $cutoff)->delete();

        $this->info("Deleted {$deleted} telemetry event(s) older than {$cutoff->toDateString()}.");

        return self::SUCCESS;
    }
}
