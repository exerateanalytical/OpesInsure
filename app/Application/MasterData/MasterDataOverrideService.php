<?php

declare(strict_types=1);

namespace App\Application\MasterData;

use App\Models\MasterData\BrokerMasterDataMapping;
use App\Models\MasterData\CarrierMasterDataMapping;
use App\Models\MasterData\MasterDataChange;
use App\Models\MasterData\MasterDataList;
use App\Models\MasterData\MasterDataTenantOverride;
use App\Models\MasterData\MasterDataValue;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * REQ-MDM-006 / MDM-015–016 — tenant ownership layer over the platform catalogue. A tenant may HIDE a value,
 * add a tenant ALIAS, map an INTERNAL_CODE, or add a PRIVATE value to a list; it can never change the meaning
 * (code, labels, parent) of a platform value. Carriers and brokers map canonical values to their own codes.
 * Every write is logged in master_data_changes and bumps the domain catalog_version (offline caches refresh).
 */
final class MasterDataOverrideService
{
    public const ACTIONS = ['HIDE', 'ALIAS', 'INTERNAL_CODE'];

    /** Adds or replaces one override (one row per tenant + value + action). */
    public function setOverride(string $tenantId, MasterDataValue $value, string $action, ?string $text, ?string $actorId): MasterDataTenantOverride
    {
        $action = strtoupper($action);
        if (! in_array($action, self::ACTIONS, true)) {
            throw ValidationException::withMessages(['action' => 'Action must be HIDE, ALIAS or INTERNAL_CODE.']);
        }
        if ($value->tenant_id !== null) {
            throw ValidationException::withMessages(['value_id' => 'Overrides apply to platform values; edit your private value instead.']);
        }
        $text = $text === null ? null : trim($text);
        if ($action !== 'HIDE' && ($text === null || $text === '' || mb_strlen($text) > 128)) {
            throw ValidationException::withMessages(['text' => $action === 'ALIAS' ? 'An alias (1–128 characters) is required.' : 'An internal code (1–128 characters) is required.']);
        }
        if ($action === 'HIDE' && $value->is_other) {
            throw ValidationException::withMessages(['value_id' => '"Other / Not listed" can never be hidden: the fallback must never block a transaction.']);
        }

        return DB::transaction(function () use ($tenantId, $value, $action, $text, $actorId) {
            $o = MasterDataTenantOverride::where(['tenant_id' => $tenantId, 'value_id' => $value->id, 'action' => $action])->first();
            $before = $o?->only(['alias', 'internal_code']);
            $attrs = ['alias' => $action === 'ALIAS' ? $text : null, 'internal_code' => $action === 'INTERNAL_CODE' ? $text : null];
            $o ? $o->update($attrs) : $o = MasterDataTenantOverride::create(['tenant_id' => $tenantId, 'value_id' => $value->id, 'action' => $action] + $attrs);
            $this->log('TENANT_OVERRIDE', $o->id, $value->domain_code, 'OVERRIDE_'.$action, $before, ['tenant_id' => $tenantId, 'value' => $value->code] + $attrs, $actorId);

            return $o;
        });
    }

    public function removeOverride(MasterDataTenantOverride $override, ?string $actorId): void
    {
        $value = MasterDataValue::find($override->value_id);
        DB::transaction(function () use ($override, $value, $actorId) {
            $this->log('TENANT_OVERRIDE', $override->id, $value?->domain_code, 'OVERRIDE_REMOVED', $override->only(['tenant_id', 'action', 'alias', 'internal_code']), null, $actorId);
            // An override is tenant configuration, not master data: removing it restores the platform value as-is.
            DB::table('master_data_tenant_overrides')->where('id', $override->id)->delete();
        });
    }

    /** A private value only this tenant sees (source USER_SUBMITTED until the platform adopts it). */
    public function addPrivateValue(string $tenantId, string $domain, string $list, array $data, ?string $actorId): MasterDataValue
    {
        $l = MasterDataList::where(['domain_code' => $domain, 'code' => $list])->first()
            ?? throw ValidationException::withMessages(['list' => "Unknown list $domain.$list."]);
        $en = trim((string) ($data['label_en'] ?? ''));
        $fr = trim((string) ($data['label_fr'] ?? '')) ?: $en;
        if ($en === '' || mb_strlen($en) > 200) {
            throw ValidationException::withMessages(['label_en' => 'A label (1–200 characters) is required.']);
        }
        $norm = MasterDataNormalizer::normalize($en);
        $clash = MasterDataValue::where('list_id', $l->id)->where('status', 'ACTIVE')->where(fn ($q) => $q->whereNull('tenant_id')->orWhere('tenant_id', $tenantId))
            ->get(['code', 'search_text'])->first(fn ($v) => str_contains(' '.$v->search_text.' ', " $norm "));
        if ($clash) {
            throw ValidationException::withMessages(['label_en' => "Already in the list as {$clash->code}; use it (or add a tenant alias) instead."]);
        }
        $code = 'T_'.substr(strtoupper(substr(str_replace('-', '', $tenantId), 0, 6)), 0, 6).'_'.MasterDataNormalizer::codeFrom($en);
        if (MasterDataValue::where(['list_id' => $l->id, 'code' => $code])->exists()) {
            throw ValidationException::withMessages(['label_en' => 'This private value already exists.']);
        }

        return DB::transaction(function () use ($l, $tenantId, $code, $en, $fr, $data, $actorId) {
            $v = MasterDataValue::create(['list_id' => $l->id, 'domain_code' => $l->domain_code, 'list_code' => $l->code, 'code' => $code,
                'label_en' => $en, 'label_fr' => $fr, 'parent_code' => ($data['parent_code'] ?? null) ?: null, 'tenant_id' => $tenantId,
                'source_type' => 'USER_SUBMITTED', 'source_reference' => 'tenant private value', 'sort_order' => 60000, 'admin_modified_at' => now()]);
            $this->log('VALUE', $v->id, $v->domain_code, 'PRIVATE_CREATED', null, ['tenant_id' => $tenantId, 'code' => $code, 'label_en' => $en], $actorId);

            return $v;
        });
    }

    public function mapForCarrier(string $carrierId, MasterDataValue $value, string $externalCode, ?string $externalLabel, string $target, ?string $actorId): CarrierMasterDataMapping
    {
        $this->assertMappable($value, $externalCode);
        $target = strtoupper(trim($target)) ?: 'CODE';

        return DB::transaction(function () use ($carrierId, $value, $externalCode, $externalLabel, $target, $actorId) {
            $m = CarrierMasterDataMapping::firstOrNew(['carrier_id' => $carrierId, 'value_id' => $value->id, 'target' => $target]);
            $before = $m->exists ? $m->only(['external_code', 'external_label', 'status']) : null;
            $m->fill(['external_code' => trim($externalCode), 'external_label' => $externalLabel, 'status' => 'ACTIVE'])->save();
            $this->log('CARRIER_MAPPING', $m->id, $value->domain_code, $before ? 'MAPPING_UPDATED' : 'MAPPING_CREATED', $before,
                ['carrier_id' => $carrierId, 'value' => $value->code, 'target' => $target, 'external_code' => $m->external_code], $actorId);

            return $m;
        });
    }

    public function mapForBroker(string $partnerId, MasterDataValue $value, string $externalCode, ?string $externalLabel, ?string $actorId): BrokerMasterDataMapping
    {
        $this->assertMappable($value, $externalCode);

        return DB::transaction(function () use ($partnerId, $value, $externalCode, $externalLabel, $actorId) {
            $m = BrokerMasterDataMapping::firstOrNew(['partner_id' => $partnerId, 'value_id' => $value->id]);
            $before = $m->exists ? $m->only(['external_code', 'external_label', 'status']) : null;
            $m->fill(['external_code' => trim($externalCode), 'external_label' => $externalLabel, 'status' => 'ACTIVE'])->save();
            $this->log('BROKER_MAPPING', $m->id, $value->domain_code, $before ? 'MAPPING_UPDATED' : 'MAPPING_CREATED', $before,
                ['partner_id' => $partnerId, 'value' => $value->code, 'external_code' => $m->external_code], $actorId);

            return $m;
        });
    }

    /** Mappings are deactivated, never deleted (transactions may have used them). */
    public function deactivateMapping(CarrierMasterDataMapping|BrokerMasterDataMapping $m, ?string $actorId): void
    {
        $m->update(['status' => 'INACTIVE']);
        $this->log($m instanceof CarrierMasterDataMapping ? 'CARRIER_MAPPING' : 'BROKER_MAPPING', $m->id, MasterDataValue::find($m->value_id)?->domain_code, 'MAPPING_DEACTIVATED', ['status' => 'ACTIVE'], ['status' => 'INACTIVE'], $actorId);
    }

    /** @return list<array<string, mixed>> the tenant's overrides with the value they apply to */
    public function overridesFor(string $tenantId, ?string $domain = null): array
    {
        return DB::table('master_data_tenant_overrides as o')->join('master_data_values as v', 'v.id', '=', 'o.value_id')
            ->where('o.tenant_id', $tenantId)->when($domain, fn ($q) => $q->where('v.domain_code', $domain))
            ->orderBy('v.domain_code')->orderBy('v.list_code')->orderBy('v.code')
            ->get(['o.id', 'o.action', 'o.alias', 'o.internal_code', 'o.value_id', 'v.domain_code', 'v.list_code', 'v.code', 'v.label_en', 'v.label_fr'])
            ->map(fn ($r) => (array) $r)->all();
    }

    private function assertMappable(MasterDataValue $value, string $externalCode): void
    {
        if ($value->tenant_id !== null) {
            throw ValidationException::withMessages(['value_id' => 'Map platform values only.']);
        }
        if (trim($externalCode) === '' || mb_strlen($externalCode) > 128) {
            throw ValidationException::withMessages(['external_code' => 'An external code (1–128 characters) is required.']);
        }
    }

    private function log(string $type, string $id, ?string $domain, string $action, ?array $before, ?array $after, ?string $actorId): void
    {
        MasterDataChange::create(['entity_type' => $type, 'entity_id' => $id, 'domain_code' => $domain, 'action' => $action, 'before' => $before, 'after' => $after,
            'actor_id' => $actorId, 'source' => 'ADMIN']);
        if ($domain) {
            MasterDataCache::bump($domain);
        }
    }
}
