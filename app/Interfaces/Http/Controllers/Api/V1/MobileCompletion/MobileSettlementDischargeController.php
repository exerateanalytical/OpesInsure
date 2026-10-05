<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\MobileCompletion;

use App\Application\Audit\AuditWriter;
use App\Application\Claims\MobileClaimService;
use App\Application\Claims\Settlement\ClaimSettlementService;
use App\Application\Claims\Settlement\MobileClaimSettlementView;
use App\Application\Documents\Signatures\SignatureService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Claim;
use App\Models\UserNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The customer's side of the settlement after acceptance (REQ-CLM-013), on the caller's own claim only:
 *  - POST mobile/claims/{claim}/settlement/discharge/sign     sign the discharge through SignatureService::sign (the caller must be the
 *         pending signer — SignatureService checks it); once every signer signed, ClaimSettlementService::confirmDischarge moves
 *         ACCEPTED → DISCHARGE_SIGNED so the payment can be requested without a staff step.
 *  - POST mobile/claims/{claim}/settlement/discharge/decline  decline with a reason (SignatureService::decline; staff re-issue).
 *  - PUT  mobile/claims/{claim}/settlement/payout             where the money goes (mobile money or bank), kept on the claim
 *         (claims.loss_details.payout) until the payment is requested; a change raises a SECURITY alert to the user.
 * Every answer is the refreshed MobileClaimSettlementView.
 */
final class MobileSettlementDischargeController
{
    public function __construct(
        private readonly MobileClaimService $claims,
        private readonly MobileClaimSettlementView $view,
        private readonly SignatureService $signatures,
        private readonly ClaimSettlementService $settlements,
        private readonly AuditWriter $audit,
    ) {}

    public function sign(string $claim, Request $request): JsonResponse
    {
        $request->validate(['consent_accepted' => 'required|accepted']);
        [$c, $s] = $this->pendingDischarge($claim, $request);
        $req = $this->signatures->sign((string) $s->signature_request_id, $request->user(), ['consent_accepted' => true, 'ip' => $request->ip(), 'user_agent' => $request->userAgent()]);
        if (($req['status'] ?? null) === 'COMPLETED') {
            try {
                $this->settlements->confirmDischarge($s->id, $request->user());
            } catch (\Throwable $e) {
                // The signature stands; the claims desk confirms the discharge by hand if the automatic step fails.
                report($e);
            }
        }
        $this->audit->record('claim.settlement.discharge_signed_by_customer', 'claim', $c->id, ['claim_settlement_id' => $s->id, 'signature_request_id' => $s->signature_request_id]);

        return response()->json(['data' => $this->view->present($c->refresh(), $request->user())]);
    }

    public function decline(string $claim, Request $request): JsonResponse
    {
        $d = $request->validate(['reason' => 'required|string|min:5|max:2000']);
        [$c, $s] = $this->pendingDischarge($claim, $request);
        $this->signatures->decline((string) $s->signature_request_id, $request->user(), $d['reason']);
        $this->audit->record('claim.settlement.discharge_declined_by_customer', 'claim', $c->id, ['claim_settlement_id' => $s->id], $d['reason']);

        return response()->json(['data' => $this->view->present($c->refresh(), $request->user())]);
    }

    public function payout(string $claim, Request $request): JsonResponse
    {
        $d = $request->validate([
            'method' => 'required|in:MOBILE_MONEY,BANK_TRANSFER',
            'operator' => ['required_if:method,MOBILE_MONEY', 'nullable', Rule::in(['MTN', 'ORANGE'])],
            'msisdn' => ['required_if:method,MOBILE_MONEY', 'nullable', 'regex:/^\+[1-9]\d{7,14}$/'],
            'bank_name' => 'required_if:method,BANK_TRANSFER|nullable|string|max:120',
            'account_name' => 'required_if:method,BANK_TRANSFER|nullable|string|max:160',
            'account_number' => ['required_if:method,BANK_TRANSFER', 'nullable', 'regex:/^[A-Za-z0-9 ]{8,40}$/'],
        ]);
        $c = $this->owned($claim, $request);
        $s = MobileClaimSettlementView::current($c);
        if (! $s || ! in_array($s->status, MobileClaimSettlementView::PAYOUT_EDITABLE, true)) {
            throw ValidationException::withMessages(['method' => __('customer_flows.payout_locked')]);
        }
        $mobile = $d['method'] === 'MOBILE_MONEY';
        $payout = array_filter([
            'method' => $d['method'],
            'operator' => $mobile ? $d['operator'] : null, 'msisdn' => $mobile ? $d['msisdn'] : null,
            'bank_name' => $mobile ? null : trim((string) $d['bank_name']), 'account_name' => $mobile ? null : trim((string) $d['account_name']),
            'account_number' => $mobile ? null : strtoupper(str_replace(' ', '', (string) $d['account_number'])),
            'updated_at' => now()->toIso8601String(), 'updated_by' => $request->user()->id,
        ], fn ($v) => $v !== null && $v !== '');

        $changed = DB::transaction(function () use ($c, $payout): bool {
            $locked = Claim::whereKey($c->id)->lockForUpdate()->firstOrFail();
            $details = $locked->loss_details ?? [];
            $before = $details['payout'] ?? null;
            $details['payout'] = $payout;
            $locked->update(['loss_details' => $details]);

            return is_array($before) && ($before['msisdn'] ?? null).($before['account_number'] ?? null) !== ($payout['msisdn'] ?? null).($payout['account_number'] ?? null);
        });
        $this->audit->record('claim.settlement.payout_destination_set', 'claim', $c->id, ['claim_settlement_id' => $s->id, 'method' => $d['method'], 'changed' => $changed]);
        // Like any payout-destination change: the account owner is told at once (security alert opens the security centre).
        UserNotification::notify($request->user(), 'SECURITY', __('customer_flows.payout_alert_title'), __('customer_flows.payout_alert_body', ['claim' => $c->claim_number]),
            'WARNING', "/claim/{$c->id}/settlement-payment", $c->tenant_id);

        return response()->json(['data' => $this->view->present($c->refresh(), $request->user())]);
    }

    /** @return array{0: Claim, 1: object} the caller's claim and its ACCEPTED settlement with a discharge out for signature */
    private function pendingDischarge(string $claim, Request $request): array
    {
        $c = $this->owned($claim, $request);
        $s = MobileClaimSettlementView::current($c);
        if (! $s || $s->status !== 'ACCEPTED' || ! $s->signature_request_id) {
            throw ValidationException::withMessages(['settlement' => __('customer_flows.no_discharge')]);
        }

        return [$c, $s];
    }

    private function owned(string $id, Request $request): Claim
    {
        return $this->claims->owned($id, $request->user(), app(TenantContext::class)->id());
    }
}
