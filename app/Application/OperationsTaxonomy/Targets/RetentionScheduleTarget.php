<?php

declare(strict_types=1);

namespace App\Application\OperationsTaxonomy\Targets;

use App\Application\Documents\Retention\RetentionScheduleService;
use App\Application\Import\ImportTarget;
use App\Application\OperationsTaxonomy\OperationsCatalogue;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Gap Closure Pack file 10 retention (PENDING_LEGAL_VALIDATION): legally validated retention periods per class, imported
 * through RetentionScheduleService as DRAFT schedules. Import approval does NOT activate them: each schedule still needs its
 * own maker-checker approval, and a class schedule refuses approval without a legal basis. Nothing is seeded.
 */
final class RetentionScheduleTarget implements ImportTarget
{
    public const TRIGGERS = ['ISSUED_AT', 'CREATED_AT', 'VALID_UNTIL'];

    public function __construct(private readonly RetentionScheduleService $schedules) {}

    public function key(): string
    {
        return 'retention_schedules';
    }

    public function label(): string
    {
        return 'Retention schedules (legally validated periods)';
    }

    public function fields(): array
    {
        return array_merge(array_fill_keys(OperationsCatalogue::fields('retention'), false),
            ['retention_class' => true, 'retention_years' => true, 'legal_basis' => true, 'document_type_code' => false]);
    }

    public function params(array $params): array
    {
        return [];
    }

    public function check(array $row, array $params, array &$seen): array
    {
        $class = trim((string) ($row['retention_class'] ?? ''));
        if (! OperationsCatalogue::has('retention_classes', $class)) {
            return ['status' => 'ERROR', 'error' => "retention_class {$class} is not in the pack's retention classes"];
        }
        if (! ctype_digit((string) ($row['retention_years'] ?? '')) || (int) $row['retention_years'] < 1 || (int) $row['retention_years'] > 200) {
            return ['status' => 'ERROR', 'key' => $class, 'error' => 'retention_years must be 1-200'];
        }
        if (trim((string) ($row['legal_basis'] ?? '')) === '') {
            return ['status' => 'ERROR', 'key' => $class, 'error' => 'legal_basis is required (retention durations are PENDING_LEGAL_VALIDATION)'];
        }
        $trigger = strtoupper(trim((string) ($row['trigger_event'] ?? 'ISSUED_AT'))) ?: 'ISSUED_AT';
        if (! in_array($trigger, self::TRIGGERS, true)) {
            return ['status' => 'ERROR', 'key' => $class, 'error' => 'trigger_event must be one of '.implode(', ', self::TRIGGERS)];
        }
        $key = $class.'|'.($row['document_type_code'] ?? '');
        if ($hit = DB::table('retention_schedules')->where('retention_class', $class)->whereIn('status', ['DRAFT', 'ACTIVE'])
            ->where(fn ($q) => empty($row['document_type_code']) ? $q->whereNull('document_type_code') : $q->where('document_type_code', $row['document_type_code']))->value('id')) {
            return ['status' => 'DUPLICATE', 'key' => $key, 'matches' => $hit];
        }
        if (isset($seen[$key])) {
            return ['status' => 'ERROR', 'key' => $key, 'error' => 'retention class repeated in the file'];
        }
        $seen[$key] = true;

        return ['status' => 'NEW', 'key' => $key];
    }

    public function import(array $row, array $params, ?User $actor, string $batchId): string
    {
        $tenant = DB::table('import_batches')->where('id', $batchId)->value('tenant_id');
        $flag = strtolower(trim((string) ($row['legal_hold_override'] ?? 'true')));

        return (string) $this->schedules->draft($tenant, [
            'code' => 'RC_'.$row['retention_class'].(empty($row['document_type_code']) ? '' : '_'.$row['document_type_code']),
            'document_type_code' => ($row['document_type_code'] ?? null) ?: null, 'retention_years' => (int) $row['retention_years'],
            'trigger_event' => strtoupper(trim((string) ($row['trigger_event'] ?? ''))) ?: 'ISSUED_AT', 'legal_basis' => $row['legal_basis'],
            'retention_class' => $row['retention_class'], 'legal_hold_override' => ! in_array($flag, ['0', 'false', 'no'], true),
            'destruction_method' => ($row['destruction_method'] ?? null) ?: null,
            'effective_from' => ($row['effective_from'] ?? null) ?: null, 'effective_until' => ($row['effective_until'] ?? null) ?: null, 'source' => 'IMPORT:'.$batchId,
        ], $actor)->id;
    }

    public function finish(array $params): void {}
}
