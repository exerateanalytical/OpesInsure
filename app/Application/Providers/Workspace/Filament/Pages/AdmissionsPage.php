<?php

declare(strict_types=1);

namespace App\Application\Providers\Workspace\Filament\Pages;

use App\Application\Health\Preauth\PreauthLifecycle;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

/**
 * Provider Portal screens "admissions_list", "admission_detail", "extension_request", "discharge" (Gap-Free spec). A new admission
 * starts as an ADMISSION preauthorization (link to the request form); once approved the provider records the admission,
 * requests stay extensions while ADMITTED and records the discharge — each through the same controller action as the API.
 */
final class AdmissionsPage extends ProviderWorkspacePage
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBuildingOffice2;

    protected static ?int $navigationSort = 4;

    protected static ?string $slug = 'admissions';

    protected static string $permission = 'provider.preauth.view';

    protected static string $screen = 'admissions_list';

    public ?string $admitted_on = null;

    public ?string $requested_until = null;

    public ?string $extension_reason = null;

    public ?string $discharged_on = null;

    public function extraView(): ?string
    {
        return 'provider-workspace.admission-form';
    }

    public function selectedStatus(): ?string
    {
        return $this->selected ? $this->ws()->preauthQuery($this->tenantId(), $this->user(), $this->scope())->where('id', $this->selected)->where('request_type', 'ADMISSION')->value('status') : null;
    }

    public function may(string $event, string $permission): bool
    {
        $status = $this->selectedStatus();
        $ok = $event === 'extend' ? $status === 'ADMITTED' : ($status !== null && PreauthLifecycle::target($status, $event) !== null);

        return $ok && (bool) rescue(fn () => $this->user()->hasPermission($permission), false, false);
    }

    public function admit(): void
    {
        if ($this->selected) {
            $this->callWorkspace('admissionStore', array_filter(['preauthorization_id' => $this->selected, 'admitted_on' => $this->admitted_on]));
        }
    }

    public function requestExtension(): void
    {
        if ($this->selected && $this->callWorkspace('admissionExtend', ['requested_until' => $this->requested_until, 'reason' => $this->extension_reason], $this->selected, ['reason' => 'extension_reason']) !== null) {
            $this->extension_reason = null;
        }
    }

    public function discharge(): void
    {
        if ($this->selected) {
            $this->callWorkspace('admissionDischarge', array_filter(['discharged_on' => $this->discharged_on]), $this->selected);
        }
    }

    public function rowActions(array $row): array
    {
        return isset($row['id']) ? [['label' => __('provider_workspace.ui.open'), 'action' => 'open', 'arg' => $row['id']]] : [];
    }

    protected function detail(): ?array
    {
        $d = $this->ws()->preauthorization($this->tenantId(), $this->user(), $this->scope(), $this->selected);

        return ['title' => __('provider_workspace.screens.admission_detail').' '.($d['preauth_number'] ?? ''),
            'cards' => array_intersect_key($d, array_flip(['preauth_number', 'status', 'service_date', 'admitted_on', 'approved_until', 'discharged_on', 'approved_amount_minor', 'currency'])),
            'rows' => array_map(fn ($e) => array_intersect_key((array) $e, array_flip(['sequence', 'status', 'requested_until', 'approved_until', 'reason', 'created_at'])), $d['extensions'] ?? [])];
    }

    protected function columns(): array
    {
        return ['preauth_number', 'status', 'member_ref', 'service_date', 'admitted_on', 'approved_until', 'discharged_on', 'approved_amount_minor', 'currency'];
    }

    protected function rows(): array
    {
        return $this->ws()->preauthorizations($this->tenantId(), $this->user(), $this->scope(), ['request_type' => 'ADMISSION', 'per_page' => 100])['data'];
    }
}
