<?php

declare(strict_types=1);

namespace App\Application\Import;

/**
 * An ImportTarget whose rows are independent (e.g. one broker onboarding per row):
 *  - a batch with some invalid rows can still be submitted; the invalid rows are skipped and reported;
 *  - at import time each row runs in its own savepoint, so one failing row is recorded as FAILED
 *    instead of rolling back the whole batch.
 * outcome() returns extra per-row details of the row just imported (merged into import_batches.result).
 */
interface PartialImportTarget extends ImportTarget
{
    /** @return array<string, mixed> */
    public function outcome(): array;
}
