<?php

declare(strict_types=1);

namespace App\Application\Quotes;

use App\Application\Audit\AuditWriter;
use App\Application\Distribution\SellabilityService;
use App\Application\Documents\Engine\DocumentNumberAllocator;
use App\Application\Events\OutboxWriter;
use App\Application\Identity\PartyResolver;
use App\Application\MasterData\RiskFactsProcessor;
use App\Application\Quotes\Models\QuoteAnswer;
use App\Application\Quotes\Models\QuoteRisk;
use App\Application\Rating\RatingService;
use App\Application\Rules\QuestionSetCatalogue;
use App\Application\Rules\RuleEngine;
use App\Application\Shared\CanonicalJson;
use App\Application\Temporal\ReferenceInstant;
use App\Application\Vehicles\MotorRiskSchema;
use App\Application\Vehicles\VehicleUsageMapper;
use App\Domain\Shared\StateMachine\Contracts\TransitionEventPublisher;
use App\Domain\Shared\StateMachine\Contracts\TransitionHistoryRecorder;
use App\Domain\Shared\StateMachine\GuardResult;
use App\Domain\Shared\StateMachine\StateMachineEngine;
use App\Domain\Shared\StateMachine\TransitionContext;
use App\Domain\Shared\StateMachine\TransitionDenied;
use App\Models\InsuranceLine;
use App\Models\InsuranceProduct;
use App\Models\Quote;
use App\Models\QuoteOffer;
use App\Models\RiskAsset;
use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * The canonical quote service (REQ-QUO-001…004, REQ-DUP-007). Every quote state change goes through
 * QuoteMachine on the shared StateMachineEngine (history in workflow_transition_history, domain event in
 * the outbox) and is persisted here, inside the caller's transaction, together with the legacy status projection.
 *
 * Pricing: RatingService (one rating path). Eligibility: RuleEngine. Sellability: SellabilityService.
 * Numbering: DocumentNumberAllocator (INSURANCE_QUOTE family). The mobile API (MobileQuoteController) is a thin
 * adapter over the owner-scoped methods at the bottom of this class — no business rules live in the mobile layer.
 */
final class QuoteService
{
    public const DECLINE_REASONS = ['CUSTOMER_DECLINED', 'PRICE_TOO_HIGH', 'COVER_NOT_SUITABLE', 'LOST_TO_COMPETITOR', 'NO_RESPONSE', 'DUPLICATE', 'OTHER'];

    public const SHARE_CHANNELS = ['EMAIL', 'SMS', 'WHATSAPP', 'LINK', 'IN_APP'];

    private readonly StateMachineEngine $engine;

    public function __construct(
        private readonly RatingService $rating,
        private readonly CanonicalJson $json,
        private readonly AuditWriter $audit,
        private readonly OutboxWriter $outbox,
        private readonly RuleEngine $rules,
        private readonly SellabilityService $sellability,
        private readonly DocumentNumberAllocator $numbers,
        private readonly QuestionSetCatalogue $questions,
        private readonly PartyResolver $parties,
        TransitionHistoryRecorder $history,
        TransitionEventPublisher $events,
    ) {
        $this->engine = new StateMachineEngine($history, $events);
        $this->engine->registerGuard('not_expired', fn ($t, TransitionContext $c) => $c->subject->expires_at && $c->subject->expires_at->isPast()
            ? GuardResult::fail(__('wave12.quote_expired')) : GuardResult::pass());
        $this->engine->registerGuard('has_offers', fn ($t, TransitionContext $c) => $c->subject->offers()->whereIn('status', ['OFFERED', 'ACCEPTED'])->exists()
            ? GuardResult::pass() : GuardResult::fail(__('quotes.no_offers')));
    }

    // ------------------------------------------------------------------ WF-010 create / amend

    public function submit(Tenant $tenant, string $partyId, array $data, ?User $actor): Quote
    {
        return DB::transaction(function () use ($tenant, $partyId, $data, $actor): Quote {
            if (! DB::table('tenant_customers')->where(['tenant_id' => $tenant->id, 'party_id' => $partyId, 'status' => 'ACTIVE'])->exists()) {
                throw ValidationException::withMessages(['party_id' => __('wave2.customer_not_active')]);
            }
            $line = InsuranceLine::where(['code' => $data['line_code'], 'status' => 'ACTIVE'])->firstOrFail();
            $facts = $this->processFacts($line, $data['risk_facts'], $tenant->id, $actor);
            if (isset($data['risk_asset_id']) && ! RiskAsset::where(['id' => $data['risk_asset_id'], 'tenant_id' => $tenant->id, 'party_id' => $partyId, 'status' => 'ACTIVE'])->exists()) {
                throw ValidationException::withMessages(['risk_asset_id' => __('wave2.asset_ownership')]);
            }
            $partnerId = $data['partner_id'] ?? null;
            if ($partnerId !== null && ! DB::table('partners')->where(['id' => $partnerId, 'tenant_id' => $tenant->id])->exists()) {
                throw ValidationException::withMessages(['partner_id' => __('quotes.partner_unknown')]);
            }
            $quote = Quote::create([
                'tenant_id' => $tenant->id, 'party_id' => $partyId, 'risk_asset_id' => $data['risk_asset_id'] ?? null, 'partner_id' => $partnerId,
                'line_code' => $data['line_code'], 'channel' => $data['channel'], 'currency' => 'XAF', 'risk_facts' => $facts,
                'lifecycle_state' => 'DRAFT', 'status' => QuoteMachine::legacyStatus('DRAFT'),
                'quote_number' => $this->numbers->allocate($tenant->id, 'INSURANCE_QUOTE')['number'],
                'submitted_at' => now(), 'expires_at' => now()->addDays($this->validityDays(null)), 'version' => 1,
            ]);
            $this->recordRisks($quote, $data['risks'] ?? [], $tenant->id, $partyId);
            $this->recordAnswers($quote, $facts, $actor);
            $this->audit->record('quote.submitted', 'quote', $quote->id, ['line_code' => $quote->line_code, 'quote_number' => $quote->quote_number]);
            $this->outbox->record('quote.submitted', 'quote', $quote->id, ['quote_id' => $quote->id, 'tenant_id' => $tenant->id]);

            return $quote;
        });
    }

    /** WF-010 PATCH / WF-011: material change — facts re-validated, live offers superseded, back to DRAFT for re-rating. */
    public function amend(Quote $quote, array $riskFacts, ?User $actor): Quote
    {
        return DB::transaction(function () use ($quote, $riskFacts, $actor): Quote {
            $quote = $this->lock($quote);
            $line = InsuranceLine::where(['code' => $quote->line_code, 'status' => 'ACTIVE'])->firstOrFail();
            $facts = $this->processFacts($line, $riskFacts, $quote->tenant_id, $actor);
            $this->transition($quote, 'amend', $actor, null, ['changed_keys' => array_keys(array_diff_assoc(array_map('json_encode', $facts), array_map('json_encode', (array) $quote->risk_facts)))]);
            $quote->offers()->where('status', 'OFFERED')->update(['status' => 'SUPERSEDED']);
            $quote->update(['risk_facts' => $facts, 'version' => $quote->version + 1, 'rated_at' => null, 'generated_at' => null]);
            QuoteRisk::where(['quote_id' => $quote->id, 'sequence' => 1])->update(['facts' => json_encode($facts), 'facts_hash' => $this->json->hash($facts)]);
            $this->recordAnswers($quote, $facts, $actor);
            $this->audit->record('quote.amended', 'quote', $quote->id, ['version' => $quote->version]);

            return $quote->refresh();
        });
    }

    // ------------------------------------------------------------------ WF-010 / WF-011 rating

    public function rate(Quote $quote, ?User $actor): Quote
    {
        if (! in_array(QuoteMachine::stateOf($quote), ['DRAFT', 'REFERRED', ...QuoteMachine::PRICED], true)) {
            throw ValidationException::withMessages(['status' => __('wave2.quote_not_rateable')]);
        }
        $products = InsuranceProduct::with(['carrier', 'coverageDefinitions', 'exclusions'])->where(['line_code' => $quote->line_code, 'status' => 'ACTIVE'])
            ->whereHas('carrier', fn ($q) => $q->where('status', 'ACTIVE'))->whereDate('effective_from', '<=', now())
            ->where(fn ($q) => $q->whereNull('effective_until')->orWhereDate('effective_until', '>=', now()))->get();
        if ($products->isEmpty()) {
            throw ValidationException::withMessages(['products' => __('wave2.no_active_products')]);
        }

        return DB::transaction(function () use ($quote, $products, $actor): Quote {
            $quote = $this->lock($quote);
            $this->transition($quote, 'start_rating', $actor);
            $offers = [];
            $skipped = [];
            $at = ReferenceInstant::at(now(), (string) config('app.timezone', 'Africa/Douala'));
            foreach ($products as $product) {
                // REQ-RUL-003: structured eligibility with outcome + trace, logged in engine_evaluations (never a silent skip).
                if (! $this->rules->eligibility($product, $quote->risk_facts, null, ['type' => 'quote', 'id' => $quote->id], $quote->tenant_id)['outcome']->quotable()) {
                    $skipped[] = ['product_id' => $product->id, 'reason' => 'NOT_ELIGIBLE'];
                    continue;
                }
                // REQ-DST-001: sellability for this seller / channel (enforced for partner quotes; direct quotes see config).
                $sell = $this->sellability->check($product->id, 'quote', ['partner_id' => $quote->partner_id, 'tenant_id' => $quote->tenant_id, 'channel' => $quote->partner_id ? null : $quote->channel]);
                if (! $sell['sellable'] && ($quote->partner_id !== null || config('quotes.enforce_direct_publication'))) {
                    $skipped[] = ['product_id' => $product->id, 'reason' => 'NOT_SELLABLE', 'reasons' => $sell['reasons']];
                    continue;
                }
                $tariff = $this->rating->tariffFor($product, $at);
                if (! $tariff) {
                    $skipped[] = ['product_id' => $product->id, 'reason' => 'NO_ACTIVE_TARIFF'];
                    continue;
                }
                $validUntil = now()->addDays($this->validityDays($product));
                if ($existing = QuoteOffer::where(['quote_id' => $quote->id, 'tariff_version_id' => $tariff->id, 'status' => 'OFFERED'])->first()) {
                    $offers[] = $existing;
                    continue;
                }
                // REQ-RAT-001/004: one rating path (RatingService) — versions via the Temporal engine, snapshot in rating_runs.
                $run = $this->rating->rateQuote($quote, $tariff, $at);
                if (! ($result = $run['pricing'])) {
                    // Vehicle Power master: unknown fiscal power routes the quote to verification / manual review (REFERRED).
                    $skipped[] = ['product_id' => $product->id, 'reason' => str_starts_with((string) $run['failure'], \App\Application\Vehicles\Power\FiscalPowerReviewRequired::REASON) ? \App\Application\Vehicles\Power\FiscalPowerReviewRequired::REASON : 'RATING_FAILED'];
                    continue;
                }
                $offer = QuoteOffer::create([
                    'quote_id' => $quote->id, 'carrier_id' => $product->carrier_id, 'product_id' => $product->id, 'tariff_version_id' => $tariff->id,
                    'premium_minor' => $result->netPremiumMinor, 'tax_minor' => $result->taxMinor, 'fee_minor' => $result->feeMinor, 'total_minor' => $result->totalMinor,
                    'currency' => $quote->currency, 'status' => 'OFFERED', 'calculation_breakdown' => $result->lines,
                    'coverage_snapshot' => $this->coverageSnapshot($product, $tariff, (array) $quote->risk_facts),
                    'valid_until' => $validUntil, 'sellability' => $sell,
                ]);
                $offer->forceFill(['rating_run_id' => $run['run_id']])->save();
                $offers[] = $offer;
            }
            $offers = collect($offers)->sort(fn ($a, $b) => [$a->total_minor, -count($a->coverage_snapshot['coverages'] ?? [])] <=> [$b->total_minor, -count($b->coverage_snapshot['coverages'] ?? [])])->values();
            foreach ($offers as $i => $offer) {
                $offer->update(['comparison_rank' => $i + 1, 'ranking_reasons' => $i === 0 ? ['LOWEST_TOTAL_THEN_COVERAGE'] : ['TOTAL_ASCENDING']]);
            }
            $priced = $offers->isNotEmpty();
            // REQ-QUO-002: a priced quote is valid as long as its longest-lived offer (per product version validity).
            $extra = ['rated_at' => now(), 'comparison_context' => ['algorithm' => 'TOTAL_ASC_THEN_COVERAGE_DESC', 'currency' => $quote->currency, 'offer_count' => $offers->count(), 'skipped' => $skipped]
                + array_intersect_key((array) $quote->comparison_context, array_flip(['agent_user_id', 'demo_key']))];
            if ($priced) {
                $extra['expires_at'] = $offers->max('valid_until');
            }
            $this->transition($quote, $priced ? 'calculated' : 'refer', $actor, null, ['offer_count' => $offers->count()], $extra);
            $status = $quote->status;
            $this->audit->record('quote.rated', 'quote', $quote->id, ['offers' => $offers->count()]);
            $this->outbox->record('quote.rated', 'quote', $quote->id, ['quote_id' => $quote->id, 'status' => $status, 'offer_count' => $offers->count()]);

            return $quote->refresh();
        });
    }

    /**
     * Hook for manual quotation (REQ-QUO-006): a carrier-recorded offer prices an unpriced quote (DRAFT / RATING /
     * REFERRED → CALCULATED) through the machine, so history + events are recorded; a priced quote keeps its state.
     */
    public function manualOfferRecorded(Quote $quote, ?User $actor): Quote
    {
        return DB::transaction(function () use ($quote, $actor): Quote {
            $quote = $this->lock($quote);
            $state = QuoteMachine::stateOf($quote);
            if (in_array($state, ['DRAFT', 'REFERRED'], true)) {
                $this->transition($quote, 'start_rating', $actor, 'MANUAL_OFFER');
                $state = 'RATING';
            }
            if ($state === 'RATING') {
                $this->transition($quote, 'calculated', $actor, 'MANUAL_OFFER', [], ['rated_at' => now()]);
            }

            return $quote->refresh();
        });
    }

    // ------------------------------------------------------------------ REQ-QUO-004 generate / send / view

    public function generate(Quote $quote, ?User $actor): Quote
    {
        return DB::transaction(function () use ($quote, $actor): Quote {
            $quote = $this->lock($quote);
            $extra = ['generated_at' => now()];
            if (! $quote->quote_number) {
                $extra['quote_number'] = $this->numbers->allocate($quote->tenant_id, 'INSURANCE_QUOTE')['number'];
            }
            $this->transition($quote, 'generate', $actor, null, [], $extra);
            $this->audit->record('quote.generated', 'quote', $quote->id, ['quote_number' => $quote->quote_number]);

            return $quote->refresh();
        });
    }

    /** WF-012: share the quotation; a CALCULATED quote is generated first. Returns the share (with a one-time plain token). */
    public function send(Quote $quote, string $channel, ?string $recipient, ?User $actor): array
    {
        $channel = strtoupper($channel);
        if (! in_array($channel, self::SHARE_CHANNELS, true)) {
            throw ValidationException::withMessages(['channel' => __('quotes.channel_invalid')]);
        }

        return DB::transaction(function () use ($quote, $channel, $recipient, $actor): array {
            $quote = $this->lock($quote);
            if (QuoteMachine::stateOf($quote) === 'CALCULATED') {
                $quote = $this->generate($quote, $actor);
            }
            $token = Str::random(48);
            $id = (string) Str::uuid();
            DB::table('quote_shares')->insert(['id' => $id, 'quote_id' => $quote->id, 'channel' => $channel, 'recipient' => $recipient, 'token_hash' => hash('sha256', $token),
                'shared_by' => $actor?->id, 'shared_at' => now(), 'expires_at' => $quote->expires_at, 'created_at' => now(), 'updated_at' => now()]);
            $this->transition($quote, 'send', $actor, null, ['channel' => $channel, 'share_id' => $id], ['sent_at' => now()]);
            // Recipient is personal data: audit the fact and channel, never the address itself.
            $this->audit->record('quote.sent', 'quote', $quote->id, ['share_id' => $id, 'channel' => $channel]);

            return ['share_id' => $id, 'channel' => $channel, 'token' => $token, 'link_path' => '/quote-shares/'.$token, 'expires_at' => $quote->expires_at?->toIso8601String(), 'quote' => $quote->refresh()];
        });
    }

    /** Viewed tracking: the owner customer opening the quote, or anyone opening a share link. No-op outside GENERATED/SENT. */
    public function markViewed(Quote $quote, ?User $actor, ?string $shareId = null): Quote
    {
        if (! in_array(QuoteMachine::stateOf($quote), ['GENERATED', 'SENT'], true) && $shareId === null) {
            return $quote;
        }

        return DB::transaction(function () use ($quote, $actor, $shareId): Quote {
            $quote = $this->lock($quote);
            if ($shareId !== null) {
                DB::table('quote_shares')->where('id', $shareId)->update(['view_count' => DB::raw('view_count + 1'), 'first_viewed_at' => DB::raw('COALESCE(first_viewed_at, now())'), 'updated_at' => now()]);
            }
            if (in_array(QuoteMachine::stateOf($quote), ['GENERATED', 'SENT'], true)) {
                $this->transition($quote, 'view', $actor, null, ['share_id' => $shareId], ['viewed_at' => now()]);
            }

            return $quote->refresh();
        });
    }

    /** Public share link (no login): returns a customer-safe summary and records the view. */
    public function openShare(string $token): array
    {
        $share = DB::table('quote_shares')->where('token_hash', hash('sha256', $token))->first();
        if (! $share || ($share->expires_at && now()->greaterThan($share->expires_at))) {
            throw new ModelNotFoundException;
        }
        $quote = $this->markViewed(Quote::findOrFail($share->quote_id), null, $share->id);

        return ['quote_number' => $quote->quote_number, 'line_code' => $quote->line_code, 'state' => QuoteMachine::stateOf($quote), 'status' => $quote->status,
            'currency' => $quote->currency, 'expires_at' => $quote->expires_at?->toIso8601String(),
            'offers' => $quote->offers()->with(['carrier.party:id,display_name', 'product:id,name,code'])->where('status', 'OFFERED')->orderBy('comparison_rank')->get()
                ->map(fn (QuoteOffer $o) => ['id' => $o->id, 'carrier' => $o->carrier?->party?->display_name, 'product' => $o->product?->name, 'premium_minor' => $o->premium_minor,
                    'tax_minor' => $o->tax_minor, 'fee_minor' => $o->fee_minor, 'total_minor' => $o->total_minor, 'valid_until' => $o->valid_until?->toIso8601String(), 'coverage_snapshot' => $o->coverage_snapshot])->all()];
    }

    // ------------------------------------------------------------------ WF-013 / WF-014 outcomes

    public function accept(Quote $quote, QuoteOffer $offer, ?User $actor): Quote
    {
        if ($offer->quote_id !== $quote->id || $offer->status !== 'OFFERED') {
            throw ValidationException::withMessages(['offer' => __('wave2.offer_unavailable')]);
        }
        if ($offer->valid_until->isPast()) {
            throw ValidationException::withMessages(['offer' => __('wave2.offer_expired')]);
        }
        // REQ-QUO-005: never accept a price that is under review, or approved but not yet applied to the offer.
        $override = $offer->premium_override_id ? DB::table('engine_overrides')->where('id', $offer->premium_override_id)->first() : null;
        if ($override && ($override->status === 'REQUESTED' || (in_array($override->status, ['APPROVED', 'AUTO_APPROVED'], true) && $offer->original_premium_minor === null))) {
            throw ValidationException::withMessages(['offer' => __('quotes.override_pending')]);
        }

        return DB::transaction(function () use ($quote, $offer, $actor): Quote {
            $quote = $this->lock($quote);
            $this->transition($quote, 'accept', $actor, null, ['offer_id' => $offer->id], ['accepted_at' => now()], 'offer');
            $quote->offers()->whereKeyNot($offer->id)->where('status', 'OFFERED')->update(['status' => 'NOT_SELECTED']);
            $offer->update(['status' => 'ACCEPTED']);
            $this->audit->record('quote.offer.accepted', 'quote_offer', $offer->id, ['quote_id' => $quote->id]);
            $this->outbox->record('quote.offer.accepted', 'quote_offer', $offer->id, ['quote_id' => $quote->id, 'offer_id' => $offer->id]);

            return $quote->refresh();
        });
    }

    public function decline(Quote $quote, string $reasonCode, ?string $note, ?User $actor): Quote
    {
        if (! in_array($reasonCode, self::DECLINE_REASONS, true)) {
            throw ValidationException::withMessages(['reason_code' => __('quotes.reason_invalid')]);
        }

        return DB::transaction(function () use ($quote, $reasonCode, $note, $actor): Quote {
            $quote = $this->lock($quote);
            $this->transition($quote, 'decline', $actor, $reasonCode, ['note' => $note], ['declined_at' => now(), 'decline_reason_code' => $reasonCode]);
            $quote->offers()->where('status', 'OFFERED')->update(['status' => 'DECLINED']);
            $this->audit->record('quote.declined', 'quote', $quote->id, ['reason_code' => $reasonCode], $reasonCode);

            return $quote->refresh();
        });
    }

    public function cancel(Quote $quote, ?User $actor): Quote
    {
        if (! in_array(QuoteMachine::stateOf($quote), ['DRAFT', 'REFERRED', ...QuoteMachine::PRICED], true)) {
            throw ValidationException::withMessages(['status' => __('wave2.quote_not_cancellable')]);
        }

        return DB::transaction(function () use ($quote, $actor): Quote {
            $quote = $this->lock($quote);
            $this->transition($quote, 'cancel', $actor, null, [], ['cancelled_at' => now()]);
            $quote->offers()->where('status', 'OFFERED')->update(['status' => 'WITHDRAWN']);
            $this->audit->record('quote.cancelled', 'quote', $quote->id, []);
            $this->outbox->record('quote.cancelled', 'quote', $quote->id, ['quote_id' => $quote->id]);

            return $quote->refresh();
        });
    }

    /** REQ-QUO-002: QUOTE_EXPIRED — one quote. Returns false when it was not open or not yet due. */
    public function expire(Quote $quote, ?User $actor = null): bool
    {
        return DB::transaction(function () use ($quote, $actor): bool {
            $quote = $this->lock($quote);
            if (! in_array(QuoteMachine::stateOf($quote), QuoteMachine::OPEN, true) || ! $quote->expires_at || $quote->expires_at->isFuture()) {
                return false;
            }
            $this->transition($quote, 'expire', $actor, 'QUOTE_EXPIRED', [], ['expired_at' => now()]);
            $quote->offers()->where('status', 'OFFERED')->update(['status' => 'EXPIRED']);
            $this->audit->record('quote.expired', 'quote', $quote->id, ['expires_at' => $quote->expires_at->toIso8601String()], 'QUOTE_EXPIRED');

            return true;
        });
    }

    /** Sweep (quotes:expire, scheduled). Covers pre-6B rows that only carry the legacy status. */
    public function expireDue(int $limit = 500): int
    {
        $n = 0;
        Quote::query()->whereNotNull('expires_at')->where('expires_at', '<', now())
            ->where(fn ($q) => $q->whereIn('lifecycle_state', QuoteMachine::OPEN)->orWhere(fn ($q) => $q->whereNull('lifecycle_state')->whereIn('status', ['SUBMITTED', 'REFERRED', 'OFFERED'])))
            ->orderBy('expires_at')->limit($limit)->get()
            ->each(function (Quote $q) use (&$n) {
                $n += $this->expire($q) ? 1 : 0;
            });

        return $n;
    }

    /** REQ-QUO-002: validity per product version (insurance_products.quote_validity_days), else config default. */
    public function validityDays(?InsuranceProduct $product): int
    {
        return max(1, (int) ($product?->quote_validity_days ?? config('quotes.default_validity_days', 7)));
    }

    // ------------------------------------------------------------------ owner-scoped reads (mobile adapter)

    public function ownedList(User $user, string $tenantId, int $perPage = 20): LengthAwarePaginator
    {
        return $this->ownedQuery($user, $tenantId)->orderByDesc('created_at')->paginate($perPage);
    }

    public function owned(string $quoteId, User $user, string $tenantId): Quote
    {
        $quote = $this->ownedQuery($user, $tenantId)->find($quoteId);
        if (! $quote) {
            $exists = Quote::where('tenant_id', $tenantId)->where('id', $quoteId)->exists();
            throw $exists ? new AuthorizationException : new ModelNotFoundException;
        }

        return $quote;
    }

    /** Mobile "resume": an open, unexpired quote (legacy messages kept for app 1.3.0). */
    public function assertResumable(Quote $quote): void
    {
        if (! in_array(QuoteMachine::stateOf($quote), ['DRAFT', 'REFERRED', ...QuoteMachine::PRICED], true)) {
            throw ValidationException::withMessages(['status' => __('wave12.quote_not_resumable')]);
        }
        if ($quote->expires_at && $quote->expires_at->isPast()) {
            throw ValidationException::withMessages(['status' => __('wave12.quote_expired')]);
        }
    }

    /** The quote + ranked offers envelope every quote read returns (mobile and core). */
    public function envelope(Quote $quote): array
    {
        return ['quote' => $quote, 'offers' => $quote->offers()->with(['carrier.party', 'product'])->orderBy('comparison_rank')->get()];
    }

    private function ownedQuery(User $user, string $tenantId): Builder
    {
        $party = $this->parties->forUser($user);
        $query = Quote::where('tenant_id', $tenantId);

        return $party ? $query->where('party_id', $party->id) : $query->whereRaw('1 = 0');
    }

    // ------------------------------------------------------------------ internals

    /**
     * Applies one QuoteMachine event: engine (guards, history, domain event) then persists lifecycle_state + the
     * legacy status projection + any extra columns. Must run inside a transaction on a locked row.
     */
    private function transition(Quote $quote, string $event, ?User $actor, ?string $reason = null, array $payload = [], array $extra = [], string $errorKey = 'status'): void
    {
        $from = QuoteMachine::stateOf($quote);
        try {
            $result = $this->engine->apply(QuoteMachine::definition(), $event, new TransitionContext('quote', $quote->id, $from, $actor, $this->roleOf($actor), $payload, $reason, $quote));
        } catch (TransitionDenied $e) {
            throw ValidationException::withMessages([$errorKey => $e->stage === TransitionDenied::GUARD ? $e->getMessage() : __('quotes.transition_invalid', ['event' => $event, 'state' => $from])]);
        }
        $quote->forceFill(['lifecycle_state' => $result->to, 'status' => QuoteMachine::legacyStatus($result->to)] + $extra)->save();
    }

    private function roleOf(?User $actor): ?string
    {
        return $actor ? (string) (rescue(fn () => $actor->primaryRole ?? $actor->role ?? null, null, false) ?? 'user') : 'system';
    }

    private function lock(Quote $quote): Quote
    {
        return Quote::whereKey($quote->id)->lockForUpdate()->firstOrFail();
    }

    private function processFacts(InsuranceLine $line, array $facts, string $tenantId, ?User $actor): array
    {
        // Master-data codes validated ("Other" filed for review) and legacy tariff facts derived (RiskFactsProcessor).
        $facts = app(RiskFactsProcessor::class)->process($line->code, $facts, $tenantId, $actor?->id);
        // Vehicle master: the 28-value vehicle_usage feeds the tariff's usage_type until tariffs rate on it directly.
        if (strtoupper((string) $line->code) === 'MOTOR') {
            MotorRiskSchema::validateVehicleFacts($facts);
            $facts = VehicleUsageMapper::withDerivedFacts($facts);
        }
        foreach ($line->risk_schema['required'] ?? [] as $key) {
            if (! array_key_exists($key, $facts)) {
                throw ValidationException::withMessages(["risk_facts.$key" => __('wave2.risk_fact_required')]);
            }
        }
        $facts = $this->validateCoverChoices($line, $facts);
        // REQ-RUL-004: configurable QUOTE completeness gate (completeness rule sets; no rules = no-op).
        $this->rules->assertComplete('QUOTE', (string) $line->code, null, $facts, null, $tenantId);

        return $facts;
    }

    /**
     * Customer cover choices carried in risk_facts (the rating engine reads them from the facts):
     *  - selected_coverages: list of OPTIONAL coverage codes offered by an active product of the line. Absent = legacy
     *    behaviour (every optional cover listed in the offer); present (even []) = only these optional covers.
     *  - cover_limits: {coverage code: limit in minor units} for coverages of the line. A limit only changes an offer
     *    where the product's tariff prices that cover from the fact `cover_limits.<CODE>` (see coverageSnapshot).
     */
    private function validateCoverChoices(InsuranceLine $line, array $facts): array
    {
        if (! array_key_exists('selected_coverages', $facts) && ! array_key_exists('cover_limits', $facts)) {
            return $facts;
        }
        $pivots = DB::table('product_coverages')->join('insurance_products', 'insurance_products.id', '=', 'product_coverages.insurance_product_id')
            ->join('coverage_definitions', 'coverage_definitions.id', '=', 'product_coverages.coverage_definition_id')
            ->where(['insurance_products.line_code' => $line->code, 'insurance_products.status' => 'ACTIVE'])
            ->get(['coverage_definitions.code', 'product_coverages.is_optional']);
        if (array_key_exists('selected_coverages', $facts)) {
            $optional = $pivots->where('is_optional', true)->pluck('code')->unique()->all();
            $sel = $facts['selected_coverages'];
            if (! is_array($sel) || ! array_is_list($sel) || array_diff(array_filter($sel, 'is_string'), $optional) || count(array_filter($sel, 'is_string')) !== count($sel)) {
                throw ValidationException::withMessages(['risk_facts.selected_coverages' => __('quotes.covers_invalid')]);
            }
            $sel = array_values(array_unique($sel));
            sort($sel);
            $facts['selected_coverages'] = $sel;
        }
        if (array_key_exists('cover_limits', $facts)) {
            $codes = $pivots->pluck('code')->unique()->all();
            $limits = $facts['cover_limits'];
            if (! is_array($limits) || ($limits !== [] && array_is_list($limits))) {
                throw ValidationException::withMessages(['risk_facts.cover_limits' => __('quotes.cover_limits_invalid')]);
            }
            foreach ($limits as $code => $minor) {
                if (! in_array($code, $codes, true) || ! is_int($minor) || $minor <= 0 || $minor > 1_000_000_000_000_000) {
                    throw ValidationException::withMessages(["risk_facts.cover_limits.$code" => __('quotes.cover_limits_invalid')]);
                }
            }
            ksort($limits);
            $facts['cover_limits'] = $limits;
        }

        return $facts;
    }

    /**
     * The offer's coverage snapshot, honouring the customer's cover choices. Optional covers not selected are listed
     * under optional_available (never under coverages, which feed the policy). Each cover says whether this product's
     * tariff prices it separately (tariff_priced) and whether its limit follows cover_limits (limit_adjustable).
     */
    private function coverageSnapshot(InsuranceProduct $product, \App\Models\TariffVersion $tariff, array $facts): array
    {
        $rules = collect($tariff->rules['coverages'] ?? [])->keyBy('code');
        $choosing = array_key_exists('selected_coverages', $facts);
        $selected = (array) ($facts['selected_coverages'] ?? []);
        $covers = [];
        $available = [];
        foreach ($product->coverageDefinitions as $c) {
            $adjustable = ($rules[$c->code]['fact'] ?? null) === 'cover_limits.'.$c->code;
            $limit = $adjustable && isset($facts['cover_limits'][$c->code]) ? (int) $facts['cover_limits'][$c->code] : $c->pivot->default_limit_minor;
            $row = ['code' => $c->code, 'name' => $c->name, 'mandatory' => $c->mandatory, 'limit_minor' => $limit, 'deductible_minor' => $c->pivot->default_deductible_minor,
                'optional' => $c->pivot->is_optional, 'default_limit_minor' => $c->pivot->default_limit_minor, 'tariff_priced' => $rules->has($c->code), 'limit_adjustable' => $adjustable];
            if ($c->pivot->is_optional && $choosing && ! in_array($c->code, $selected, true)) {
                $available[] = $row;
            } else {
                $covers[] = $row;
            }
        }

        return ['coverages' => array_values($covers), 'optional_available' => $available, 'cover_selection' => $choosing ? 'CUSTOMER' : 'DEFAULT',
            'exclusions' => $product->exclusions->map(fn ($e) => ['code' => $e->code, 'name' => $e->name])->values()];
    }

    /** REQ-QUO-003: risk 1 is the primary risk (quote risk_facts); extra risks reference the customer's own insured objects. */
    private function recordRisks(Quote $quote, array $extra, string $tenantId, string $partyId): void
    {
        $rows = [['risk_asset_id' => $quote->risk_asset_id, 'facts' => $quote->risk_facts]];
        foreach ($extra as $i => $r) {
            $assetId = $r['risk_asset_id'] ?? null;
            if ($assetId !== null && ! RiskAsset::where(['id' => $assetId, 'tenant_id' => $tenantId, 'party_id' => $partyId, 'status' => 'ACTIVE'])->exists()) {
                throw ValidationException::withMessages(["risks.$i.risk_asset_id" => __('wave2.asset_ownership')]);
            }
            $rows[] = ['risk_asset_id' => $assetId, 'facts' => (array) ($r['risk_facts'] ?? [])];
        }
        foreach ($rows as $i => $r) {
            $type = $r['risk_asset_id'] ? RiskAsset::whereKey($r['risk_asset_id'])->value('type') : null;
            QuoteRisk::create(['quote_id' => $quote->id, 'risk_asset_id' => $r['risk_asset_id'], 'sequence' => $i + 1, 'line_code' => $quote->line_code,
                'risk_type' => $type, 'facts' => $r['facts'], 'facts_hash' => $this->json->hash($r['facts'])]);
        }
    }

    /** Answers are captured against the QUOTE question set in force (REQ-DUP-020: the single question source). */
    private function recordAnswers(Quote $quote, array $facts, ?User $actor): void
    {
        $set = $this->questions->resolve(null, $quote->line_code, 'QUOTE');
        $answers = $facts;
        $missing = [];
        if ($set) {
            $answers = [];
            foreach ($set->questions()->get() as $q) {
                if (array_key_exists($q->fact_key, $facts)) {
                    $answers[$q->code] = $facts[$q->fact_key];
                } elseif ($q->required) {
                    $missing[] = $q->code;
                }
            }
        }
        QuoteAnswer::create(['quote_id' => $quote->id, 'question_set_id' => $set?->id, 'question_set_version' => $set?->version, 'schema_hash' => $set?->schema_hash,
            'answers' => $answers, 'answers_hash' => $this->json->hash($answers), 'unanswered_required' => $missing, 'answered_by' => $actor?->id, 'answered_at' => now()]);
        if ($set && $quote->question_set_id !== $set->id) {
            $quote->forceFill(['question_set_id' => $set->id])->save();
        }
    }
}
