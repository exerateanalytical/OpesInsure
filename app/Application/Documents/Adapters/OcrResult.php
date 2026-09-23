<?php

declare(strict_types=1);

namespace App\Application\Documents\Adapters;

/**
 * Mirrors ScanResult's shape/intent for extraction instead of scanning.
 * status is deliberately never a fabricated "success" when no real backend
 * ran — MANUAL_REVIEW_REQUIRED is the only status ManualReviewOcrAdapter can
 * return (see its docblock). A future real backend would return EXTRACTED
 * with the fields it actually read.
 */
final readonly class OcrResult
{
    public function __construct(
        public string $status,
        public array $fields = [],
        public ?string $provider = null,
    ) {
    }

    public static function manualReviewRequired(): self
    {
        return new self('MANUAL_REVIEW_REQUIRED');
    }

    public static function extracted(array $fields, string $provider): self
    {
        return new self('EXTRACTED', $fields, $provider);
    }
}
