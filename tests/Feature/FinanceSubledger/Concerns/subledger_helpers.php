<?php

declare(strict_types=1);

// Agent F1 — shared fixture for the finance counterparty accounts & commission sub-ledger acceptance tests.

use App\Application\Finance\Obligations\ObligationService;
use App\Application\Ledger\FinancialPostingService;
use App\Application\Ledger\Posting\AccountingEventMappingService;
use App\Models\Carrier;
use App\Models\CommissionAccrual;
use App\Models\InsuranceProduct;
use App\Models\Partner;
use App\Models\Party;
use App\Models\Policy;
use App\Models\Proposal;
use App\Models\Quote;
use App\Models\QuoteOffer;
use App\Models\SettlementBatch;
use App\Models\TariffVersion;
use App\Models\Tenant;
use Illuminate\Support\Str;

require_once __DIR__.'/../../Wave12/Concerns/mobile_customer_helpers.php';
require_once __DIR__.'/../../Concerns/wave_auth_helpers.php';

if (! function_exists('fslPolicy')) {
    function fslPerms(): array
    {
        return ['finance.accounts.view', 'finance.accounts.export', 'finance.ledger.view', 'finance.commissions.view', 'finance.commissions.configure',
            'finance.settlements.create', 'finance.reconciliation.override', 'finance.adjustments.create', 'finance.adjustments.approve'];
    }

    /** A policy in the tenant for (party, carrier, product line) with its own quote / offer / proposal chain. */
    function fslPolicy(Tenant $t, Party $party, Carrier $carrier, string $line = 'AUTO', ?string $servicingPartnerId = null, int $premium = 100000): array
    {
        $product = InsuranceProduct::create(['carrier_id' => $carrier->id, 'line_code' => $line, 'code' => $line.'-'.Str::random(6), 'name' => $line.' plan', 'version' => 1,
            'effective_from' => now()->toDateString(), 'status' => 'ACTIVE']);
        $tariff = TariffVersion::create(['insurance_product_id' => $product->id, 'version' => 1, 'effective_from' => now()->toDateString(), 'status' => 'APPROVED', 'input_schema' => [], 'rules' => [], 'rules_hash' => Str::random(64)]);
        $quote = Quote::create(['tenant_id' => $t->id, 'party_id' => $party->id, 'line_code' => $line, 'status' => 'RATED', 'currency' => 'XAF', 'risk_facts' => []]);
        $offer = QuoteOffer::create(['quote_id' => $quote->id, 'carrier_id' => $carrier->id, 'product_id' => $product->id, 'tariff_version_id' => $tariff->id, 'premium_minor' => $premium,
            'total_minor' => $premium, 'currency' => 'XAF', 'status' => 'OFFERED', 'calculation_breakdown' => [], 'valid_until' => now()->addDays(7)]);
        $proposal = Proposal::create(['tenant_id' => $t->id, 'quote_offer_id' => $offer->id, 'party_id' => $party->id, 'status' => 'APPROVED']);
        $policy = Policy::create(['tenant_id' => $t->id, 'proposal_id' => $proposal->id, 'carrier_id' => $carrier->id, 'party_id' => $party->id, 'policy_number' => 'POL-'.Str::upper(Str::random(8)),
            'status' => 'ACTIVE', 'coverage_starts_at' => now()->subDays(10), 'coverage_ends_at' => now()->addYear(), 'terms_snapshot' => [], 'version' => 1, 'currency' => 'XAF',
            'premium_minor' => $premium, 'issued_at' => now()->subDays(10)]);
        $policy->forceFill(['servicing_partner_id' => $servicingPartnerId])->save();

        return ['policy' => $policy, 'product' => $product, 'proposal' => $proposal];
    }

    function fslPartner(Tenant $t, string $type): Partner
    {
        $party = Party::create(['type' => 'ORGANIZATION', 'display_name' => $type.' '.Str::random(4), 'status' => 'ACTIVE']);

        return Partner::create(['tenant_id' => $t->id, 'party_id' => $party->id, 'type' => $type, 'status' => 'ACTIVE']);
    }

    function fslPost(Tenant $t, string $event, string $ref, int $amount): string
    {
        return app(FinancialPostingService::class)->post($t->id, $event, $ref, $amount, 'XAF', 'fsl:'.$ref);
    }

    /** Customer premium billed (receivable + journal). */
    function fslBill(Tenant $t, Policy $p, int $amount, ?string $dueAt = null): object
    {
        $o = app(ObligationService::class)->create(['tenant_id' => $t->id, 'kind' => 'RECEIVABLE', 'type' => 'PREMIUM', 'source_type' => 'policy', 'source_id' => $p->id,
            'source_reference' => 'premium:'.Str::random(6), 'currency' => 'XAF', 'amount_minor' => $amount, 'due_at' => $dueAt ?? now()->toDateString(),
            'debtor_type' => 'party', 'debtor_id' => $p->party_id, 'creditor_type' => 'carrier', 'creditor_id' => $p->carrier_id, 'policy_id' => $p->id]);
        fslPost($t, 'finance.obligation.created', $o->id, $amount);

        return $o;
    }

    /** Customer payment collected against a receivable (payment + settlement + journal). */
    function fslCollect(Tenant $t, Proposal $proposal, object $receivable, int $amount): object
    {
        $pay = makeMobileTestPayment($proposal, $t, ['amount_minor' => $amount, 'status' => 'SUCCEEDED']);
        $pay->forceFill(['reconciled_at' => now()])->save();
        app(ObligationService::class)->settle($receivable->id, $amount, 'pay:'.$pay->id);
        fslPost($t, 'payment.succeeded', $pay->id, $amount);

        return $pay;
    }

    /** Premium due to the insurer (carrier PAYABLE opened by an approved settlement, posted). */
    function fslCarrierPayable(Tenant $t, Policy $p, int $amount, ?string $dueAt = null, ?string $staffId = null): object
    {
        $staffId ??= makeAuthTestUser($t, [])->id;
        $batch = SettlementBatch::create(['tenant_id' => $t->id, 'carrier_id' => $p->carrier_id, 'settlement_number' => 'ST-'.Str::random(8), 'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->endOfMonth()->toDateString(), 'net_amount_minor' => $amount, 'currency' => 'XAF', 'status' => 'APPROVED', 'prepared_by' => $staffId]);
        $o = app(ObligationService::class)->create(['tenant_id' => $t->id, 'kind' => 'PAYABLE', 'type' => 'PREMIUM', 'source_type' => 'settlement_batch', 'source_id' => $batch->id,
            'source_reference' => 'policy:'.$p->id, 'currency' => 'XAF', 'amount_minor' => $amount, 'due_at' => $dueAt ?? now()->addDays(15)->toDateString(),
            'creditor_type' => 'carrier', 'creditor_id' => $p->carrier_id, 'policy_id' => $p->id]);
        fslPost($t, 'settlement.approved', $batch->id, $amount);

        return $o;
    }

    function fslAccrual(Tenant $t, Policy $p, Partner $partner, int $amount, string $status = 'VESTED', array $extra = []): CommissionAccrual
    {
        $a = CommissionAccrual::create(array_merge(['tenant_id' => $t->id, 'policy_id' => $p->id, 'partner_id' => $partner->id, 'rule_version' => 'v1', 'amount_minor' => $amount,
            'currency' => 'XAF', 'status' => $status, 'vested_minor' => in_array($status, ['VESTED', 'PAID'], true) ? $amount : 0, 'paid_minor' => 0, 'clawed_back_minor' => 0,
            'idempotency_key' => 'fsl:'.Str::random(10)], $extra));
        fslPost($t, 'commission.accrued', $a->id, $amount);

        return $a;
    }

    /** One broker tenant world: customer, insurer, broker, agent, one billed + part-collected policy, a carrier payable and an agent accrual. */
    function fslWorld(): array
    {
        $f = makeMobileCustomerFixture('+2376'.random_int(10000000, 99999999));
        $t = $f['tenant'];
        app(AccountingEventMappingService::class)->provisionChart($t->id, 'XAF');
        $broker = fslPartner($t, 'BROKER');
        $agent = fslPartner($t, 'AGENT');
        $staff = makeAuthTestUser($t, fslPerms());
        $p = fslPolicy($t, $f['party'], $f['carrier'], 'AUTO', $broker->id);
        $receivable = fslBill($t, $p['policy'], 100000);
        $payment = fslCollect($t, $p['proposal'], $receivable, 60000);
        $payable = fslCarrierPayable($t, $p['policy'], 90000, null, $staff->id);
        $accrual = fslAccrual($t, $p['policy'], $agent, 10000);

        return array_merge($f, ['broker' => $broker, 'agent' => $agent, 'staff' => $staff, 'policy' => $p['policy'], 'product' => $p['product'], 'proposal2' => $p['proposal'],
            'receivable' => $receivable, 'payment' => $payment, 'payable' => $payable, 'accrual' => $accrual]);
    }
}
