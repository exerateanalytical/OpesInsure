<?php

declare(strict_types=1);

namespace App\Application\Temporal;

use RuntimeException;

final class TemporalResolutionException extends RuntimeException
{
    public const NO_VERSION = 'TEMPORAL_NO_VERSION';
    public const AMBIGUOUS = 'TEMPORAL_AMBIGUOUS';
    public const NO_RULE = 'TEMPORAL_NO_RULE';
    public const NO_ANCHOR = 'TEMPORAL_NO_ANCHOR';
    public const UNKNOWN_ARTIFACT = 'TEMPORAL_UNKNOWN_ARTIFACT';

    /** @param array<string, mixed> $key */
    public function __construct(
        public readonly string $reasonCode,
        public readonly string $artifactType,
        public readonly array $key = [],
        public readonly ?string $at = null,
    ) {
        parent::__construct("{$reasonCode}: {$artifactType} ".json_encode($key).($at ? " at {$at}" : ''));
    }
}
