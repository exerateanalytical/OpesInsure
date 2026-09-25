<?php

declare(strict_types=1);

namespace App\Application\Vehicles\Power;

use DomainException;

/** Rating stops (quote → REFERRED / issuance blocked) when a required fiscal power is unknown — never guessed. */
final class FiscalPowerReviewRequired extends DomainException
{
    public const REASON = 'FISCAL_POWER_REVIEW_REQUIRED';

    public function __construct(public readonly array $snapshot)
    {
        parent::__construct(self::REASON.': '.($snapshot['reason'] ?? 'FISCAL_POWER_UNVERIFIED'));
    }
}
