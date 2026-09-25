<?php

declare(strict_types=1);

namespace App\Application\DataReadiness;

/** Raised by ProductionUseGuard. Extends DomainException so existing handlers of ChargeTableService::assertUsable keep working. */
final class NonProductionDataException extends \DomainException
{
    public function __construct(public readonly string $domain, public readonly string $dataStatus, string $message)
    {
        parent::__construct($message);
    }
}
