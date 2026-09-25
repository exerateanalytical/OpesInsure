<?php

declare(strict_types=1);

namespace App\Application\Regulatory\Rules;

use Illuminate\Support\Facades\DB;

/**
 * Agent B1 — REQ-RPT-002 impact analysis of a regulatory change.
 *
 * Scope keys (all optional): line_codes, product_codes, branch_codes (product_regulatory_mappings.branch_code),
 * document_type_codes, reference_set_code (regulatory_reference_sets.code).
 * Returns the affected products, tariffs (live tariff_versions of those products), document templates and other
 * regulatory rules / report definitions. Read-only.
 */
final class RegulatoryImpactAnalyzer
{
    private const LIVE_TARIFF_EXCLUDED = ['RETIRED', 'SUPERSEDED', 'EXPIRED', 'REJECTED', 'ARCHIVED'];

    /** @return array{products:list<array>,tariffs:list<array>,documents:list<array>,rules:list<array>,report_definitions:list<array>,summary:array<string,int>} */
    public function analyse(array $scope, string $jurisdiction = 'CM', ?string $code = null, ?string $excludeRuleId = null): array
    {
        $lines = array_values(array_filter((array) ($scope['line_codes'] ?? [])));
        $productCodes = array_values(array_filter((array) ($scope['product_codes'] ?? [])));
        $branches = array_values(array_filter((array) ($scope['branch_codes'] ?? [])));
        $docTypes = array_values(array_filter((array) ($scope['document_type_codes'] ?? [])));
        $refSet = $scope['reference_set_code'] ?? null;

        $products = collect();
        if ($lines !== [] || $productCodes !== [] || $branches !== []) {
            $products = DB::table('insurance_products')
                ->where(function ($q) use ($lines, $productCodes, $branches) {
                    $q->whereRaw('1 = 0');
                    if ($lines !== []) {
                        $q->orWhereIn('line_code', $lines);
                    }
                    if ($productCodes !== []) {
                        $q->orWhereIn('code', $productCodes);
                    }
                    if ($branches !== []) {
                        $q->orWhereIn('id', DB::table('product_regulatory_mappings')->whereIn('branch_code', $branches)->whereNull('effective_until')->select('insurance_product_id'));
                    }
                })
                ->whereNotIn('status', ['RETIRED', 'SUPERSEDED'])
                ->orderBy('code')->orderBy('version')->get(['id', 'code', 'version', 'line_code', 'status', 'carrier_id']);
        }
        $productIds = $products->pluck('id')->all();

        $tariffs = $productIds === [] ? collect() : DB::table('tariff_versions')->whereIn('insurance_product_id', $productIds)
            ->whereNotIn('status', self::LIVE_TARIFF_EXCLUDED)->orderBy('insurance_product_id')->orderBy('version')
            ->get(['id', 'insurance_product_id', 'version', 'status', 'effective_from', 'effective_until']);

        $documents = collect();
        if ($productIds !== [] || $lines !== [] || $docTypes !== []) {
            $documents = DB::table('document_templates')
                ->where(function ($q) use ($productIds, $lines, $docTypes) {
                    $q->whereRaw('1 = 0');
                    if ($productIds !== []) {
                        $q->orWhereIn('product_id', $productIds);
                    }
                    if ($lines !== []) {
                        $q->orWhereIn('insurance_class', $lines);
                    }
                    if ($docTypes !== []) {
                        $q->orWhereIn('document_type_code', $docTypes);
                    }
                })
                ->whereNull('retired_at')->orderBy('code')->orderBy('version')
                ->get(['id', 'code', 'document_type_code', 'version', 'status', 'product_id', 'insurance_class', 'language']);
        }

        $rules = DB::table('regulatory_rules')->where('jurisdiction', $jurisdiction)->where('status', '!=', 'SUPERSEDED')
            ->when($excludeRuleId, fn ($q, $v) => $q->where('id', '!=', $v))
            ->where(function ($q) use ($code, $refSet, $lines, $productCodes) {
                $q->whereRaw('1 = 0');
                if ($code !== null) {
                    $q->orWhere('code', $code);
                }
                if ($refSet !== null) {
                    $q->orWhere('reference_set_code', $refSet);
                }
                foreach ($lines as $l) {
                    $q->orWhereRaw("jsonb_exists(scope->'line_codes', ?)", [$l]);
                }
                foreach ($productCodes as $p) {
                    $q->orWhereRaw("jsonb_exists(scope->'product_codes', ?)", [$p]);
                }
            })
            ->orderBy('code')->orderBy('version')->get(['id', 'code', 'version', 'status', 'rule_type', 'effective_from']);

        $definitions = $refSet === null && $code === null ? collect() : DB::table('regulatory_report_definitions')->whereIn('status', ['DRAFT', 'ACTIVE'])
            ->where(function ($q) use ($refSet, $code) {
                $q->whereRaw('1 = 0');
                foreach (array_filter([$refSet, $code]) as $c) {
                    $q->orWhereRaw("jsonb_exists(schema->'rule_codes', ?)", [$c])->orWhereRaw("jsonb_exists(schema->'reference_set_codes', ?)", [$c]);
                }
            })->orderBy('code')->get(['id', 'code', 'version', 'status', 'report_type']);

        $out = ['products' => $products->map(fn ($x) => (array) $x)->all(), 'tariffs' => $tariffs->map(fn ($x) => (array) $x)->all(),
            'documents' => $documents->map(fn ($x) => (array) $x)->all(), 'rules' => $rules->map(fn ($x) => (array) $x)->all(),
            'report_definitions' => $definitions->map(fn ($x) => (array) $x)->all()];
        $out['summary'] = array_map('count', $out);

        return $out;
    }
}
