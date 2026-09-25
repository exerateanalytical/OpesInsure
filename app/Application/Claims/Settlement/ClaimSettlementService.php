<?php

declare(strict_types=1);

namespace App\Application\Claims\Settlement;

use App\Application\Audit\AuditWriter;
use App\Application\Claims\ClaimPaymentService;
use App\Application\Documents\Signatures\SignatureService;
use App\Application\Events\OutboxWriter;
use App\Application\Finance\Obligations\ObligationService;
use App\Application\Ledger\FinancialPostingService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Claim;
use App\Models\ClaimDecision;
use App\Models\ClaimPayment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REQ-CLM-013 claim settlement lifecycle.
 *
 *   CALCULATED ──offer (checker ≠ calculator)──▶ OFFERED ──accept──▶ ACCEPTED ──discharge signed──▶ DISCHARGE_SIGNED
 *        ▲                                          │                   (quittance + e-signature)
 *        └──── recalculate (supersedes) ◀── DISPUTED ◀──dispute──┘
 *   DISCHARGE_SIGNED ──requestPayment──▶ PAYMENT_PENDING ──ClaimPaymentService::paid──▶ PAID
 *
 * Money: requestPayment raises the ClaimPayment (existing maker-checker/processing flow), a PAYABLE/CLAIM
 * financial obligation to the payee party and posts claim.settlement.approved; the payment's `paid` settles
 * the obligation and posts claim.settlement.paid.
 */
final class ClaimSettlementService
{
    public const LIVE = ['CALCULATED', 'OFFERED', 'ACCEPTED', 'DISPUTED', 'DISCHARGE_SIGNED', 'PAYMENT_PENDING'];

    private const TRANSITIONS = [
        'CALCULATED' => ['OFFERED', 'SUPERSEDED'],
        'OFFERED' => ['ACCEPTED', 'DISPUTED', 'SUPERSEDED'],
        'DISPUTED' => ['SUPERSEDED'],
        'ACCEPTED' => ['DISCHARGE_SIGNED'],
        'DISCHARGE_SIGNED' => ['PAYMENT_PENDING'],
        'PAYMENT_PENDING' => ['PAID'],
        'PAID' => ['PAYMENT_PENDING'], // payment reversed
    ];

    public function __construct(
        private SettlementCalculator $calculator,
        private SettlementLimitResolver $limits,
        private DischargeDocumentBuilder $discharge,
        private SignatureService $signatures,
        private ObligationService $obligations,
        private FinancialPostingService $posting,
        private AuditWriter $audit,
        private OutboxWriter $outbox,
    ) {}

    /**
     * @param array{covered_minor:int, excluded_minor?:int, deductible_minor?:?int, adjustments?:list<array{code:string, amount_minor:int, reason?:?string}>,
     *              coverage_code?:?string, payee_party_id?:?string} $d
     */
    public function calculate(Claim $claim, array $d, User $actor): object
    {
        return DB::transaction(function () use ($claim, $d, $actor): object {
            $c = Claim::whereKey($claim->id)->lockForUpdate()->firstOrFail();
            $this->tenant($c->tenant_id);
            if (! in_array($c->status, ['APPROVED', 'PARTIALLY_APPROVED'], true)) {
                $this->fail('status', 'Settlement can only be calculated on an approved claim.');
            }
            $decision = ClaimDecision::where(['claim_id' => $c->id, 'status' => 'APPROVED'])->orderByDesc('approved_at')->first()
                ?? $this->fail('claim_decision_id', 'No approved decision on this claim.');
            $payee = $d['payee_party_id'] ?? $c->claimant_party_id;
            if ($payee !== $c->claimant_party_id) {
                $this->fail('payee_party_id', 'The payee must be the claimant party.');
            }
            $live = DB::table('claim_settlements')->where('claim_id', $c->id)->whereIn('status', self::LIVE)->lockForUpdate()->first();
            if ($live && ! in_array($live->status, ['CALCULATED', 'OFFERED', 'DISPUTED'], true)) {
                $this->fail('status', "Settlement {$live->reference} is already {$live->status}.");
            }

            $prior = (int) ClaimPayment::where(['claim_id' => $c->id, 'status' => 'PAID'])->sum('amount_minor');
            $limit = $this->limits->resolve($c, $d['coverage_code'] ?? null, $prior);
            $deductible = array_key_exists('deductible_minor', $d) && $d['deductible_minor'] !== null ? (int) $d['deductible_minor'] : (int) ($limit['deductible_minor'] ?? 0);
            $r = $this->calculator->calculate((int) $d['covered_minor'], (int) ($d['excluded_minor'] ?? 0), $deductible, $prior, array_values($d['adjustments'] ?? []), $limit['remaining_limit_minor']);

            if ($live) {
                $this->move($live, 'SUPERSEDED', $actor, ['reason' => 'RECALCULATED']);
            }
            $id = (string) Str::uuid();
            $breakdown = ['lines' => $r['lines'], 'limit' => ['source' => $limit['source'], 'policy_version_id' => $limit['policy_version_id'], 'limits' => $limit['limits']],
                'deductible_source' => array_key_exists('deductible_minor', $d) && $d['deductible_minor'] !== null ? 'INPUT' : ($limit['deductible_minor'] !== null ? 'POLICY' : 'NONE'),
                'coverage_code' => $d['coverage_code'] ?? null, 'decision_approved_minor' => (int) $decision->approved_amount_minor, 'supersedes' => $live?->id];
            DB::table('claim_settlements')->insert([
                'id' => $id, 'tenant_id' => $c->tenant_id, 'claim_id' => $c->id, 'claim_decision_id' => $decision->id, 'payee_party_id' => $payee,
                'reference' => 'STL-'.strtoupper(Str::random(12)), 'status' => 'CALCULATED', 'currency' => $c->currency,
                'covered_minor' => $r['covered_minor'], 'excluded_minor' => $r['excluded_minor'], 'deductible_minor' => $r['deductible_minor'],
                'prior_payments_minor' => $r['prior_payments_minor'], 'adjustments_minor' => $r['adjustments_minor'], 'remaining_limit_minor' => $r['remaining_limit_minor'],
                'gross_minor' => $r['gross_minor'], 'amount_minor' => $r['amount_minor'], 'limit_capped' => $r['limit_capped'], 'limit_source' => $limit['source'],
                'breakdown' => json_encode($breakdown, JSON_THROW_ON_ERROR), 'calculated_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->history($id, null, 'CALCULATED', $actor, ['amount_minor' => $r['amount_minor']]);
            $this->audit->record('claim.settlement.calculated', 'claim_settlement', $id, ['claim_id' => $c->id, 'amount_minor' => $r['amount_minor'], 'limit_capped' => $r['limit_capped']]);
            $this->outbox->record('claim.settlement.calculated', 'claim', $c->id, ['claim_settlement_id' => $id, 'amount_minor' => $r['amount_minor'], 'currency' => $c->currency]);

            return $this->get($id);
        });
    }

    public function offer(string $id, User $actor): object
    {
        return DB::transaction(function () use ($id, $actor): object {
            $s = $this->locked($id);
            if ($s->calculated_by === $actor->id) {
                $this->fail('actor', 'The settlement must be offered by a different user than the one who calculated it.');
            }
            if ((int) $s->amount_minor <= 0) {
                $this->fail('amount_minor', 'A zero settlement cannot be offered.');
            }
            $approved = (int) ClaimDecision::whereKey($s->claim_decision_id)->value('approved_amount_minor');
            if ((int) $s->amount_minor > $approved) {
                $this->fail('amount_minor', 'The settlement exceeds the approved decision amount.');
            }
            $this->move($s, 'OFFERED', $actor, [], ['offered_by' => $actor->id, 'offered_at' => now()]);
            $this->outbox->record('claim.settlement.offered', 'claim', $s->claim_id, ['claim_settlement_id' => $s->id, 'amount_minor' => (int) $s->amount_minor]);

            return $this->get($id);
        });
    }

    public function accept(string $id, User $actor): object
    {
        return DB::transaction(function () use ($id, $actor): object {
            $s = $this->locked($id);
            $this->move($s, 'ACCEPTED', $actor, [], ['accepted_at' => now()]);
            $this->outbox->record('claim.settlement.accepted', 'claim', $s->claim_id, ['claim_settlement_id' => $s->id]);

            return $this->get($id);
        });
    }

    public function dispute(string $id, string $reason, User $actor): object
    {
        return DB::transaction(function () use ($id, $reason, $actor): object {
            $s = $this->locked($id);
            $this->move($s, 'DISPUTED', $actor, ['reason' => $reason], ['disputed_at' => now(), 'dispute_reason' => $reason]);
            $this->outbox->record('claim.settlement.disputed', 'claim', $s->claim_id, ['claim_settlement_id' => $s->id]);

            return $this->get($id);
        });
    }

    /** Generates the discharge receipt (quittance) and sends it to the payee for e-signature. Idempotent while a request is pending. */
    public function requestDischarge(string $id, User $actor, ?string $signerUserId = null): object
    {
        return DB::transaction(function () use ($id, $actor, $signerUserId): object {
            $s = $this->locked($id);
            if ($s->status !== 'ACCEPTED') {
                $this->fail('status', 'The discharge can only be requested on an accepted settlement.');
            }
            if ($s->signature_request_id && DB::table('signature_requests')->where('id', $s->signature_request_id)->whereIn('status', ['PENDING', 'COMPLETED'])->exists()) {
                return $this->get($id);
            }
            $claim = Claim::findOrFail($s->claim_id);
            $doc = $this->discharge->build($s, $claim, $actor);
            $payeeName = (string) (DB::table('parties')->where('id', $s->payee_party_id)->value('display_name') ?? 'Payee');
            $req = $this->signatures->request($s->tenant_id, [
                'document_id' => $doc->id,
                'consent_text' => "I accept {$s->amount_minor} {$s->currency} in full and final settlement of claim {$claim->claim_number} and sign this discharge.",
                'signers' => [['party_id' => $s->payee_party_id, 'user_id' => $signerUserId, 'name' => $payeeName, 'role' => 'PAYEE']],
            ], $actor);
            DB::table('claim_settlements')->where('id', $s->id)->update(['discharge_document_id' => $doc->id, 'signature_request_id' => $req['id'], 'updated_at' => now()]);
            $this->history($s->id, 'ACCEPTED', 'ACCEPTED', $actor, ['discharge_document_id' => $doc->id, 'signature_request_id' => $req['id']]);
            $this->outbox->record('claim.settlement.discharge_requested', 'claim', $s->claim_id, ['claim_settlement_id' => $s->id, 'document_id' => $doc->id, 'signature_request_id' => $req['id']]);

            return $this->get($id);
        });
    }

    /** ACCEPTED → DISCHARGE_SIGNED once every signer of the discharge signature request has signed. */
    public function confirmDischarge(string $id, ?User $actor = null): object
    {
        return DB::transaction(function () use ($id, $actor): object {
            $s = $this->locked($id);
            $req = $s->signature_request_id ? DB::table('signature_requests')->where('id', $s->signature_request_id)->first() : null;
            if (! $req || $req->status !== 'COMPLETED') {
                $this->fail('signature_request_id', 'The discharge has not been signed.');
            }
            $this->move($s, 'DISCHARGE_SIGNED', $actor, ['signature_request_id' => $req->id], ['discharge_signed_at' => $req->completed_at ?? now()]);
            DB::table('documents')->where('id', $s->discharge_document_id)->where('status', 'PENDING_SIGNATURE')->update(['status' => 'ISSUED', 'status_changed_at' => now(), 'updated_at' => now()]);
            $this->outbox->record('claim.settlement.discharge_signed', 'claim', $s->claim_id, ['claim_settlement_id' => $s->id, 'signature_request_id' => $req->id]);

            return $this->get($id);
        });
    }

    /** DISCHARGE_SIGNED → PAYMENT_PENDING: claim payment + PAYABLE/CLAIM obligation + claim.settlement.approved posting. */
    public function requestPayment(string $id, User $actor): object
    {
        return DB::transaction(function () use ($id, $actor): object {
            $s = $this->locked($id);
            if ($s->status !== 'DISCHARGE_SIGNED') {
                $this->fail('status', 'Payment can only be requested once the discharge is signed.');
            }
            $claim = Claim::findOrFail($s->claim_id);
            $payment = app(ClaimPaymentService::class)->request($claim, ClaimDecision::findOrFail($s->claim_decision_id), [
                'payee_party_id' => $s->payee_party_id, 'amount_minor' => (int) $s->amount_minor, 'idempotency_key' => 'settlement:'.$s->id, 'claim_settlement_id' => $s->id,
            ], $actor);
            $obligation = $this->obligations->create([
                'tenant_id' => $s->tenant_id, 'kind' => 'PAYABLE', 'type' => 'CLAIM', 'creditor_type' => 'PARTY', 'creditor_id' => $s->payee_party_id,
                'source_type' => 'claim_settlement', 'source_id' => $s->id, 'policy_id' => $claim->policy_id, 'currency' => $s->currency,
                'amount_minor' => (int) $s->amount_minor, 'due_at' => now(), 'description' => "Claim {$claim->claim_number} settlement {$s->reference}",
                'metadata' => ['claim_id' => $claim->id, 'claim_payment_id' => $payment->id],
            ], $actor->id);
            $journal = $this->posting->post($s->tenant_id, 'claim.settlement.approved', $s->id, (int) $s->amount_minor, $s->currency, 'claim-settlement:'.$s->id);
            $this->move($s, 'PAYMENT_PENDING', $actor, ['claim_payment_id' => $payment->id], [
                'claim_payment_id' => $payment->id, 'financial_obligation_id' => $obligation->id, 'approved_journal_id' => $journal, 'payment_requested_at' => now(),
            ]);
            $this->outbox->record('claim.settlement.payment_requested', 'claim', $s->claim_id, ['claim_settlement_id' => $s->id, 'claim_payment_id' => $payment->id, 'financial_obligation_id' => $obligation->id]);

            return $this->get($id);
        });
    }

    /** Called by ClaimPaymentService::paid for a settlement payment (inside its transaction). */
    public function onPaymentPaid(ClaimPayment $p, ?string $reference): void
    {
        $s = DB::table('claim_settlements')->where('id', $p->claim_settlement_id)->lockForUpdate()->first();
        if (! $s || $s->status !== 'PAYMENT_PENDING') {
            return;
        }
        if ($s->financial_obligation_id) {
            $this->obligations->settle($s->financial_obligation_id, (int) $p->amount_minor, 'claim-payment:'.$p->id);
        }
        $journal = $this->posting->post($s->tenant_id, 'claim.settlement.paid', $s->id, (int) $p->amount_minor, $s->currency, 'claim-settlement:'.$s->id);
        $this->move($s, 'PAID', null, ['claim_payment_id' => $p->id, 'external_reference' => $reference], ['paid_at' => now(), 'paid_journal_id' => $journal]);
        $this->outbox->record('claim.settlement.paid', 'claim', $s->claim_id, ['claim_settlement_id' => $s->id, 'claim_payment_id' => $p->id, 'amount_minor' => (int) $p->amount_minor]);
    }

    /** Called by ClaimPaymentService::reverse: the obligation reopens and the settlement returns to PAYMENT_PENDING. */
    public function onPaymentReversed(ClaimPayment $p, string $reason): void
    {
        $s = DB::table('claim_settlements')->where('id', $p->claim_settlement_id)->lockForUpdate()->first();
        if (! $s || $s->status !== 'PAID') {
            return;
        }
        if ($s->financial_obligation_id) {
            $this->obligations->unsettle($s->financial_obligation_id, (int) $p->amount_minor, 'claim-payment-reversal:'.$p->id);
        }
        $this->move($s, 'PAYMENT_PENDING', null, ['reason' => $reason, 'claim_payment_id' => $p->id], ['paid_at' => null]);
    }

    /** @return array<string,mixed> */
    public function present(object $s): array
    {
        $row = (array) $s;
        $row['breakdown'] = is_string($s->breakdown) ? json_decode($s->breakdown, true) : $s->breakdown;
        $row['limit_capped'] = (bool) $s->limit_capped;

        return $row;
    }

    public function get(string $id): object
    {
        return DB::table('claim_settlements')->where('id', $id)->first() ?? abort(404);
    }

    public function owned(string $id): object
    {
        $s = $this->get($id);
        $this->tenant($s->tenant_id);

        return $s;
    }

    private function locked(string $id): object
    {
        $s = DB::table('claim_settlements')->where('id', $id)->lockForUpdate()->first() ?? abort(404);
        $this->tenant($s->tenant_id);

        return $s;
    }

    private function move(object $s, string $to, ?User $actor, array $details = [], array $extra = []): void
    {
        if (! in_array($to, self::TRANSITIONS[$s->status] ?? [], true)) {
            $this->fail('status', "Settlement cannot move from {$s->status} to {$to}.");
        }
        DB::table('claim_settlements')->where('id', $s->id)->update(['status' => $to, 'version' => $s->version + 1, 'updated_at' => now()] + $extra);
        $this->history($s->id, $s->status, $to, $actor, $details);
        $this->audit->record('claim.settlement.transitioned', 'claim_settlement', $s->id, ['from' => $s->status, 'to' => $to] + $details);
    }

    private function history(string $id, ?string $from, string $to, ?User $actor, array $details): void
    {
        DB::table('claim_settlement_events')->insert(['id' => (string) Str::uuid(), 'claim_settlement_id' => $id, 'from_status' => $from, 'to_status' => $to,
            'actor_id' => $actor?->id, 'details' => json_encode($details, JSON_THROW_ON_ERROR), 'occurred_at' => now()]);
    }

    private function tenant(string $tenantId): void
    {
        if ($tenantId !== app(TenantContext::class)->id()) {
            abort(404);
        }
    }

    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
