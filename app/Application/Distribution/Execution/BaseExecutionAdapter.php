<?php

declare(strict_types=1);

namespace App\Application\Distribution\Execution;

/**
 * REQ-AOM-002 — shared behaviour of the MANUAL / CONFIGURED / HYBRID / REMOTE_API adapters.
 * Subclasses only declare their capability, mode, the OPES handler and the next action; the
 * REMOTE_API variant always answers INTEGRATION_UNAVAILABLE (interface + stub, no carrier API yet).
 */
abstract class BaseExecutionAdapter implements ExecutionAdapter
{
    abstract protected function handler(): ?string;

    abstract protected function nextAction(): string;

    public function execute(ExecutionContext $context): ExecutionOutcome
    {
        if ($this->executionMode() === 'REMOTE_API') {
            return new ExecutionOutcome(ExecutionOutcome::INTEGRATION_UNAVAILABLE, $this->capability(), 'REMOTE_API', static::class,
                null, $this->nextAction(), 'INTEGRATION_UNAVAILABLE');
        }
        $status = match ($this->executionMode()) {
            'MANUAL' => ExecutionOutcome::AWAITING_CARRIER,
            'HYBRID' => ExecutionOutcome::AWAITING_CARRIER_CONFIRMATION,
            default => ExecutionOutcome::HANDLED_BY_PLATFORM,
        };

        return new ExecutionOutcome($status, $this->capability(), $this->executionMode(), static::class, $this->handler(), $this->nextAction());
    }
}
