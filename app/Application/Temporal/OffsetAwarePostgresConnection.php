<?php

declare(strict_types=1);

namespace App\Application\Temporal;

use App\Application\Demo\DemoMode;
use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\Grammars\PostgresGrammar;

/**
 * REQ-TMP-003 — timestamptz stores true instants.
 *
 * Laravel formats every DateTimeInterface binding / Eloquent date as
 * 'Y-m-d H:i:s' (no offset). PostgreSQL then reads that wall clock in the
 * *session* timezone, so an app running in Africa/Douala against a UTC
 * session stored instants one hour off (the old DemoPurchaseSettler
 * workaround). Writing with an explicit offset ('Y-m-d H:i:sP') makes the
 * stored instant independent of the session timezone. Values read back carry
 * their offset and Eloquent parses them (createFromFormat, then parse()).
 * Date / timestamp-without-tz columns ignore the offset, so they are unchanged.
 *
 * S13 — while demo mode is off, a SELECT whose root table carries the is_demo
 * flag (DemoMode::TABLES) gets "AND <table>.is_demo = false" appended, the
 * original WHERE being wrapped in parentheses so OR clauses keep their
 * meaning. Only reads are filtered; the clause adds no bindings.
 */
final class OffsetAwarePostgresConnection extends PostgresConnection
{
    public const DATE_FORMAT = 'Y-m-d H:i:sP';

    protected function getDefaultQueryGrammar()
    {
        $grammar = new class($this) extends PostgresGrammar
        {
            public function getDateFormat()
            {
                return OffsetAwarePostgresConnection::DATE_FORMAT;
            }

            public function compileSelect(Builder $query)
            {
                return parent::compileSelect(OffsetAwarePostgresConnection::withoutDemoRows($query, $this));
            }
        };

        return $grammar;
    }

    /** @internal S13 demo-row filter; returns $query untouched unless it must be filtered. */
    public static function withoutDemoRows(Builder $query, PostgresGrammar $grammar): Builder
    {
        $targets = [];
        if ($alias = self::flaggedAlias($query->from)) {
            $targets[] = $alias;
        }
        foreach ((array) $query->joins as $join) {
            if ($alias = self::flaggedAlias($join->table)) {
                $targets[] = $alias;
            }
        }
        if ($targets === []) {
            return $query;
        }
        try {
            if (! app(DemoMode::class)->hidesDemoData()) {
                return $query;
            }
            $targets = array_filter($targets, fn (array $t) => DemoMode::isFlagged($t[0]));
        } catch (\Throwable) {
            return $query;
        }
        if ($targets === []) {
            return $query;
        }

        $filtered = clone $query;
        $filtered->wheres = [];
        if ($query->wheres !== []) {
            $nested = $query->forNestedWhere();
            $nested->wheres = $query->wheres;
            $filtered->wheres[] = ['type' => 'Nested', 'query' => $nested, 'boolean' => 'and'];
        }
        // IS NOT TRUE also keeps rows whose LEFT JOIN found nothing.
        foreach ($targets as [, $alias]) {
            $filtered->wheres[] = ['type' => 'raw', 'sql' => $grammar->wrap($alias.'.is_demo').' IS NOT TRUE', 'boolean' => 'and'];
        }

        return $filtered;
    }

    /** @return array{0: string, 1: string}|null [table, alias] when $from names a demo-filtered table. */
    private static function flaggedAlias(mixed $from): ?array
    {
        if (! is_string($from) || ! preg_match('/^\s*"?(\w+)"?(?:\s+as\s+"?(\w+)"?)?\s*$/i', $from, $m) || ! DemoMode::isFiltered($m[1])) {
            return null;
        }

        return [$m[1], ($m[2] ?? '') !== '' ? $m[2] : $m[1]];
    }
}
