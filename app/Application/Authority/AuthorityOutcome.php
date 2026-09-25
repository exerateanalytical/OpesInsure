<?php

declare(strict_types=1);

namespace App\Application\Authority;

/** Result of AuthorityService: ALLOWED, REFERRED (an AUTHORITY_REFERRAL case is open) or DENIED (hard scope failure). */
final readonly class AuthorityOutcome
{
    public const ALLOWED = 'ALLOWED';

    public const REFERRED = 'REFERRED';

    public const DENIED = 'DENIED';

    public function __construct(
        public string $outcome,
        public string $reason,
        public string $source,
        public ?string $limitId,
        public ?int $maxAmountMinor,
        public ?string $intermediaryAuthorizationId,
        public ?string $referralCaseId,
        public string $checkId,
    ) {}

    public function allowed(): bool
    {
        return $this->outcome === self::ALLOWED;
    }

    public function referred(): bool
    {
        return $this->outcome === self::REFERRED;
    }

    public function denied(): bool
    {
        return $this->outcome === self::DENIED;
    }

    /** @return array<string, mixed> */
    public function snapshot(): array
    {
        return [
            'outcome' => $this->outcome, 'decision' => $this->reason, 'source' => $this->source,
            'authority_limit_id' => $this->limitId, 'max_amount_minor' => $this->maxAmountMinor,
            'intermediary_authorization_id' => $this->intermediaryAuthorizationId,
            'referral_case_id' => $this->referralCaseId, 'authority_check_id' => $this->checkId,
        ];
    }
}
