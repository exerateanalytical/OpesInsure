<?php

declare(strict_types=1);

namespace App\Application\Documents\Scanning;

/**
 * Where clamd listens: CLAMAV_SOCKET (a unix socket path, preferred when set) or CLAMAV_HOST + CLAMAV_PORT (default
 * 3310). Shared by ClamAvMalwareScanAdapter (INSTREAM) and MalwareScannerHealth (PING / VERSION) so both always talk
 * to the same daemon.
 */
final class ClamAvEndpoint
{
    public static function configured(): bool
    {
        return filled(config('services.clamav.socket')) || filled(config('services.clamav.host'));
    }

    /** Human-readable target, e.g. "tcp://127.0.0.1:3310" or "unix:///run/clamav/clamd.ctl" (no secrets involved). */
    public static function describe(): ?string
    {
        if (filled($socket = (string) config('services.clamav.socket'))) {
            return 'unix://'.$socket;
        }
        if (filled($host = (string) config('services.clamav.host'))) {
            return 'tcp://'.$host.':'.self::port();
        }

        return null;
    }

    public static function port(): int
    {
        $port = (int) config('services.clamav.port', 3310);

        return $port > 0 ? $port : 3310;
    }

    public static function timeout(): int
    {
        return max(1, (int) config('services.clamav.timeout', 10));
    }

    /**
     * @return resource|false
     */
    public static function open(?int $timeout = null, ?string &$error = null)
    {
        $timeout ??= self::timeout();
        $errno = 0;
        $errstr = '';
        if (filled($socket = (string) config('services.clamav.socket'))) {
            $handle = @stream_socket_client('unix://'.$socket, $errno, $errstr, $timeout);
        } elseif (filled($host = (string) config('services.clamav.host'))) {
            $handle = @fsockopen($host, self::port(), $errno, $errstr, $timeout);
        } else {
            $error = 'ClamAV is not configured (set CLAMAV_HOST or CLAMAV_SOCKET).';

            return false;
        }
        if ($handle === false) {
            $error = 'Unable to reach the ClamAV daemon at '.self::describe().': '.($errstr ?: 'connection failed');

            return false;
        }
        stream_set_timeout($handle, $timeout);

        return $handle;
    }
}
