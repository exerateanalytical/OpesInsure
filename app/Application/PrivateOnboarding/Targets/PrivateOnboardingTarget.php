<?php

declare(strict_types=1);

namespace App\Application\PrivateOnboarding\Targets;

use App\Application\Import\ImportTarget;
use App\Application\PrivateOnboarding\PrivateOnboardingTemplates;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Gap Closure Pack file 11 — one private onboarding dataset (params: dataset, organization_type, organization_id).
 * Fields are the pack's required_import_fields; the dataset key field and effective_from (when the dataset has one) are
 * mandatory. Rows are staged in tenant_onboarding_records (PENDING_PRIVATE_SOURCE, RECEIVED) — never written into the
 * live domain tables and never enabling a workflow. Duplicate = same dataset/organization/key already staged.
 */
final class PrivateOnboardingTarget implements ImportTarget
{
    public function key(): string
    {
        return 'private_onboarding';
    }

    public function label(): string
    {
        return 'Private onboarding dataset (insurer / broker / tenant)';
    }

    /** Union of all dataset fields; the per-dataset required set is enforced in check(). */
    public function fields(): array
    {
        $out = [];
        foreach (PrivateOnboardingTemplates::datasets() as $code => $d) {
            foreach ($d['required_import_fields'] as $f) {
                $out[$f] ??= false;
            }
        }

        return $out;
    }

    public function params(array $params): array
    {
        $dataset = strtoupper((string) ($params['dataset'] ?? ''));
        PrivateOnboardingTemplates::dataset($dataset);
        $type = strtoupper((string) ($params['organization_type'] ?? ''));
        $allowed = PrivateOnboardingTemplates::WIRING[$dataset][0] ?? PrivateOnboardingTemplates::ORGANIZATION_TYPES;
        if (! in_array($type, $allowed, true)) {
            throw ValidationException::withMessages(['organization_type' => "{$dataset} applies to: ".implode(', ', $allowed).'.']);
        }
        $orgId = $params['organization_id'] ?? null;
        if ($type !== 'TENANT') {
            $table = $type === 'INSURER' ? 'carriers' : 'partners';
            if (! is_string($orgId) || ! Str::isUuid($orgId) || ! DB::table($table)->where('id', $orgId)->exists()) {
                throw ValidationException::withMessages(['organization_id' => "organization_id must be an existing {$table} id."]);
            }
        }

        return ['dataset' => $dataset, 'organization_type' => $type, 'organization_id' => $type === 'TENANT' ? null : $orgId];
    }

    public function check(array $row, array $params, array &$seen): array
    {
        $fields = PrivateOnboardingTemplates::dataset($params['dataset'])['required_import_fields'];
        $keyField = PrivateOnboardingTemplates::keyField($params['dataset']);
        $key = trim((string) ($row[$keyField] ?? ''));
        if ($key === '') {
            return ['status' => 'ERROR', 'error' => "{$keyField} is required"];
        }
        foreach (['effective_from', 'period_start'] as $dateField) {
            if (in_array($dateField, $fields, true)) {
                $v = trim((string) ($row[$dateField] ?? ''));
                if ($v === '' || strtotime($v) === false) {
                    return ['status' => 'ERROR', 'key' => $key, 'error' => "{$dateField} (date) is required"];
                }
            }
        }
        if ($params['dataset'] === 'SIGNATORY_MANDATES' && trim((string) ($row['source_mandate_document_id'] ?? '')) === '') {
            return ['status' => 'ERROR', 'key' => $key, 'error' => 'source_mandate_document_id is required (no signature authority without mandate evidence)'];
        }
        $exists = DB::table('tenant_onboarding_records')->where(['dataset' => $params['dataset'], 'organization_type' => $params['organization_type'], 'record_key' => $key])
            ->where(fn ($q) => $params['organization_id'] ? $q->where('organization_id', $params['organization_id']) : $q->whereNull('organization_id'))
            ->where('review_status', '<>', 'REJECTED')->value('id');
        if ($exists) {
            return ['status' => 'DUPLICATE', 'key' => $key, 'matches' => $exists];
        }
        if (isset($seen[$key])) {
            return ['status' => 'ERROR', 'key' => $key, 'error' => "{$keyField} {$key} repeated in the file"];
        }
        $seen[$key] = true;

        return ['status' => 'NEW', 'key' => $key];
    }

    public function import(array $row, array $params, ?User $actor, string $batchId): string
    {
        $fields = PrivateOnboardingTemplates::dataset($params['dataset'])['required_import_fields'];
        $payload = array_intersect_key($row, array_flip($fields));
        $id = (string) Str::uuid();
        $from = $row['effective_from'] ?? $row['period_start'] ?? null;
        $until = $row['effective_until'] ?? $row['period_end'] ?? null;
        DB::table('tenant_onboarding_records')->insert([
            'id' => $id, 'tenant_id' => DB::table('import_batches')->where('id', $batchId)->value('tenant_id'),
            'dataset' => $params['dataset'], 'organization_type' => $params['organization_type'], 'organization_id' => $params['organization_id'],
            'record_key' => trim((string) $row[PrivateOnboardingTemplates::keyField($params['dataset'])]), 'payload' => json_encode($payload),
            'source_document_id' => $row['source_document_id'] ?? $row['source_mandate_document_id'] ?? $row['wording_document_id'] ?? $row['source_agreement_id'] ?? null,
            'effective_from' => $from ? date('Y-m-d', strtotime((string) $from)) : null, 'effective_until' => $until && strtotime((string) $until) ? date('Y-m-d', strtotime((string) $until)) : null,
            'data_status' => 'PENDING_PRIVATE_SOURCE', 'review_status' => 'RECEIVED', 'import_batch_id' => $batchId,
            'created_by' => DB::table('import_batches')->where('id', $batchId)->value('created_by'), 'source' => PrivateOnboardingTemplates::SOURCE,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return $id;
    }

    public function finish(array $params): void {}
}
