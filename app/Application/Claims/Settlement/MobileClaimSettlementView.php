<?php

declare(strict_types=1);

namespace App\Application\Claims\Settlement;

use App\Models\Claim;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * The customer's side of the REQ-CLM-013 settlement lifecycle
 * (GET/POST mobile/claims/{claim}/settlement[/decision]).
 *
 * Built on claim_settlements, never on claim_decisions: a CALCULATED
 * settlement (or a decision still awaiting approval) is an internal figure
 * the checker has not released, so the customer only ever sees a settlement
 * once it has been OFFERED, and their answer moves the real lifecycle
 * through ClaimSettlementService (OFFERED → ACCEPTED, or OFFERED → DISPUTED
 * for a rejection, which staff resolve by recalculating).
 */
final class MobileClaimSettlementView
{
    /** Settlement statuses a customer may see (CALCULATED and SUPERSEDED stay internal). */
    public const CUSTOMER_VISIBLE = ['OFFERED', 'ACCEPTED', 'DISPUTED', 'DISCHARGE_SIGNED', 'PAYMENT_PENDING', 'PAID'];

    public function __construct(private ClaimSettlementService $settlements) {}

    /** The latest settlement released to the customer, or null while none has been offered. */
    public static function current(Claim $claim): ?object
    {
        return DB::table('claim_settlements')
            ->where('claim_id', $claim->id)
            ->whereIn('status', self::CUSTOMER_VISIBLE)
            ->orderByDesc('offered_at')
            ->orderByDesc('created_at')
            ->first();
    }

    /** True only while an offer is waiting for the customer's answer. */
    public static function hasOpenOffer(Claim $claim): bool
    {
        return self::current($claim)?->status === 'OFFERED';
    }

    /** @return array<string, mixed> */
    public function present(Claim $claim): array
    {
        $s = self::current($claim);

        if (! $s) {
            return [
                'id' => null, 'claim_id' => $claim->id, 'reference' => null, 'status' => 'PENDING', 'currency' => $claim->currency ?? 'XAF',
                'offered_minor' => null, 'deductible_minor' => null, 'net_minor' => null, 'lines' => [],
                'can_decide' => false, 'payment_status' => 'NOT_STARTED', 'payment_reference' => null,
                'offered_at' => null, 'decided_at' => null, 'terms' => null,
            ];
        }

        $breakdown = is_string($s->breakdown) ? json_decode($s->breakdown, true) : (array) $s->breakdown;
        $payment = $s->claim_payment_id ? DB::table('claim_payments')->where('id', $s->claim_payment_id)->first() : null;
        $rationale = DB::table('claim_decisions')->where('id', $s->claim_decision_id)->value('rationale');

        return [
            'id' => $s->id,
            'claim_id' => $claim->id,
            'reference' => $s->reference,
            'status' => $s->status,
            'currency' => $s->currency,
            // The assessed loss before the deductible and other deductions; net_minor is what is paid.
            'offered_minor' => max(0, (int) $s->covered_minor - (int) $s->excluded_minor),
            'deductible_minor' => (int) $s->deductible_minor,
            'net_minor' => (int) $s->amount_minor,
            'lines' => array_values(array_map(fn (array $l) => [
                'code' => (string) ($l['code'] ?? ''), 'label' => (string) ($l['label'] ?? ''),
                'operator' => $l['operator'] ?? null, 'amount_minor' => (int) ($l['amount_minor'] ?? 0),
            ], $breakdown['lines'] ?? [])),
            'can_decide' => $s->status === 'OFFERED',
            'payment_status' => $payment->status ?? 'NOT_STARTED',
            'payment_reference' => $payment->external_reference ?? null,
            'offered_at' => $this->iso($s->offered_at),
            'decided_at' => $this->iso($s->accepted_at ?? $s->disputed_at),
            'terms' => $rationale,
        ];
    }

    /** ACCEPT → ClaimSettlementService::accept; REJECT → ::dispute. Only an OFFERED settlement can be answered. */
    public function decide(Claim $claim, string $decision, ?string $reason, User $user): void
    {
        $s = self::current($claim);

        if (! $s || $s->status !== 'OFFERED') {
            throw ValidationException::withMessages(['decision' => __('wave12.claim_settlement_no_offer')]);
        }

        if ($decision === 'ACCEPT') {
            $this->settlements->accept($s->id, $user);

            return;
        }

        $this->settlements->dispute($s->id, filled($reason) ? (string) $reason : 'Customer rejected the settlement offer from the app.', $user);
    }

    private function iso(mixed $value): ?string
    {
        return $value ? \Illuminate\Support\Carbon::parse($value)->toIso8601String() : null;
    }
}
