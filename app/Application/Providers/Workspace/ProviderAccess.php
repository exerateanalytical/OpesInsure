<?php

declare(strict_types=1);

namespace App\Application\Providers\Workspace;

use App\Application\Audit\AuditWriter;
use App\Application\Providers\Portal\ProviderScope;
use App\Interfaces\Http\Errors\ApiProblemException;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Provider Portal Gap-Free spec — organisation model + roles + facility scope (REQ-PRV-003).
 *
 * Who acts for a provider stays ProviderScope (party / EMPLOYED_BY). This class adds, on top of it, the portal role
 * and the facility scope of each provider user (provider_users): a head-office user (facility_scope ALL, or no
 * provider_users row = the legacy "employee of the organisation" rule) sees every facility of the provider and of its
 * child providers (parent_provider_id); a facility user (ASSIGNED) sees only the facilities assigned to them.
 *
 * Roles are the canonical provider.provider_user_role codes; the spec role names are accepted as aliases.
 */
final class ProviderAccess
{
    /** Canonical role => whether it may read clinical content (diagnosis, clinical notes, medical documents). */
    public const ROLES = [
        'PROVIDER_ADMIN' => false, 'FRONT_DESK' => false, 'INSURANCE_DESK_OFFICER' => false, 'PRACTITIONER' => true, 'NURSE' => true,
        'PREAUTHORIZATION_OFFICER' => true, 'BILLING_OFFICER' => false, 'CLAIMS_OFFICER' => false, 'FINANCE_OFFICER' => false, 'CASHIER' => false,
        'MEDICAL_RECORDS_OFFICER' => true, 'PROVIDER_COMPLIANCE_OFFICER' => false, 'BRANCH_MANAGER' => false, 'FINANCE_DIRECTOR' => false,
        'EXECUTIVE_VIEWER' => false, 'AUDITOR' => false, 'PHARMACIST' => true, 'LAB_TECHNICIAN' => true,
    ];

    /** Spec role names (Gap-Free spec §roles) => canonical code (same aliases as the master-data list). */
    public const ALIASES = ['PROVIDER_ADMINISTRATOR' => 'PROVIDER_ADMIN', 'RECEPTION_ELIGIBILITY_OFFICER' => 'FRONT_DESK', 'DOCTOR' => 'PRACTITIONER'];

    public function __construct(private readonly AuditWriter $audit) {}

    public static function role(string $code): string
    {
        $c = strtoupper(trim($code));
        $c = self::ALIASES[$c] ?? $c;
        if (! array_key_exists($c, self::ROLES)) {
            throw new ApiProblemException('PROVIDER_ROLE_UNKNOWN', 422, "Unknown provider role {$code}.");
        }

        return $c;
    }

    /** The provider_users row of $user for $providerId (null = legacy employee: organisation-wide). */
    public function membership(User $user, string $providerId): ?object
    {
        return DB::table('provider_users')->where(['provider_profile_id' => $providerId, 'user_id' => $user->id, 'status' => 'ACTIVE'])->first();
    }

    /** Provider ids of the organisation: the provider and its child providers (groups). @return list<string> */
    public function organisation(string $providerId): array
    {
        return [$providerId, ...DB::table('provider_profiles')->where('parent_provider_id', $providerId)->where('credentialing_status', '<>', 'TERMINATED')
            ->orderBy('id')->pluck('id')->map(fn ($v) => (string) $v)->all()];
    }

    /** Facility ids the user may see for the active provider. @return list<string> */
    public function facilityIds(User $user, ProviderScope $s): array
    {
        $m = $this->membership($user, $s->providerId);
        if ($m === null || $m->facility_scope === 'ALL') {
            return DB::table('provider_facilities')->whereIn('provider_profile_id', $this->organisation($s->providerId))->orderBy('code')->pluck('id')->map(fn ($v) => (string) $v)->all();
        }

        return DB::table('provider_user_facilities')->where('provider_user_id', $m->id)->pluck('provider_facility_id')->map(fn ($v) => (string) $v)->all();
    }

    public function hasFullScope(User $user, ProviderScope $s): bool
    {
        $m = $this->membership($user, $s->providerId);

        return $m === null || $m->facility_scope === 'ALL';
    }

    /** A facility the user names must be in scope (404 otherwise — existence is not leaked). */
    public function assertFacility(User $user, ProviderScope $s, ?string $facilityId): void
    {
        if ($facilityId !== null && ! in_array($facilityId, $this->facilityIds($user, $s), true)) {
            throw new ApiProblemException('FACILITY_NOT_FOUND', 404, 'Facility not found.');
        }
    }

    /** Records with a facility are visible when in scope; records without one only to organisation-wide users. */
    public function mayViewFacilityRecord(User $user, ProviderScope $s, ?string $facilityId): bool
    {
        return $facilityId === null ? $this->hasFullScope($user, $s) : in_array($facilityId, $this->facilityIds($user, $s), true);
    }

    /** Clinical content (diagnosis / clinical notes / medical documents) only for clinical roles; legacy employees: never. */
    public function mayReadClinical(User $user, ProviderScope $s): bool
    {
        $m = $this->membership($user, $s->providerId);

        return $m !== null && (self::ROLES[$m->provider_role] ?? false);
    }

    // ----------------------------------------------------------------- user management (provider.users.manage)

    /** @param list<string> $facilityIds */
    public function assign(ProviderScope $s, string $userId, string $role, string $scope, array $facilityIds, User $actor): object
    {
        $role = self::role($role);
        $scope = strtoupper($scope);
        if (! in_array($scope, ['ALL', 'ASSIGNED'], true)) {
            throw new ApiProblemException('FACILITY_SCOPE_INVALID', 422, 'facility_scope is ALL or ASSIGNED.');
        }
        $target = User::find($userId) ?? throw new ApiProblemException('USER_NOT_FOUND', 404, 'User not found.');
        if (! ProviderScope::actsFor($target, $s->providerId)) {
            throw new ApiProblemException('USER_NOT_PROVIDER_STAFF', 422, 'The user must first be linked to the provider (party EMPLOYED_BY relationship).');
        }
        $own = $this->facilityIds($actor, $s);
        foreach ($facilityIds as $f) {
            if (! in_array($f, $own, true)) {
                throw new ApiProblemException('FACILITY_NOT_FOUND', 404, 'Facility not found.');
            }
        }

        return DB::transaction(function () use ($s, $target, $role, $scope, $facilityIds, $actor) {
            $row = DB::table('provider_users')->where(['provider_profile_id' => $s->providerId, 'user_id' => $target->id])->first();
            $id = $row->id ?? (string) Str::uuid();
            $vals = ['provider_role' => $role, 'facility_scope' => $scope, 'status' => 'ACTIVE', 'updated_at' => now()];
            $row ? DB::table('provider_users')->where('id', $id)->update($vals)
                : DB::table('provider_users')->insert($vals + ['id' => $id, 'provider_profile_id' => $s->providerId, 'user_id' => $target->id, 'created_by' => $actor->id, 'created_at' => now()]);
            DB::table('provider_user_facilities')->where('provider_user_id', $id)->delete();
            foreach (array_unique($facilityIds) as $f) {
                DB::table('provider_user_facilities')->insert(['id' => (string) Str::uuid(), 'provider_user_id' => $id, 'provider_facility_id' => $f, 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->audit->record('provider.user.assigned', 'provider_user', $id, ['provider_id' => $s->providerId, 'user_id' => $target->id, 'role' => $role, 'scope' => $scope, 'facilities' => $facilityIds]);

            return $this->user($s, $id);
        });
    }

    public function revoke(ProviderScope $s, string $providerUserId, User $actor): object
    {
        $row = $this->user($s, $providerUserId);
        DB::table('provider_users')->where('id', $row->id)->update(['status' => 'REVOKED', 'updated_at' => now()]);
        $this->audit->record('provider.user.revoked', 'provider_user', $row->id, ['provider_id' => $s->providerId, 'user_id' => $row->user_id, 'by' => $actor->id]);

        return $this->user($s, $providerUserId);
    }

    public function user(ProviderScope $s, string $id): object
    {
        $u = DB::table('provider_users as pu')->join('users as u', 'u.id', '=', 'pu.user_id')->where('pu.provider_profile_id', $s->providerId)->where('pu.id', $id)
            ->select('pu.*', 'u.full_name', 'u.email')->first() ?? throw new ApiProblemException('PROVIDER_USER_NOT_FOUND', 404, 'Provider user not found.');
        $u->facility_ids = DB::table('provider_user_facilities')->where('provider_user_id', $id)->pluck('provider_facility_id')->all();

        return $u;
    }

    public function users(ProviderScope $s): array
    {
        return DB::table('provider_users as pu')->join('users as u', 'u.id', '=', 'pu.user_id')->where('pu.provider_profile_id', $s->providerId)
            ->orderBy('u.full_name')->select('pu.id', 'pu.user_id', 'pu.provider_role', 'pu.facility_scope', 'pu.status', 'u.full_name', 'u.email')->get()->all();
    }

    /** FACILITY → DEPARTMENT → SERVICE_UNIT. */
    public function addDepartment(User $actor, ProviderScope $s, string $facilityId, array $d): object
    {
        $this->assertFacility($actor, $s, $facilityId);
        $level = strtoupper($d['level'] ?? 'DEPARTMENT');
        $parent = $d['parent_department_id'] ?? null;
        if ($level === 'SERVICE_UNIT' && ! $parent) {
            throw new ApiProblemException('PARENT_DEPARTMENT_REQUIRED', 422, 'A service unit belongs to a department.');
        }
        if ($parent && ! DB::table('provider_departments')->where(['id' => $parent, 'provider_facility_id' => $facilityId, 'level' => 'DEPARTMENT'])->exists()) {
            throw new ApiProblemException('PARENT_DEPARTMENT_NOT_FOUND', 422, 'Parent department not found in this facility.');
        }
        $code = strtoupper($d['code']);
        if (DB::table('provider_departments')->where(['provider_facility_id' => $facilityId, 'code' => $code])->exists()) {
            throw new ApiProblemException('DEPARTMENT_EXISTS', 409, "Department {$code} already exists in this facility.");
        }
        $id = (string) Str::uuid();
        DB::table('provider_departments')->insert(['id' => $id, 'provider_facility_id' => $facilityId, 'parent_department_id' => $parent, 'level' => $level, 'code' => $code,
            'name' => $d['name'], 'specialty_code' => $d['specialty_code'] ?? null, 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
        $this->audit->record('provider.department.added', 'provider_department', $id, ['provider_id' => $s->providerId, 'facility_id' => $facilityId, 'level' => $level]);

        return DB::table('provider_departments')->find($id);
    }

    public function departments(User $user, ProviderScope $s): array
    {
        return DB::table('provider_departments')->whereIn('provider_facility_id', $this->facilityIds($user, $s))->orderBy('code')->get()->all();
    }
}
