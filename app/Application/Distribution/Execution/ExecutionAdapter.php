<?php

declare(strict_types=1);

namespace App\Application\Distribution\Execution;

/** REQ-AOM-002 — common port of QuoteProvider / UnderwritingProvider / PolicyIssuer / ClaimProvider. */
interface ExecutionAdapter
{
    /** CapabilityCatalogue capability this adapter serves (QUOTATION, UNDERWRITING, POLICY_ISSUANCE, CLAIMS_INTAKE). */
    public function capability(): string;

    /** One of CapabilityCatalogue::EXECUTION_MODES. */
    public function executionMode(): string;

    public function execute(ExecutionContext $context): ExecutionOutcome;
}
