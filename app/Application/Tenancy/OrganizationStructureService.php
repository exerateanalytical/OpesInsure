<?php

declare(strict_types=1);

namespace App\Application\Tenancy;

use App\Application\Audit\AuditWriter;
use App\Application\Settings\TimezoneCatalogue;
use App\Application\Temporal\TimezoneResolver;
use App\Models\TenantBranch;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REQ-TEN-001 / REQ-TEN-002 / REQ-TMP-003 — tenant kinds, branch hierarchy
 * and capabilities, departments, and tenant/branch timezone settings.
 * Organizations remain the existing tenants (+ partners/carriers); no new
 * organizations table (TRACEABILITY §3: tenants is canonical).
 */
final class OrganizationStructureService
{
    /** SCF tenant kinds (REQ-TEN-001). */
    public const TENANT_TYPES = ['PLATFORM', 'INSURER', 'BROKER', 'REINSURER', 'PROVIDER_NETWORK', 'CORPORATE'];

    /** Still accepted by the DB constraint for existing rows: CARRIER = INSURER, AGENCY = old admin form value. */
    public const LEGACY_TENANT_TYPES = ['CARRIER', 'AGENCY'];

    /** @return array<string, string> */
    public static function tenantTypeOptions(): array
    {
        return ['PLATFORM' => 'Platform', 'CARRIER' => 'Insurer (carrier)', 'INSURER' => 'Insurer', 'BROKER' => 'Broker', 'REINSURER' => 'Reinsurer', 'PROVIDER_NETWORK' => 'Provider network', 'CORPORATE' => 'Corporate client'];
    }

    /** Operational capabilities a branch may be granted (SCF §31). */
    public const BRANCH_CAPABILITIES = ['SALES', 'UNDERWRITING', 'ISSUANCE', 'CLAIMS', 'CASH_COLLECTION', 'STICKER_CUSTODY', 'CUSTOMER_SERVICE'];

    private const MAX_DEPTH = 8;

    public function __construct(private readonly AuditWriter $audit, private readonly TimezoneCatalogue $timezones, private readonly TimezoneResolver $resolver)
    {
    }

    public static function isTenantType(string $type): bool
    {
        return in_array($type, self::TENANT_TYPES, true) || in_array($type, self::LEGACY_TENANT_TYPES, true);
    }

    public function setTenantTimezone(string $tenantId, ?string $timezone, ?string $actorId): void
    {
        $this->assertTimezone($timezone);
        $previous = DB::table('tenants')->where('id', $tenantId)->value('timezone');
        DB::table('tenants')->where('id', $tenantId)->update(['timezone' => $timezone, 'updated_at' => now()]);
        $this->resolver->forget();
        $this->audit->record('tenant.timezone.changed', 'tenant', $tenantId, ['from' => $previous, 'to' => $timezone]);
    }

    /** @param array<string, mixed> $data parent_branch_id, capabilities, timezone */
    public function updateBranch(TenantBranch $branch, array $data): TenantBranch
    {
        $changes = [];
        if (array_key_exists('timezone', $data)) {
            $this->assertTimezone($data['timezone']);
            $changes['timezone'] = $data['timezone'];
        }
        if (array_key_exists('parent_branch_id', $data)) {
            $this->assertParent($branch, $data['parent_branch_id']);
            $changes['parent_branch_id'] = $data['parent_branch_id'];
        }
        if (array_key_exists('capabilities', $data)) {
            $caps = array_values(array_unique((array) $data['capabilities']));
            if (array_diff($caps, self::BRANCH_CAPABILITIES) !== []) {
                throw ValidationException::withMessages(['capabilities' => 'Unknown branch capability.']);
            }
            $changes['capabilities'] = json_encode($caps, JSON_THROW_ON_ERROR);
        }
        foreach (['name', 'status', 'phone_e164', 'email', 'manager_user_id'] as $f) {
            if (array_key_exists($f, $data)) {
                $changes[$f] = $data[$f];
            }
        }
        if ($changes === []) {
            return $branch;
        }
        $before = DB::table('tenant_branches')->where('id', $branch->id)->first();
        DB::table('tenant_branches')->where('id', $branch->id)->update([...$changes, 'updated_at' => now()]);
        $this->resolver->forget();
        $this->audit->record('tenant.branch.updated', 'tenant_branch', $branch->id, ['before' => array_intersect_key((array) $before, $changes), 'after' => $changes]);

        return $branch->refresh();
    }

    /** @return list<array<string, mixed>> nested branch tree for a tenant */
    public function tree(string $tenantId): array
    {
        $rows = DB::table('tenant_branches')->where('tenant_id', $tenantId)->whereNull('deleted_at')->orderBy('code')
            ->get(['id', 'code', 'name', 'status', 'parent_branch_id', 'timezone', 'capabilities', 'manager_user_id']);
        $byParent = [];
        foreach ($rows as $r) {
            $byParent[$r->parent_branch_id ?? ''][] = $r;
        }
        $build = function (string $parent) use (&$build, $byParent, $tenantId): array {
            $out = [];
            foreach ($byParent[$parent] ?? [] as $r) {
                $out[] = [
                    'id' => $r->id, 'code' => $r->code, 'name' => $r->name, 'status' => $r->status,
                    'timezone' => $r->timezone, 'effective_timezone' => $this->resolver->forBranch($r->id, $tenantId),
                    'capabilities' => json_decode((string) $r->capabilities, true) ?: [],
                    'manager_user_id' => $r->manager_user_id,
                    'departments' => DB::table('tenant_departments')->where('branch_id', $r->id)->orderBy('code')->get(['id', 'code', 'name', 'status', 'queue_code'])->all(),
                    'children' => $build($r->id),
                ];
            }

            return $out;
        };

        return $build('');
    }

    /** @param array<string, mixed> $data */
    public function createDepartment(string $tenantId, array $data): object
    {
        if (! empty($data['branch_id']) && DB::table('tenant_branches')->where(['id' => $data['branch_id'], 'tenant_id' => $tenantId])->doesntExist()) {
            throw ValidationException::withMessages(['branch_id' => 'Branch does not belong to this tenant.']);
        }
        if (DB::table('tenant_departments')->where(['tenant_id' => $tenantId, 'code' => $data['code']])->exists()) {
            throw ValidationException::withMessages(['code' => 'Department code already used in this tenant.']);
        }
        $id = (string) Str::uuid();
        DB::table('tenant_departments')->insert([
            'id' => $id, 'tenant_id' => $tenantId, 'branch_id' => $data['branch_id'] ?? null, 'code' => $data['code'], 'name' => $data['name'],
            'status' => 'ACTIVE', 'manager_user_id' => $data['manager_user_id'] ?? null, 'queue_code' => $data['queue_code'] ?? null,
            'limits' => json_encode($data['limits'] ?? (object) [], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->audit->record('tenant.department.created', 'tenant_department', $id, ['code' => $data['code'], 'branch_id' => $data['branch_id'] ?? null]);

        return DB::table('tenant_departments')->where('id', $id)->first();
    }

    private function assertTimezone(mixed $timezone): void
    {
        if ($timezone !== null && ! $this->timezones->isValid($timezone)) {
            throw ValidationException::withMessages(['timezone' => 'Timezone must be an IANA identifier from master data geography.']);
        }
    }

    private function assertParent(TenantBranch $branch, ?string $parentId): void
    {
        if ($parentId === null) {
            return;
        }
        $cursor = $parentId;
        for ($depth = 0; $cursor !== null; $depth++) {
            if ($cursor === $branch->id || $depth >= self::MAX_DEPTH) {
                throw ValidationException::withMessages(['parent_branch_id' => 'Branch hierarchy would form a cycle or exceed '.self::MAX_DEPTH.' levels.']);
            }
            $row = DB::table('tenant_branches')->where('id', $cursor)->first(['tenant_id', 'parent_branch_id']);
            if ($row === null || $row->tenant_id !== $branch->tenant_id) {
                throw ValidationException::withMessages(['parent_branch_id' => 'Parent branch must belong to the same tenant.']);
            }
            $cursor = $row->parent_branch_id;
        }
    }
}
