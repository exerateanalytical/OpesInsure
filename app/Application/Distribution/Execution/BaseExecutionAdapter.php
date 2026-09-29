<?php

declare(strict_types=1);

namespace App\Application\Distribution\Execution;

/**
 * REQ-AOM-002 — shared behaviour of the MANUAL / CONFIGURED / HYBRID / REMOTE_API adapters.
 * Subclasses only declare their capability, mode, the OPES handler and the next action; the
 * REMOTE_API variant delegates to the carrier's API connector (RemoteCarrierConnectors, e.g. Activa) when one
 * serves the carrier and this capability, and otherwise answers INTEGRATION_UNAVAILABLE.
 */
abstract class BaseExecutionAdapter implements ExecutionAdapter
{
    abstract protected function handler(): ?string;

    abstract protected function nextAction(): string;

    public function execute(ExecutionContext $context): ExecutionOutcome
    {
        if ($this->executionMode() === 'REMOTE_API') {
            $connector = app(RemoteCarrierConnectors::class)->for($context->carrierId);
            if ($connector !== null && $connector->supports($this->capability())) {
                return $connector->execute($this->capability(), $context);
            }

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
