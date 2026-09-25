<?php

declare(strict_types=1);

namespace App\Application\DataReadiness;

use RuntimeException;

/**
 * Read-only loader of database/data/workflow_institutional_data_master_2026.json (the owner's Workflow Institutional
 * Data Master v1). The file is the source of the domain list and of each domain's declared status; values are merged
 * into the canonical module tables and lists, never copied into a parallel store.
 */
final class WorkflowDataMaster
{
    public const DATA_FILE = 'data/workflow_institutional_data_master_2026.json';

    /** @var array<string, mixed>|null */
    private ?array $data = null;

    /** @return array<string, mixed> */
    public function all(): array
    {
        if ($this->data === null) {
            $raw = @file_get_contents(database_path(self::DATA_FILE));
            if ($raw === false) {
                throw new RuntimeException('Workflow data master file is missing: '.self::DATA_FILE);
            }
            // The owner's file is Latin-1 encoded; decode it safely either way.
            $json = json_decode($raw, true) ?? json_decode(mb_convert_encoding($raw, 'UTF-8', 'ISO-8859-1'), true);
            if (! is_array($json)) {
                throw new RuntimeException('Workflow data master file is not valid JSON.');
            }
            $this->data = $json;
        }

        return $this->data;
    }

    /** @return array<string, mixed> */
    public function dataset(): array
    {
        return (array) ($this->all()['dataset'] ?? []);
    }

    public function version(): string
    {
        return (string) ($this->dataset()['version'] ?? '');
    }

    /** @return list<string> every domain (top-level key) except the dataset header */
    public function domains(): array
    {
        return array_values(array_filter(array_keys($this->all()), fn ($k) => $k !== 'dataset'));
    }

    /** @return array<string, mixed> */
    public function section(string $domain): array
    {
        return (array) ($this->all()[$domain] ?? []);
    }

    /** @return mixed */
    public function get(string $path, mixed $default = null): mixed
    {
        return data_get($this->all(), $path, $default);
    }
}
