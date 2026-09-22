<?php

declare(strict_types=1);

namespace App\Application\Notifications\Adapters;

/**
 * Mirrors App\Application\Payments\Adapters\PaymentProviderAdapter: one
 * adapter per real channel/provider, given a resolved real destination and
 * already-rendered content — destination resolution and template rendering
 * are the caller's job (NotificationDispatchService), not the adapter's.
 */
interface NotificationChannelAdapter
{
    public function channel(): string;

    public function send(string $destination, string $subject, string $body, string $idempotencyKey): ChannelSendResult;
}
