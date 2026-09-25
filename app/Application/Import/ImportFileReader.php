<?php

declare(strict_types=1);

namespace App\Application\Import;

use Illuminate\Validation\ValidationException;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;

/** REQ-IMP-001 — reads CSV (UTF-8, BOM tolerated, , or ; separated), XLSX (first sheet) or JSON (array of objects). Headers are lower-cased. */
final class ImportFileReader
{
    public const MAX_ROWS = 20000;

    public const FORMATS = ['csv', 'xlsx', 'json'];

    public static function format(string $filename): string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        return match ($ext) {
            'csv', 'txt' => 'csv',
            'xlsx' => 'xlsx',
            'json' => 'json',
            default => throw ValidationException::withMessages(['file' => 'Use a CSV, XLSX or JSON file.']),
        };
    }

    /** @return array{columns: list<string>, rows: list<array<string, string|null>>} */
    public function read(string $path, string $format): array
    {
        if (! is_file($path)) {
            throw ValidationException::withMessages(['file' => 'The uploaded file could not be read.']);
        }
        $rows = match ($format) {
            'csv' => $this->csv($path),
            'xlsx' => $this->xlsx($path),
            'json' => $this->json($path),
        };
        if (count($rows) > self::MAX_ROWS) {
            throw ValidationException::withMessages(['file' => 'A file may hold at most '.self::MAX_ROWS.' rows; split it.']);
        }
        $columns = [];
        foreach ($rows as $r) {
            foreach (array_keys($r) as $k) {
                $columns[$k] = true;
            }
        }

        return ['columns' => array_keys($columns), 'rows' => $rows];
    }

    private static function header(string $c): string
    {
        return strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', $c)));
    }

    private function csv(string $path): array
    {
        $h = fopen($path, 'r');
        $first = (string) fgets($h);
        rewind($h);
        $sep = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';
        $header = null;
        $rows = [];
        while (($r = fgetcsv($h, null, $sep)) !== false) {
            if ($header === null) {
                $header = array_map(fn ($c) => self::header((string) $c), $r);
                continue;
            }
            if ($r === [null] || implode('', array_map('strval', $r)) === '') {
                continue;
            }
            $rows[] = array_combine($header, array_slice(array_pad($r, count($header), null), 0, count($header)));
        }
        fclose($h);

        return $rows;
    }

    private function xlsx(string $path): array
    {
        $reader = new XlsxReader;
        $reader->open($path);
        $rows = [];
        $header = null;
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $cells = array_map(fn ($c) => ($v = $c->getValue()) instanceof \DateTimeInterface ? $v->format('Y-m-d') : (string) $v, $row->getCells());
                if ($header === null) {
                    $header = array_map(fn ($c) => self::header($c), $cells);
                    continue;
                }
                if (implode('', $cells) === '') {
                    continue;
                }
                $rows[] = array_combine($header, array_slice(array_pad($cells, count($header), null), 0, count($header)));
            }
            break;
        }
        $reader->close();

        return $rows;
    }

    private function json(string $path): array
    {
        try {
            $data = json_decode((string) file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw ValidationException::withMessages(['file' => 'The JSON file is not valid.']);
        }
        if (! is_array($data) || ! array_is_list($data)) {
            throw ValidationException::withMessages(['file' => 'The JSON file must be an array of objects.']);
        }

        return array_map(function ($r) {
            $out = [];
            foreach ((array) $r as $k => $v) {
                $out[self::header((string) $k)] = is_array($v) ? implode('|', array_map('strval', $v)) : ($v === null ? null : (string) $v);
            }

            return $out;
        }, $data);
    }
}
