<?php

declare(strict_types=1);

namespace App\Application\MasterData;

use App\Models\MasterData\MasterDataAlias;
use App\Models\MasterData\MasterDataImport;
use App\Models\MasterData\MasterDataList;
use App\Models\MasterData\MasterDataValue;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use OpenSpout\Common\Entity\Row;

/**
 * MDM-013/014 import and export. Import pipeline:
 * upload → map columns → validate → detect duplicates → preview → approve → import → audit.
 * Accepted formats: CSV, XLSX, JSON (array of objects). Columns: code, label_en,
 * label_fr, parent_code, description_en, description_fr, aliases (| separated),
 * source_reference. Existing codes are skipped (never overwritten); nothing is deleted.
 */
final class MasterDataImportService
{
    public const COLUMNS = ['code', 'label_en', 'label_fr', 'parent_code', 'description_en', 'description_fr', 'aliases', 'source_reference'];

    public function upload(string $domain, string $list, string $path, string $filename, ?string $userId, array $mapping = []): MasterDataImport
    {
        if (! MasterDataList::where(['domain_code' => $domain, 'code' => $list])->exists()) {
            throw ValidationException::withMessages(['list' => "Unknown list $domain.$list."]);
        }
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $rows = match ($ext) {
            'csv', 'txt' => $this->readCsv($path),
            'xlsx' => $this->readXlsx($path),
            'json' => json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR),
            default => throw ValidationException::withMessages(['file' => 'Use a CSV, XLSX or JSON file.']),
        };
        $rows = array_map(fn ($r) => $this->mapRow((array) $r, $mapping), array_values($rows));
        $import = MasterDataImport::create(['domain_code' => $domain, 'list_code' => $list, 'format' => $ext === 'txt' ? 'csv' : $ext, 'filename' => $filename,
            'status' => 'UPLOADED', 'mapping' => $mapping ?: null, 'rows' => $rows, 'created_by' => $userId]);

        return $this->validate($import);
    }

    /** Validates every row and flags duplicates (existing codes, normalized label or alias matches). */
    public function validate(MasterDataImport $import): MasterDataImport
    {
        $list = MasterDataList::where(['domain_code' => $import->domain_code, 'code' => $import->list_code])->firstOrFail();
        $existing = MasterDataValue::where('list_id', $list->id)->get(['id', 'code', 'label_en', 'label_fr', 'search_text']);
        $codes = $existing->pluck('code')->all();
        $seen = [];
        $report = ['valid' => 0, 'errors' => [], 'duplicates' => [], 'new' => []];
        foreach ($import->rows ?? [] as $i => $r) {
            $line = $i + 1;
            $code = strtoupper(trim((string) ($r['code'] ?? ''))) ?: MasterDataNormalizer::codeFrom((string) ($r['label_en'] ?? ''));
            if (! preg_match('/^[A-Z0-9_]+$/', $code) || trim((string) ($r['label_en'] ?? '')) === '' || trim((string) ($r['label_fr'] ?? '')) === '') {
                $report['errors'][] = ['row' => $line, 'error' => 'code (A-Z0-9_), label_en and label_fr are required'];
                continue;
            }
            if (isset($seen[$code])) {
                $report['errors'][] = ['row' => $line, 'error' => "code $code repeated in the file"];
                continue;
            }
            $seen[$code] = true;
            $norm = MasterDataNormalizer::normalize($r['label_en']);
            $dupe = in_array($code, $codes, true) ? $code : $existing->first(fn ($v) => str_contains((string) $v->search_text, " $norm "))?->code;
            if ($dupe) {
                $report['duplicates'][] = ['row' => $line, 'code' => $code, 'matches' => $dupe];
                continue;
            }
            $report['valid']++;
            $report['new'][] = $code;
        }
        $import->update(['status' => $report['errors'] ? 'FAILED' : 'VALIDATED', 'report' => $report]);

        return $import;
    }

    /** Approve and import the valid, non-duplicate rows. */
    public function import(MasterDataImport $import, ?string $userId): MasterDataImport
    {
        if ($import->status !== 'VALIDATED') {
            throw ValidationException::withMessages(['status' => 'Only a validated import (no errors) can be imported.']);
        }
        $list = MasterDataList::where(['domain_code' => $import->domain_code, 'code' => $import->list_code])->firstOrFail();
        $new = array_flip($import->report['new'] ?? []);
        DB::transaction(function () use ($import, $list, $new) {
            foreach ($import->rows as $r) {
                $code = strtoupper(trim((string) ($r['code'] ?? ''))) ?: MasterDataNormalizer::codeFrom((string) $r['label_en']);
                if (! isset($new[$code])) {
                    continue;
                }
                $v = MasterDataValue::create([
                    'list_id' => $list->id, 'domain_code' => $list->domain_code, 'list_code' => $list->code, 'code' => $code,
                    'label_en' => trim($r['label_en']), 'label_fr' => trim($r['label_fr']), 'parent_code' => ($r['parent_code'] ?? null) ?: null,
                    'description_en' => ($r['description_en'] ?? null) ?: null, 'description_fr' => ($r['description_fr'] ?? null) ?: null,
                    'source_type' => 'MANUAL_VERIFIED', 'source_reference' => ($r['source_reference'] ?? null) ?: "import {$import->filename}",
                    'sort_order' => 50000, 'verified_at' => now(), 'verified_by' => $import->created_by,
                ]);
                foreach (array_filter(array_map('trim', explode('|', (string) ($r['aliases'] ?? '')))) as $alias) {
                    MasterDataAlias::create(['value_id' => $v->id, 'alias' => $alias]);
                }
            }
            if ($list->parent_list_code) {
                [$pd, $pl] = str_contains($list->parent_list_code, '.') ? explode('.', $list->parent_list_code, 2) : [$list->domain_code, $list->parent_list_code];
                $parentListId = MasterDataList::where(['domain_code' => $pd, 'code' => $pl])->value('id');
                DB::update('UPDATE master_data_values v SET parent_value_id = p.id FROM master_data_values p WHERE v.list_id = ? AND p.list_id = ? AND p.code = v.parent_code AND v.parent_value_id IS NULL', [$list->id, $parentListId]);
            }
        });
        $import->update(['status' => 'IMPORTED', 'approved_by' => $userId, 'imported_at' => now()]);

        return $import;
    }

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

    private function mapRow(array $row, array $mapping): array
    {
        $out = [];
        foreach (self::COLUMNS as $col) {
            $src = $mapping[$col] ?? $col;
            $out[$col] = isset($row[$src]) ? (is_array($row[$src]) ? implode('|', $row[$src]) : trim((string) $row[$src])) : null;
        }

        return $out;
    }

    private function readCsv(string $path): array
    {
        $h = fopen($path, 'r');
        $header = null;
        $rows = [];
        while (($r = fgetcsv($h)) !== false) {
            if ($header === null) {
                $header = array_map(fn ($c) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string) $c))), $r);
                continue;
            }
            if ($r === [null]) {
                continue;
            }
            $rows[] = array_combine($header, array_pad($r, count($header), null));
        }
        fclose($h);

        return $rows;
    }

    private function readXlsx(string $path): array
    {
        $reader = new XlsxReader;
        $reader->open($path);
        $rows = [];
        $header = null;
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $cells = array_map(fn ($c) => (string) $c->getValue(), $row->getCells());
                if ($header === null) {
                    $header = array_map(fn ($c) => strtolower(trim($c)), $cells);
                    continue;
                }
                $rows[] = array_combine($header, array_pad($cells, count($header), null));
            }
            break;
        }
        $reader->close();

        return $rows;
    }
}
