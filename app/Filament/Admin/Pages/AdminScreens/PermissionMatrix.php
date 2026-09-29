<?php

declare(strict_types=1);

namespace App\Filament\Admin\Pages\AdminScreens;

use App\Application\Identity\Rbac\PermissionCatalogue;
use App\Application\Identity\RoleCatalogue;
use BackedEnum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

/**
 * ADM-020 Permission matrix — read-only: every governed permission (PermissionCatalogue::all(): config/permissions.php
 * plus every RoleCatalogue default grant) with the roles granted it by default and the roles the catalogue suggests.
 * Search by permission, category or role code. Grants stay in roles.permissions; nothing can be changed here.
 */
final class PermissionMatrix extends AdminScreenPage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-table';

    protected static ?int $navigationSort = 31;

    protected static ?string $slug = 'access/permission-matrix';

    protected static array $permissions = ['identity.roles.manage', 'identity.invite'];

    protected static string $screen = 'permission_matrix';

    protected static string $group = 'Administration';

    /** @return array<string, array<string, mixed>> */
    public static function rows(): array
    {
        $grants = [];
        foreach (RoleCatalogue::codes() as $role) {
            foreach (RoleCatalogue::defaultPermissions($role) as $p) {
                $grants[$p][] = $role;
            }
        }
        $rows = [];
        foreach (PermissionCatalogue::all() as $code => $meta) {
            $rows[$code] = [
                '__key' => $code, 'id' => $code, 'permission' => $code, 'category' => $meta['category'],
                'description' => $meta['description'] ?? '—',
                'granted' => implode(', ', $grants[$code] ?? []) ?: '—',
                'granted_count' => count($grants[$code] ?? []),
                'suggested' => implode(', ', array_diff($meta['suggested_roles'], $grants[$code] ?? [])) ?: '—',
                'business_data' => PermissionCatalogue::isBusinessData($code) ? __('admin_screens.yes') : __('admin_screens.no'),
            ];
        }

        return $rows;
    }

    public function kpis(): array
    {
        $rows = self::rows();

        return [
            self::kpi('permissions', count($rows)),
            self::kpi('categories', collect($rows)->pluck('category')->unique()->count()),
            self::kpi('ungranted', collect($rows)->where('granted_count', 0)->count(), null, __('admin_screens.kpis.ungranted_hint')),
            self::kpi('roles', count(RoleCatalogue::codes())),
        ];
    }

    public function table(Table $table): Table
    {
        return $table
            // Rows load after the page (permission codes are never part of the first paint).
            ->deferLoading()
            ->records(fn (?string $search, int|string $page, int|string $recordsPerPage) => self::pageOf(self::rows(), $search, ['permission', 'category', 'granted', 'suggested'], $page, $recordsPerPage))
            ->columns([
                TextColumn::make('permission')->label(self::col('permission'))->searchable()->fontFamily('mono'),
                TextColumn::make('category')->label(self::col('category'))->badge(),
                TextColumn::make('description')->label(self::col('description'))->wrap()->limit(120),
                TextColumn::make('granted')->label(self::col('granted_roles'))->wrap(),
                TextColumn::make('suggested')->label(self::col('suggested_roles'))->wrap(),
                TextColumn::make('business_data')->label(self::col('business_data')),
            ])
            ->paginated([25, 50, 100])
            ->emptyStateHeading(__('admin_screens.empty'));
    }
}
