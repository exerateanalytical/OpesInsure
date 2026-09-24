<?php

declare(strict_types=1);

namespace App\Application\MasterData;

use Illuminate\Support\Facades\DB;

/**
 * MDM-001 dashboard and MDM-020 data-quality figures. Completeness % is data
 * completeness (translated, described, verified/sourced) — not a business rating.
 */
final class MasterDataQualityReport
{
    public function summary(): array
    {
        $v = DB::table('master_data_values')->whereNull('tenant_id');

        return [
            'domains' => DB::table('master_data_domains')->count(),
            'lists' => DB::table('master_data_lists')->count(),
            'values' => (clone $v)->count(),
            'active' => (clone $v)->where('status', 'ACTIVE')->count(),
            'aliases' => DB::table('master_data_aliases')->count(),
            'open_suggestions' => DB::table('master_data_review_queue')->whereIn('status', ['SUBMITTED', 'UNDER_REVIEW', 'DUPLICATE_FOUND'])->count(),
            'duplicate_suggestions' => DB::table('master_data_review_queue')->where('status', 'DUPLICATE_FOUND')->count(),
            'carrier_mappings' => DB::table('carrier_master_data_mappings')->count(),
            'broker_mappings' => DB::table('broker_master_data_mappings')->count(),
            'changes_30d' => DB::table('master_data_changes')->where('created_at', '>=', now()->subDays(30))->count(),
            'by_source' => (clone $v)->select('source_type', DB::raw('count(*) as n'))->groupBy('source_type')->orderByDesc('n')->pluck('n', 'source_type')->all(),
        ];
    }

    /** @return array<string, mixed> */
    public function quality(): array
    {
        $values = DB::table('master_data_values')->whereNull('tenant_id')->where('is_other', false);
        $dupLabels = DB::table('master_data_values')->select('list_id', DB::raw('lower(label_en) as l'), DB::raw('count(*) as n'))
            ->where('status', 'ACTIVE')->whereNull('tenant_id')->groupBy('list_id', DB::raw('lower(label_en)'))->havingRaw('count(*) > 1')->get();

        return [
            'untranslated' => (clone $values)->whereColumn('label_en', 'label_fr')->whereRaw("label_en ~ '[a-z]{4,}'")->limit(200)
                ->get(['id', 'domain_code', 'list_code', 'code', 'label_en', 'label_fr'])->all(),
            'duplicates' => $dupLabels->count(),
            'unverified' => (clone $values)->whereNull('verified_at')->whereIn('source_type', ['USER_SUBMITTED'])->count(),
            'high_frequency_manual' => DB::table('master_data_review_queue')->whereIn('status', ['SUBMITTED', 'UNDER_REVIEW', 'DUPLICATE_FOUND'])
                ->orderByDesc('submission_count')->limit(20)->get(['id', 'domain_code', 'list_code', 'raw_input', 'submission_count'])->all(),
            'unused' => (clone $values)->where('status', 'ACTIVE')->where('usage_count', 0)->count(),
            'failed_mappings' => DB::table('carrier_master_data_mappings as m')->join('master_data_values as v', 'v.id', '=', 'm.value_id')->where('v.status', 'INACTIVE')->count(),
            'stale' => (clone $values)->whereNotNull('effective_until')->where('effective_until', '<', now()->toDateString())->where('status', 'ACTIVE')->count(),
            'empty_lists' => DB::table('master_data_lists as l')->where('structure_only', false)->whereNotExists(fn ($q) => $q->from('master_data_values as v')->whereColumn('v.list_id', 'l.id')->where('v.is_other', false))
                ->get(['domain_code', 'code'])->all(),
            'structure_only' => DB::table('master_data_lists')->where('structure_only', true)->get(['domain_code', 'code', 'note'])->all(),
            'completeness' => $this->completeness(),
        ];
    }

    /** @return array<int, array{domain:string, values:int, completeness:float}> */
    public function completeness(): array
    {
        return DB::table('master_data_values')->whereNull('tenant_id')->where('is_other', false)
            ->select('domain_code',
                DB::raw('count(*) as n'),
                DB::raw("sum(case when label_fr <> '' and label_en <> '' then 1 else 0 end) as translated"),
                DB::raw("sum(case when source_type is not null and (source_reference is not null or source_type = 'PLATFORM_NORMALIZED' or verified_at is not null) then 1 else 0 end) as sourced"),
                DB::raw("sum(case when status = 'ACTIVE' then 1 else 0 end) as active"))
            ->groupBy('domain_code')->orderBy('domain_code')->get()
            ->map(fn ($r) => ['domain' => $r->domain_code, 'values' => (int) $r->n,
                'completeness' => $r->n ? round(100 * ($r->translated + $r->sourced + $r->active) / (3 * $r->n), 1) : 0.0])
            ->all();
    }
}
