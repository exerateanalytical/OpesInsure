<?php

declare(strict_types=1);

namespace App\Application\Finance\ReferenceMasters;

use App\Application\Import\ImportTarget;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Agent GP6 — official import of the banks / payment institutions master through the generic ImportPipeline (upload, mapping,
 * maker-checker approval, audit). A row matching a seeded PENDING_OFFICIAL_IMPORT placeholder (same code, never admin-edited)
 * completes that placeholder; any other existing code or BIC is a DUPLICATE and is never overwritten.
 */
final class FinancialInstitutionImportTarget implements ImportTarget
{
    public function key(): string
    {
        return 'financial_institutions';
    }

    public function label(): string
    {
        return 'Banks and payment institutions';
    }

    public function fields(): array
    {
        return ['institution_type' => true, 'legal_name' => true, 'trade_name' => false, 'bank_code' => false, 'bic_swift' => false, 'head_office_city' => false,
            'website' => false, 'source_url' => true, 'verification_status' => true, 'effective_from' => false];
    }

    public function params(array $params): array
    {
        return [];
    }

    public function check(array $row, array $params, array &$seen): array
    {
        $type = strtoupper((string) ($row['institution_type'] ?? ''));
        $status = strtoupper((string) ($row['verification_status'] ?? ''));
        if (! in_array($type, FinanceReferenceCatalogue::INSTITUTION_TYPES, true) || empty($row['legal_name']) || empty($row['source_url'])
            || ! in_array($status, FinanceReferenceCatalogue::GAP_STATUSES, true)) {
            return ['status' => 'ERROR', 'error' => 'institution_type, legal_name, source_url and a gap-pack verification_status are required'];
        }
        $bic = strtoupper(trim((string) ($row['bic_swift'] ?? '')));
        if ($bic !== '' && ! preg_match('/^[A-Z]{6}[A-Z0-9]{2}([A-Z0-9]{3})?$/', $bic)) {
            return ['status' => 'ERROR', 'error' => 'bic_swift must be ISO 9362 (8 or 11 characters)'];
        }
        $code = FinanceReferenceCatalogue::institutionCode($type, (string) $row['legal_name']);
        if (isset($seen[$code])) {
            return ['status' => 'ERROR', 'error' => "institution $code repeated in the file"];
        }
        $seen[$code] = true;
        if ($bic !== '' && DB::table('financial_institutions')->where('bic_swift', $bic)->exists()) {
            return ['status' => 'DUPLICATE', 'key' => $code, 'matches' => $bic];
        }
        $existing = DB::table('financial_institutions')->where('code', $code)->first();
        if ($existing && ! $this->isPlaceholder($existing)) {
            return ['status' => 'DUPLICATE', 'key' => $code, 'matches' => $existing->code];
        }

        return ['status' => 'NEW', 'key' => $code];
    }

    public function import(array $row, array $params, ?User $actor, string $batchId): string
    {
        $type = strtoupper((string) $row['institution_type']);
        $code = FinanceReferenceCatalogue::institutionCode($type, (string) $row['legal_name']);
        $row['bic_swift'] = ($b = strtoupper(trim((string) ($row['bic_swift'] ?? '')))) !== '' ? $b : null;
        $existing = DB::table('financial_institutions')->where('code', $code)->first();
        if ($existing) {
            if (! $this->isPlaceholder($existing)) {
                throw ValidationException::withMessages(['code' => "Institution $code already exists."]);
            }
            $fill = collect(['trade_name', 'bank_code', 'bic_swift', 'head_office_city', 'website', 'effective_from'])
                ->mapWithKeys(fn ($f) => [$f => $existing->{$f} ?? ($row[$f] ?? null)])->all();
            DB::table('financial_institutions')->where('id', $existing->id)->update($fill + ['source' => 'OFFICIAL_IMPORT', 'source_url' => $row['source_url'],
                'verification_status' => strtoupper((string) $row['verification_status']), 'dataset_version' => 'import:'.substr($batchId, 0, 8), 'updated_at' => now()]);

            return $existing->id;
        }

        return app(FinanceReferenceService::class)->importInstitution(['code' => $code] + $row);
    }

    public function finish(array $params): void {}

    private function isPlaceholder(object $r): bool
    {
        return $r->admin_edited_at === null && $r->source === FinanceReferenceCatalogue::SOURCE
            && ! in_array($r->verification_status, FinanceReferenceCatalogue::PRODUCTION_STATUSES, true);
    }
}
