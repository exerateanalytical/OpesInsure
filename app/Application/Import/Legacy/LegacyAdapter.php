<?php

declare(strict_types=1);

namespace App\Application\Import\Legacy;

/**
 * REQ-IMP-002 — one legacy entity the LegacyMigrationPipeline can migrate. An adapter knows its fields, its
 * validation rules, the legacy id and the amount it carries (for reconciliation), how to create the record
 * through the owning domain service, and how to re-measure a migrated record from the database.
 * Idempotency (external_record_mappings by legacy id), staging, approval, audit and batch bookkeeping stay in
 * the pipeline.
 */
interface LegacyAdapter
{
    /** Stable key stored on import_batches.target (legacy.customers, legacy.policies, ...). */
    public function key(): string;

    public function label(): string;

    /** external_record_mappings.record_type for this entity. */
    public function recordType(): string;

    /** @return array<string, bool> field => required */
    public function fields(): array;

    /**
     * Rule report for one mapped row. Each failure names the rule broken.
     *
     * @return list<array{rule: string, field: string, message: string}>
     */
    public function validate(array $row, LegacyContext $ctx): array;

    /** Amount the row carries, in minor units (0 when the entity has no amount: customers). */
    public function sourceAmount(array $row): int;

    /**
     * Creates the record(s) through the domain services. Returns the new record id plus notes for the report
     * (match candidates, ...). An entity carrying a balance returns opening_balance; the pipeline posts it on
     * migration.opening_balance (or reports CONFIG_REQUIRED) with the new record id as reference.
     *
     * @return array{id: string, notes?: array, opening_balance?: array{amount_minor: int, currency: string}}
     */
    public function migrate(array $row, LegacyContext $ctx): array;

    /** Re-reads a migrated record's amount (minor units) from the database for reconciliation; null if missing. */
    public function measure(string $id): ?int;
}
