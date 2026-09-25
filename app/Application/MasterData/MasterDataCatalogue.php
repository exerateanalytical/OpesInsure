<?php

declare(strict_types=1);

namespace App\Application\MasterData;

use Illuminate\Support\Facades\DB;

/**
 * Read side of institutional master data: cached per domain catalog_version.
 * Values are exposed by canonical code with EN/FR labels, parent code and
 * attributes. Tenant overrides (hide / alias / internal code) are applied on top.
 */
final class MasterDataCatalogue
{
    /** @return array<int, array<string, mixed>> */
    public function domains(): array
    {
        return MasterDataCache::remember('_index', 'domains', fn () => DB::table('master_data_domains')->where('status', 'ACTIVE')
            ->orderBy('sort_order')->get()->map(fn ($d) => [
                'code' => $d->code, 'label' => ['en' => $d->label_en, 'fr' => $d->label_fr], 'number' => $d->number,
                'catalog_version' => (int) $d->catalog_version, 'lists' => DB::table('master_data_lists')->where('domain_id', $d->id)->where('status', 'ACTIVE')->orderBy('sort_order')->pluck('code')->all(),
            ])->all());
    }

    /** @return array<string, mixed>|null */
    public function domain(string $code): ?array
    {
        return MasterDataCache::remember($code, 'domain', function () use ($code): ?array {
            $d = DB::table('master_data_domains')->where('code', $code)->where('status', 'ACTIVE')->first();
            if (! $d) {
                return null;
            }
            $lists = DB::table('master_data_lists')->where('domain_id', $d->id)->where('status', 'ACTIVE')->orderBy('sort_order')->get();
            $values = DB::table('master_data_values')->where('domain_code', $code)->where('status', 'ACTIVE')->whereNull('tenant_id')->whereNull('merged_into_id')
                ->orderBy('sort_order')->orderBy('label_en')->get()->groupBy('list_id');
            $aliases = DB::table('master_data_aliases as a')->join('master_data_values as v', 'v.id', '=', 'a.value_id')->where('v.domain_code', $code)->whereNull('a.tenant_id')
                ->get(['a.value_id', 'a.alias'])->groupBy('value_id');
            $statuses = app(WorkflowDataStatuses::class)->byList();

            return [
                'code' => $d->code, 'label' => ['en' => $d->label_en, 'fr' => $d->label_fr], 'number' => $d->number,
                'disclaimer' => $d->disclaimer_en ? ['en' => $d->disclaimer_en, 'fr' => $d->disclaimer_fr] : null,
                'catalog_version' => (int) $d->catalog_version,
                'lists' => $lists->map(fn ($l) => [
                    'code' => $l->code, 'label' => ['en' => $l->label_en, 'fr' => $l->label_fr], 'parent_list' => $l->parent_list_code,
                    'selection' => $l->selection, 'allow_other' => (bool) $l->allow_other, 'structure_only' => (bool) $l->structure_only,
                    'source_type' => $l->source_type, 'source_reference' => $l->source_reference, 'version' => $l->version, 'note' => $l->note,
                    // Owner workflow data master status (PENDING_SOURCE lists may be empty; pickers still offer Other / Not listed).
                    'data_status' => $statuses[$code.'.'.$l->code]['status'] ?? null,
                    'values_status' => $statuses[$code.'.'.$l->code]['values_status'] ?? null,
                    'values' => ($values[$l->id] ?? collect())->map(fn ($v) => $this->present($v, $aliases[$v->id] ?? null))->values()->all(),
                ])->all(),
            ];
        });
    }

    /** @return array<string, mixed>|null */
    public function list(string $domain, string $list): ?array
    {
        $d = $this->domain($domain);
        $hit = $d ? collect($d['lists'])->firstWhere('code', $list) : null;
        if ($hit) {
            return $hit;
        }
        // Superseded (duplicate) lists keep answering: served from their canonical list.
        [$cd, $cl] = MasterDataFlows::resolveSource($domain, $list);

        return [$cd, $cl] !== [$domain, $list] ? $this->list($cd, $cl) : null;
    }

    /** Canonical [domain, list] for a request: the list itself while active, else its redirect target. */
    public function canonical(string $domain, string $list): array
    {
        $d = $this->domain($domain);
        if ($d && collect($d['lists'])->contains('code', $list)) {
            return [$domain, $list];
        }

        return MasterDataFlows::resolveSource($domain, $list);
    }

    /** Active value by code (merged codes redirect to the surviving value). */
    public function value(string $domain, string $list, string $code): ?array
    {
        $l = $this->list($domain, $list);
        if (! $l) {
            return null;
        }
        $hit = collect($l['values'])->firstWhere('code', $code);
        if ($hit) {
            return $hit;
        }
        [$cd, $cl] = $this->canonical($domain, $list);
        if ([$cd, $cl] !== [$domain, $list]) {
            // Code stored against a superseded list: the canonical list carries it as a seeded alias.
            $norm = MasterDataNormalizer::normalize($code);
            $alias = collect($l['values'])->first(fn ($v) => collect($v['aliases'] ?? [])->contains(fn ($a) => MasterDataNormalizer::normalize($a) === $norm));
            if ($alias) {
                return $alias;
            }
        }
        $redirect = DB::table('master_data_values as old')->join('master_data_values as new', 'new.id', '=', 'old.merged_into_id')
            ->where(['old.domain_code' => $domain, 'old.list_code' => $list, 'old.code' => $code])->value('new.code');

        return $redirect ? collect($l['values'])->firstWhere('code', $redirect) : null;
    }

    public function listExists(string $domain, string $list): bool
    {
        return $this->list($domain, $list) !== null;
    }

    /** Applies tenant overrides: hidden values removed, tenant aliases and internal codes added, private values appended. */
    public function forTenant(array $list, ?string $tenantId, string $domain): array
    {
        if (! $tenantId) {
            return $list;
        }
        $ids = array_column($list['values'], 'id');
        $overrides = DB::table('master_data_tenant_overrides')->where('tenant_id', $tenantId)->whereIn('value_id', $ids)->get()->groupBy('value_id');
        $list['values'] = collect($list['values'])->reject(fn ($v) => ($overrides[$v['id']] ?? collect())->contains('action', 'HIDE'))
            ->map(function ($v) use ($overrides) {
                foreach ($overrides[$v['id']] ?? [] as $o) {
                    if ($o->action === 'ALIAS' && $o->alias) {
                        $v['aliases'][] = $o->alias;
                    }
                    if ($o->action === 'INTERNAL_CODE') {
                        $v['internal_code'] = $o->internal_code;
                    }
                }

                return $v;
            })->values()->all();
        $private = DB::table('master_data_values')->where(['domain_code' => $domain, 'list_code' => $list['code'], 'tenant_id' => $tenantId, 'status' => 'ACTIVE'])->get();
        foreach ($private as $p) {
            $list['values'][] = $this->present($p, null) + ['private' => true];
        }

        return $list;
    }

    private function present(object $v, $aliases): array
    {
        $attrs = $v->attributes ? json_decode($v->attributes, true) : null;

        return array_filter([
            'id' => $v->id, 'code' => $v->code, 'label' => ['en' => $v->label_en, 'fr' => $v->label_fr],
            'description' => $v->description_en ? ['en' => $v->description_en, 'fr' => $v->description_fr] : null,
            'parent' => $v->parent_code, 'attributes' => $attrs, 'is_other' => (bool) $v->is_other ?: null, 'common' => (bool) $v->is_common ?: null,
            'aliases' => $aliases ? $aliases->pluck('alias')->values()->all() : null, 'source_type' => $v->source_type,
            'search' => $v->search_text, 'usage' => (int) $v->usage_count,
        ], fn ($x) => $x !== null);
    }
}
