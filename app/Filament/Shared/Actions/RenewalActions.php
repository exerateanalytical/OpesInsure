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
}
