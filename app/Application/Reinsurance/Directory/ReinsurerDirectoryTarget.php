<?php

declare(strict_types=1);

namespace App\Application\Reinsurance\Directory;

use App\Application\Import\ImportTarget;
use App\Application\Reinsurance\TreatyService;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gap Closure Pack v1 (07) — reinsurer / reinsurance-broker directory import through the generic ImportPipeline
 * (status PENDING_CIMA_OR_TENANT_APPROVED_SOURCE / PENDING_PRIVATE_OR_REGULATORY_SOURCE). Rows land in the tenant's
 * canonical `reinsurers` table as PENDING_VERIFICATION: an import never grants approved-security status (that is the
 * separate security decision). Existing codes are duplicates and are never overwritten.
 */
final class ReinsurerDirectoryTarget implements ImportTarget
{
    public function __construct(private readonly TreatyService $treaties) {}

    public function key(): string
    {
        return 'reinsurers';
    }

    public function label(): string
    {
        return 'Reinsurer / reinsurance broker directory';
    }

    public function fields(): array
    {
        return ['code' => true, 'legal_name' => true, 'role' => false, 'jurisdiction' => false, 'regulator' => false, 'license_reference' => false,
            'rating_agency' => false, 'rating' => false, 'website' => false, 'contact_email' => false, 'contact_phone' => false,
            'effective_from' => false, 'effective_until' => false, 'source_url' => false];
    }

    public function params(array $params): array
    {
        $tenantId = (string) ($params['tenant_id'] ?? '');
        if ($tenantId === '') {
            try {
                $tenantId = app(TenantContext::class)->id();
            } catch (\LogicException) {
                $tenantId = '';
            }
        }
        if ($tenantId === '' || ! DB::table('tenants')->where('id', $tenantId)->exists()) {
            throw ValidationException::withMessages(['tenant_id' => 'A reinsurer directory import belongs to one tenant.']);
        }

        return ['tenant_id' => $tenantId];
    }

    public function check(array $row, array $params, array &$seen): array
    {
        $code = strtoupper(trim((string) ($row['code'] ?? '')));
        $role = strtoupper(trim((string) ($row['role'] ?? 'REINSURER'))) ?: 'REINSURER';
        if (! preg_match('/^[A-Z0-9_\-]{1,64}$/', $code) || trim((string) ($row['legal_name'] ?? '')) === '') {
            return ['status' => 'ERROR', 'error' => 'code (A-Z0-9_-) and legal_name are required'];
        }
        if (! in_array($role, TreatyService::REINSURER_ROLES, true)) {
            return ['status' => 'ERROR', 'error' => "role must be one of ".implode(', ', TreatyService::REINSURER_ROLES)];
        }
        $jur = trim((string) ($row['jurisdiction'] ?? ''));
        if ($jur !== '' && ! preg_match('/^[A-Za-z]{2}$/', $jur)) {
            return ['status' => 'ERROR', 'error' => 'jurisdiction must be an ISO 3166 alpha-2 code'];
        }
        foreach (['website', 'source_url'] as $u) {
            if (! empty($row[$u]) && ! filter_var($row[$u], FILTER_VALIDATE_URL)) {
                return ['status' => 'ERROR', 'error' => "$u must be a URL"];
            }
        }
        if (DB::table('reinsurers')->where('tenant_id', $params['tenant_id'])->where('code', $code)->exists()) {
            return ['status' => 'DUPLICATE', 'key' => $code, 'matches' => $code];
        }
        if (isset($seen[$code])) {
            return ['status' => 'ERROR', 'error' => "code $code repeated in the file"];
        }
        $seen[$code] = true;

        return ['status' => 'NEW', 'key' => $code];
    }

    public function import(array $row, array $params, ?User $actor, string $batchId): string
    {
        $contact = array_filter(['email' => $row['contact_email'] ?? null, 'phone' => $row['contact_phone'] ?? null]);
        $r = $this->treaties->createReinsurer($params['tenant_id'], [
            'code' => strtoupper(trim((string) $row['code'])), 'name' => trim((string) $row['legal_name']), 'role' => strtoupper(trim((string) ($row['role'] ?? ''))) ?: 'REINSURER',
            'country_code' => ! empty($row['jurisdiction']) ? strtoupper((string) $row['jurisdiction']) : null,
            'rating' => $row['rating'] ?? null, 'rating_agency' => $row['rating_agency'] ?? null, 'regulator' => $row['regulator'] ?? null,
            'license_reference' => $row['license_reference'] ?? null, 'website' => $row['website'] ?? null, 'contact' => $contact,
            'effective_from' => $row['effective_from'] ?? null, 'effective_until' => $row['effective_until'] ?? null, 'source_url' => $row['source_url'] ?? null,
            'data_source' => 'IMPORT:'.$batchId,
        ]);

        return (string) $r['id'];
    }

    public function finish(array $params): void {}
}
