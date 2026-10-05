<?php

declare(strict_types=1);

namespace App\Application\Partners\Onboarding;

use App\Application\Audit\AuditWriter;
use App\Application\Tenancy\TenantLifecycleService;
use App\Models\Tenant;
use App\Models\TenantBranch;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * Tenant + head-office creation shared by partner-application and organisation-claim approvals. The tenant is
 * activated through TenantLifecycleService (status history + audit), like the S9 bulk onboarding.
 */
final class OrganisationProvisioner
{
    public function __construct(private readonly AuditWriter $audit, private readonly TenantLifecycleService $lifecycle) {}

    /** @param array{legal_name: string, trade_name?: ?string, rccm?: ?string, niu?: ?string, city?: ?string, address?: ?string, locale?: ?string} $n */
    public function tenant(string $type, array $n, array $source, string $reason, User $actor): Tenant
    {
        $base = Str::limit(Str::slug(($n['trade_name'] ?? null) ?: $n['legal_name']) ?: strtolower($type), 70, '');
        $slug = $base;
        for ($i = 2; Tenant::withTrashed()->where('slug', $slug)->exists(); $i++) {
            $slug = $base.'-'.$i;
        }
        $tenant = Tenant::create(['type' => $type, 'legal_name' => $n['legal_name'], 'trade_name' => $n['trade_name'] ?? null, 'slug' => $slug,
            'registration_number' => $n['rccm'] ?? null, 'tax_number' => $n['niu'] ?? null, 'status' => 'PENDING', 'country_code' => 'CM', 'currency' => 'XAF',
            'primary_locale' => $n['locale'] ?? 'fr', 'timezone' => 'Africa/Douala',
            'settings' => array_filter(['city' => $n['city'] ?? null, 'address' => $n['address'] ?? null]) + ['onboarding' => $source]]);
        $this->audit->record('tenant.created', 'tenant', $tenant->id, ['type' => $type] + $source);

        return $this->lifecycle->transition($tenant, 'ACTIVE', strtoupper((string) ($source['source'] ?? 'ONBOARDING')), $reason, $actor);
    }

    /** @param array{city?: ?string, address?: ?string, locale?: ?string, org_phone?: ?string, org_email?: ?string} $n */
    public function headOffice(Tenant $tenant, array $n, string $source): TenantBranch
    {
        $branch = TenantBranch::create(['tenant_id' => $tenant->id, 'code' => 'HQ', 'name' => ($n['locale'] ?? 'fr') === 'fr' ? 'Siège' : 'Head office', 'status' => 'ACTIVE',
            'timezone' => 'Africa/Douala', 'phone_e164' => $n['org_phone'] ?? null, 'email' => $n['org_email'] ?? null,
            'address' => array_filter(['city' => $n['city'] ?? null, 'line1' => $n['address'] ?? null, 'country' => 'CM'])]);
        $this->audit->record('tenant.branch.created', 'tenant_branch', $branch->id, ['source' => $source]);

        return $branch;
    }
}
