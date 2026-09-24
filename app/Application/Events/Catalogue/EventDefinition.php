<?php

declare(strict_types=1);

namespace App\Application\Events\Catalogue;

/** REQ-ARC-004: one catalogue entry. Definitions only; payload JSON schemas live in canonical_event_schemas. */
final class EventDefinition
{
    public const SOURCE_WRS = 'WRS';       // WORKFLOW_REGISTER_SPEC "Required domain events" (26)
    public const SOURCE_PRE = 'PRE';       // PRODUCT_RULE_ENGINE_SPEC events (15)
    public const SOURCE_CODE = 'CODE';     // already emitted by the codebase, no spec alias
    public const SOURCE_ENGINE = 'ENGINE'; // platform engines (state machine, ...)

    /**
     * @param list<string> $aliases PascalCase spec names mapped onto this dotted name
     * @param list<string> $sources
     */
    public function __construct(
        public readonly string $name,
        public readonly string $aggregate,
        public readonly string $description,
        public readonly array $aliases = [],
        public readonly array $sources = [self::SOURCE_CODE],
        public readonly bool $emitted = false,
        public readonly int $version = 1,
    ) {}

    public function domain(): string
    {
        return explode('.', $this->name, 2)[0];
    }
}
