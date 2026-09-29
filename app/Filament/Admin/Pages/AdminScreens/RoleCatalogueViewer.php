<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\AdminScreens;

use App\Application\Identity\Rbac\PermissionCatalogue;
use App\Application\Identity\RoleCatalogue;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\DB;

/**
 * ADM-018 Roles / ADM-019 Role details — read-only catalogue of the platform roles (RoleCatalogue: label, source,
 * default data scope, default permissions) with the number of active members holding each role in this organisation.
 * Opened by the membership-management permissions (identity.roles.manage / identity.invite). Nothing is granted here.
 */
final class RoleCatalogueViewer extends AdminScreenPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-key-round';

    protected static ?int $navigationSort = 30;

    protected static ?string $slug = 'access/roles';

    protected static array $permissions = ['identity.roles.manage', 'identity.invite'];

    protected static string $screen = 'role_catalogue';

    protected static string $group = 'Administration';

    public static function roleLabel(string $code): string
    {
        $t = __('admin_screens.roles.'.$code);

        return $t === 'admin_screens.roles.'.$code ? (RoleCatalogue::LABELS[$code] ?? $code) : $t;
    }

    /** @return array<string, array<string, mixed>> */
    public function rows(): array
    {
        $members = $this->tenantId === null ? collect() : DB::table('tenant_memberships')->where('tenant_id', $this->tenantId)->where('status', 'ACTIVE')
            ->selectRaw('role_code, count(*) as n')->groupBy('role_code')->pluck('n', 'role_code');
        $rows = [];
        foreach (RoleCatalogue::codes() as $code) {
            $perms = RoleCatalogue::defaultPermissions($code);
            $rows[$code] = [
                '__key' => $code, 'id' => $code, 'code' => $code, 'label' => self::roleLabel($code),
                'source' => RoleCatalogue::SOURCES[$code] ?? '—',
                'scope' => RoleCatalogue::defaultScope($code)->value,
                'permissions' => in_array('*', $perms, true) ? __('admin_screens.all_permissions') : (string) count($perms),
                'invitable' => in_array($code, RoleCatalogue::INVITABLE, true) ? __('admin_screens.yes') : __('admin_screens.no'),
                'platform_only' => RoleCatalogue::isPlatformOnly($code) ? __('admin_screens.yes') : __('admin_screens.no'),
                'members' => (int) ($members[$code] ?? 0),
            ];
        }

        return $rows;
    }

    public function kpis(): array
    {
        $rows = $this->rows();

        return [
            self::kpi('roles', count($rows)),
            self::kpi('permissions', count(PermissionCatalogue::all())),
            self::kpi('roles_in_use', collect($rows)->where('members', '>', 0)->count()),
            self::kpi('active_members', collect($rows)->sum('members')),
        ];
    }

    /** ADM-019: default permissions of one role, grouped by catalogue category. */
    public static function details(string $code): array
    {
        $catalogue = PermissionCatalogue::all();
        $grouped = [];
        foreach (RoleCatalogue::defaultPermissions($code) as $p) {
            $grouped[$catalogue[$p]['category'] ?? 'other'][] = ['code' => $p, 'description' => $catalogue[$p]['description'] ?? null];
        }
        ksort($grouped);

        return $grouped;
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (?string $search, int|string $page, int|string $recordsPerPage) => self::pageOf($this->rows(), $search, ['code', 'label', 'scope'], $page, $recordsPerPage))
            ->columns([
                TextColumn::make('label')->label(self::col('role'))->searchable()->description(fn (array $record) => $record['code']),
                TextColumn::make('scope')->label(self::col('data_scope'))->badge(),
                TextColumn::make('permissions')->label(self::col('permission_count')),
                TextColumn::make('members')->label(self::col('members'))->numeric(),
                TextColumn::make('invitable')->label(self::col('invitable')),
                TextColumn::make('platform_only')->label(self::col('platform_only')),
                TextColumn::make('source')->label(self::col('source')),
            ])
            ->recordActions([
                Action::make('roleDetails')->label(__('admin_screens.roleDetails.label'))->icon('lucide-eye')->color('gray')
                    ->modalHeading(fn (array $record) => self::roleLabel($record['code']).' ('.$record['code'].')')
                    ->modalSubmitAction(false)
                    ->modalContent(fn (array $record) => view('filament.admin.pages.partials.role-details', [
                        'code' => $record['code'], 'scope' => $record['scope'], 'groups' => self::details($record['code']),
                    ])),
            ])
            ->paginated([25, 50, 'all'])
            ->emptyStateHeading(__('admin_screens.empty'));
    }
}
