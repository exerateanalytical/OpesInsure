<?php

declare(strict_types=1);

namespace App\Application\Security\Purpose;

final readonly class PurposeDecision
{
    public function __construct(
        public bool $allowed,
        public string $purposeCode,
        public ?string $lawfulBasis,
        public ?string $consentId,
        public ?string $refusalCode,
    ) {}
}
