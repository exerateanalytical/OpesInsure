<?php

declare(strict_types=1);

namespace App\Application\Documents\Adapters;

final readonly class SignedUrl
{
    public function __construct(
        public string $url,
        public string $expiresAt,
    ) {
    }
}
