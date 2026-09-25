<?php

declare(strict_types=1);

namespace App\Domain\Rules;

/**
 * REQ-RUL-003 — eligibility outcomes (PRE §19; traceability conflict row "Eligibility outcomes": the PRE set wins,
 * SCF §11 REQUIRES_DOCUMENT maps to MORE_INFORMATION_REQUIRED and UNDERWRITING_REFERRAL to REFER_TO_UNDERWRITING).
 * Severity order (PREP §3.4): INELIGIBLE > REFER_TO_UNDERWRITING > MORE_INFORMATION_REQUIRED > CONDITIONAL > ELIGIBLE.
 */
enum EligibilityOutcome: string
{
    case ELIGIBLE = 'ELIGIBLE';
    case CONDITIONAL = 'CONDITIONAL';
    case MORE_INFORMATION_REQUIRED = 'MORE_INFORMATION_REQUIRED';
    case REFER_TO_UNDERWRITING = 'REFER_TO_UNDERWRITING';
    case INELIGIBLE = 'INELIGIBLE';

    public const ALIASES = [
        'UNDERWRITING_REFERRAL' => 'REFER_TO_UNDERWRITING', 'REFER' => 'REFER_TO_UNDERWRITING', 'REFERRAL' => 'REFER_TO_UNDERWRITING',
        'REQUIRES_DOCUMENT' => 'MORE_INFORMATION_REQUIRED', 'MORE_INFO' => 'MORE_INFORMATION_REQUIRED', 'DECLINE' => 'INELIGIBLE',
    ];

    public static function parse(string $value): self
    {
        $v = strtoupper(trim($value));

        return self::tryFrom(self::ALIASES[$v] ?? $v) ?? throw new \InvalidArgumentException("Unknown eligibility outcome [{$value}].");
    }

    public function severity(): int
    {
        return match ($this) {
            self::ELIGIBLE => 0, self::CONDITIONAL => 1, self::MORE_INFORMATION_REQUIRED => 2, self::REFER_TO_UNDERWRITING => 3, self::INELIGIBLE => 4,
        };
    }

    /** May the product be quoted / offered with this outcome? (CONDITIONAL = offered with conditions attached.) */
    public function quotable(): bool
    {
        return $this === self::ELIGIBLE || $this === self::CONDITIONAL;
    }

    public function blocking(): bool
    {
        return ! $this->quotable();
    }

    public static function worst(self ...$outcomes): self
    {
        $worst = self::ELIGIBLE;
        foreach ($outcomes as $o) {
            if ($o->severity() > $worst->severity()) {
                $worst = $o;
            }
        }

        return $worst;
    }
}
