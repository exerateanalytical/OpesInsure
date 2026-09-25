<?php

declare(strict_types=1);

namespace App\Domain\Rules;

/** A versioned, effective-dated rule set as the evaluator sees it (already resolved for a reference date). */
final class RuleSetDefinition
{
    public const DOMAINS = ['ELIGIBILITY', 'COMPLETENESS', 'UNDERWRITING', 'REFERRAL', 'DOCUMENTS', 'QUESTION_EFFECT', 'TAX_EXEMPTION'];

    /** @param list<RuleDefinition> $rules */
    public function __construct(
        public readonly string $code,
        public readonly string $domain,
        public readonly int $version,
        public readonly array $rules,
        public readonly ?string $id = null,
        public readonly ?string $contentHash = null,
        public readonly string $sourceTable = 'rule_sets',
    ) {
        if (! in_array($domain, self::DOMAINS, true)) {
            throw new \InvalidArgumentException("Unknown rule domain [{$domain}].");
        }
    }

    /** Evaluation order (PREP §3.4): priority ascending, then code ascending — deterministic tie-break. */
    public function ordered(): array
    {
        $rules = array_values(array_filter($this->rules, fn (RuleDefinition $r) => $r->enabled));
        usort($rules, fn (RuleDefinition $a, RuleDefinition $b) => [$a->priority, $a->code] <=> [$b->priority, $b->code]);

        return $rules;
    }
}
