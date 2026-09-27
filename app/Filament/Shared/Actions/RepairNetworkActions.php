<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\Claims\RepairNetwork\RepairNetworkService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;

/**
 * Repair-network register actions (garages, experts, adjusters, surveyors), mounted on RepairNetworkRegister rows:
 *   capabilities  POST repair-network/providers/{p}/capabilities   providers.manage      RepairNetworkService::setCapabilities
 *   verifySource  POST repair-network/providers/{p}/verify-source  providers.credential  RepairNetworkService::verifySource
 */
final class RepairNetworkActions
{
    public static function capabilities(): Action
    {
        $p = 'providers.manage';

        return ClaimCaseActions::make('repairCapabilities', $p)->icon('lucide-wrench')
            ->schema([
                Select::make('kind')->label(__('claim_actions.fields.capability_kind'))->options(WorkflowAction::options(['SERVICE', 'SPECIALTY', 'VEHICLE_MAKE']))->required(),
                TagsInput::make('codes')->label(__('claim_actions.fields.codes'))->required(),
            ])
            ->action(fn (Action $action, $record, array $data) => WorkflowAction::run($action, $p, fn () => app(RepairNetworkService::class)->setCapabilities(
                WorkflowAction::id($record), $data['kind'], array_values((array) $data['codes']), auth()->id(), 'MANUAL'), __('claim_actions.repairCapabilities.done')));
    }

    public static function verifySource(): Action
    {
        $p = 'providers.credential';

        return ClaimCaseActions::make('repairVerifySource', $p)->icon('lucide-badge-check')->color('success')->requiresConfirmation()
            ->visible(fn ($record) => in_array($record->data_status ?? null, RepairNetworkService::PENDING, true))
            ->schema([
                TextInput::make('source_url')->label(__('claim_actions.fields.source_url'))->url()->maxLength(500),
                TextInput::make('source_reference')->label(__('claim_actions.fields.source_reference'))->maxLength(191),
            ])
            ->action(fn (Action $action, $record, array $data) => WorkflowAction::run($action, $p, fn () => app(RepairNetworkService::class)->verifySource(
                WorkflowAction::id($record), array_filter(['source_url' => $data['source_url'] ?? null, 'source_reference' => $data['source_reference'] ?? null]), auth()->user()),
                __('claim_actions.repairVerifySource.done')));
    }
}
