<?php

declare(strict_types=1);

namespace App\Application\Documents\Engine;

use RuntimeException;

/**
 * Issuance of one pack document refused before numbering (canonical finalization sequence step 5):
 * BLOCKED_MISSING_FIELDS (required canonical field empty) or BLOCKED_SECURITY_CONTROLS (a required
 * control is CONFIG_REQUIRED while document_security.enforce_controls is on).
 */
final class DocumentIssuanceBlocked extends RuntimeException
{
    /** @param array<int, string> $missing */
    public function __construct(public readonly string $state, public readonly array $missing, string $message)
    {
        parent::__construct($message);
    }
}
