<?php

declare(strict_types=1);

namespace App\Application\Claims\Adapters;

use App\Application\Distribution\Execution\ExecutionAdapter;

/** REQ-AOM-002 — CLAIMS_INTAKE execution port (MANUAL / CONFIGURED / HYBRID / REMOTE_API), chosen by ClaimProviderRegistry. */
interface ClaimProvider extends ExecutionAdapter
{
    public const CAPABILITY = 'CLAIMS_INTAKE';

    /** CapabilityPinner subject type pinned when the transaction is created. */
    public const SUBJECT_TYPE = 'claim';
}
