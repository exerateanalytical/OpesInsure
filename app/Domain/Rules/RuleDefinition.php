<?php

declare(strict_types=1);

namespace App\Domain\Rules;

/** One structured rule (PREP §3.3): the condition says when the rule fires; the outcome says what firing means. */
final class RuleDefinition
{
    /**
     * @param  array<string, mixed>|true  $condition
     * @param  array<string, mixed>  $outcome  e.g. {result: INELIGIBLE, reason_code, message_key} or {result: BLOCK|WARN, reason_code}
     */
    public function __construct(
        public readonly string $code,
        public readonly int $priority,
        public readonly bool $stopProcessing,
        public readonly array|bool $condition,
        public readonly array $outcome,
        public readonly ?string $id = null,
        public readonly ?string $explanationEn = null,
        public readonly ?string $explanationFr = null,
        public readonly bool $enabled = true,
    ) {}
}
