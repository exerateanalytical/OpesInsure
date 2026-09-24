<?php

declare(strict_types=1);

namespace App\Application\Notifications\Push;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

final class SendPushNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 3;

    public array $backoff = [30, 120];

    public function __construct(public string $userId, public string $title, public string $body, public array $data = []) {}

    public function handle(ExpoPushSender $sender): void
    {
        try {
            $sender->sendToUser($this->userId, $this->title, $this->body, $this->data);
        } catch (Throwable $e) {
            Log::warning('push.job.attempt_failed', ['user_id' => $this->userId, 'error' => $e->getMessage()]);
            // On a real queue, rethrow so the worker retries with backoff;
            // on the sync driver (tests/dev) never surface a push failure to
            // the request or transaction that produced the notification.
            if ($this->job !== null && ! $this->job instanceof \Illuminate\Queue\Jobs\SyncJob) {
                throw $e;
            }
        }
    }

    public function failed(Throwable $e): void
    {
        Log::error('push.job.failed', ['user_id' => $this->userId, 'error' => $e->getMessage()]);
    }
}
