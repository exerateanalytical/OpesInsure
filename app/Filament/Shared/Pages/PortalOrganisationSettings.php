<?php

declare(strict_types=1);

namespace App\Filament\Shared\Pages;

use App\Application\WebExperiences\PortalAccess;
use Filament\Facades\Filament;

/**
 * Portal panels (insurer, broker): fixed to the tenant of the membership that granted entry to this panel
 * (PortalAccess::membershipFor, as ResolvePortalTenant). Organisation and branch settings are editable by that
 * tenant's administrators only; every portal user can set their own language and display timezone.
 */
final class PortalOrganisationSettings extends OrganizationSettings
{
    public const MANAGER_ROLES = ['CARRIER_ADMIN', 'CARRIER_SUPER_ADMIN', 'BROKER_ADMIN'];

    public static function canAccess(): bool
    {
        return app(PortalAccess::class)->membershipFor(auth()->user(), Filament::getCurrentOrDefaultPanel()->getId()) !== null;
    }

    protected function resolveTenantId(): ?string
    {
        return app(PortalAccess::class)->membershipFor(auth()->user(), Filament::getCurrentOrDefaultPanel()->getId())?->tenant_id;
    }

    /**
     * /insurer (owner rule 2026-09-29, docs/spec/PORTAL_WRITE_RULES.md): the API permission of the organisation and
     * branch routes (tenant.manage: POST branches, PATCH organization/branches/{b}) in the portal tenant, not a role
     * name. /broker keeps its administrator roles (broker portal is owned by P5/P6).
     */
    protected function canManageTenant(): bool
    {
        if ($this->tenantId === null) {
            return false;
        }
        if (Filament::getCurrentOrDefaultPanel()->getId() === 'insurer') {
            return $this->tenantId === rescue(fn () => app(\App\Domain\Tenancy\TenantContext::class)->id(), null, false)
                && \App\Application\WebExperiences\PortalScope::allowsWrite('tenant.manage');
        }

        return (bool) auth()->user()?->memberships()->where('status', 'ACTIVE')
            ->where('tenant_id', $this->tenantId)->whereIn('role_code', self::MANAGER_ROLES)->exists();
    }

    /** New branch in the caller's own organisation: same validation and audit event as POST /branches (BranchController::store). */
    protected function getHeaderActions(): array
    {
        return [
            \Filament\Actions\Action::make('addBranch')->label(__('insurer_portal_ops.branches.add'))->icon('lucide-git-branch-plus')
                ->authorize(fn (): bool => $this->canManageTenant())
                ->schema([
                    \Filament\Forms\Components\TextInput::make('code')->label(__('insurer_portal_ops.branches.code'))->required()->alphaDash()->maxLength(40),
                    \Filament\Forms\Components\TextInput::make('name')->label(__('insurer_portal_ops.branches.name'))->required()->maxLength(160),
                    \Filament\Forms\Components\TextInput::make('phone_e164')->label(__('insurer_portal_ops.branches.phone'))->tel()->maxLength(20),
                    \Filament\Forms\Components\TextInput::make('email')->label(__('insurer_portal_ops.branches.email'))->email(),
                    \Filament\Forms\Components\Select::make('timezone')->label(__('organisation_settings.timezone'))->options(fn () => app(\App\Application\Settings\TimezoneCatalogue::class)->options())->searchable(),
                ])
                ->action(function (array $data): void {
                    abort_unless($this->canManageTenant(), 403);
                    if (\App\Models\TenantBranch::where('tenant_id', $this->tenantId)->where('code', $data['code'])->exists()) {
                        throw \Illuminate\Validation\ValidationException::withMessages(['mountedActions.0.data.code' => __('insurer_portal_ops.branches.duplicate')]);
                    }
                    $b = \App\Models\TenantBranch::create([...array_filter($data, fn ($v) => $v !== null && $v !== ''), 'tenant_id' => $this->tenantId, 'status' => 'ACTIVE']);
                    app(\App\Application\Audit\AuditWriter::class)->record('tenant.branch.created', 'tenant_branch', $b->id);
                    $this->fillState();
                    \Filament\Notifications\Notification::make()->title(__('insurer_portal_ops.branches.added'))->success()->send();
                }),
        ];
    }
}
