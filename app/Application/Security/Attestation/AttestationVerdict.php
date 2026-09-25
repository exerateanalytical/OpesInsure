<?php

declare(strict_types=1);

namespace App\Application\Security\Attestation;

final readonly class AttestationVerdict
{
    public const PASS = 'PASS';

    public const FAIL = 'FAIL';

    public const UNVERIFIED = 'UNVERIFIED';

    /** @param list<string> $reasons */
    public function __construct(public string $verdict, public array $reasons = []) {}

    public static function unverified(string $reason): self
    {
        return new self(self::UNVERIFIED, [$reason]);
    }
}
