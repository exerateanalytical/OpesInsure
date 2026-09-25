<?php

declare(strict_types=1);

namespace App\Application\Temporal;

use Illuminate\Database\PostgresConnection;
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
        };

        return $grammar;
    }
}
