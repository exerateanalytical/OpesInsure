<?php

declare(strict_types=1);

namespace App\Application\Demo;

use App\Application\Audit\AuditWriter;
use App\Application\Policies\PolicyIssuanceService;
use App\Models\PaymentIntentRecord;
use App\Models\Policy;
use App\Models\Proposal;
use App\Models\Tenant;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Demo-only straight-through settlement. Real deployments confirm a mobile
 * money payment through the provider webhook and issue the policy through
 * the carrier's issuance queue; a demo server has neither a live provider
 * nor a carrier desk, so — only while demo mode is on — a payment that has
 * been waiting on the "customer" for ~20 seconds is confirmed, reconciled,
 * and the policy issued through the real PolicyIssuanceService, exactly as
 * a carrier approval would. Polled from the purchase-status endpoint the
 * app already hits while it waits.
 */
final class DemoPurchaseSettler
{
    public const CUSTOMER_PROMPT_SECONDS = 20;

    public function __construct(private PolicyIssuanceService $issuance, private AuditWriter $audit) {}

    public function settleIfDue(Proposal $proposal, User $customer): void
    {
        if (! config('demo.enabled')) {
            return;
        }
        $payment = PaymentIntentRecord::where('proposal_id', $proposal->id)->latest('created_at')->first();
        if (! $payment) {
            return;
        }

        if (in_array($payment->status, ['PENDING_CUSTOMER', 'PROCESSING', 'CREATED'], true) && $payment->created_at->lte(now()->subSeconds(self::CUSTOMER_PROMPT_SECONDS))) {
            DB::transaction(function () use ($payment) {
                $previous = $payment->status;
                $payment->update(['status' => 'SUCCEEDED', 'reconciled_at' => now(), 'provider_snapshot' => array_merge($payment->provider_snapshot ?? [], ['demo' => true, 'settled_by' => 'DemoPurchaseSettler'])]);
                DB::table('payment_events')->insert(['id' => (string) Str::uuid(), 'payment_intent_id' => $payment->id, 'type' => 'PROVIDER_STATUS', 'provider_event_id' => 'DEMO-'.Str::uuid(), 'previous_status' => $previous, 'new_status' => 'SUCCEEDED', 'amount_minor' => $payment->amount_minor, 'currency' => $payment->currency, 'provider_payload' => json_encode(['demo' => true]), 'occurred_at' => now()]);
                $this->audit->record('demo.payment.settled', 'payment_intent', $payment->id, ['previous' => $previous]);
            });
            $payment->refresh();
        }

        if ($payment->status === 'SUCCEEDED' && $proposal->status === 'PAYMENT_PENDING' && ! Policy::where('proposal_id', $proposal->id)->exists()) {
            $this->issue($proposal, $payment, $customer);
        }
    }

    private function issue(Proposal $proposal, PaymentIntentRecord $payment, User $customer): void
    {
        $tenant = Tenant::findOrFail($proposal->tenant_id);
        $approver = User::whereHas('memberships', fn ($q) => $q->where('tenant_id', $tenant->id)->where('status', 'ACTIVE')->whereIn('role_code', ['CARRIER_STAFF', 'PLATFORM_ADMIN', 'SYSTEM_ADMIN']))->where('id', '!=', $customer->id)->orderBy('created_at')->first();
        if (! $approver) {
            return;
        }
        $starts = now()->startOfDay();
        $ends = $proposal->offer?->quote?->line_code === 'TRAVEL' ? $starts->copy()->addDays(30) : $starts->copy()->addYear()->subDay();
        $request = DB::table('policy_issuance_requests')->where('proposal_id', $proposal->id)->first();
        if (! $request) {
            $this->issuance->request($tenant, $proposal, $payment, ['coverage_starts_at' => $starts->toDateTimeString(), 'coverage_ends_at' => $ends->toDateTimeString(), 'territory' => 'CM'], $customer);
        }
        $requestModel = \App\Models\PolicyIssuanceRequest::where('proposal_id', $proposal->id)->firstOrFail();
        if (in_array($requestModel->status, ['REQUESTED', 'CARRIER_REVIEW'], true)) {
            $policy = $this->issuance->approve($requestModel, ['policy_number' => 'POL-'.now()->format('Y').'-'.str_pad((string) (Policy::count() + 1000), 6, '0', STR_PAD_LEFT), 'carrier_reference' => 'DEMO-'.strtoupper(Str::random(8))], $approver);
            UserNotification::notify($customer, 'POLICY', 'Your policy is active', "Policy {$policy->policy_number} has been issued. Your certificate is ready in your wallet.", 'SUCCESS', "/policy/{$policy->id}", $tenant->id);
        }
    }
}
