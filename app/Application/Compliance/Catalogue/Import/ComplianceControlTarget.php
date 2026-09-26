<?php

declare(strict_types=1);

namespace App\Application\Compliance\Catalogue\Import;

use App\Application\DataReadiness\DataStatus;
use App\Application\Import\ImportTarget;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Pack 09 AML / ICT (CIMA Reg. 010-24) control records. Fills the requirement of a seeded control code whose text is still
 * pending, or adds a new control code. A control that is already VERIFIED is a DUPLICATE (never overwritten).
 * source_reference and requirement_summary are mandatory: that is what lifts the assessment gate.
 */
final class ComplianceControlTarget implements ImportTarget
{
    public function key(): string
    {
        return 'compliance_controls';
    }

    public function label(): string
    {
        return 'Compliance controls (AML / ICT 010-24)';
    }

    public function fields(): array
    {
        return ['framework' => true, 'control_code' => true, 'domain' => false, 'requirement_summary' => true, 'owner_role' => false, 'frequency' => false,
            'evidence_types' => false, 'automation_level' => false, 'kpi' => false, 'kri' => false, 'test_procedure' => false, 'remediation_sla' => false,
            'failure_severity' => false, 'source_reference' => true, 'effective_from' => false];
    }

    public function params(array $params): array
    {
        return [];
    }

    public function check(array $row, array $params, array &$seen): array
    {
        $fw = strtoupper((string) ($row['framework'] ?? ''));
        $code = strtoupper(trim((string) ($row['control_code'] ?? '')));
        if (! in_array($fw, ['AML', 'ICT'], true) || ! preg_match('/^[A-Z0-9_]+$/', $code)) {
            return ['status' => 'ERROR', 'error' => 'framework (AML|ICT) and control_code (A-Z0-9_) are required'];
        }
        if (blank($row['requirement_summary'] ?? null) || blank($row['source_reference'] ?? null)) {
            return ['status' => 'ERROR', 'error' => 'requirement_summary and source_reference are required'];
        }
        $key = "$fw.$code";
        if (DB::table('compliance_controls')->where(['framework' => $fw, 'control_code' => $code, 'data_status' => DataStatus::VERIFIED])->exists()) {
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
        $fw = strtoupper($row['framework']);
        $code = strtoupper(trim($row['control_code']));
        $ev = array_values(array_filter(array_map('trim', explode('|', (string) ($row['evidence_types'] ?? '')))));
        $vals = ['domain' => $row['domain'] ?? $code, 'requirement_summary' => $row['requirement_summary'], 'owner_role' => $row['owner_role'] ?? null,
            'frequency' => $row['frequency'] ?? null, 'evidence_types' => json_encode($ev), 'automation_level' => $row['automation_level'] ?? null,
            'kpi' => $row['kpi'] ?? null, 'kri' => $row['kri'] ?? null, 'test_procedure' => $row['test_procedure'] ?? null, 'remediation_sla' => $row['remediation_sla'] ?? null,
            'failure_severity' => $row['failure_severity'] ?? null, 'source_reference' => $row['source_reference'],
            'effective_from' => filled($row['effective_from'] ?? null) ? date('Y-m-d', strtotime($row['effective_from'])) : null,
            'data_status' => DataStatus::VERIFIED, 'import_batch_id' => $batchId, 'updated_at' => now()];
        $existing = DB::table('compliance_controls')->where(['framework' => $fw, 'control_code' => $code])->value('id');
        if ($existing) {
            DB::table('compliance_controls')->where('id', $existing)->update($vals);

            return $existing;
        }
        $id = (string) Str::uuid();
        DB::table('compliance_controls')->insert($vals + ['id' => $id, 'framework' => $fw, 'control_code' => $code, 'source' => 'OFFICIAL_IMPORT', 'created_at' => now()]);

        return $id;
    }

    public function finish(array $params): void {}
}
