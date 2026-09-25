<?php

declare(strict_types=1);

namespace App\Application\DataCorrections;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Owner decision 19 (2026-09-25) — historical timestamp correction, steps 1-4 ONLY (read-only):
 *
 *  1. Defective application path: before REQ-TMP-003, Laravel formatted every DateTimeInterface binding / Eloquent
 *     date as 'Y-m-d H:i:s' (no offset). The app runs in config('app.timezone') (Africa/Douala, UTC+1, no DST) and
 *     the PostgreSQL session ran in UTC, so each timestamptz written by the application (Eloquent timestamps,
 *     casts, query-builder Carbon bindings) stored the Douala wall clock as if it were UTC: the stored instant
 *     is later than the true instant by the app timezone's UTC offset (+1h).
 *  2. Fix: App\Application\Temporal\OffsetAwarePostgresConnection (writes 'Y-m-d H:i:sP') + session timezone in
 *     TemporalServiceProvider, introduced by commit 676908c (Phase 2) and deployed as release r20260925-034108
 *     (release names use the server's UTC clock: 2026-09-25T03:41:08Z, 14 minutes after the 03:27Z commit).
 *  3. Affected columns: every `timestamp with time zone` column of the application schema. Date and
 *     `timestamp without time zone` columns are NOT affected (PostgreSQL ignores the offset there, so the stored
 *     wall clock is the same before and after the fix).
 *  4. Affected rows: classified by row provenance (see predicates()): values certainly written before the
 *     cutoff are AFFECTED; values that may have been (row updated after the fix, or created within one offset
 *     of the cutoff) are AMBIGUOUS and counted apart. Values filled by a database default (now() /
 *     CURRENT_TIMESTAMP) were computed by PostgreSQL and are correct; such columns are flagged.
 *
 * Nothing is modified: the report runs inside a READ ONLY transaction. Steps 5-10 (backup, dry run, verification,
 * targeted correction, migration audit, downstream chronology) need the owner's review of this report.
 */
final class TimestampOffsetAudit
{
    public const FIX_COMMIT = '676908cb5a646d6d58e32e3fa90768d263f48113';

    public const FIX_RELEASE = 'r20260925-034108';

    public const FIX_DEPLOYED_AT_UTC = '2026-09-25T03:41:08Z';

    /** Tables whose rows are not business data written through the defective path. */
    public const EXCLUDED_TABLES = ['migrations'];

    /**
     * @return array<string, mixed>
     */
    public function report(?string $cutoffUtc = null, int $samples = 5, ?string $appTimezone = null): array
    {
        $cutoff = CarbonImmutable::parse($cutoffUtc ?? self::FIX_DEPLOYED_AT_UTC)->utc();
        $tz = $appTimezone ?? (string) config('app.timezone', 'Africa/Douala');
        // Offset of the app timezone at the cutoff (Africa/Douala has no DST: constant +3600 s).
        $offset = (int) $cutoff->setTimezone($tz)->getOffset();
        $upper = $cutoff->addSeconds(max(0, $offset));
        $conn = DB::connection();

        $outer = $conn->transactionLevel() > 0;

        return $conn->transaction(function () use ($conn, $cutoff, $upper, $offset, $tz, $samples, $outer) {
            // Own transaction: enforce READ ONLY at the database. Inside a caller's transaction (tests) only
            // SELECTs run, and changing the outer transaction's mode is not ours to do.
            if (! $outer) {
                $conn->statement('SET TRANSACTION READ ONLY');
            }
            $columns = $conn->select("SELECT c.table_name, c.column_name, c.column_default
                FROM information_schema.columns c JOIN information_schema.tables t ON t.table_schema = c.table_schema AND t.table_name = c.table_name
                WHERE c.table_schema = current_schema() AND t.table_type = 'BASE TABLE' AND c.data_type = 'timestamp with time zone'
                ORDER BY c.table_name, c.ordinal_position");
            $pk = [];
            foreach ($conn->select("SELECT tc.table_name, kcu.column_name FROM information_schema.table_constraints tc
                JOIN information_schema.key_column_usage kcu ON kcu.constraint_name = tc.constraint_name AND kcu.table_schema = tc.table_schema
                WHERE tc.table_schema = current_schema() AND tc.constraint_type = 'PRIMARY KEY' ORDER BY kcu.ordinal_position") as $r) {
                $pk[$r->table_name][] = $r->column_name;
            }

            $tzCols = [];
            foreach ($columns as $c) {
                $tzCols[$c->table_name][] = $c->column_name;
            }

            $rows = [];
            $totals = ['columns_scanned' => 0, 'columns_with_affected_rows' => 0, 'affected_values' => 0, 'ambiguous_values' => 0, 'tables_with_affected_rows' => []];
            [$lo, $hi] = [$cutoff->toIso8601String(), $upper->toIso8601String()];
            foreach ($columns as $c) {
                if (in_array($c->table_name, self::EXCLUDED_TABLES, true)) {
                    continue;
                }
                $totals['columns_scanned']++;
                $t = $this->q($c->table_name);
                $col = $this->q($c->column_name);
                [$affectedSql, $ambiguousSql, $basis] = $this->predicates($c->column_name, $tzCols[$c->table_name], $lo, $hi);
                $stat = $conn->selectOne("SELECT count(*) FILTER (WHERE {$col} IS NOT NULL) AS non_null,
                        count(*) FILTER (WHERE {$col} IS NOT NULL AND ({$affectedSql})) AS affected,
                        count(*) FILTER (WHERE {$col} IS NOT NULL AND ({$ambiguousSql})) AS ambiguous,
                        min({$col}) FILTER (WHERE {$affectedSql}) AS min_affected, max({$col}) FILTER (WHERE {$affectedSql}) AS max_affected
                    FROM {$t}");
                $affected = (int) $stat->affected;
                $ambiguous = (int) $stat->ambiguous;
                $default = (string) ($c->column_default ?? '');
                $sampleRows = [];
                if ($samples > 0 && ($affected + $ambiguous) > 0) {
                    $keys = $pk[$c->table_name] ?? [];
                    $keySql = $keys ? implode(', ', array_map(fn ($k) => $this->q($k).'::text AS '.$this->q('pk_'.$k), $keys)) : 'ctid::text AS pk_ctid';
                    foreach (['AFFECTED' => $affectedSql, 'AMBIGUOUS' => $ambiguousSql] as $class => $sql) {
                        foreach ($conn->select("SELECT {$keySql}, {$col} AS stored FROM {$t} WHERE {$col} IS NOT NULL AND ({$sql}) ORDER BY {$col} DESC LIMIT {$samples}") as $r) {
                            $r = (array) $r;
                            $stored = CarbonImmutable::parse((string) $r['stored'])->utc();
                            unset($r['stored']);
                            $sampleRows[] = ['primary_key' => $r, 'stored_utc' => $stored->toIso8601String(), 'classification' => $class,
                                'proposed_true_utc_not_applied' => $stored->subSeconds($offset)->toIso8601String()];
                        }
                    }
                }
                if ($affected > 0) {
                    $totals['columns_with_affected_rows']++;
                    $totals['tables_with_affected_rows'][$c->table_name] = true;
                }
                $totals['affected_values'] += $affected;
                $totals['ambiguous_values'] += $ambiguous;
                $rows[] = ['table' => $c->table_name, 'column' => $c->column_name, 'non_null' => (int) $stat->non_null,
                    'affected' => $affected, 'ambiguous' => $ambiguous, 'row_provenance_basis' => $basis,
                    'min_affected_stored_utc' => $stat->min_affected ? CarbonImmutable::parse((string) $stat->min_affected)->utc()->toIso8601String() : null,
                    'max_affected_stored_utc' => $stat->max_affected ? CarbonImmutable::parse((string) $stat->max_affected)->utc()->toIso8601String() : null,
                    'has_database_default' => $default !== '', 'database_default' => $default ?: null,
                    'note' => $default !== '' && preg_match('/now\(\)|current_timestamp/i', $default)
                        ? 'Column has a database-side default: rows filled by PostgreSQL are correct and must be excluded before any correction.' : null,
                    'samples' => $sampleRows];
            }
            $totals['tables_with_affected_rows'] = count($totals['tables_with_affected_rows']);
            $server = $conn->selectOne("SELECT reset_val, boot_val FROM pg_settings WHERE name = 'TimeZone'");

            return [
                'report' => 'timestamp_offset_affected_rows', 'read_only' => true, 'data_modified' => false,
                'generated_at_utc' => now()->utc()->toIso8601String(),
                'owner_decision' => 'OWNER_DECISIONS_2026-09-25 item 19 (steps 1-4 of 10)',
                'defect' => [
                    'path' => 'Laravel DateTimeInterface bindings / Eloquent dates formatted without offset (Y-m-d H:i:s) written to timestamptz while the app ran in '.$tz.' and the PostgreSQL session in UTC.',
                    'effect' => 'Stored instant = true instant + UTC offset of '.$tz.' ('.$offset.' s).',
                    'app_timezone' => $tz, 'offset_seconds' => $offset,
                    'unaffected' => ['date columns', 'timestamp without time zone columns', 'values filled by PostgreSQL defaults (now(), CURRENT_TIMESTAMP)'],
                ],
                'fix' => ['commit' => self::FIX_COMMIT, 'release' => self::FIX_RELEASE, 'deployed_at_utc' => $cutoff->toIso8601String(),
                    'cutoff_source' => $cutoff->equalTo(CarbonImmutable::parse(self::FIX_DEPLOYED_AT_UTC)) ? 'release name (server UTC clock)' : 'operator override (--cutoff)',
                    'component' => 'App\\Application\\Temporal\\OffsetAwarePostgresConnection + TemporalServiceProvider session timezone'],
                'classification' => ['AFFECTED' => 'certainly written before the fix (row created and last updated before the cutoff; or, without created_at, value < cutoff)', 'AMBIGUOUS' => 'possibly written before the fix (row updated after the cutoff, or written within one offset of it); needs row-level evidence such as audit logs'],
                'database' => ['connection' => $conn->getName(), 'database' => $conn->getDatabaseName(), 'server_timezone_reset_val' => $server->reset_val ?? null, 'server_timezone_boot_val' => $server->boot_val ?? null,
                    'caveat' => 'The +offset shift assumes the pre-fix PostgreSQL session ran in UTC (as observed in production, docs/HANDOVER_MOBILE_PLATFORM_AUDIT.md). Confirm before correcting.'],
                'totals' => $totals,
                'columns' => $rows,
                'next_steps_require_owner' => ['5. Back up', '6. Dry run', '7. Verify before/after samples', '8. Execute the targeted correction', '9. Persist a migration audit', '10. Verify downstream chronology'],
            ];
        });
    }

    /**
     * Row provenance: a value is AFFECTED when it was certainly written before the fix, AMBIGUOUS when it may have
     * been. Future-dated columns (expires_at, period ends) are why the value itself is not the test: a pre-fix
     * write of a 2027 expiry is still shifted.
     *   - created_at itself, or a table without created_at: value < cutoff => AFFECTED; value in the window => AMBIGUOUS.
     *   - other columns of a table with created_at: row created before the fix (created_at < cutoff) and never
     *     updated after it (updated_at absent or < cutoff) => AFFECTED; row created before the fix but updated
     *     after it, or created in the window => AMBIGUOUS (the later update may or may not have rewritten this
     *     column; audit logs decide).
     * $lo / $hi come from Carbon (ISO 8601), never from user text.
     *
     * @param  list<string>  $tableTzColumns
     * @return array{0: string, 1: string, 2: string}
     */
    private function predicates(string $column, array $tableTzColumns, string $lo, string $hi): array
    {
        $L = "'{$lo}'::timestamptz";
        $H = "'{$hi}'::timestamptz";
        $col = $this->q($column);
        $hasCreated = in_array('created_at', $tableTzColumns, true);
        if (! $hasCreated || $column === 'created_at') {
            return ["{$col} < {$L}", "{$col} >= {$L} AND {$col} < {$H}", $hasCreated ? 'VALUE_OF_CREATED_AT' : 'COLUMN_VALUE (no created_at)'];
        }
        $notUpdatedAfter = in_array('updated_at', $tableTzColumns, true) && $column !== 'updated_at' ? ' AND ("updated_at" IS NULL OR "updated_at" < '.$L.')' : '';
        if ($column === 'updated_at') {
            return ["{$col} < {$L}", "\"created_at\" < {$H} AND {$col} >= {$L} AND {$col} < {$H}", 'VALUE_OF_UPDATED_AT'];
        }

        return ["\"created_at\" < {$L}{$notUpdatedAfter}",
            "\"created_at\" < {$H} AND NOT (\"created_at\" < {$L}{$notUpdatedAfter})",
            $notUpdatedAfter ? 'ROW_CREATED_AND_LAST_UPDATED_BEFORE_FIX' : 'ROW_CREATED_BEFORE_FIX'];
    }

    private function q(string $ident): string
    {
        return '"'.str_replace('"', '""', $ident).'"';
    }
}
