<?php

declare(strict_types=1);

namespace App\Application\Distribution\Execution;

/**
 * REQ-AOM-002 — a real carrier API behind the REMOTE_API execution mode. BaseExecutionAdapter asks
 * RemoteCarrierConnectors for the carrier's connector; without one (or when it is not configured) the REMOTE_API
 * adapter keeps answering INTEGRATION_UNAVAILABLE and the profile's fallback mode applies.
 */
interface RemoteCarrierConnector
{
    /** Whether this connector serves the carrier at all (configured or not). */
    public function handles(string $carrierId): bool;

    /** Capabilities (CapabilityCatalogue codes) the carrier's API implements. */
    public function supports(string $capability): bool;

    public function execute(string $capability, ExecutionContext $context): ExecutionOutcome;
}
