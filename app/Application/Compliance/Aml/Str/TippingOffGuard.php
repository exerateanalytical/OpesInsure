<?php

declare(strict_types=1);

namespace App\Application\Compliance\Aml\Str;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * REQ-AML-003 / Reg. 003-25 tipping-off controls. The existence of a suspicious transaction report must never reach
 * the customer, a broker / partner, or a staff member without cases.str.view:
 *
 *   - STR cases are STR_RESTRICTED on the case engine (CaseVisibility global scope).
 *   - hideFromAudit(): audit-log reads by users without cases.str.view drop every STR / AML-STR entry
 *     (aml_str rows, aml.str.* actions, case-engine rows of STR_RESTRICTED cases).
 *   - withholdFromIntegrations(): the outbox dispatcher never fans out STR case events or aml.* events to
 *     partner webhooks (the row is claimed and left undelivered).
 *   - StrService raises no outbox event and no notification of its own.
 */
final class TippingOffGuard
{
    public const AUDIT_SUBJECT = 'aml_str';

    /** Outbox events never delivered to integrations. */
    public const WITHHELD_EVENT_PREFIXES = ['aml.'];

    public static function hideFromAudit(Builder $q): void
    {
        $q->where(fn ($w) => $w->whereNull('subject_type')->orWhere('subject_type', '<>', self::AUDIT_SUBJECT))
            ->where('action', 'not like', 'aml.str.%')
            ->where(fn ($w) => $w->whereNull('subject_type')->orWhere('subject_type', '<>', 'case')
                ->orWhereNotIn('subject_id', DB::table('cases')->select('id')->where('confidentiality', 'STR_RESTRICTED')));
    }

    public static function withholdFromIntegrations(object $message): bool
    {
        foreach (self::WITHHELD_EVENT_PREFIXES as $p) {
            if (str_starts_with((string) $message->event_name, $p)) {
                return true;
            }
        }
        $payload = is_string($message->payload ?? null) ? (array) json_decode($message->payload, true) : (array) ($message->payload ?? []);
        if (($payload['confidentiality'] ?? null) === 'STR_RESTRICTED' || ($payload['case_type'] ?? null) === 'STR') {
            return true;
        }

        return ($message->aggregate_type ?? null) === 'case'
            && DB::table('cases')->where('id', $message->aggregate_id)->where('confidentiality', 'STR_RESTRICTED')->exists();
    }
}
