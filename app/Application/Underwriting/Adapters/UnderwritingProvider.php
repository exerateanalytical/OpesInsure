<?php

declare(strict_types=1);

namespace App\Application\Underwriting\Adapters;

use App\Application\Distribution\Execution\ExecutionAdapter;

/** REQ-AOM-002 — UNDERWRITING execution port (MANUAL / CONFIGURED / HYBRID / REMOTE_API), chosen by UnderwritingProviderRegistry. */
interface UnderwritingProvider extends ExecutionAdapter
{
    public const CAPABILITY = 'UNDERWRITING';

    /** CapabilityPinner subject type pinned when the transaction is created. */
    public const SUBJECT_TYPE = 'proposal';
}
