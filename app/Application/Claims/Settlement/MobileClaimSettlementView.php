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

    /** Settlement statuses while the customer may still set / change where the money goes (before the payment is requested). */
    public const PAYOUT_EDITABLE = ['OFFERED', 'ACCEPTED', 'DISCHARGE_SIGNED'];

    /**
     * $user: the caller — sign_discharge is only offered when they are the pending signer of the discharge.
     *
     * @return array<string, mixed>
     */
    public function present(Claim $claim, ?User $user = null): array
    {
        $s = self::current($claim);

        if (! $s) {
            return [
                'id' => null, 'claim_id' => $claim->id, 'reference' => null, 'status' => 'PENDING', 'currency' => $claim->currency ?? 'XAF',
                'offered_minor' => null, 'deductible_minor' => null, 'net_minor' => null, 'lines' => [],
                'can_decide' => false, 'payment_status' => 'NOT_STARTED', 'payment_reference' => null,
                'offered_at' => null, 'decided_at' => null, 'terms' => null,
                'discharge' => null, 'payout' => self::payout($claim), 'payment_advice_document_id' => null, 'allowed_actions' => [],
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
        ] + $this->discharge($claim, $s, $user);
    }

    /** Discharge signature (REQ-CLM-013 ACCEPTED → DISCHARGE_SIGNED), payout destination and the payment advice. */
    private function discharge(Claim $claim, object $s, ?User $user): array
    {
        $req = $s->signature_request_id ? DB::table('signature_requests')->where('id', $s->signature_request_id)->first() : null;
        $signer = $req ? DB::table('signature_request_signers')->where('signature_request_id', $req->id)->orderBy('signing_order')
            ->when($user, fn ($q) => $q->where(fn ($w) => $w->where('signer_user_id', $user->id)->when($user->party_id, fn ($x) => $x->orWhere('signer_party_id', $user->party_id))))
            ->first() : null;
        $canSign = $req && $user && $signer && $s->status === 'ACCEPTED' && $req->status === 'PENDING' && $signer->status === 'PENDING'
            && ($req->expires_at === null || now()->lessThan($req->expires_at));
        $advice = DB::table('documents')->where('claim_id', $claim->id)->where('document_type_code', 'CLAIM_PAYMENT_ADVICE')
            ->when($s->payee_party_id, fn ($q) => $q->where('party_id', $s->payee_party_id))
            ->whereNotIn('status', ['REVOKED', 'CANCELLED', 'SUPERSEDED', 'REPLACED'])->orderByDesc('created_at')->value('id');

        return [
            'discharge' => $req ? [
                'signature_request_id' => $req->id, 'document_id' => $s->discharge_document_id, 'status' => $req->status,
                'consent_text' => $req->consent_text, 'signer_status' => $signer->status ?? null,
                'signed_at' => $signer && $signer->status === 'SIGNED' ? $this->iso($signer->acted_at) : null,
                'declined_at' => $signer && $signer->status === 'DECLINED' ? $this->iso($signer->acted_at) : null,
                'expires_at' => $this->iso($req->expires_at),
            ] : null,
            'payout' => self::payout($claim),
            'payment_advice_document_id' => $advice,
            'allowed_actions' => array_values(array_filter([
                $s->status === 'OFFERED' ? 'decide' : null,
                $canSign ? 'sign_discharge' : null,
                in_array($s->status, self::PAYOUT_EDITABLE, true) ? 'set_payout' : null,
            ])),
        ];
    }

    /** The payout destination the customer gave for this claim (claims.loss_details.payout), masked. */
    public static function payout(Claim $claim): ?array
    {
        $p = ($claim->loss_details ?? [])['payout'] ?? null;
        if (! is_array($p) || empty($p['method'])) {
            return null;
        }
        $mask = fn (?string $v) => $v ? str_repeat('•', max(0, strlen($v) - 4)).substr($v, -4) : null;

        return [
            'method' => $p['method'], 'operator' => $p['operator'] ?? null, 'msisdn_masked' => $mask($p['msisdn'] ?? null),
            'bank_name' => $p['bank_name'] ?? null, 'account_name' => $p['account_name'] ?? null, 'account_number_masked' => $mask($p['account_number'] ?? null),
            'updated_at' => $p['updated_at'] ?? null,
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
