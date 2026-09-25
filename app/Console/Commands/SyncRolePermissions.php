<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Application\Identity\RoleCatalogue;
use App\Models\Role;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * REQ-RBAC-003 — DatabaseSeeder / invitations create tenant roles with firstOrCreate, so a role that already
 * exists never receives permissions added to RoleCatalogue later. This command tops every existing tenant role
 * up with its missing catalogue defaults. Additive only: custom grants are never removed, wildcard roles and
 * codes unknown to the catalogue are left untouched. Idempotent.
 */
final class SyncRolePermissions extends Command
{
    protected $signature = 'rbac:sync-role-permissions {--tenant= : only this tenant id} {--dry-run : report what would be added without writing}';

    protected $description = 'Add missing RoleCatalogue default permissions to existing tenant roles (never removes grants).';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $changed = 0;
        $added = 0;
        Role::query()->whereIn('code', RoleCatalogue::codes())
            ->when($this->option('tenant'), fn ($q, $t) => $q->where('tenant_id', $t))
            ->orderBy('id')
            ->each(function (Role $role) use ($dry, &$changed, &$added): void {
                $current = array_values((array) $role->permissions);
                if (in_array('*', $current, true)) {
                    return;
                }
                $missing = array_values(array_diff(RoleCatalogue::defaultPermissions($role->code), $current));
                if ($missing === []) {
                    return;
                }
                $changed++;
                $added += count($missing);
                $this->line(($dry ? '[dry-run] ' : '')."{$role->tenant_id} {$role->code}: +".implode(', ', $missing));
                if (! $dry) {
                    DB::transaction(function () use ($role): void {
                        $fresh = Role::whereKey($role->id)->lockForUpdate()->firstOrFail();
                        $perms = array_values((array) $fresh->permissions);
                        $fresh->forceFill(['permissions' => array_values(array_unique([...$perms, ...RoleCatalogue::defaultPermissions($fresh->code)]))])->save();
                    });
                }
            });
        $this->info(($dry ? 'Dry run. ' : '')."Roles updated: {$changed}. Permissions added: {$added}.");

        return self::SUCCESS;
    }
}
