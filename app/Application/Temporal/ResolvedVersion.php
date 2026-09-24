<?php

declare(strict_types=1);

namespace App\Application\Temporal;

final class ResolvedVersion
{
    /** @param array<string, mixed> $key */
    public function __construct(
        public readonly string $artifactType,
        public readonly array $key,
        public readonly string $id,
        public readonly int|string|null $version,
        public readonly ?string $validFrom,
        public readonly ?string $validTo,
        public readonly string $sourceTable,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['artifact_type' => $this->artifactType, 'key' => $this->key, 'id' => $this->id, 'version' => $this->version,
            'valid_from' => $this->validFrom, 'valid_to' => $this->validTo, 'source_table' => $this->sourceTable];
    }
}
