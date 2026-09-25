<?php

declare(strict_types=1);

namespace App\Application\Policies\Adapters;

use App\Application\Distribution\Execution\ExecutionAdapter;

/** REQ-AOM-002 — POLICY_ISSUANCE execution port (MANUAL / CONFIGURED / HYBRID / REMOTE_API), chosen by PolicyIssuerRegistry. */
interface PolicyIssuer extends ExecutionAdapter
{
    public const CAPABILITY = 'POLICY_ISSUANCE';

    /** CapabilityPinner subject type pinned when the transaction is created. */
    public const SUBJECT_TYPE = 'policy_issuance_request';
}
