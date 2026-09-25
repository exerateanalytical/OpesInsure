<?php

declare(strict_types=1);

namespace App\Application\Distribution\Execution;

use App\Application\Capabilities\CapabilityPinner;
use App\Application\Capabilities\CapabilityResolver;
use InvalidArgumentException;

/**
 * REQ-AOM-002 — picks the adapter for a carrier/product from CapabilityResolver (never its own
 * mode logic). For an existing transaction the pinned execution mode wins, so a later profile
 * change never re-routes work already in flight (REQ-AOM-001 pins).
 */
abstract class AdapterRegistry
{
    public function __construct(protected readonly CapabilityResolver $resolver, protected readonly CapabilityPinner $pinner) {}

    abstract public function capability(): string;

    /** @return array<string, class-string<ExecutionAdapter>> execution mode => adapter class */
    abstract protected function adapters(): array;

    public function forMode(string $executionMode): ExecutionAdapter
    {
        $class = $this->adapters()[$executionMode] ?? throw new InvalidArgumentException("No {$this->capability()} adapter for mode {$executionMode}.");

        return app($class);
    }

    public function for(string $carrierId, ?string $productId = null): ExecutionAdapter
    {
        return $this->forMode($this->resolver->mode($carrierId, $this->capability(), $productId)['execution_mode']);
    }

    public function forSubject(string $subjectType, string $subjectId, string $carrierId, ?string $productId = null): ExecutionAdapter
    {
        $pin = $this->pinner->pinned($subjectType, $subjectId, $this->capability());

        return $pin ? $this->forMode($pin->execution_mode) : $this->for($carrierId, $productId);
    }

    /** Execute with the pinned adapter; when REMOTE_API is unavailable and the profile names a fallback, report it. */
    public function execute(ExecutionContext $context): ExecutionOutcome
    {
        $adapter = $context->subjectId !== null
            ? $this->forSubject($context->subjectType, $context->subjectId, $context->carrierId, $context->productId)
            : $this->for($context->carrierId, $context->productId);
        $outcome = $adapter->execute($context);
        if (! $outcome->available()) {
            $fallback = $this->resolver->mode($context->carrierId, $this->capability(), $context->productId)['fallback_mode'];

            return new ExecutionOutcome($outcome->status, $outcome->capability, $outcome->executionMode, $outcome->adapter, null, $outcome->nextAction, $outcome->errorCode, $fallback);
        }

        return $outcome;
    }
}
