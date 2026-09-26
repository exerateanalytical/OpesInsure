<?php

declare(strict_types=1);

namespace App\Application\Compliance\Catalogue\Import;

use App\Application\DataReadiness\DataStatus;
use App\Application\Import\ImportTarget;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pack 09 regulatory_reporting_dictionary (PENDING_FORM_BY_FORM_OFFICIAL_IMPORT): one row per report line, imported from the
 * official form. source_document_id is mandatory. Approved lines are VERIFIED; list columns (filters, validation_rules,
 * signatory_roles) accept JSON or | separated values.
 */
final class RegulatoryDictionaryLineTarget implements ImportTarget
{
    public const PERIODICITIES = ['MONTHLY', 'QUARTERLY', 'SEMI_ANNUAL', 'ANNUAL', 'EVENT_DRIVEN', 'AD_HOC'];

    public function key(): string
    {
        return 'regulatory_report_dictionary_lines';
    }

    public function label(): string
    {
        return 'Regulatory report dictionary lines';
    }

    public function fields(): array
    {
        return ['report_id' => false, 'regulator' => true, 'report_code' => true, 'report_name' => true, 'periodicity' => true,
            'submission_deadline_rule' => false, 'line_code' => true, 'line_label' => true, 'data_type' => true, 'currency' => false, 'formula' => false,
            'source_entity' => false, 'source_field' => false, 'filters' => false, 'validation_rules' => false, 'signatory_roles' => false,
            'effective_from' => true, 'effective_until' => false, 'source_document_id' => true];
    }

    public function params(array $params): array
    {
        return [];
    }

    public function check(array $row, array $params, array &$seen): array
    {
        foreach (['regulator', 'report_code', 'report_name', 'line_code', 'line_label', 'data_type', 'source_document_id'] as $f) {
            if (blank($row[$f] ?? null)) {
                return ['status' => 'ERROR', 'error' => "$f is required"];
            }
        }
        if (! in_array(strtoupper((string) ($row['periodicity'] ?? '')), self::PERIODICITIES, true)) {
            return ['status' => 'ERROR', 'error' => 'periodicity must be one of '.implode(', ', self::PERIODICITIES)];
        }
        if (strtotime((string) ($row['effective_from'] ?? '')) === false) {
            return ['status' => 'ERROR', 'error' => 'effective_from must be a date'];
        }
        $from = date('Y-m-d', strtotime((string) $row['effective_from']));
        $key = $row['report_code'].':'.$row['line_code'].'@'.$from;
        if (DB::table('regulatory_report_dictionary_lines')->where(['report_code' => $row['report_code'], 'line_code' => $row['line_code']])
            ->whereDate('effective_from', $from)->exists()) {
            return ['status' => 'DUPLICATE', 'key' => $key, 'matches' => $key];
        }
        if (isset($seen[$key])) {
            return ['status' => 'ERROR', 'error' => "$key repeated in the file"];
        }
        $seen[$key] = true;

        return ['status' => 'NEW', 'key' => $key];
    }

    public function import(array $row, array $params, ?User $actor, string $batchId): string
    {
        $list = function ($v, bool $assoc = false): string {
            if (is_array($v)) {
                return json_encode($v);
            }
            $v = trim((string) $v);
            if ($v === '') {
                return $assoc ? '{}' : '[]';
            }
            $j = json_decode($v, true);

            return json_encode(is_array($j) ? $j : array_values(array_filter(array_map('trim', explode('|', $v)))));
        };
        $id = (string) Str::uuid();
        DB::table('regulatory_report_dictionary_lines')->insert(['id' => $id, 'report_id' => $row['report_id'] ?? null, 'regulator' => $row['regulator'],
            'report_code' => $row['report_code'], 'report_name' => $row['report_name'], 'periodicity' => strtoupper($row['periodicity']),
            'submission_deadline_rule' => $row['submission_deadline_rule'] ?? null, 'line_code' => $row['line_code'], 'line_label' => $row['line_label'],
            'data_type' => $row['data_type'], 'currency' => $row['currency'] ?? null, 'formula' => $row['formula'] ?? null, 'source_entity' => $row['source_entity'] ?? null,
            'source_field' => $row['source_field'] ?? null, 'filters' => $list($row['filters'] ?? null, true), 'validation_rules' => $list($row['validation_rules'] ?? null),
            'signatory_roles' => $list($row['signatory_roles'] ?? null), 'effective_from' => date('Y-m-d', strtotime($row['effective_from'])),
            'effective_until' => filled($row['effective_until'] ?? null) ? date('Y-m-d', strtotime($row['effective_until'])) : null,
            'source_document_id' => $row['source_document_id'], 'data_status' => DataStatus::VERIFIED, 'import_batch_id' => $batchId, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    public function finish(array $params): void {}
}
