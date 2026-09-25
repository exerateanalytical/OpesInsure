<?php

declare(strict_types=1);

namespace App\Application\MasterData;

use App\Models\MasterData\MasterDataValue;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * MDM-014 export of platform master data (CSV, XLSX, JSON). Imports go through the one generic pipeline
 * (App\Application\Import\ImportPipeline, target master_data_values) — REQ-IMP-001; the former
 * master-data-only importer was folded into it (its history stays in master_data_imports).
 */
final class MasterDataExportService
{
    /** Export a list (or whole domain) as CSV, XLSX or JSON; returns the file path. */
    public function export(string $domain, ?string $list, string $format): string
    {
        $rows = MasterDataValue::with('aliases')->where('domain_code', $domain)->when($list, fn ($q) => $q->where('list_code', $list))
            ->whereNull('tenant_id')->orderBy('list_code')->orderBy('sort_order')->get()
            ->map(fn ($v) => ['list' => $v->list_code, 'code' => $v->code, 'label_en' => $v->label_en, 'label_fr' => $v->label_fr, 'parent_code' => $v->parent_code,
                'status' => $v->status, 'source_type' => $v->source_type, 'source_reference' => $v->source_reference, 'aliases' => $v->aliases->pluck('alias')->join('|'),
                'attributes' => $v->attributes ? json_encode($v->attributes, JSON_UNESCAPED_UNICODE) : null])->all();
        $path = storage_path('app/master-data-export-'.$domain.($list ? "-$list" : '').'-'.now()->format('YmdHis').'.'.$format);
        if ($format === 'json') {
            file_put_contents($path, json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } elseif ($format === 'xlsx') {
            $w = new XlsxWriter;
            $w->openToFile($path);
            $w->addRow(Row::fromValues(array_keys($rows[0] ?? ['code' => ''])));
            foreach ($rows as $r) {
                $w->addRow(Row::fromValues(array_map(fn ($x) => (string) $x, array_values($r))));
            }
            $w->close();
        } else {
            $h = fopen($path, 'w');
            fwrite($h, "\xEF\xBB\xBF");
            fputcsv($h, array_keys($rows[0] ?? ['code' => '']));
            foreach ($rows as $r) {
                fputcsv($h, $r);
            }
            fclose($h);
        }

        return $path;
    }
}
