<?php

declare(strict_types=1);

namespace App\Application\Agents;

use App\Application\FinancialDistribution\PayoutService;
use App\Application\Identity\TotpService;
use App\Models\PartnerPayoutRequest;
use App\Models\PartnerStatement;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;

/**
 * Self-service commission withdrawal for an agent's own accrued balance.
 * Deliberately does not reimplement any payout mechanics — every mutation
 * goes through the existing App\Application\FinancialDistribution\PayoutService
 * (encryption of the destination, reserved-balance re-check, maker-checker
 * state machine, PartnerPayoutAttempt trail) exactly as the Filament
 * back-office does. This class only adds what "self-service" requires on
 * top of that back-office-shaped service:
 *
 *  - resolves the PartnerStatement to withdraw against automatically (the
 *    agent's own latest PUBLISHED statement) instead of requiring the
 *    caller to know a statement id;
 *  - a velocity check (one in-flight request at a time per partner);
 *  - step-up authentication (a TOTP code, re-verified here, independent of
 *    the bearer token) before a financial mutation, per the Patch 4 merge
 *    guide's "Withdrawals require ... verified destination, step-up
 *    authentication and idempotency."
 *
 * Approval/processing/completion (PayoutService::approve/markProcessing/
 * complete/fail/reverse) stay back-office-only and are NOT exposed here —
 * an agent can request a withdrawal but, by the same maker-checker rule
 * that already guards every other financial transition in this app, cannot
 * approve or settle their own request.
 */
final class AgentWithdrawalService
{
    public function __construct(
        private readonly AgentPartnerResolver $partners,
        private readonly PayoutService $payouts,
        private readonly TotpService $totp,
    ) {
    }

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'amount_minor' => 'required|integer|min:1',
            'destination_type' => 'required|in:MOBILE_MONEY,BANK_ACCOUNT',
            'destination' => 'required|string|max:190',
            'step_up_code' => 'required|digits:6',
        ];
    }

    /** The statements the agent's withdrawable balance is drawn from. */
    public function statements(User $user, string $tenantId, int $perPage = 20): LengthAwarePaginator
    {
        $partner = $this->partners->resolve($user);

        return PartnerStatement::where('tenant_id', $tenantId)->where('partner_id', $partner->id)->orderByDesc('period_end')->paginate($perPage);
    }

    public function withdrawals(User $user, string $tenantId, int $perPage = 20): LengthAwarePaginator
    {
        $partner = $this->partners->resolve($user);

        return PartnerPayoutRequest::where('tenant_id', $tenantId)->where('partner_id', $partner->id)->orderByDesc('created_at')->paginate($perPage);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function request(array $data, User $user, string $tenantId, string $idempotencyKey): PartnerPayoutRequest
    {
        $partner = $this->partners->resolveActive($user);

        $this->assertStepUp($user, (string) $data['step_up_code']);

        if (PartnerPayoutRequest::where('tenant_id', $tenantId)->where('partner_id', $partner->id)->whereIn('status', ['REQUESTED', 'APPROVED', 'PROCESSING'])->exists()) {
            throw ValidationException::withMessages(['withdrawal' => [__('wave12.agent_withdrawal_in_flight')]]);
        }

        $statement = PartnerStatement::where('tenant_id', $tenantId)->where('partner_id', $partner->id)->where('status', 'PUBLISHED')->orderByDesc('period_end')->first();

        if (! $statement || $statement->closing_balance_minor <= 0) {
            throw ValidationException::withMessages(['withdrawal' => [__('wave12.agent_no_available_balance')]]);
        }

        if ($data['destination_type'] === 'MOBILE_MONEY' && ! preg_match('/^\+[1-9]\d{7,14}$/', (string) $data['destination'])) {
            throw ValidationException::withMessages(['destination' => [__('wave12.agent_withdrawal_destination_invalid')]]);
        }

        return $this->payouts->request($statement, [
            'amount_minor' => $data['amount_minor'],
            'destination_type' => $data['destination_type'],
            'destination' => $data['destination'],
            'idempotency_key' => $idempotencyKey,
        ], $user);
    }

    /**
     * Verified independently of the bearer session: requires an already
     * enrolled+verified TOTP method (see AccountSecurityService::confirmTotp)
     * and a fresh code for THIS request, reusing the same TotpService the
     * account-security enrollment flow uses rather than inventing a second
     * OTP mechanism.
     */
    private function assertStepUp(User $user, string $code): void
    {
        $method = $user->mfaMethods()->where('type', 'TOTP')->whereNotNull('verified_at')->whereNull('disabled_at')->first();

        if (! $method) {
            throw ValidationException::withMessages(['step_up_code' => [__('wave12.agent_withdrawal_mfa_required')]]);
        }

        if (! $this->totp->verify((string) $method->secret_encrypted, $code)) {
            throw ValidationException::withMessages(['step_up_code' => [__('wave12.agent_withdrawal_mfa_invalid')]]);
        }
    }
}
