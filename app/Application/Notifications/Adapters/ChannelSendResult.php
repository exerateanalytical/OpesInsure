<?php

declare(strict_types=1);

namespace App\Application\Notifications\Adapters;

final readonly class ChannelSendResult
{
    public function __construct(
        public string $provider,
        public string $providerReference,
        public array $safeResponse = [],
    ) {
    }
}
