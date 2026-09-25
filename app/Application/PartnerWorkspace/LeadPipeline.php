<?php

declare(strict_types=1);

namespace App\Application\PartnerWorkspace;

use Illuminate\Validation\ValidationException;

/**
 * REQ-CRM-001 lead pipeline (BP I leads, WF-005..007):
 * NEW → CONTACTED → QUALIFIED → QUOTE → NEGOTIATION → WON / LOST.
 *
 * WON is stored as CONVERTED (the Wave 16 value the mobile app already
 * renders) and is reached only through a consented conversion, never by a
 * plain status change. LOST can be reopened to NEW.
 */
final class LeadPipeline
{
    public const WON = 'CONVERTED';

    public const STATUSES = ['NEW', 'CONTACTED', 'QUALIFIED', 'QUOTE', 'NEGOTIATION', self::WON, 'LOST'];

    /** Statuses a user may set directly (everything except WON). */
    public const MANUAL_STATUSES = ['NEW', 'CONTACTED', 'QUALIFIED', 'QUOTE', 'NEGOTIATION', 'LOST'];

    public const OPEN_STATUSES = ['NEW', 'CONTACTED', 'QUALIFIED', 'QUOTE', 'NEGOTIATION'];

    private const TRANSITIONS = [
        'NEW' => ['CONTACTED', 'QUALIFIED', 'LOST'],
        'CONTACTED' => ['QUALIFIED', 'LOST'],
        'QUALIFIED' => ['QUOTE', 'NEGOTIATION', 'LOST'],
        'QUOTE' => ['NEGOTIATION', 'LOST'],
        'NEGOTIATION' => ['QUOTE', 'LOST'],
        'LOST' => ['NEW'],
        self::WON => [],
    ];

    /** @return list<string> */
    public static function next(string $from): array
    {
        return self::TRANSITIONS[$from] ?? [];
    }

    public static function assert(string $from, string $to): void
    {
        if ($from === self::WON) {
            throw ValidationException::withMessages(['status' => ['This lead is already a client.']]);
        }
        if ($from !== $to && ! in_array($to, self::next($from), true)) {
            throw ValidationException::withMessages(['status' => ["A lead cannot move from {$from} to {$to}."]]);
        }
    }

    public static function isOpen(string $status): bool
    {
        return in_array($status, self::OPEN_STATUSES, true);
    }
}
