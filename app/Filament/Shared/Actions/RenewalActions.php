<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\Policies\RenewalService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Tenant;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;

/**
 * Renewal list-page actions (labels in party_actions). Same service / permission as:
 *   renewalSweep  POST renewals/seed  renewals.manage  RenewalService::sweep
 */
final class RenewalActions
{
    public static function sweep(): Action
    {
        $p = 'renewals.manage';

        return WorkflowAction::make('renewalSweep', $p, 'party_actions')->icon('lucide-calendar-sync')->requiresConfirmation()
            ->schema([TextInput::make('days_ahead')->label(__('party_actions.fields.days_ahead'))->required()->integer()->minValue(1)->maxValue(180)->default(90)])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, function () use ($data) {
                $x = app(RenewalService::class)->sweep(Tenant::findOrFail(app(TenantContext::class)->id()), (int) $data['days_ahead'], auth()->user());

                return $x;
            }, __('party_actions.renewalSweep.done')));
    }

    /**
     * S3 2026-09-29: renewalReassign  POST renewals/{r}/assignment  renewals.manage  RenewalService::reassign
     * (record action on BRK-066 Renewal Assignment and the renewal case view, /admin and /broker; labels in leftover_actions).
     */
    public static function reassign(): Action
    {
        $p = 'renewals.manage';

        return WorkflowAction::make('renewalReassign', $p, 'leftover_actions')->icon('lucide-user-round-cog')
            ->visible(fn ($record) => $record instanceof \App\Models\RenewalCase && in_array($record->status, \App\Application\Policies\Renewals\RenewalMachine::OPEN, true))
            ->schema([
                \Filament\Forms\Components\Select::make('assignee_id')->label(__('leftover_actions.fields.assignee'))->searchable()
                    ->placeholder(__('leftover_actions.fields.unassigned'))->options(fn () => self::assignees()),
                \Filament\Forms\Components\Textarea::make('reason')->label(__('leftover_actions.fields.reason'))->maxLength(500),
            ])
            ->action(fn (Action $action, array $data, $record) => WorkflowAction::run($action, $p, fn () => app(RenewalService::class)
                ->reassign($record, ($data['assignee_id'] ?? null) ?: null, auth()->user(), ($data['reason'] ?? null) ?: null)));
    }

    /** Colleagues the caller may hand a case to: ACTIVE members of the tenant, inside the caller's colleague scope, holding renewals.manage. */
    private static function assignees(): array
    {
        $tenant = app(TenantContext::class)->id();
        $q = \Illuminate\Support\Facades\DB::table('users')->join('tenant_memberships as m', 'm.user_id', '=', 'users.id')
            ->where('m.tenant_id', $tenant)->where('m.status', 'ACTIVE')->select('users.id', 'users.full_name')->distinct()->orderBy('users.full_name')->limit(300);
        if (($colleagues = app(\App\Application\Partners\BookScope::class)->users(auth()->user())) !== null) {
            $q->whereIn('users.id', $colleagues);
        }

        return $q->get()->filter(fn ($u) => (bool) rescue(fn () => \App\Models\User::find($u->id)?->hasPermission('renewals.manage'), false, false))
            ->mapWithKeys(fn ($u) => [$u->id => $u->full_name ?: $u->id])->all();
    }
}
