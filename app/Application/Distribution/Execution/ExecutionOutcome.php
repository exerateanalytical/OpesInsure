<?php

declare(strict_types=1);

namespace App\Application\Distribution\Execution;

/**
 * REQ-AOM-002 — the result of asking an adapter to execute a step. It never fakes a carrier answer:
 * MANUAL routes to a person, CONFIGURED is handled by the OPES engine named in `handler`, HYBRID is
 * OPES-computed but needs carrier confirmation, REMOTE_API is INTEGRATION_UNAVAILABLE until a real
 * carrier connector exists (no carrier API is wired yet).
 */
final readonly class ExecutionOutcome
{
    public const HANDLED_BY_PLATFORM = 'HANDLED_BY_PLATFORM';

    public const AWAITING_CARRIER = 'AWAITING_CARRIER';

    public const AWAITING_CARRIER_CONFIRMATION = 'AWAITING_CARRIER_CONFIRMATION';

    public const INTEGRATION_UNAVAILABLE = 'INTEGRATION_UNAVAILABLE';

    public function __construct(
        public string $status,
        public string $capability,
        public string $executionMode,
        public string $adapter,
        public ?string $handler = null,
        public ?string $nextAction = null,
        public ?string $errorCode = null,
        public ?string $fallbackMode = null,
    ) {}

    public function available(): bool
    {
        return $this->status !== self::INTEGRATION_UNAVAILABLE;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'status' => $this->status, 'capability' => $this->capability, 'execution_mode' => $this->executionMode, 'adapter' => $this->adapter,
            'handler' => $this->handler, 'next_action' => $this->nextAction, 'error_code' => $this->errorCode, 'fallback_mode' => $this->fallbackMode,
        ];
    }
}
