<?php

declare(strict_types=1);

namespace App\Application\Documents\Adapters;

/**
 * status is one of the same three values the existing documents.scan_status
 * column already accepts (see the staff DocumentController::review()
 * validation: CLEAN, INFECTED, FAILED). There is deliberately no fourth
 * "not scanned" success-like state — an adapter that cannot reach a real
 * scanner returns FAILED, never CLEAN, so an unscanned document can never
 * be mistaken for a safe one (see FailClosedMalwareScanAdapter).
 */
final readonly class ScanResult
{
    public function __construct(
        public string $status,
        public ?string $detail = null,
    ) {
    }

    public static function clean(): self
    {
        return new self('CLEAN');
    }

    public static function infected(string $detail): self
    {
        return new self('INFECTED', $detail);
    }

    public static function failed(string $detail): self
    {
        return new self('FAILED', $detail);
    }
}
