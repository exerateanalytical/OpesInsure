<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\MobileCompletion;

use App\Application\Agents\AgentClientIntakeService;
use App\Application\Agents\AgentPartnerResolver;
use App\Application\Audit\AuditWriter;
use App\Application\Quotes\QuoteService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Partner;
use App\Models\Policy;
use App\Models\Tenant;
use App\Models\TenantCustomer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The agent portal (app/agent/*) in the shapes the app renders. Wraps the
 * Agent Mode services where they exist (client intake) and reads the
 * commission/renewal/payout tables directly for the rest. Every read is
 * scoped to the agent's own Partner via AgentPartnerResolver.
 */
final class MobileAgentPortalController
{
    public function __construct(private AgentPartnerResolver $partners, private AgentClientIntakeService $intake, private AuditWriter $audit) {}

    public function dashboard(Request $request): JsonResponse
    {
        $t = app(TenantContext::class)->id();
        $partner = $this->partners->resolve($request->user());
        $clientIds = $this->clientPartyIds($partner, $t);
        $active = Policy::where('tenant_id', $t)->whereIn('party_id', $clientIds)->where('status', 'ACTIVE')->count();
        $due = DB::table('renewal_work_items')->where('tenant_id', $t)->whereIn('policy_id', Policy::where('tenant_id', $t)->whereIn('party_id', $clientIds)->pluck('id'))->where('status', 'DUE')->count();
        $comm = DB::table('commission_accruals')->where('tenant_id', $t)->where('partner_id', $partner->id)->selectRaw("COALESCE(SUM(CASE WHEN status='AVAILABLE' THEN vested_minor - paid_minor ELSE 0 END),0) available, COALESCE(SUM(CASE WHEN status='PENDING' THEN amount_minor ELSE 0 END),0) pending")->first();
        $xaf = fn ($minor) => number_format(((int) $minor) / 100, 0, '.', ' ').' FCFA';

        return response()->json(['data' => ['metrics' => [
            ['label' => 'Clients', 'value' => (string) count($clientIds), 'tone' => 'info'],
            ['label' => 'Active policies', 'value' => (string) $active, 'tone' => 'success'],
            ['label' => 'Renewals due', 'value' => (string) $due, 'tone' => $due > 0 ? 'warning' : 'neutral'],
            ['label' => 'Commission available', 'value' => $xaf($comm->available), 'tone' => 'success'],
            ['label' => 'Commission pending', 'value' => $xaf($comm->pending), 'tone' => 'neutral'],
        ]]]);
    }

    public function profile(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->profileOf($this->partners->resolve($request->user()), $request)]);
    }

    public function updateProfile(Request $request): JsonResponse
    {
        $data = $request->validate(['full_name' => 'sometimes|string|min:3|max:120', 'national_id_number' => 'sometimes|nullable|string|max:40', 'momo_phone_e164' => 'sometimes|string|max:32']);
        $partner = $this->partners->resolve($request->user());
        $compliance = $partner->compliance ?? [];
        if (isset($data['momo_phone_e164'])) {
            $compliance['momo_phone_e164'] = $data['momo_phone_e164'];
        }
        if (array_key_exists('national_id_number', $data) && $data['national_id_number']) {
            $compliance['national_id_encrypted'] = Crypt::encryptString($data['national_id_number']);
            $compliance['national_id_masked'] = '••••••'.substr($data['national_id_number'], -4);
        }
        if ($partner->status === 'DRAFT') {
            $partner->status = 'PENDING_REVIEW';
        }
        $partner->compliance = $compliance;
        $partner->save();
        if (isset($data['full_name'])) {
            $request->user()->forceFill(['full_name' => $data['full_name']])->save();
            $request->user()->party?->update(['display_name' => $data['full_name']]);
        }
        $this->audit->record('agent.profile.updated', 'partner', $partner->id, ['fields' => array_keys($data)]);

        return response()->json(['data' => $this->profileOf($partner->refresh(), $request)]);
    }

    public function clients(Request $request): JsonResponse
    {
        $t = app(TenantContext::class)->id();
        $rows = $this->intake->list($request->user(), $t, 100)->getCollection();

        return response()->json(['data' => $rows->map(fn (TenantCustomer $c) => $this->clientOf($c, $t))->values()]);
    }

    public function client(string $customer, Request $request): JsonResponse
    {
        $t = app(TenantContext::class)->id();

        return response()->json(['data' => $this->clientOf($this->intake->show($customer, $request->user(), $t), $t)]);
    }

    public function createClient(Request $request): JsonResponse
    {
        $data = $request->validate(['full_name' => 'required|string|min:3|max:160', 'phone_e164' => 'required|string|max:32', 'city' => 'required|string|max:80', 'consent_reference' => 'required|string|max:255']);
        $t = app(TenantContext::class)->id();
        $result = $this->intake->register([
            'type' => 'PERSON', 'display_name' => $data['full_name'], 'phone_e164' => $data['phone_e164'], 'notice_version' => 'agent-2026-01', 'evidence_reference' => $data['consent_reference'], 'consent' => true,
        ], $request->user(), $t);
        $customerId = $result['customer']->id ?? $result['customer_id'] ?? ($result['id'] ?? null);
        $customer = TenantCustomer::with('party.contacts')->findOrFail($customerId);
        DB::table('party_addresses')->updateOrInsert(['party_id' => $customer->party_id, 'type' => 'HOME'], ['id' => DB::table('party_addresses')->where(['party_id' => $customer->party_id, 'type' => 'HOME'])->value('id') ?? (string) Str::uuid(), 'city' => $data['city'], 'country_code' => 'CM', 'is_primary' => true, 'created_at' => now(), 'updated_at' => now()]);

        return response()->json(['data' => $this->clientOf($customer, $t)], 201);
    }

    public function renewals(Request $request): JsonResponse
    {
        $t = app(TenantContext::class)->id();
        $partner = $this->partners->resolve($request->user());
        $clientIds = $this->clientPartyIds($partner, $t);
        $policies = Policy::with('party')->where('tenant_id', $t)->whereIn('party_id', $clientIds)->where('status', 'ACTIVE')->where('coverage_ends_at', '<=', now()->addDays(60))->orderBy('coverage_ends_at')->get();

        return response()->json(['data' => $policies->map(fn (Policy $p) => [
            'id' => $p->id, 'customer_id' => TenantCustomer::where(['tenant_id' => $t, 'party_id' => $p->party_id])->value('id') ?? $p->party_id, 'customer_name' => $p->party?->display_name ?? 'Customer',
            'policy_number' => $p->policy_number, 'expires_at' => $p->coverage_ends_at?->toIso8601String(), 'days_remaining' => (int) now()->startOfDay()->diffInDays($p->coverage_ends_at, false),
            'status' => DB::table('renewal_work_items')->where('policy_id', $p->id)->value('status') ?? 'DUE',
        ])->values()]);
    }

    public function commissions(Request $request): JsonResponse
    {
        $t = app(TenantContext::class)->id();
        $partner = $this->partners->resolve($request->user());
        $rows = DB::table('commission_accruals')->where('tenant_id', $t)->where('partner_id', $partner->id)->orderByDesc('created_at')->limit(100)->get();

        return response()->json(['data' => $rows->map(fn ($r) => [
            'id' => $r->id, 'policy_id' => $r->policy_id, 'status' => $r->status, 'amount_minor' => (int) $r->amount_minor, 'currency' => $r->currency,
            'available_at' => $r->available_at ? \Carbon\Carbon::parse($r->available_at)->toIso8601String() : null, 'reason' => $r->source_type ? strtolower((string) $r->source_type).' commission' : null,
        ])->values()]);
    }

    public function withdrawals(Request $request): JsonResponse
    {
        $t = app(TenantContext::class)->id();
        $partner = $this->partners->resolve($request->user());

        return response()->json(['data' => DB::table('partner_payout_requests')->where('tenant_id', $t)->where('partner_id', $partner->id)->orderByDesc('created_at')->limit(50)->get()->map(fn ($w) => $this->withdrawalOf($w))->values()]);
    }

    public function requestWithdrawal(Request $request): JsonResponse
    {
        $data = $request->validate(['provider' => 'required|in:mtn_momo,orange_money', 'amount_minor' => 'required|integer|min:100', 'destination_phone' => 'required|string|max:32']);
        $t = app(TenantContext::class)->id();
        $partner = $this->partners->resolveActive($request->user());
        $statement = DB::table('partner_statements')->where('tenant_id', $t)->where('partner_id', $partner->id)->where('status', 'PUBLISHED')->orderByDesc('period_end')->first();
        $available = (int) DB::table('commission_accruals')->where('tenant_id', $t)->where('partner_id', $partner->id)->where('status', 'AVAILABLE')->sum(DB::raw('vested_minor - paid_minor'));
        abort_unless($statement, 422, 'No published statement is available to withdraw against yet.');
        abort_if($data['amount_minor'] > max($available, (int) $statement->closing_balance_minor), 422, 'Amount exceeds your available balance.');
        abort_if(DB::table('partner_payout_requests')->where('tenant_id', $t)->where('partner_id', $partner->id)->whereIn('status', ['REQUESTED', 'APPROVED', 'PROCESSING'])->exists(), 422, 'A withdrawal is already in progress.');
        $id = (string) Str::uuid();
        DB::table('partner_payout_requests')->insert([
            'id' => $id, 'tenant_id' => $t, 'partner_id' => $partner->id, 'partner_statement_id' => $statement->id, 'payout_number' => 'PAY-'.strtoupper(Str::random(10)), 'amount_minor' => $data['amount_minor'], 'currency' => 'XAF', 'status' => 'REQUESTED',
            'destination_type' => 'MOBILE_MONEY', 'destination_encrypted' => Crypt::encryptString($data['provider'].':'.$data['destination_phone']), 'idempotency_key' => $request->header('Idempotency-Key') ?: $id, 'requested_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->audit->record('agent.withdrawal.requested', 'partner_payout_request', $id, ['amount_minor' => $data['amount_minor'], 'provider' => $data['provider']]);

        return response()->json(['data' => $this->withdrawalOf(DB::table('partner_payout_requests')->find($id))], 201);
    }

    public function createSale(Request $request, QuoteService $quotes): JsonResponse
    {
        $data = $request->validate(['customer_id' => 'required|uuid', 'product' => 'required|string|max:32', 'payment_phone_e164' => 'required|string|max:32', 'risk_facts' => 'sometimes|array']);
        $t = app(TenantContext::class)->id();
        $customer = $this->intake->show($data['customer_id'], $request->user(), $t);
        $line = strtoupper($data['product']);
        $facts = $data['risk_facts'] ?? match ($line) {
            'MOTOR' => ['registration_number' => 'TBC', 'fiscal_power' => 7, 'usage_type' => 'PRIVATE', 'zone' => 'CAMEROON'],
            'TRAVEL' => ['destination_country' => 'FRANCE', 'departure_date' => now()->addDays(14)->toDateString(), 'return_date' => now()->addDays(28)->toDateString(), 'traveller_count' => 1],
            'HOME' => ['property_type' => 'HOUSE', 'occupancy' => 'OWNER_OCCUPIED', 'city' => 'DOUALA', 'declared_value_minor' => 20_000_000_00],
            'HEALTH' => ['beneficiary_count' => 1, 'oldest_age' => 35, 'coverage_zone' => 'CAMEROON', 'plan_type' => 'INDIVIDUAL'],
            default => ['insured_age' => 35, 'cover_amount_minor' => 10_000_000_00, 'term_years' => 10, 'purpose' => 'FAMILY_PROTECTION'],
        };
        $quote = $quotes->submit(Tenant::findOrFail($t), $customer->party_id, ['line_code' => $line, 'channel' => 'AGENT', 'risk_facts' => $facts], $request->user());
        $quote = $quotes->rate($quote, $request->user());
        $best = $quote->offers()->orderBy('comparison_rank')->first();
        $quote->update(['comparison_context' => array_merge($quote->comparison_context ?? [], ['assisted_sale' => true, 'agent_user_id' => $request->user()->id, 'payment_phone_e164' => $data['payment_phone_e164'], 'customer_id' => $customer->id])]);
        $this->audit->record('agent.sale.created', 'quote', $quote->id, ['line_code' => $line, 'offers' => $quote->offers()->count()]);

        return response()->json(['data' => $this->saleOf($quote->refresh(), $customer, $best)], 201);
    }

    public function sale(string $id, Request $request): JsonResponse
    {
        $t = app(TenantContext::class)->id();
        $quote = \App\Models\Quote::where('tenant_id', $t)->where('comparison_context->agent_user_id', $request->user()->id)->findOrFail($id);
        $customer = TenantCustomer::where(['tenant_id' => $t, 'party_id' => $quote->party_id])->firstOrFail();

        return response()->json(['data' => $this->saleOf($quote, $customer, $quote->offers()->orderBy('comparison_rank')->first())]);
    }

    public function requestPayment(string $id, Request $request): JsonResponse
    {
        $t = app(TenantContext::class)->id();
        $quote = \App\Models\Quote::where('tenant_id', $t)->where('comparison_context->agent_user_id', $request->user()->id)->findOrFail($id);
        $quote->update(['comparison_context' => array_merge($quote->comparison_context ?? [], ['payment_requested_at' => now()->toIso8601String(), 'payment_status' => 'CUSTOMER_PROMPTED'])]);
        $customer = TenantCustomer::where(['tenant_id' => $t, 'party_id' => $quote->party_id])->firstOrFail();
        $this->audit->record('agent.sale.payment_requested', 'quote', $quote->id, []);

        return response()->json(['data' => $this->saleOf($quote->refresh(), $customer, $quote->offers()->orderBy('comparison_rank')->first())]);
    }

    public function offlineQueue(Request $request): JsonResponse
    {
        $rows = DB::table('sync_operations')->where('user_id', $request->user()->id)->orderByDesc('updated_at')->limit(50)->get();

        return response()->json(['data' => $rows->map(fn ($r) => $this->offlineOf($r))->values()]);
    }

    public function retryOffline(string $operation, Request $request): JsonResponse
    {
        $row = DB::table('sync_operations')->where('user_id', $request->user()->id)->where('id', $operation)->first();
        abort_unless($row, 404);
        DB::table('sync_operations')->where('id', $row->id)->update(['status' => 'QUEUED', 'updated_at' => now()]);

        return response()->json(['data' => $this->offlineOf(DB::table('sync_operations')->find($row->id))]);
    }

    // ----------------------------------------------------------------- shapes

    private function clientPartyIds(Partner $partner, string $t): array
    {
        return DB::table('customer_attributions')->where('partner_id', $partner->id)->where('status', 'ACTIVE')->pluck('party_id')->all();
    }

    private function profileOf(Partner $partner, Request $request): array
    {
        $c = $partner->compliance ?? [];
        $items = $c['items'] ?? [['label' => 'National ID', 'status' => isset($c['national_id_masked']) ? 'SUBMITTED' : 'MISSING'], ['label' => 'Agent mandate', 'status' => $partner->licence_number ? 'VERIFIED' : 'MISSING'], ['label' => 'Mobile money account', 'status' => isset($c['momo_phone_e164']) ? 'SUBMITTED' : 'MISSING']];

        return [
            'id' => $partner->id, 'status' => in_array($partner->status, ['DRAFT', 'PENDING_REVIEW', 'ACTIVE', 'SUSPENDED'], true) ? $partner->status : 'ACTIVE',
            'agent_code' => $c['agent_code'] ?? 'AG-'.strtoupper(substr($partner->id, 0, 6)), 'full_name' => $request->user()->full_name, 'national_id_number' => $c['national_id_masked'] ?? null,
            'momo_phone_e164' => $c['momo_phone_e164'] ?? $request->user()->phone_e164, 'mandate_expires_at' => $partner->licence_expires_on?->toDateString() ?? ($c['mandate_expires_at'] ?? null), 'compliance_items' => $items,
        ];
    }

    private function clientOf(TenantCustomer $c, string $t): array
    {
        $policies = Policy::where('tenant_id', $t)->where('party_id', $c->party_id)->where('status', 'ACTIVE');

        return [
            'id' => $c->id, 'full_name' => $c->party?->display_name ?? 'Client', 'phone_e164' => $c->party?->contacts?->firstWhere('type', 'PHONE')?->normalized_value ?? '',
            'city' => DB::table('party_addresses')->where('party_id', $c->party_id)->value('city'),
            'kyc_status' => DB::table('kyc_submissions')->where('party_id', $c->party_id)->orderByDesc('created_at')->value('status') ?? 'NOT_STARTED',
            'origin_locked' => DB::table('customer_attributions')->where('party_id', $c->party_id)->where('status', 'ACTIVE')->exists(),
            'active_policies' => (clone $policies)->count(), 'renewal_due_at' => (clone $policies)->min('coverage_ends_at') ? \Carbon\Carbon::parse((clone $policies)->min('coverage_ends_at'))->toIso8601String() : null,
        ];
    }

    private function withdrawalOf(object $w): array
    {
        $dest = '';
        try {
            $dest = Crypt::decryptString($w->destination_encrypted);
        } catch (\Throwable) {
        }
        [$provider, $phone] = str_contains($dest, ':') ? explode(':', $dest, 2) : ['mtn_momo', $dest];

        return ['id' => $w->id, 'provider' => $provider, 'amount_minor' => (int) $w->amount_minor, 'status' => $w->status, 'requested_at' => \Carbon\Carbon::parse($w->created_at)->toIso8601String(), 'destination_phone' => $phone ? substr($phone, 0, 7).'••••' : '••••'];
    }

    private function saleOf(\App\Models\Quote $q, TenantCustomer $customer, ?\App\Models\QuoteOffer $offer): array
    {
        $ctx = $q->comparison_context ?? [];
        $policy = Policy::whereHas('proposal', fn ($p) => $p->whereIn('quote_offer_id', $q->offers()->pluck('id')))->first();

        return [
            'id' => $q->id, 'customer_id' => $customer->id, 'customer_name' => $customer->party?->display_name ?? 'Client', 'product' => $q->line_code,
            'status' => $policy ? 'ISSUED' : ($q->status === 'OFFERED' ? 'QUOTED' : $q->status), 'premium_minor' => (int) ($offer?->total_minor ?? 0), 'currency' => 'XAF',
            'payment_phone_e164' => $ctx['payment_phone_e164'] ?? '', 'payment_status' => $policy ? 'PAID' : ($ctx['payment_status'] ?? 'NOT_REQUESTED'), 'commission_minor' => (int) round(($offer?->premium_minor ?? 0) * 0.10),
            'created_at' => $q->created_at?->toIso8601String(),
        ];
    }

    private function offlineOf(object $r): array
    {
        $status = match (strtoupper((string) $r->status)) { 'APPLIED', 'SYNCED', 'SUCCEEDED' => 'SYNCED', 'FAILED', 'CONFLICT' => 'FAILED', 'SYNCING', 'PROCESSING' => 'SYNCING', default => 'QUEUED' };

        return ['id' => $r->id, 'type' => $r->resource ?? $r->kind ?? 'OPERATION', 'local_reference' => $r->client_reference ?? $r->idempotency_key ?? substr($r->id, 0, 8), 'status' => $status, 'updated_at' => \Carbon\Carbon::parse($r->updated_at)->toIso8601String(), 'error' => $r->error_code ?? null];
    }
}
