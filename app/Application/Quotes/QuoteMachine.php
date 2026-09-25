<?php

declare(strict_types=1);

namespace App\Application\Quotes;

use App\Domain\Shared\StateMachine\StateMachineDefinition;
use App\Models\Quote;

/**
 * REQ-QUO-001 — the quote machine on the shared StateMachineEngine (REQ-WFL-001), blueprint states
 * (WRS WF-010…014): DRAFT → RATING → CALCULATED → GENERATED → SENT → VIEWED → ACCEPTED; DECLINED, EXPIRED, CANCELLED.
 * Transitions whose fact already has a legacy outbox event (quote.rated, quote.offer.accepted, quote.cancelled — written by
 * QuoteService with their established payloads) publish workflow.transition.applied instead, so no fact is emitted twice.
 * REFERRED is the "no instant offer" outcome of rating (hands over to manual quotation, REQ-QUO-006).
 *
 * quotes.lifecycle_state holds the canonical state. quotes.status is kept as the app-facing projection
 * (mobile 1.3.0 reads SUBMITTED / REFERRED / OFFERED / ACCEPTED / CANCELLED) — derived only here, never set elsewhere.
 */
final class QuoteMachine
{
    public const NAME = 'quote';

    public const OPEN = ['DRAFT', 'RATING', 'CALCULATED', 'REFERRED', 'GENERATED', 'SENT', 'VIEWED'];

    public const PRICED = ['CALCULATED', 'GENERATED', 'SENT', 'VIEWED'];

    public const TERMINAL = ['ACCEPTED', 'DECLINED', 'EXPIRED', 'CANCELLED'];

    /** canonical → app-facing status */
    public const LEGACY = [
        'DRAFT' => 'SUBMITTED', 'RATING' => 'SUBMITTED', 'REFERRED' => 'REFERRED',
        'CALCULATED' => 'OFFERED', 'GENERATED' => 'OFFERED', 'SENT' => 'OFFERED', 'VIEWED' => 'OFFERED',
        'ACCEPTED' => 'ACCEPTED', 'DECLINED' => 'DECLINED', 'EXPIRED' => 'EXPIRED', 'CANCELLED' => 'CANCELLED',
    ];

    private const FROM_LEGACY = ['SUBMITTED' => 'DRAFT', 'REFERRED' => 'REFERRED', 'OFFERED' => 'CALCULATED', 'ACCEPTED' => 'ACCEPTED',
        'DECLINED' => 'DECLINED', 'EXPIRED' => 'EXPIRED', 'CANCELLED' => 'CANCELLED'];

    private static ?StateMachineDefinition $machine = null;

    /** Current canonical state; rows written before 6B carry only the legacy status. */
    public static function stateOf(Quote $quote): string
    {
        return $quote->lifecycle_state ?? self::FROM_LEGACY[(string) $quote->status] ?? 'DRAFT';
    }

    public static function legacyStatus(string $state): string
    {
        return self::LEGACY[$state];
    }

    public static function definition(): StateMachineDefinition
    {
        $reprice = ['DRAFT', 'REFERRED', ...self::PRICED];

        return self::$machine ??= StateMachineDefinition::fromArray([
            'name' => self::NAME, 'version' => 1, 'subject_type' => 'quote',
            'states' => ['DRAFT' => ['initial' => true], 'RATING' => [], 'CALCULATED' => [], 'REFERRED' => [], 'GENERATED' => [], 'SENT' => [], 'VIEWED' => [],
                'ACCEPTED' => ['terminal' => true], 'DECLINED' => ['terminal' => true], 'EXPIRED' => ['terminal' => true], 'CANCELLED' => ['terminal' => true]],
            'transitions' => [
                ['event' => 'amend', 'from' => $reprice, 'to' => 'DRAFT', 'guards' => ['not_expired'], 'domain_event' => 'quote.amended', 'failure_path' => 'quote unchanged'],
                ['event' => 'start_rating', 'from' => $reprice, 'to' => 'RATING', 'guards' => ['not_expired'], 'failure_path' => 'stay; quote expired'],
                ['event' => 'calculated', 'from' => ['RATING'], 'to' => 'CALCULATED'],
                ['event' => 'refer', 'from' => ['RATING'], 'to' => 'REFERRED', 'failure_path' => 'manual quotation (REQ-QUO-006)'],
                ['event' => 'generate', 'from' => self::PRICED, 'to' => 'GENERATED', 'guards' => ['not_expired', 'has_offers'], 'domain_event' => 'quote.generated'],
                ['event' => 'send', 'from' => ['GENERATED', 'SENT', 'VIEWED'], 'to' => 'SENT', 'guards' => ['not_expired'], 'domain_event' => 'quote.sent', 'notification' => 'quote.sent'],
                ['event' => 'view', 'from' => ['GENERATED', 'SENT'], 'to' => 'VIEWED', 'domain_event' => 'quote.viewed'],
                ['event' => 'accept', 'from' => self::PRICED, 'to' => 'ACCEPTED', 'guards' => ['not_expired'], 'failure_path' => 'stay; offer or quote expired'],
                ['event' => 'decline', 'from' => ['REFERRED', ...self::PRICED], 'to' => 'DECLINED', 'domain_event' => 'quote.declined'],
                ['event' => 'expire', 'from' => self::OPEN, 'to' => 'EXPIRED', 'domain_event' => 'quote.expired', 'notification' => 'QUOTE_EXPIRED'],
                ['event' => 'cancel', 'from' => ['DRAFT', 'REFERRED', ...self::PRICED], 'to' => 'CANCELLED'],
            ],
        ]);
    }
}
