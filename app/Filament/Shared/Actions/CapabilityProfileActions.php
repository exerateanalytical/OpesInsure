<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\Capabilities\CapabilityCatalogue;
use App\Application\Capabilities\CapabilityProfileService;
use App\Application\Capabilities\Models\CapabilityProfile;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;

/**
 * Carrier capability profiles (UI coverage batch 27). Same service, same permission, same validation as the API routes;
 * the service keeps maker-checker (the maker or submitter of a profile cannot approve it) and the incoherence gate.
 *   capabilityReplaceModes  PUT  capability-profiles/{p}/modes    capability_profiles.manage   CapabilityProfileService::replaceModes
 *   capabilitySubmit        POST capability-profiles/{p}/submit   capability_profiles.manage   CapabilityProfileService::submit
 *   capabilityApprove       POST capability-profiles/{p}/approve  capability_profiles.approve  CapabilityProfileService::approve
 *   capabilityReject        POST capability-profiles/{p}/reject   capability_profiles.approve  CapabilityProfileService::reject
 */
final class CapabilityProfileActions
{
    private const L = RiskTransferSupport::L;

    private static function profile(mixed $record): CapabilityProfile
    {
        return CapabilityProfile::findOrFail(WorkflowAction::id($record));
    }

    public static function capabilityReplaceModes(): Action
    {
        $p = 'capability_profiles.manage';

        return WorkflowAction::make('capabilityReplaceModes', $p, self::L)->icon('lucide-sliders-horizontal')
            ->visible(fn (mixed $record) => ($record['status'] ?? null) === 'DRAFT')
            ->fillForm(fn (mixed $record) => ['modes' => self::profile($record)->modes()->get()
                ->map(fn ($m) => ['capability' => $m->capability, 'mode' => $m->mode, 'scope_class_code' => $m->scope_class_code, 'fallback_mode' => $m->fallback_mode])->all()])
            ->schema([
                Repeater::make('modes')->label(RiskTransferSupport::f('modes'))->defaultItems(0)->schema([
                    Select::make('capability')->label(RiskTransferSupport::f('capability'))->options(RiskTransferSupport::codes(array_keys(CapabilityCatalogue::CAPABILITIES)))->required()->live(),
                    Select::make('mode')->label(RiskTransferSupport::f('mode'))->required()
                        ->options(fn (Get $get) => RiskTransferSupport::codes(array_values(array_unique([...array_keys(CapabilityCatalogue::CAPABILITIES[$get('capability')] ?? []), ...CapabilityCatalogue::EXECUTION_MODES])))),
                    TextInput::make('scope_class_code')->label(RiskTransferSupport::f('scope_class_code'))->maxLength(64),
                    TextInput::make('fallback_mode')->label(RiskTransferSupport::f('fallback_mode'))->maxLength(40),
                ]),
            ])
            ->action(fn (Action $action, mixed $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(CapabilityProfileService::class)->replaceModes(self::profile($record), array_values(array_map(fn ($m) => RiskTransferSupport::clean($m), $data['modes'] ?? [])), RiskTransferSupport::user()),
                __(self::L.'.capabilityReplaceModes.done')));
    }

    public static function capabilitySubmit(): Action
    {
        $p = 'capability_profiles.manage';

        return WorkflowAction::make('capabilitySubmit', $p, self::L)->icon('lucide-send')->requiresConfirmation()
            ->visible(fn (mixed $record) => ($record['status'] ?? null) === 'DRAFT')
            ->action(fn (Action $action, mixed $record) => WorkflowAction::run($action, $p,
                fn () => app(CapabilityProfileService::class)->submit(self::profile($record), RiskTransferSupport::user()), __(self::L.'.capabilitySubmit.done')));
    }

    public static function capabilityApprove(): Action
    {
        $p = 'capability_profiles.approve';

        return WorkflowAction::make('capabilityApprove', $p, self::L)->icon('lucide-badge-check')->color('success')->requiresConfirmation()
            ->visible(fn (mixed $record) => ($record['status'] ?? null) === 'SUBMITTED')
            ->action(fn (Action $action, mixed $record) => WorkflowAction::run($action, $p,
                fn () => app(CapabilityProfileService::class)->approve(self::profile($record), RiskTransferSupport::user()), __(self::L.'.capabilityApprove.done')));
    }

    public static function capabilityReject(): Action
    {
        $p = 'capability_profiles.approve';

        return WorkflowAction::make('capabilityReject', $p, self::L)->icon('lucide-circle-x')->color('danger')
            ->visible(fn (mixed $record) => ($record['status'] ?? null) === 'SUBMITTED')
            ->schema([Textarea::make('reason')->label(RiskTransferSupport::f('reason'))->required()->minLength(5)->maxLength(2000)])
            ->action(fn (Action $action, mixed $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(CapabilityProfileService::class)->reject(self::profile($record), RiskTransferSupport::user(), $data['reason']), __(self::L.'.capabilityReject.done')));
    }
}
