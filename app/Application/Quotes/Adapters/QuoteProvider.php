<?php

declare(strict_types=1);

namespace App\Application\Quotes\Adapters;

use App\Application\Distribution\Execution\ExecutionAdapter;

/** REQ-AOM-002 — QUOTATION execution port (MANUAL / CONFIGURED / HYBRID / REMOTE_API), chosen by QuoteProviderRegistry. */
interface QuoteProvider extends ExecutionAdapter
{
    public const CAPABILITY = 'QUOTATION';

    /** CapabilityPinner subject type pinned when the transaction is created. */
    public const SUBJECT_TYPE = 'quote';
}
