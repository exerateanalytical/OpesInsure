<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\Configuration\PlatformSetupService;
use App\Interfaces\Http\Controllers\Api\V1\Organization\TimezoneSettingsController;
use App\Interfaces\Http\Controllers\Api\V1\Tenancy\TenantController;
use Filament\Actions\Action;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;

/**
 * Platform desk actions — shown only while working in the PLATFORM tenant (platform administrators), on top of the
 * API permission:
 *   platformTimezone       PATCH admin/platform/settings/timezone  platform.settings.manage     TimezoneSettingsController@updatePlatform (TimezoneResolver::forget)
 *   platformSetupComplete  POST platform/setup/complete            platform.settings.manage     PlatformSetupService::complete
 *   platformIdentityDraft  POST platform/setup/identity            configuration.changes.manage PlatformSetupService::draftIdentity (maker-checker via configuration changes)
 *   tenantCreate           POST tenants                            (API: any signed-in user) — web: tenant.manage in the platform tenant; TenantController@store
 */
final class MiscPlatformActions
{
    public static function all(): array
    {
        return [self::platformTimezone(), self::platformSetupComplete(), self::platformIdentityDraft(), self::tenantCreate()];
    }

    public static function permissions(): array
    {
        return ['platform.settings.manage', 'configuration.changes.manage', 'tenant.manage'];
    }

    private static function platformOnly(Action $a): Action
    {
        return $a->visible(fn () => MiscSupport::isPlatformTenant());
    }

    public static function platformTimezone(): Action
    {
        return self::platformOnly(MiscSupport::op('platformTimezone', 'platform.settings.manage', 'lucide-clock', [
            TextInput::make('default_timezone')->label(MiscSupport::f('timezone'))->maxLength(64)->placeholder('Africa/Douala'),
        ], fn (array $d) => ControllerCall::invoke(TimezoneSettingsController::class, 'updatePlatform', ['default_timezone' => filled($d['default_timezone'] ?? null) ? $d['default_timezone'] : null], [], 'PATCH')));
    }

    public static function platformSetupComplete(): Action
    {
        return self::platformOnly(MiscSupport::op('platformSetupComplete', 'platform.settings.manage', 'lucide-flag-triangle-right', [],
            fn () => app(PlatformSetupService::class)->complete(MiscSupport::user())));
    }

    public static function platformIdentityDraft(): Action
    {
        return self::platformOnly(MiscSupport::op('platformIdentityDraft', 'configuration.changes.manage', 'lucide-id-card', [
            KeyValue::make('changes')->label(MiscSupport::f('identity_changes'))->required(),
            Textarea::make('reason')->label(MiscSupport::f('reason'))->required()->minLength(5)->maxLength(2000),
            DatePicker::make('effective_from')->label(MiscSupport::f('effective_from')),
        ], function (array $d) {
            $v = MiscSupport::validate(MiscSupport::filled($d), ['changes' => 'required|array', 'reason' => 'required|string|min:5|max:2000', 'effective_from' => 'nullable|date']);

            return app(PlatformSetupService::class)->draftIdentity(MiscSupport::user(), $v['changes'], $v['reason'], $v['effective_from'] ?? null);
        }));
    }

    public static function tenantCreate(): Action
    {
        return self::platformOnly(MiscSupport::op('tenantCreate', 'tenant.manage', 'lucide-building-2', [
            Select::make('type')->label(MiscSupport::f('tenant_type'))->required()->options(MiscSupport::options(['BROKER', 'CARRIER', 'AGENCY', 'PLATFORM'])),
            TextInput::make('legal_name')->label(MiscSupport::f('legal_name'))->required()->maxLength(160),
            TextInput::make('trade_name')->label(MiscSupport::f('trade_name'))->maxLength(160),
            TextInput::make('slug')->label(MiscSupport::f('slug'))->required()->alphaDash()->maxLength(80),
            TextInput::make('registration_number')->label(MiscSupport::f('registration_number'))->maxLength(80),
            TextInput::make('tax_number')->label(MiscSupport::f('tax_number'))->maxLength(80),
            Select::make('primary_locale')->label(MiscSupport::f('locale'))->required()->options(['en' => 'English', 'fr' => 'Français'])->default('fr'),
        ], fn (array $d) => ControllerCall::invoke(TenantController::class, 'store', MiscSupport::filled($d))));
    }
}
