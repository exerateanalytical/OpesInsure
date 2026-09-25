<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\MobileCompletion;

use App\Application\Audit\AuditWriter;
use App\Application\Policies\Endorsements\ServiceRequestIntake;
use App\Application\Policies\MobileWalletService;
use App\Application\Quotes\QuoteService;
use App\Domain\Tenancy\TenantContext;
use App\Models\Policy;
use App\Models\Quote;
use App\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * services/* screens and the policy detail's "request a change" / "renew"
 * actions, on top of policy_transactions (+ events) — the same table the
 * back-office endorsement workflow (policies/{policy}/transactions) uses,
 * so a request raised in the app is the one staff approve in Filament.
 */
final class MobilePolicyServiceController
{
    public function __construct(private MobileWalletService $wallet, private AuditWriter $audit) {}

    public function index(Request $request): JsonResponse
    {
        $policyIds = $this->ownedPolicyIds($request);
        $q = DB::table('policy_transactions')->whereIn('policy_id', $policyIds)->orderByDesc('created_at');
        if ($request->query('policy_id')) {
            $q->where('policy_id', $request->query('policy_id'));
        }

        return response()->json(['data' => $q->limit(50)->get()->map(fn ($t) => $this->present($t, false))->values()]);
    }

    public function show(string $id, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->present($this->owned($id, $request), true)]);
    }

    public function store(Request $request, ?string $policy = null): JsonResponse
    {
        $data = $request->validate([
            'policy_id' => $policy ? 'nullable' : 'required|uuid',
            'type' => 'required|string|in:'.implode(',', ServiceRequestIntake::TYPES),
            'reason' => 'required|string|min:5|max:4000',
        ]);
        $policyModel = $this->wallet->policy($policy ?? $data['policy_id'], $request->user(), app(TenantContext::class)->id());
        // REQ-DUP-014: one intake for both the canonical and the deprecated mobile alias route.
        $id = app(ServiceRequestIntake::class)->submit($policyModel, $data['type'], $data['reason'], $request->user(), $request->header('Idempotency-Key'));

        return response()->json(['data' => $this->present(DB::table('policy_transactions')->find($id), true)], 201);
    }

    public function message(string $id, Request $request): JsonResponse
    {
        $data = $request->validate(['message' => 'required|string|min:1|max:4000']);
        $t = $this->owned($id, $request);
        DB::table('policy_transaction_events')->insert(['id' => (string) Str::uuid(), 'policy_transaction_id' => $t->id, 'from_status' => $t->status, 'to_status' => $t->status, 'reason_code' => 'CUSTOMER_MESSAGE', 'actor_id' => $request->user()->id, 'metadata' => json_encode(['message' => $data['message']]), 'occurred_at' => now()]);
        DB::table('policy_transactions')->where('id', $t->id)->update(['updated_at' => now()]);

        return response()->json(['data' => $this->present(DB::table('policy_transactions')->find($t->id), true)], 201);
    }

    /**
     * POST policies/{policy}/renewal-quote: re-rates the expiring policy's
     * original risk facts against today's approved tariffs and returns the
     * same {quote, offers} shape the Compare flow uses, so the app can drop
     * straight into offer selection with the renewal linked to the policy.
     */
    public function renewalQuote(string $policy, Request $request, QuoteService $quotes): JsonResponse
    {
        $tenantId = app(TenantContext::class)->id();
        $policyModel = $this->wallet->policy($policy, $request->user(), $tenantId);
        $terms = $policyModel->terms_snapshot ?? [];
        $original = $policyModel->proposal?->offer?->quote;
        $lineCode = $original?->line_code ?? ($terms['line_code'] ?? null);
        $facts = $original?->risk_facts ?? ($terms['risk_facts'] ?? []);
        abort_unless($lineCode && $facts, 422, 'This policy has no rateable risk facts on record.');

        $quote = $quotes->submit(Tenant::findOrFail($tenantId), $policyModel->party_id, ['line_code' => $lineCode, 'risk_asset_id' => $original?->risk_asset_id, 'channel' => 'B2C', 'risk_facts' => $facts], $request->user());
        $quote->update(['comparison_context' => ['renewal_of_policy_id' => $policyModel->id]]);
        $quote = $quotes->rate($quote, $request->user());
        DB::table('renewal_cases')->updateOrInsert(['tenant_id' => $tenantId, 'policy_id' => $policyModel->id], [
            'id' => DB::table('renewal_cases')->where(['tenant_id' => $tenantId, 'policy_id' => $policyModel->id])->value('id') ?? (string) Str::uuid(),
            'renewal_quote_id' => $quote->id, 'status' => 'QUOTED', 'due_on' => $policyModel->coverage_ends_at?->toDateString() ?? now()->toDateString(),
            'attribution_snapshot' => json_encode(['origin_type' => 'SYSTEM']), 'contact_attempts' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);

        return response()->json(['data' => ['quote' => $quote, 'offers' => $quote->offers()->with(['carrier.party', 'product'])->orderBy('comparison_rank')->get()]], 201);
    }

    private function ownedPolicyIds(Request $request): array
    {
        return $this->wallet->wallet($request->user(), app(TenantContext::class)->id(), 200)->getCollection()->pluck('id')->all();
    }

    private function owned(string $id, Request $request): object
    {
        $t = DB::table('policy_transactions')->whereIn('policy_id', $this->ownedPolicyIds($request))->where('id', $id)->first();
        abort_unless($t, 404);

        return $t;
    }

    private function present(object $t, bool $withTimeline): array
    {
        $changes = json_decode($t->requested_changes ?? '{}', true) ?: [];
        $timeline = $withTimeline ? DB::table('policy_transaction_events')->where('policy_transaction_id', $t->id)->orderBy('occurred_at')->get()->map(fn ($e) => [
            'id' => $e->id,
            'label' => match ($e->reason_code) { 'CUSTOMER_REQUEST' => 'Request submitted', 'CUSTOMER_MESSAGE' => 'You added a message', default => ucfirst(strtolower(str_replace('_', ' ', (string) ($e->to_status ?? $e->reason_code)))) },
            'occurred_at' => \Carbon\Carbon::parse($e->occurred_at)->toIso8601String(),
            'description' => json_decode($e->metadata ?? '{}', true)['message'] ?? null,
        ])->values() : [];

        return [
            'id' => $t->id, 'policy_id' => $t->policy_id, 'type' => $t->type, 'reason' => $changes['reason'] ?? $t->notes ?? '', 'status' => $t->status,
            'created_at' => \Carbon\Carbon::parse($t->created_at)->toIso8601String(), 'updated_at' => \Carbon\Carbon::parse($t->updated_at)->toIso8601String(),
            'timeline' => $timeline, 'requested_documents' => $changes['requested_documents'] ?? [],
        ];
    }
}
