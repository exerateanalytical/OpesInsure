<?php

declare(strict_types=1);

namespace App\Application\Providers\Workspace\Filament\Pages;

use App\Application\Providers\Workspace\ProviderAccess;
use BackedEnum;
use Illuminate\Support\Facades\DB;

/** Provider Portal screens "user_management" and "roles_permissions": list, assign (role + facility scope) and revoke provider users. */
final class UsersPage extends ProviderWorkspacePage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-users';

    protected static ?int $navigationSort = 17;

    protected static ?string $slug = 'users';

    protected static string $permission = 'provider.users.manage';

    protected static string $screen = 'user_management';

    public ?string $user_id = null;

    public string $provider_role = 'FRONT_DESK';

    public string $facility_scope = 'ALL';

    /** @var list<string> */
    public array $facility_ids = [];

    public function extraView(): ?string
    {
        return 'provider-workspace.users-form';
    }

    /** Staff of the provider organisation (party EMPLOYED_BY the provider), the only users that may be assigned. @return array<string, string> */
    public function staffOptions(): array
    {
        $party = DB::table('provider_profiles')->where('id', $this->scope()->providerId)->value('party_id');

        return DB::table('users as u')->join('party_relationships as r', 'r.from_party_id', '=', 'u.party_id')
            ->where(['r.to_party_id' => $party, 'r.type' => 'EMPLOYED_BY', 'r.status' => 'ACTIVE'])->orderBy('u.full_name')
            ->get(['u.id', 'u.full_name', 'u.email'])->mapWithKeys(fn ($u) => [(string) $u->id => trim($u->full_name.' — '.$u->email, ' —')])->all();
    }

    /** Canonical portal roles (ProviderAccess::ROLES), clinical ones flagged. @return array<string, string> */
    public function roleOptions(): array
    {
        return collect(ProviderAccess::ROLES)->mapWithKeys(fn ($clinical, $code) => [$code => $code.($clinical ? ' ('.__('provider_workspace.ui.clinical').')' : '')])->all();
    }

    /** Same controller action as POST /api/v1/provider-portal/users (validation, facility scope, audit). */
    public function assign(): void
    {
        if ($this->callWorkspace('userAssign', ['user_id' => (string) $this->user_id, 'provider_role' => $this->provider_role, 'facility_scope' => $this->facility_scope,
            'facility_ids' => $this->facility_scope === 'ASSIGNED' ? array_values(array_filter($this->facility_ids)) : []]) !== null) {
            $this->user_id = null;
            $this->facility_ids = [];
        }
    }

    public function revoke(string $id): void
    {
        $this->callWorkspace('userRevoke', [], $id);
    }

    public function rowActions(array $row): array
    {
        return isset($row['id']) && ($row['status'] ?? null) === 'ACTIVE' && ($row['user_id'] ?? null) !== $this->user()->id
            ? [['label' => __('provider_workspace.ui.revoke'), 'action' => 'revoke', 'arg' => $row['id']]] : [];
    }

    protected function columns(): array
    {
        return ['full_name', 'email', 'provider_role', 'facility_scope', 'status'];
    }

    protected function rows(): array
    {
        return array_map(fn ($r) => (array) $r, app(\App\Application\Providers\Workspace\ProviderAccess::class)->users($this->scope()));
    }
}
