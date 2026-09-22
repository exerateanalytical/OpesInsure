<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Notifications\NotificationDispatchService;
use App\Models\NotificationDelivery;
use Illuminate\Console\Command;

/**
 * The worker `NotificationController::queue()` never had: drains QUEUED
 * notification_deliveries (including ones NotificationDispatchService itself
 * rescheduled with a future next_attempt_at after a failure) through a real
 * channel adapter.
 */
final class DispatchPendingNotifications extends Command
{
    protected $signature = 'notifications:dispatch-pending {--limit=200}';

    protected $description = 'Send pending (and due-for-retry) notification_deliveries through their real channel adapter.';

    public function handle(NotificationDispatchService $dispatcher): int
    {
        $limit = (int) $this->option('limit');
        $sent = 0;
        $failed = 0;
        $deadLettered = 0;

        $deliveries = NotificationDelivery::where('status', 'QUEUED')
            ->where(fn ($q) => $q->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now()))
            ->orderBy('created_at')
            ->limit($limit)
            ->get();

        foreach ($deliveries as $delivery) {
            $result = $dispatcher->dispatch($delivery);

            match ($result->status) {
                'SENT' => $sent++,
                'DEAD_LETTERED' => $deadLettered++,
                default => $failed++,
            };
        }

        $this->info("Claimed: {$deliveries->count()}. Sent: {$sent}. Rescheduled after failure: {$failed}. Dead-lettered: {$deadLettered}.");

        return self::SUCCESS;
    }
}
