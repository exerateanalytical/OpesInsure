<?php

declare(strict_types=1);

namespace App\Application\Providers\Workspace\Filament\Pages;

use BackedEnum;

/** Provider Portal screen "facility_management" (Gap-Free spec ui_screen_register). */
final class FacilitiesPage extends ProviderWorkspacePage
{
    protected static string|BackedEnum|null $navigationIcon = 'lucide-map-pin';

    protected static ?int $navigationSort = 16;

    protected static ?string $slug = 'facilities';

    protected static string $permission = 'provider_portal.profile.view';

    protected static string $screen = 'facility_management';

    public ?string $department_facility = null;

    public ?string $department_code = null;

    public ?string $department_name = null;

    public ?string $department_level = 'DEPARTMENT';

    public ?string $parent_department_id = null;

    public ?string $department_specialty = null;

    public function extraView(): ?string
    {
        return 'provider-workspace.department-form';
    }

    /** POST provider-portal/facilities/{facility}/departments (provider.settings.manage) through the API controller action (ProviderAccess::addDepartment). */
    public function addDepartment(): void
    {
        if (! $this->allows('provider.settings.manage')) {
            abort(403);
        }
        if ($this->callWorkspace('departmentStore', array_filter(['code' => $this->department_code, 'name' => $this->department_name, 'level' => $this->department_level,
            'parent_department_id' => $this->parent_department_id, 'specialty_code' => $this->department_specialty]), (string) $this->department_facility,
            ['code' => 'department_code', 'name' => 'department_name', 'level' => 'department_level', 'specialty_code' => 'department_specialty']) !== null) {
            $this->department_code = $this->department_name = $this->parent_department_id = $this->department_specialty = null;
        }
    }

    /** Departments (level DEPARTMENT) of the chosen facility, for the service-unit parent select. @return array<string, string> */
    public function parentDepartmentOptions(): array
    {
        return $this->department_facility ? collect(rescue(fn () => app(\App\Application\Providers\Workspace\ProviderAccess::class)->departments($this->user(), $this->scope()), [], false))
            ->filter(fn ($d) => $d->provider_facility_id === $this->department_facility && $d->level === 'DEPARTMENT')->mapWithKeys(fn ($d) => [$d->id => $d->code.' — '.$d->name])->all() : [];
    }

    protected function rows(): array
    {
        $ids = app(\App\Application\Providers\Workspace\ProviderAccess::class)->facilityIds($this->user(), $this->scope());

        return \Illuminate\Support\Facades\DB::table('provider_facilities')->whereIn('id', $ids)->orderBy('code')->get(['code', 'name', 'facility_type_code', 'city_code', 'status'])->map(fn ($f) => (array) $f)->all();
    }
}
