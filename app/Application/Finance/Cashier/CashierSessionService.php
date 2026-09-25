<?php

declare(strict_types=1);

namespace App\Application\Finance\Cashier;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Application\Finance\Fx\FinanceProblem;
use App\Application\Finance\Fx\FxRateService;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * REQ-PAY-010 cashier sessions (FRP I; ICE gap 30).
 *
 * OPEN (float) -> collections (CASH / CHEQUE, never anonymous) -> CLOSED (counted cash, variance computed)
 * -> APPROVED | REJECTED by a supervisor who is not the cashier (maker-checker, also a DB CHECK).
 * One OPEN session per cashier per branch (partial unique index).
 * A collection in another currency is converted to the session currency through FxRateService and keeps the
 * fx_conversion_id. A collection linked to a financial obligation settles it through ObligationService
 * (agent 9-1) when that service is present.
 */
final class CashierSessionService
{
    public const METHODS = ['CASH', 'CHEQUE'];

    private const OBLIGATION_SERVICE = 'App\\Application\\Finance\\Obligations\\ObligationService';

    public function __construct(private AuditWriter $audit, private OutboxWriter $outbox, private FxRateService $fx) {}

    public function open(string $tenantId, string $branchId, int $floatMinor, string $currency, User $cashier): object
    {
        if ($floatMinor < 0) {
            throw FinanceProblem::make('CASHIER_INVALID_FLOAT', 422, 'Opening float cannot be negative.');
        }
        if (! DB::table('tenant_branches')->where('tenant_id', $tenantId)->where('id', $branchId)->whereNull('deleted_at')->exists()) {
            throw FinanceProblem::make('CASHIER_UNKNOWN_BRANCH', 422, 'Branch not found for this tenant.');
        }
        $id = (string) Str::uuid();
        try {
            DB::transaction(function () use ($id, $tenantId, $branchId, $floatMinor, $currency, $cashier) {
                DB::table('cashier_sessions')->insert([
                    'id' => $id, 'tenant_id' => $tenantId, 'branch_id' => $branchId, 'cashier_user_id' => $cashier->id, 'currency' => strtoupper($currency),
                    'opening_float_minor' => $floatMinor, 'status' => 'OPEN', 'opened_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
                $payload = ['branch_id' => $branchId, 'cashier_user_id' => $cashier->id, 'currency' => strtoupper($currency), 'opening_float_minor' => $floatMinor];
                $this->audit->record('cashier.session.opened', 'cashier_session', $id, $payload);
                $this->outbox->record('cashier.session.opened', 'cashier_session', $id, $payload);
            });
        } catch (QueryException $e) {
            if (str_contains($e->getMessage(), 'cashier_sessions_one_open')) {
                throw FinanceProblem::make('CASHIER_SESSION_ALREADY_OPEN', 409, 'This cashier already has an open session at this branch.');
            }
            throw $e;
        }

        return $this->find($tenantId, $id);
    }

    /**
     * @param array{method:string, amount_minor:int, currency?:?string, payer_party_id?:?string, payer_name?:?string, payment_intent_id?:?string,
     *              financial_obligation_id?:?string, cheque_number?:?string, cheque_bank?:?string, reference?:?string} $d
     */
    public function collect(string $tenantId, string $sessionId, array $d, User $actor): object
    {
        $session = $this->find($tenantId, $sessionId);
        if ($session->status !== 'OPEN') {
            throw FinanceProblem::make('CASHIER_SESSION_NOT_OPEN', 409, 'Collections can only be recorded on an open session.');
        }
        if ($session->cashier_user_id !== $actor->id) {
            throw FinanceProblem::make('CASHIER_NOT_OWNER', 403, 'Only the session cashier can record collections.');
        }
        if (! in_array($d['method'], self::METHODS, true)) {
            throw FinanceProblem::make('CASHIER_INVALID_METHOD', 422, 'Cashier collections are CASH or CHEQUE.');
        }
        if ((int) $d['amount_minor'] <= 0) {
            throw FinanceProblem::make('CASHIER_INVALID_AMOUNT', 422, 'Amount must be positive.');
        }
        if (empty($d['payer_party_id']) && empty($d['payer_name'])) {
            throw FinanceProblem::make('CASHIER_ANONYMOUS_PAYER', 422, 'No anonymous cash: a payer party or payer name is required.');
        }
        if ($d['method'] === 'CHEQUE' && empty($d['cheque_number'])) {
            throw FinanceProblem::make('CASHIER_CHEQUE_NUMBER', 422, 'A cheque collection needs the cheque number.');
        }
        if (! empty($d['payment_intent_id']) && ! DB::table('payment_intents')->where('tenant_id', $tenantId)->where('id', $d['payment_intent_id'])->exists()) {
            throw FinanceProblem::make('CASHIER_UNKNOWN_PAYMENT', 422, 'Payment not found for this tenant.');
        }
        $currency = strtoupper($d['currency'] ?? $session->currency);
        $id = (string) Str::uuid();
        $receipt = 'CR-'.now()->format('Ymd').'-'.strtoupper(Str::random(8));

        DB::transaction(function () use ($id, $receipt, $tenantId, $session, $d, $currency, $actor) {
            $sessionAmount = (int) $d['amount_minor'];
            $conversionId = null;
            if ($currency !== $session->currency) {
                $conv = $this->fx->convert($tenantId, (int) $d['amount_minor'], $currency, $session->currency, now(), 'cashier_collection', $id);
                $sessionAmount = (int) $conv->to_amount_minor;
                $conversionId = $conv->id;
            }
            DB::table('cashier_collections')->insert([
                'id' => $id, 'tenant_id' => $tenantId, 'cashier_session_id' => $session->id, 'method' => $d['method'], 'currency' => $currency,
                'amount_minor' => (int) $d['amount_minor'], 'session_amount_minor' => $sessionAmount, 'fx_conversion_id' => $conversionId,
                'payer_party_id' => $d['payer_party_id'] ?? null, 'payer_name' => $d['payer_name'] ?? null, 'payment_intent_id' => $d['payment_intent_id'] ?? null,
                'financial_obligation_id' => $d['financial_obligation_id'] ?? null, 'cheque_number' => $d['cheque_number'] ?? null, 'cheque_bank' => $d['cheque_bank'] ?? null,
                'receipt_number' => $receipt, 'reference' => $d['reference'] ?? null, 'collected_by' => $actor->id, 'collected_at' => now(),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            if (! empty($d['financial_obligation_id']) && class_exists(self::OBLIGATION_SERVICE) && method_exists(self::OBLIGATION_SERVICE, 'settle')) {
                app(self::OBLIGATION_SERVICE)->settle($d['financial_obligation_id'], $sessionAmount, $receipt);
            }
            $payload = ['cashier_session_id' => $session->id, 'method' => $d['method'], 'currency' => $currency, 'amount_minor' => (int) $d['amount_minor'],
                'session_amount_minor' => $sessionAmount, 'receipt_number' => $receipt, 'payment_intent_id' => $d['payment_intent_id'] ?? null,
                'financial_obligation_id' => $d['financial_obligation_id'] ?? null];
            $this->audit->record('cashier.collection.recorded', 'cashier_collection', $id, $payload);
            $this->outbox->record('cashier.collection.recorded', 'cashier_collection', $id, $payload);
        });

        return DB::table('cashier_collections')->find($id);
    }

    public function close(string $tenantId, string $sessionId, int $countedCashMinor, ?string $notes, User $actor): object
    {
        $session = $this->find($tenantId, $sessionId);
        if ($session->status !== 'OPEN') {
            throw FinanceProblem::make('CASHIER_SESSION_NOT_OPEN', 409, 'Only an open session can be closed.');
        }
        if ($session->cashier_user_id !== $actor->id) {
            throw FinanceProblem::make('CASHIER_NOT_OWNER', 403, 'Only the session cashier can close it.');
        }
        if ($countedCashMinor < 0) {
            throw FinanceProblem::make('CASHIER_INVALID_COUNT', 422, 'Counted cash cannot be negative.');
        }
        $t = $this->totals($session->id);
        $expected = (int) $session->opening_float_minor + $t['cash'];
        $variance = $countedCashMinor - $expected;
        if ($variance !== 0 && trim((string) $notes) === '') {
            throw FinanceProblem::make('CASHIER_VARIANCE_NOTES', 422, 'A closing variance needs an explanation.');
        }
        DB::transaction(function () use ($session, $expected, $countedCashMinor, $variance, $t, $notes) {
            DB::table('cashier_sessions')->where('id', $session->id)->update([
                'status' => 'CLOSED', 'expected_cash_minor' => $expected, 'counted_cash_minor' => $countedCashMinor, 'variance_minor' => $variance,
                'cheque_total_minor' => $t['cheque'], 'cheque_count' => $t['cheque_count'], 'closing_notes' => $notes, 'closed_at' => now(), 'updated_at' => now(),
            ]);
            $payload = ['expected_cash_minor' => $expected, 'counted_cash_minor' => $countedCashMinor, 'variance_minor' => $variance, 'cheque_total_minor' => $t['cheque'], 'currency' => $session->currency];
            $this->audit->record('cashier.session.closed', 'cashier_session', $session->id, $payload, $notes);
            $this->outbox->record('cashier.session.closed', 'cashier_session', $session->id, $payload);
        });

        return $this->find($tenantId, $session->id);
    }

    public function decide(string $tenantId, string $sessionId, bool $approve, ?string $notes, User $supervisor): object
    {
        $session = $this->find($tenantId, $sessionId);
        if ($session->status !== 'CLOSED') {
            throw FinanceProblem::make('CASHIER_SESSION_NOT_CLOSED', 409, 'Only a closed session can be approved or rejected.');
        }
        if ($session->cashier_user_id === $supervisor->id) {
            throw FinanceProblem::make('MAKER_CHECKER', 403, 'The cashier cannot approve their own session.');
        }
        if (! $approve && trim((string) $notes) === '') {
            throw FinanceProblem::make('CASHIER_REJECTION_NOTES', 422, 'A rejection needs a reason.');
        }
        $status = $approve ? 'APPROVED' : 'REJECTED';
        $event = $approve ? 'cashier.session.approved' : 'cashier.session.rejected';
        DB::transaction(function () use ($session, $status, $event, $notes, $supervisor) {
            DB::table('cashier_sessions')->where('id', $session->id)->update(['status' => $status, 'decided_by' => $supervisor->id, 'decided_at' => now(), 'decision_notes' => $notes, 'updated_at' => now()]);
            $payload = ['variance_minor' => (int) $session->variance_minor, 'currency' => $session->currency, 'decided_by' => $supervisor->id];
            $this->audit->record($event, 'cashier_session', $session->id, $payload, $notes);
            $this->outbox->record($event, 'cashier_session', $session->id, $payload);
        });

        return $this->find($tenantId, $session->id);
    }

    public function find(string $tenantId, string $sessionId): object
    {
        $s = DB::table('cashier_sessions')->where('tenant_id', $tenantId)->where('id', $sessionId)->first();
        if (! $s) {
            throw FinanceProblem::make('NOT_FOUND', 404, 'Cashier session not found.');
        }

        return $s;
    }

    /** @return array{cash:int, cheque:int, cheque_count:int} */
    public function totals(string $sessionId): array
    {
        $rows = DB::table('cashier_collections')->where('cashier_session_id', $sessionId)
            ->selectRaw('method, COALESCE(SUM(session_amount_minor),0) AS total, COUNT(*) AS n')->groupBy('method')->get()->keyBy('method');

        return ['cash' => (int) ($rows['CASH']->total ?? 0), 'cheque' => (int) ($rows['CHEQUE']->total ?? 0), 'cheque_count' => (int) ($rows['CHEQUE']->n ?? 0)];
    }
}
