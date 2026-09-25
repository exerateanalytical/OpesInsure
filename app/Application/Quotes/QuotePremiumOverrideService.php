<?php

declare(strict_types=1);

namespace App\Application\Quotes;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Application\Overrides\OverrideService;
use App\Models\Quote;
use App\Models\QuoteOffer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * REQ-QUO-005 (BRK-035) — controlled premium override on a quote offer, through the single override path
 * (OverrideService / engine_overrides, REQ-OVR-001): old and new premium, reason, requester, approver and time are
 * recorded permanently in the audit chain, maker-checker via the approval inbox. The offer changes ONLY when the
 * override is effective (approved) — the rated figures are kept in original_* and the rating run is untouched.
 * While an override is pending the offer cannot be accepted (QuoteService::accept).
 */
final class QuotePremiumOverrideService
{
    public const TYPE = 'PREMIUM_OVERRIDE';

    public const REASONS = ['COMMERCIAL_DISCOUNT', 'LOADING', 'BROKER_NEGOTIATION', 'CARRIER_INSTRUCTION', 'RATING_CORRECTION', 'OTHER'];

    public function __construct(private readonly OverrideService $overrides, private readonly AuditWriter $audit, private readonly OutboxWriter $outbox) {}

    public function request(Quote $quote, QuoteOffer $offer, int $newPremiumMinor, string $reasonCode, string $justification, User $actor): object
    {
        $this->assertOpen($quote, $offer);
        if (! in_array($reasonCode, self::REASONS, true)) {
            throw ValidationException::withMessages(['reason_code' => __('quotes.reason_invalid')]);
        }
        if ($newPremiumMinor <= 0 || $newPremiumMinor === (int) $offer->premium_minor) {
            throw ValidationException::withMessages(['premium_minor' => __('quotes.override_amount_invalid')]);
        }
        if ($offer->premium_override_id && DB::table('engine_overrides')->where('id', $offer->premium_override_id)->value('status') === 'REQUESTED') {
            throw ValidationException::withMessages(['offer' => __('quotes.override_pending')]);
        }

        return DB::transaction(function () use ($quote, $offer, $newPremiumMinor, $reasonCode, $justification, $actor) {
            $o = $this->overrides->request($actor->id, [
                'override_type' => self::TYPE, 'subject_type' => 'quote_offer', 'subject_id' => $offer->id, 'field' => 'premium_minor',
                'previous_value' => ['premium_minor' => (int) $offer->premium_minor, 'total_minor' => (int) $offer->total_minor],
                'new_value' => ['premium_minor' => $newPremiumMinor, 'total_minor' => (int) $offer->total_minor - (int) $offer->premium_minor + $newPremiumMinor],
                'reason_code' => $reasonCode, 'justification' => $justification, 'tenant_id' => $quote->tenant_id,
            ]);
            $offer->update(['premium_override_id' => $o->id]);
            $this->outbox->record('quote.premium_override.requested', 'quote_offer', $offer->id, ['quote_id' => $quote->id, 'override_id' => $o->id]);

            return $o;
        });
    }

    /** Checker decision. APPROVED applies the new premium to the offer; REJECTED leaves it as rated. */
    public function decide(Quote $quote, QuoteOffer $offer, string $overrideId, bool $approve, ?string $note, User $checker): object
    {
        if ($offer->premium_override_id !== $overrideId) {
            throw ValidationException::withMessages(['override' => __('quotes.override_unknown')]);
        }

        return DB::transaction(function () use ($quote, $offer, $overrideId, $approve, $note, $checker) {
            $o = $approve ? $this->overrides->approve($overrideId, $checker->id, $note) : $this->overrides->reject($overrideId, $checker->id, (string) $note);
            if ($approve && $this->overrides->isEffective($o)) {
                $this->apply($quote, $offer->refresh(), $o);
            }

            return $this->overrides->find($overrideId);
        });
    }

    /** For overrides approved through the approval inbox (multi-level matrix): apply once effective. Idempotent. */
    public function applyEffective(Quote $quote, QuoteOffer $offer, string $overrideId): QuoteOffer
    {
        return DB::transaction(function () use ($quote, $offer, $overrideId) {
            $offer = QuoteOffer::whereKey($offer->id)->lockForUpdate()->firstOrFail();
            $o = $this->overrides->find($overrideId);
            if ($offer->premium_override_id !== $overrideId || $o->subject_id !== $offer->id || ! $this->overrides->isEffective($o)) {
                throw ValidationException::withMessages(['override' => __('quotes.override_not_effective')]);
            }
            if ($offer->original_premium_minor === null || (int) json_decode((string) $o->new_value, true)['premium_minor'] !== (int) $offer->premium_minor) {
                $this->apply($quote, $offer, $o);
            }

            return $offer->refresh();
        });
    }

    private function apply(Quote $quote, QuoteOffer $offer, object $o): void
    {
        $this->assertOpen($quote, $offer);
        $new = json_decode((string) $o->new_value, true);
        $old = ['premium_minor' => (int) $offer->premium_minor, 'total_minor' => (int) $offer->total_minor];
        $offer->update([
            'original_premium_minor' => $offer->original_premium_minor ?? $offer->premium_minor,
            'original_total_minor' => $offer->original_total_minor ?? $offer->total_minor,
            'premium_minor' => (int) $new['premium_minor'],
            'total_minor' => (int) $offer->total_minor - (int) $offer->premium_minor + (int) $new['premium_minor'],
        ]);
        $this->audit->recordChange('quote.premium_override.applied', 'quote_offer', $offer->id, $old,
            ['premium_minor' => $offer->premium_minor, 'total_minor' => $offer->total_minor], (string) $o->reason_code, ['override_id' => $o->id, 'quote_id' => $quote->id, 'approved_by' => $o->approved_by]);
        $this->outbox->record('quote.premium_override.applied', 'quote_offer', $offer->id, ['quote_id' => $quote->id, 'override_id' => $o->id]);
    }

    private function assertOpen(Quote $quote, QuoteOffer $offer): void
    {
        if ($offer->quote_id !== $quote->id || $offer->status !== 'OFFERED' || ! in_array(QuoteMachine::stateOf($quote), QuoteMachine::PRICED, true)) {
            throw ValidationException::withMessages(['offer' => __('wave2.offer_unavailable')]);
        }
    }
}
