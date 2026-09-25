<?php

declare(strict_types=1);

namespace App\Application\Import;

use App\Models\User;

/**
 * REQ-IMP-001 — one entity an ImportPipeline can load. A target only knows its own fields, how to recognise
 * an existing record (duplicate) and how to create one through its owning domain service. Upload, mapping,
 * approval, audit and batch bookkeeping stay in the pipeline, so every importer behaves the same way.
 * Targets never overwrite or delete existing records.
 */
interface ImportTarget
{
    /** Stable key stored on import_batches.target. */
    public function key(): string;

    public function label(): string;

    /** @return array<string, bool> target field => required */
    public function fields(): array;

    /** Validates and normalises batch parameters (e.g. which master-data list). @throws \Illuminate\Validation\ValidationException */
    public function params(array $params): array;

    /**
     * Classifies one mapped row. $seen is shared across the batch so repeats inside the file are caught.
     *
     * @return array{status: 'NEW'|'DUPLICATE'|'ERROR', key?: string, matches?: string, error?: string}
     */
    public function check(array $row, array $params, array &$seen): array;

    /** Creates the record for a NEW row through the domain service; returns the created id. */
    public function import(array $row, array $params, ?User $actor, string $batchId): string;

    /** Hook after all rows were imported (link parents, bump caches). */
    public function finish(array $params): void;
}
