<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\MobileCompletion;

use App\Application\Agents\AgentClientIntakeService;
use App\Application\Agents\AgentPartnerResolver;
use App\Application\Agents\AssistedSaleService;
use App\Application\Audit\AuditWriter;
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
        // Mobile audit B5: changing the payout destination needs a PAYOUT_DESTINATION_CHANGE step-up grant.
        $payoutChanged = isset($data['momo_phone_e164']) && $data['momo_phone_e164'] !== ($compliance['momo_phone_e164'] ?? null);
        if ($payoutChanged) {
            app(\App\Application\Security\StepUpGate::class)->assert($request, 'PAYOUT_DESTINATION_CHANGE');
            $compliance['momo_phone_e164'] = $data['momo_phone_e164'];
        }
        if (array_key_exists('national_id_number', $data) && $data['national_id_number']) {
            $compliance['national_id_encrypted'] = Crypt::encryptString($data['national_id_number']);
            $compliance['national_id_masked'] = '••••••'.substr($data['national_id_number'], -4);
        }
        if ($partner->status === 'DRAFT') {
            $partner->status = 'PENDING_REVIEW';
        }
        // Security review 2026-09-27 item 4: a new payout number starts the withdrawal cooling-off period.
        $compliance = app(\App\Application\Agents\PayoutDestinationGuard::class)->stampIfChanged($partner->compliance ?? [], $compliance);
        $partner->compliance = $compliance;
        $partner->save();
        if (isset($data['full_name'])) {
            $request->user()->forceFill(['full_name' => $data['full_name']])->save();
            $request->user()->party?->update(['display_name' => $data['full_name']]);
        }
        $this->audit->record('agent.profile.updated', 'partner', $partner->id, ['fields' => array_keys($data)]);
        if ($payoutChanged) {
            app(\App\Application\Security\Alerts\SecurityAlerts::class)->send($request->user(), 'PAYOUT_DESTINATION_CHANGED', app(TenantContext::class)->id());
        }

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

        $client = $this->intake->show($customer, $request->user(), $t);
        $party = \App\Models\Party::find($client->party_id ?? null);

        return response()->json(['data' => $this->clientOf($client, $t) + ['allowed_actions' => $party ? app(\App\Application\Mobile\Capabilities\CapabilityResolver::class)->forParty($party, $request->user()) : []]]);
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
        $policies = Policy::with('party')->where('tenant_id', $t)->whereIn('party_id', $clientIds)->whereIn('status', ['ACTIVE', 'EXPIRING'])->where('coverage_ends_at', '<=', now()->addDays(60))->orderBy('coverage_ends_at')->get();

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
        // Security review 2026-09-27 item 4: only the registered payout number, and not while it is cooling off.
        app(\App\Application\Agents\PayoutDestinationGuard::class)->assertWithdrawable($partner->compliance ?? [], $data['destination_phone'], $request->user()->phone_e164);
        $id = (string) Str::uuid();
        DB::table('partner_payout_requests')->insert([
            'id' => $id, 'tenant_id' => $t, 'partner_id' => $partner->id, 'partner_statement_id' => $statement->id, 'payout_number' => 'PAY-'.strtoupper(Str::random(10)), 'amount_minor' => $data['amount_minor'], 'currency' => 'XAF', 'status' => 'REQUESTED',
            'destination_type' => 'MOBILE_MONEY', 'destination_encrypted' => Crypt::encryptString($data['provider'].':'.$data['destination_phone']), 'idempotency_key' => $request->header('Idempotency-Key') ?: $id, 'requested_by' => $request->user()->id, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->audit->record('agent.withdrawal.requested', 'partner_payout_request', $id, ['amount_minor' => $data['amount_minor'], 'provider' => $data['provider']]);

        return response()->json(['data' => $this->withdrawalOf(DB::table('partner_payout_requests')->find($id))], 201);
    }

    /**
     * Assisted sale: prices the client's REAL risk facts (captured in the app with the same risk schema as the
     * customer quote wizard) through QuoteService — validated exactly like POST /quotes; no invented defaults.
     */
    public function createSale(Request $request, AssistedSaleService $sales): JsonResponse
    {
        $data = $request->validate([
            'customer_id' => 'required|uuid', 'product' => 'required|string|max:32', 'payment_phone_e164' => ['required', 'regex:/^\+[1-9]\d{7,14}$/'],
            'provider' => 'nullable|in:'.implode(',', AssistedSaleService::PROVIDERS), 'risk_facts' => 'required|array|min:1',
        ]);
        $t = app(TenantContext::class)->id();
        $customer = $this->intake->show($data['customer_id'], $request->user(), $t);
        $quote = $sales->create(Tenant::findOrFail($t), $customer, $data, $request->user());

        return response()->json(['data' => $sales->present($quote, $customer, $request->user())], 201);
    }

    public function sale(string $id, Request $request, AssistedSaleService $sales): JsonResponse
    {
        [$quote, $customer] = $this->ownSale($id, $request);
        $sale = $sales->present($quote, $customer, $request->user());

        return response()->json(['data' => $sale + ['client_acceptance' => $this->clientAcceptance($sale, $request->user(), false)]]);
    }

    /**
     * The sale's next step, server-driven (AssistedSaleService::advance): open the client's application from the
     * chosen offer, remind the client to accept the terms, or — once the application is payable and the client
     * accepted the terms — send the real mobile-money request (operator prompt on the client's phone). Idempotent.
     */
    public function requestPayment(string $id, Request $request, AssistedSaleService $sales): JsonResponse
    {
        $data = $request->validate([
            'offer_id' => 'nullable|uuid', 'provider' => 'nullable|in:'.implode(',', AssistedSaleService::PROVIDERS),
            'payment_phone_e164' => ['nullable', 'regex:/^\+[1-9]\d{7,14}$/'],
        ]);
        [$quote, $customer] = $this->ownSale($id, $request);
        $before = $sales->present($quote, $customer, $request->user()) + ['notified_at' => ($quote->comparison_context ?? [])['client_notified_at'] ?? null];
        $sales->advance($quote, $customer, $data, $request->user());
        $sale = $sales->present($quote->refresh(), $customer, $request->user());
        $acceptance = $this->clientAcceptance($sale, $request->user(), true);
        $reminded = $acceptance['sent_now'] || (($quote->comparison_context ?? [])['client_notified_at'] ?? null) !== $before['notified_at'];

        return response()->json(['data' => $sale + ['client_acceptance' => $acceptance, 'step' => self::step($before, $sale, $reminded)]]);
    }

    /**
     * The client's own contract acceptance on the sale (ProposalAcceptanceLinks): ACCEPTED | LINK_SENT | SMS_FAILED |
     * NO_PHONE | NOT_SENT | NOT_NEEDED (no application yet). With $send, while the sale waits on the client, the client
     * gets the web acceptance link by SMS (at most every 10 minutes) — for clients without the app. The link itself is
     * never returned to the agent.
     */
    private function clientAcceptance(array $sale, \App\Models\User $agent, bool $send): array
    {
        $p = $sale['proposal_id'] ? \App\Models\Proposal::find($sale['proposal_id']) : null;
        if ($p === null) {
            return ['status' => 'NOT_NEEDED', 'sent_now' => false, 'sent_at' => null, 'expires_at' => null, 'phone_masked' => null];
        }
        $links = app(\App\Application\Underwriting\Proposal\ProposalAcceptanceLinks::class);

        return $send && $sale['next_action'] === 'AWAIT_CLIENT' && $links->needed($p)
            ? $links->send($p, $sale['payment_phone_e164'] ?: null, $agent)
            : $links->status($p);
    }

    /** What this tap did, for the agent's message: APPLICATION_SENT | REMINDER_SENT | REMINDER_RECENT | PAYMENT_PROMPTED | PAYMENT_PENDING | PAYMENT_FAILED | UNDER_REVIEW | PAID | NONE. */
    private static function step(array $before, array $after, bool $reminded): string
    {
        return match (true) {
            in_array($after['status'], ['ISSUED', 'PAID'], true) => 'PAID',
            $before['proposal_id'] === null && $after['proposal_id'] !== null => 'APPLICATION_SENT',
            $after['next_action'] === 'AWAIT_PAYMENT' => $before['next_action'] === 'AWAIT_PAYMENT' ? 'PAYMENT_PENDING' : 'PAYMENT_PROMPTED',
            in_array($after['payment_status'], ['FAILED', 'EXPIRED', 'CANCELLED'], true) => 'PAYMENT_FAILED',
            $after['next_action'] === 'AWAIT_CLIENT' => $reminded ? 'REMINDER_SENT' : 'REMINDER_RECENT',
            $after['next_action'] === 'AWAIT_UNDERWRITING' => 'UNDER_REVIEW',
            default => 'NONE',
        };
    }

    /** @return array{0: \App\Models\Quote, 1: TenantCustomer} the agent's own assisted sale (404 otherwise) */
    private function ownSale(string $id, Request $request): array
    {
        $t = app(TenantContext::class)->id();
        $quote = \App\Models\Quote::where('tenant_id', $t)->where('comparison_context->agent_user_id', $request->user()->id)->findOrFail($id);
        $customerId = TenantCustomer::where(['tenant_id' => $t, 'party_id' => $quote->party_id])->valueOrFail('id');

        // Still in the agent's book (attribution ACTIVE) — a client who left the book is no longer sold to.
        return [$quote, $this->intake->show($customerId, $request->user(), $t)];
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
        return app(\App\Application\PartnerWorkspace\PartnerWorkspaceScope::class)->bookPartyIds(request()->user(), $partner);
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
            'id' => $c->id, 'party_id' => $c->party_id, 'full_name' => $c->party?->display_name ?? 'Client', 'phone_e164' => $c->party?->contacts?->firstWhere('type', 'PHONE')?->normalized_value ?? '',
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

    private function offlineOf(object $r): array
    {
        $status = match (strtoupper((string) $r->status)) { 'APPLIED', 'SYNCED', 'SUCCEEDED' => 'SYNCED', 'FAILED', 'CONFLICT' => 'FAILED', 'SYNCING', 'PROCESSING' => 'SYNCING', default => 'QUEUED' };

        return ['id' => $r->id, 'type' => $r->resource ?? $r->kind ?? 'OPERATION', 'local_reference' => $r->client_reference ?? $r->idempotency_key ?? substr($r->id, 0, 8), 'status' => $status, 'updated_at' => \Carbon\Carbon::parse($r->updated_at)->toIso8601String(), 'error' => $r->error_code ?? null];
    }
}
