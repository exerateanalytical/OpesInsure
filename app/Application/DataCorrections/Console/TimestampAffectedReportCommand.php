<?php

declare(strict_types=1);

namespace App\Application\DataCorrections\Console;

use App\Application\DataCorrections\TimestampOffsetAudit;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Owner decision 19, steps 1-4: read-only affected-row report for the pre-REQ-TMP-003 timestamptz offset defect.
 * Writes JSON + CSV under storage/app/reports. Modifies no data; the correction (steps 5-10) waits for the owner.
 */
final class TimestampAffectedReportCommand extends Command
{
    protected $signature = 'opesinsure:timestamps:affected-report
        {--cutoff= : Fix deployment instant (ISO 8601); default '.TimestampOffsetAudit::FIX_DEPLOYED_AT_UTC.' (release '.TimestampOffsetAudit::FIX_RELEASE.')}
        {--samples=5 : Sample rows per column}
        {--timezone= : App timezone in force before the fix (default config app.timezone)}';

    protected $description = 'Read-only report of timestamptz values written before the offset fix (owner decision 19, steps 1-4). Changes no data.';

    public function handle(TimestampOffsetAudit $audit): int
    {
        $report = $audit->report($this->option('cutoff') ?: null, max(0, min(50, (int) $this->option('samples'))), $this->option('timezone') ?: null);
        $stamp = now()->utc()->format('Ymd\THis\Z');
        $base = "timestamp-offset-affected-{$stamp}";
        $disk = Storage::build(['driver' => 'local', 'root' => storage_path('app/reports'), 'throw' => true]);   // storage/app/reports
        $disk->put("{$base}.json", json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        $csv = fopen('php://temp', 'r+');
        fputcsv($csv, ['table', 'column', 'non_null', 'affected', 'ambiguous', 'min_affected_stored_utc', 'max_affected_stored_utc', 'has_database_default', 'database_default', 'sample_primary_keys']);
        foreach ($report['columns'] as $c) {
            fputcsv($csv, [$c['table'], $c['column'], $c['non_null'], $c['affected'], $c['ambiguous'], $c['min_affected_stored_utc'], $c['max_affected_stored_utc'],
                $c['has_database_default'] ? 'yes' : 'no', $c['database_default'],
                implode(' | ', array_map(fn ($s) => json_encode($s['primary_key']).'@'.$s['stored_utc'].'('.$s['classification'].')', $c['samples']))]);
        }
        rewind($csv);
        $disk->put("{$base}.csv", stream_get_contents($csv));
        fclose($csv);

        $t = $report['totals'];
        $this->info("Fix: {$report['fix']['release']} ({$report['fix']['commit']}) deployed {$report['fix']['deployed_at_utc']}; offset {$report['defect']['offset_seconds']} s.");
        $this->table(['table', 'column', 'affected', 'ambiguous'], array_map(fn ($c) => [$c['table'], $c['column'], $c['affected'], $c['ambiguous']],
            array_values(array_filter($report['columns'], fn ($c) => $c['affected'] + $c['ambiguous'] > 0))));
        $this->info("Columns scanned: {$t['columns_scanned']}; with affected rows: {$t['columns_with_affected_rows']} in {$t['tables_with_affected_rows']} tables; affected values: {$t['affected_values']}; ambiguous: {$t['ambiguous_values']}.");
        $this->info('Report: '.$disk->path("{$base}.json"));
        $this->info('CSV:    '.$disk->path("{$base}.csv"));
        $this->warn('No data was modified. Steps 5-10 (backup, dry run, verification, correction, audit, chronology) need the owner\'s review of this report.');

        return self::SUCCESS;
    }
}
