<?php

declare(strict_types=1);

namespace App\Application\Compliance\Catalogue\Import;

use App\Application\Compliance\Catalogue\ComplianceCatalogueService;
use App\Application\DataReadiness\DataStatus;
use App\Application\Import\ImportTarget;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Pack 08 country_risk (CONFIG_REQUIRED): imported through the generic pipeline, approved by a checker -> VERIFIED. Source is mandatory. */
final class CountryRiskRatingTarget implements ImportTarget
{
    public function key(): string
    {
        return 'country_risk_ratings';
    }

    public function label(): string
    {
        return 'Country risk ratings (AML/CFT)';
    }

    public function fields(): array
    {
        return ['country_code' => true, 'risk_level' => true, 'basis' => false, 'sanctions_status' => false, 'fatf_status' => false,
            'internal_notes' => false, 'effective_from' => true, 'effective_until' => false, 'source' => true];
    }

    public function params(array $params): array
    {
        return ['tenant_id' => $params['tenant_id'] ?? null];
    }

    public function check(array $row, array $params, array &$seen): array
    {
        $cc = strtoupper(trim((string) ($row['country_code'] ?? '')));
        if (! preg_match('/^[A-Z]{2}$/', $cc)) {
            return ['status' => 'ERROR', 'error' => 'country_code must be ISO 3166-1 alpha-2'];
        }
        if (! in_array(ComplianceCatalogueService::level((string) ($row['risk_level'] ?? '')), ComplianceCatalogueService::RISK_LEVELS, true)) {
            return ['status' => 'ERROR', 'error' => 'risk_level must be one of '.implode(', ', ComplianceCatalogueService::RISK_LEVELS)];
        }
        if (blank($row['source'] ?? null) || strtotime((string) ($row['effective_from'] ?? '')) === false) {
            return ['status' => 'ERROR', 'error' => 'source and a valid effective_from are required'];
        }
        $from = date('Y-m-d', strtotime((string) $row['effective_from']));
        $key = $cc.'@'.$from;
        $tenant = $params['tenant_id'] ?? null;
        if (DB::table('country_risk_ratings')->where('country_code', $cc)->whereDate('effective_from', $from)
            ->where(fn ($q) => $tenant ? $q->where('tenant_id', $tenant) : $q->whereNull('tenant_id'))->exists()) {
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
        $id = (string) Str::uuid();
        DB::table('country_risk_ratings')->insert(['id' => $id, 'tenant_id' => $params['tenant_id'] ?? null, 'country_code' => strtoupper(trim($row['country_code'])),
            'risk_level' => ComplianceCatalogueService::level($row['risk_level']), 'basis' => $row['basis'] ?? null, 'sanctions_status' => $row['sanctions_status'] ?? null,
            'fatf_status' => $row['fatf_status'] ?? null, 'internal_notes' => $row['internal_notes'] ?? null, 'effective_from' => date('Y-m-d', strtotime($row['effective_from'])),
            'effective_until' => filled($row['effective_until'] ?? null) ? date('Y-m-d', strtotime($row['effective_until'])) : null, 'source' => $row['source'],
            'data_status' => DataStatus::VERIFIED, 'import_batch_id' => $batchId, 'created_at' => now(), 'updated_at' => now()]);

        return $id;
    }

    public function finish(array $params): void {}
}
