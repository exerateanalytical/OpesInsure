<?php

declare(strict_types=1);

namespace App\Application\Distribution\Execution;

/**
 * REQ-AOM-002 — what an execution adapter acts on: one transaction (quote offer, proposal, issuance
 * request, claim) of one carrier/product, with the mode that was pinned when it was created.
 */
final readonly class ExecutionContext
{
    public function __construct(
        public string $subjectType,
        public ?string $subjectId,
        public string $carrierId,
        public ?string $productId = null,
        public array $payload = [],
    ) {}
}
