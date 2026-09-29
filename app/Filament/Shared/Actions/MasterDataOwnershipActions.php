<?php

declare(strict_types=1);

namespace App\Filament\Shared\Actions;

use App\Application\MasterData\MasterDataOverrideService;
use App\Application\MasterData\MasterDataReviewService;
use App\Application\PartnerWorkspace\PartnerWorkspaceScope;
use App\Domain\Tenancy\TenantContext;
use App\Filament\Admin\Resources\CarrierMasterDataMappings\CarrierMasterDataMappingResource;
use App\Models\MasterData\MasterDataList;
use App\Models\MasterData\MasterDataValue;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;

/**
 * Tenant-owned master data (batch 19, REQ-MDM-006 / REQ-DUP-013). Same permission + service + validation as
 * MasterDataOwnershipController / MasterDataController::suggest (routes/master_data.php):
 *   mdMapForCarrier    PUT  master-data/carrier-mappings                 master_data.mappings.manage  MasterDataOverrideService::mapForCarrier
 *   mdMapForBroker     PUT  master-data/broker-mappings                  master_data.mappings.manage  MasterDataOverrideService::mapForBroker
 *   mdAddPrivateValue  POST master-data/{domain}/{list}/private-values   master_data.overrides.manage MasterDataOverrideService::addPrivateValue
 *   mdSuggest          POST master-data/suggestions                      (signed in)                  MasterDataReviewService::submit
 * The insurer / broker is the caller's own (PartnerWorkspaceScope), never picked; values are platform values only.
 */
final class MasterDataOwnershipActions
{
    private const LANG = 'masterdata_actions';

    public static function mapForCarrier(): Action
    {
        $p = 'master_data.mappings.manage';

        return WorkflowAction::make('mdMapForCarrier', $p, self::LANG)->icon('lucide-link')
            ->schema([
                CarrierMasterDataMappingResource::valueSelect()->label(self::f('value')),
                TextInput::make('external_code')->label(self::f('external_code'))->required()->maxLength(128),
                TextInput::make('external_label')->label(self::f('external_label'))->maxLength(255),
                TextInput::make('target')->label(self::f('target'))->maxLength(64),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, function () use ($data) {
                $carrier = app(PartnerWorkspaceScope::class)->carrierId(auth()->user(), self::tenant()) ?? abort(403, __('masterdata_actions.mdMapForCarrier.not_insurer'));

                return app(MasterDataOverrideService::class)->mapForCarrier($carrier, self::platformValue($data['value_id']), $data['external_code'],
                    filled($data['external_label'] ?? null) ? $data['external_label'] : null, filled($data['target'] ?? null) ? $data['target'] : 'CODE', auth()->id());
            }, __('masterdata_actions.mdMapForCarrier.done')));
    }

    public static function mapForBroker(): Action
    {
        $p = 'master_data.mappings.manage';

        return WorkflowAction::make('mdMapForBroker', $p, self::LANG)->icon('lucide-link')
            ->schema([
                CarrierMasterDataMappingResource::valueSelect()->label(self::f('value')),
                TextInput::make('external_code')->label(self::f('external_code'))->required()->maxLength(128),
                TextInput::make('external_label')->label(self::f('external_label'))->maxLength(255),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, $p, function () use ($data) {
                $broker = app(PartnerWorkspaceScope::class)->broker(auth()->user())?->id ?? abort(403, __('masterdata_actions.mdMapForBroker.not_broker'));

                return app(MasterDataOverrideService::class)->mapForBroker($broker, self::platformValue($data['value_id']), $data['external_code'],
                    filled($data['external_label'] ?? null) ? $data['external_label'] : null, auth()->id());
            }, __('masterdata_actions.mdMapForBroker.done')));
    }

    /** On a list's detail page: a value only the caller's organization sees. */
    public static function addPrivateValue(): Action
    {
        $p = 'master_data.overrides.manage';

        return WorkflowAction::make('mdAddPrivateValue', $p, self::LANG)->icon('lucide-plus')
            ->schema([
                TextInput::make('label_en')->label(self::f('label_en'))->required()->maxLength(200),
                TextInput::make('label_fr')->label(self::f('label_fr'))->maxLength(200),
                TextInput::make('parent_code')->label(self::f('parent_code'))->maxLength(128),
            ])
            ->action(fn (Action $action, MasterDataList $record, array $data) => WorkflowAction::run($action, $p,
                fn () => app(MasterDataOverrideService::class)->addPrivateValue(self::tenant(), $record->domain_code, $record->code,
                    array_filter($data, fn ($v) => filled($v)), auth()->id()), __('masterdata_actions.mdAddPrivateValue.done')));
    }

    /** Staff suggestion of a missing value (goes to the review queue; an existing match is reported, not duplicated). */
    public static function suggest(): Action
    {
        return WorkflowAction::make('mdSuggest', null, self::LANG)->icon('lucide-message-square-plus')
            ->schema([
                Select::make('list')->label(self::f('list'))->required()->searchable()
                    ->options(fn () => MasterDataList::orderBy('domain_code')->orderBy('code')->limit(500)->get()->mapWithKeys(fn ($l) => [$l->domain_code.'|'.$l->code => $l->domain_code.'.'.$l->code])->all()),
                TextInput::make('text')->label(self::f('text'))->required()->maxLength(200),
                TextInput::make('parent')->label(self::f('parent_code'))->maxLength(128),
                TextInput::make('suggested_category')->label(self::f('suggested_category'))->maxLength(255),
            ])
            ->action(fn (Action $action, array $data) => WorkflowAction::run($action, null, function () use ($data) {
                [$domain, $list] = explode('|', (string) $data['list'], 2) + [1 => ''];

                return app(MasterDataReviewService::class)->submit($domain, $list, $data['text'], [
                    'parent_code' => $data['parent'] ?? null, 'locale' => app()->getLocale() === 'fr' ? 'fr' : 'en', 'user_id' => auth()->id(),
                    'tenant_id' => self::tenant(), 'screen' => 'admin.master-data-reviews', 'line_code' => null, 'field_key' => null,
                    'suggested_category' => $data['suggested_category'] ?? null,
                ]);
            }, __('masterdata_actions.mdSuggest.done')));
    }

    private static function platformValue(string $id): MasterDataValue
    {
        return MasterDataValue::whereNull('tenant_id')->whereKey($id)->firstOrFail();
    }

    private static function tenant(): ?string
    {
        return rescue(fn () => app(TenantContext::class)->id(), null, false);
    }

    private static function f(string $k): string
    {
        return __("masterdata_actions.fields.{$k}");
    }
}
