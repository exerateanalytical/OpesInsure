<?php

declare(strict_types=1);

namespace App\Application\Documents\Engine;

use Illuminate\Support\Facades\DB;

/**
 * Continuous, gap-free document numbering per tenant and numbering family
 * (POL-, ATT-MOT-, AVN-, CLM-, RCT-, SET-, CRT-, DOC- …) — the CIMA registry
 * requirement. The counter row is locked FOR UPDATE and incremented inside
 * the caller's transaction, so a rolled-back issuance also rolls back its
 * number (no gaps) and two concurrent issuances can never get the same one
 * (no duplicates; documents also carry UNIQUE(tenant_id, document_number)).
 *
 * Families are tenant-configurable (document_numbering_families): a tenant
 * row overrides the platform row / built-in default prefix, year segment,
 * padding, and may claim extra document types for its family.
 */
final class DocumentNumberAllocator
{
    public function __construct(private DocumentRegister $register) {}

    /** @return array{family: string, number: string, sequence: int, period: string} */
    public function allocate(?string $tenantId, string $documentTypeCode): array
    {
        $family = $this->familyFor($tenantId, $documentTypeCode);
        $config = $this->config($tenantId, $family);
        $period = $config['include_year'] ? now()->format('Y') : 'ALL';
        $scope = $tenantId ?? 'PLATFORM';

        return DB::transaction(function () use ($scope, $family, $period, $config): array {
            DB::statement('INSERT INTO document_number_counters (scope_key, family_code, period, last_value, created_at, updated_at) VALUES (?, ?, ?, 0, now(), now()) ON CONFLICT DO NOTHING', [$scope, $family, $period]);
            $row = DB::table('document_number_counters')->where(['scope_key' => $scope, 'family_code' => $family, 'period' => $period])->lockForUpdate()->first();
            $next = (int) $row->last_value + 1;
            DB::table('document_number_counters')->where(['scope_key' => $scope, 'family_code' => $family, 'period' => $period])->update(['last_value' => $next, 'updated_at' => now()]);

            $number = $config['prefix'].'-'.($config['include_year'] ? $period.'-' : '').str_pad((string) $next, (int) $config['pad'], '0', STR_PAD_LEFT);

            return ['family' => $family, 'number' => $number, 'sequence' => $next, 'period' => $period];
        });
    }

    public function familyFor(?string $tenantId, string $documentTypeCode): string
    {
        $claimed = DB::table('document_numbering_families')->where('status', 'ACTIVE')
            ->where(fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))
            ->whereRaw('document_type_codes @> ?::jsonb', [json_encode([$documentTypeCode])])
            ->orderByRaw('tenant_id IS NULL')->value('family_code');

        return $claimed ?? $this->register->describe($documentTypeCode)['numbering_family'];
    }

    /** @return array{prefix: string, include_year: bool, pad: int} */
    public function config(?string $tenantId, string $family): array
    {
        $row = DB::table('document_numbering_families')->where('family_code', $family)->where('status', 'ACTIVE')
            ->where(fn ($q) => $q->where('tenant_id', $tenantId)->orWhereNull('tenant_id'))
            ->orderByRaw('tenant_id IS NULL')->first();

        return $row ? ['prefix' => $row->prefix, 'include_year' => (bool) $row->include_year, 'pad' => (int) $row->pad] : ['prefix' => $family, 'include_year' => true, 'pad' => 6];
    }
}
