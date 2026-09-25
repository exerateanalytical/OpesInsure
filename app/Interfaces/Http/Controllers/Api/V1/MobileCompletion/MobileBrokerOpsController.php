<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\MobileCompletion;

use App\Application\Audit\AuditWriter;
use App\Application\FinancialDistribution\MobilePartnerFinanceService;
use App\Application\Identity\PartyResolver;
use App\Domain\Tenancy\TenantContext;
use App\Models\Policy;
use App\Models\TenantCustomer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** The broker portal (app/broker/*) in the shapes the app renders. */
final class MobileBrokerOpsController
{
    public function __construct(private MobilePartnerFinanceService $finance, private PartyResolver $parties, private AuditWriter $audit) {}

    public function dashboard(Request $request): JsonResponse
    {
        $t = app(TenantContext::class)->id();
        $base = $this->finance->dashboard($request->user(), $t);
        $partner = $this->parties->partnerForUser($request->user());
        $clientIds = $partner ? DB::table('customer_attributions')->where('partner_id', $partner->id)->where('status', 'ACTIVE')->pluck('party_id')->all() : [];
        $policies = Policy::where('tenant_id', $t)->whereIn('party_id', $clientIds);
        $xaf = fn ($minor) => number_format(((int) $minor) / 100, 0, '.', ' ').' FCFA';
        $outstanding = (int) collect($base['commission'])->sum('outstanding_minor');
        $due = DB::table('renewal_work_items')->where('tenant_id', $t)->whereIn('policy_id', (clone $policies)->pluck('id'))->where('status', 'DUE')->count();
        $open = DB::table('compliance_cases')->where('tenant_id', $t)->where('status', 'OPEN')->count();

        return response()->json(['data' => $base + ['metrics' => [
            ['label' => 'Clients', 'value' => (string) count($clientIds), 'tone' => 'info'],
            ['label' => 'Policies in force', 'value' => (string) (clone $policies)->where('status', 'ACTIVE')->count(), 'tone' => 'success'],
            ['label' => 'Premium written (12m)', 'value' => $xaf((clone $policies)->where('issued_at', '>=', now()->subYear())->sum('premium_minor')), 'tone' => 'neutral'],
            ['label' => 'Commission outstanding', 'value' => $xaf($outstanding), 'tone' => $outstanding > 0 ? 'warning' : 'neutral'],
            ['label' => 'Renewals due', 'value' => (string) $due, 'tone' => $due > 0 ? 'warning' : 'neutral'],
            ['label' => 'Open compliance items', 'value' => (string) $open, 'tone' => $open > 0 ? 'danger' : 'success'],
        ]]]);
    }

    public function clients(Request $request): JsonResponse
    {
        $t = app(TenantContext::class)->id();

        return response()->json(['data' => $this->clientQuery($request, $t)->get()->map(fn (TenantCustomer $c) => $this->clientOf($c, $t))->values()]);
    }

    public function client(string $customer, Request $request): JsonResponse
    {
        $t = app(TenantContext::class)->id();
        $c = $this->clientQuery($request, $t)->where('tenant_customers.id', $customer)->first();
        abort_unless($c, 404);

        return response()->json(['data' => $this->clientOf($c, $t) + ['policies_detail' => Policy::with('carrier.party')->where('tenant_id', $t)->where('party_id', $c->party_id)->orderByDesc('issued_at')->get()->map(fn ($p) => $this->productionOf($p))->values()]]);
    }

    public function production(Request $request): JsonResponse
    {
        $t = app(TenantContext::class)->id();
        $ids = $this->clientQuery($request, $t)->pluck('tenant_customers.party_id');

        return response()->json(['data' => Policy::with(['carrier.party', 'party'])->where('tenant_id', $t)->whereIn('party_id', $ids)->orderByDesc('issued_at')->limit(100)->get()->map(fn ($p) => $this->productionOf($p))->values()]);
    }

    public function renewals(Request $request): JsonResponse
    {
        $t = app(TenantContext::class)->id();
        $ids = $this->clientQuery($request, $t)->pluck('tenant_customers.party_id');
        $policies = Policy::with('party')->where('tenant_id', $t)->whereIn('party_id', $ids)->whereIn('status', ['ACTIVE', 'EXPIRING'])->where('coverage_ends_at', '<=', now()->addDays(60))->orderBy('coverage_ends_at')->get();

        return response()->json(['data' => $policies->map(fn (Policy $p) => [
            'id' => $p->id, 'customer_id' => TenantCustomer::where(['tenant_id' => $t, 'party_id' => $p->party_id])->value('id') ?? $p->party_id, 'customer_name' => $p->party?->display_name ?? 'Client',
            'policy_number' => $p->policy_number, 'expires_at' => $p->coverage_ends_at?->toIso8601String(), 'days_remaining' => (int) now()->startOfDay()->diffInDays($p->coverage_ends_at, false),
            'status' => DB::table('renewal_work_items')->where('policy_id', $p->id)->value('status') ?? 'DUE',
        ])->values()]);
    }

    public function receivables(Request $request): JsonResponse
    {
        $t = app(TenantContext::class)->id();
        $partner = $this->parties->partnerForUser($request->user());
        $rows = $partner ? DB::table('commission_accruals')->join('policies', 'policies.id', '=', 'commission_accruals.policy_id')->leftJoin('parties', 'parties.id', '=', 'policies.party_id')
            ->where('commission_accruals.tenant_id', $t)->where('commission_accruals.partner_id', $partner->id)->whereIn('commission_accruals.status', ['PENDING', 'AVAILABLE'])
            ->select('commission_accruals.*', 'policies.policy_number', 'parties.display_name')->orderByDesc('commission_accruals.created_at')->limit(100)->get() : collect();

        return response()->json(['data' => $rows->map(fn ($r) => [
            'id' => $r->id, 'policy_id' => $r->policy_id, 'policy_number' => $r->policy_number, 'customer_name' => $r->display_name, 'amount_minor' => (int) ($r->amount_minor - $r->paid_minor - $r->clawed_back_minor), 'currency' => $r->currency,
            'status' => $r->status, 'due_at' => $r->available_at ? \Carbon\Carbon::parse($r->available_at)->toIso8601String() : null, 'label' => 'Commission · '.$r->policy_number,
        ])->values()]);
    }

    public function compliance(Request $request): JsonResponse
    {
        $t = app(TenantContext::class)->id();
        $partner = $this->parties->partnerForUser($request->user());
        // Only the caller's own partner's cases — never the tenant's whole
        // compliance book (other brokers, customers, staff investigations).
        $cases = ($partner
            ? DB::table('compliance_cases')->where('tenant_id', $t)->where('subject_type', 'partner')->where('subject_id', $partner->id)->where('status', '!=', 'CLOSED')->orderBy('review_due_on')->get()
            : collect())->map(fn ($c) => [
            'id' => $c->id, 'label' => ucfirst(strtolower(str_replace('_', ' ', $c->type))).' · '.$c->case_number, 'status' => $c->status, 'due_at' => \Carbon\Carbon::parse($c->review_due_on ?? now())->toIso8601String(), 'severity' => $c->severity,
        ]);
        $licences = $partner ? DB::table('partner_licences')->where('partner_id', $partner->id)->get()->map(fn ($l) => [
            'id' => $l->id, 'label' => ucfirst(strtolower(str_replace('_', ' ', $l->licence_type))).' '.$l->licence_number, 'status' => \Carbon\Carbon::parse($l->expires_on)->isPast() ? 'EXPIRED' : ($l->status === 'VALID' && \Carbon\Carbon::parse($l->expires_on)->lte(now()->addDays(90)) ? 'EXPIRING' : $l->status),
            'due_at' => \Carbon\Carbon::parse($l->expires_on)->toIso8601String(), 'severity' => \Carbon\Carbon::parse($l->expires_on)->lte(now()->addDays(90)) ? 'HIGH' : 'LOW',
        ]) : collect();

        return response()->json(['data' => $licences->concat($cases)->values()]);
    }

    public function publications(Request $request): JsonResponse
    {
        $t = app(TenantContext::class)->id();

        return response()->json(['data' => $this->publicationRows($t)->map(fn ($p) => $this->publicationOf($p))->values()]);
    }

    public function togglePublication(string $id, Request $request): JsonResponse
    {
        $data = $request->validate(['enabled' => 'required|boolean']);
        $t = app(TenantContext::class)->id();
        $row = DB::table('marketplace_publications')->where(['tenant_id' => $t, 'id' => $id])->first();
        abort_unless($row, 404);
        $next = $data['enabled'] ? ($row->approved_at ? 'APPROVED' : 'SUBMITTED') : 'PAUSED';
        DB::table('marketplace_publications')->where('id', $id)->update(['status' => $next, 'ends_at' => $data['enabled'] ? null : now(), 'updated_at' => now(), 'version' => (int) $row->version + 1]);
        $this->audit->record('marketplace.publication.toggled', 'marketplace_publication', $id, ['status' => $next]);

        return response()->json(['data' => $this->publicationOf($this->publicationRows($t)->firstWhere('id', $id))]);
    }

    // ----------------------------------------------------------------- shapes

    private function clientQuery(Request $request, string $t)
    {
        $partner = $this->parties->partnerForUser($request->user());
        $q = TenantCustomer::with('party.contacts')->where('tenant_customers.tenant_id', $t)->orderByDesc('tenant_customers.created_at');

        return $partner ? $q->whereHas('party.attributions', fn ($a) => $a->where('partner_id', $partner->id)->where('status', 'ACTIVE')) : $q->whereRaw('1 = 0');
    }

    private function clientOf(TenantCustomer $c, string $t): array
    {
        $policies = Policy::where('tenant_id', $t)->where('party_id', $c->party_id);
        $outstanding = (int) DB::table('payment_intents')->join('proposals', 'proposals.id', '=', 'payment_intents.proposal_id')->where('proposals.party_id', $c->party_id)->whereIn('payment_intents.status', ['CREATED', 'PENDING_CUSTOMER', 'PROCESSING', 'PENDING'])->sum('payment_intents.amount_minor');

        return [
            'id' => $c->id, 'party_id' => $c->party_id, 'full_name' => $c->party?->display_name ?? 'Client', 'phone_e164' => $c->party?->contacts?->firstWhere('type', 'PHONE')?->normalized_value ?? '',
            'city' => DB::table('party_addresses')->where('party_id', $c->party_id)->value('city'),
            'origin_locked' => DB::table('customer_attributions')->where('party_id', $c->party_id)->where('status', 'ACTIVE')->exists(),
            'policies' => (clone $policies)->where('status', 'ACTIVE')->count(), 'outstanding_minor' => $outstanding,
            'renewal_due_at' => ($min = (clone $policies)->whereIn('status', ['ACTIVE', 'EXPIRING'])->min('coverage_ends_at')) ? \Carbon\Carbon::parse($min)->toIso8601String() : null,
        ];
    }

    private function productionOf(Policy $p): array
    {
        return ['id' => $p->id, 'policy_number' => $p->policy_number, 'customer_name' => $p->party?->display_name ?? 'Client', 'carrier_name' => $p->carrier?->party?->display_name ?? 'Carrier', 'premium_minor' => (int) $p->premium_minor, 'status' => $p->status, 'issued_at' => $p->issued_at?->toIso8601String()];
    }

    private function publicationRows(string $t)
    {
        return DB::table('marketplace_publications')->join('insurance_products', 'insurance_products.id', '=', 'marketplace_publications.product_id')->where('marketplace_publications.tenant_id', $t)
            ->select('marketplace_publications.*', 'insurance_products.name as product_name')->orderByDesc('marketplace_publications.created_at')->get();
    }

    private function publicationOf(object $p): array
    {
        $channels = json_decode($p->channels ?? '[]', true) ?: [];

        return ['id' => $p->id, 'product_name' => $p->product_name, 'status' => $p->status, 'channel' => implode(', ', $channels) ?: 'MARKETPLACE', 'submitted_at' => \Carbon\Carbon::parse($p->starts_at ?? $p->created_at)->toIso8601String()];
    }
}
