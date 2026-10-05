<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\MobileCompletion;

use App\Application\Identity\Rbac\PlatformAuthority;
use App\Application\Mobile\ListCursor;
use App\Application\Mobile\Workspace\WorkspaceDataScope;
use App\Application\Notifications\NotificationCatalog;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The permission-aware operational workspace (app/workspace/[role]) for
 * platform staff roles that have no dedicated portal. Modules appear only
 * when the caller holds the permission the read model needs.
 *
 * Phase-1 fix S (2026-09-30): every metric and module row is narrowed to the caller's data scope
 * (WorkspaceDataScope: carrier / branch / assigned ...), metrics follow the module permissions, module lists page
 * with ?cursor= (meta.next_cursor), and every label / column header / status is in the app language
 * (Accept-Language, else the user's locale). Rows stay keyed by their column header (older apps read them so) and
 * also carry the record `id`.
 */
final class MobileWorkspaceController
{
    private const MODULES = [
        ['key' => 'policies', 'permission' => 'policies.read', 'icon' => 'FileText'],
        ['key' => 'claims', 'permission' => 'claims.view', 'icon' => 'ShieldAlert'],
        ['key' => 'customers', 'permission' => 'customers.read', 'icon' => 'Users'],
        ['key' => 'payments', 'permission' => 'ledger.read', 'icon' => 'CreditCard'],
        ['key' => 'cashier', 'permission' => 'cashier.sessions.view', 'icon' => 'Wallet'],
        ['key' => 'collections', 'permission' => 'cashier.sessions.view', 'icon' => 'Banknote'],
        ['key' => 'treaties', 'permission' => 'reinsurance.treaties.view', 'icon' => 'Layers'],
        ['key' => 'cessions', 'permission' => 'reinsurance.cessions.view', 'icon' => 'Split'],
        ['key' => 'support', 'permission' => 'support.manage', 'icon' => 'LifeBuoy'],
        ['key' => 'compliance', 'permission' => 'compliance.read', 'icon' => 'Scale'],
        // Launch fix 2026-09-29: issue reports hold free text from any user, so the module needs an operations
        // permission and rows are scoped to the caller's tenant (see issueReports()).
        ['key' => 'issue-reports', 'permission' => self::ISSUE_REPORTS_PERMISSION, 'icon' => 'Flag'],
    ];

    public const ISSUE_REPORTS_PERMISSION = 'operations.console.view';

    private const OPEN_CLAIM_EXCLUDED = ['PAID', 'CLOSED', 'DECLINED'];

    public function __construct(private readonly WorkspaceDataScope $scope) {}

    /**
     * mobile_issue_reports has no tenant_id: a report belongs to the tenant(s) its reporter is a member of. Only a
     * platform administrator acting in the platform tenant sees every report (including anonymous, pre-sign-in ones).
     */
    private function issueReports(string $tenantId, $user): \Illuminate\Database\Query\Builder
    {
        $query = DB::table('mobile_issue_reports');
        $authority = app(PlatformAuthority::class);
        if ($authority->isPlatformTenant($tenantId) && $authority->isPlatformAdmin($user)) {
            return $query;
        }

        return $query->whereIn('user_id', DB::table('tenant_memberships')->where('tenant_id', $tenantId)->select('user_id'));
    }

    public function dashboard(Request $request): JsonResponse
    {
        $t = app(TenantContext::class)->id();
        /** @var User $user */
        $user = $request->user();
        $locale = NotificationCatalog::requestLocale($request);
        $tr = fn (string $key, array $replace = []) => __('mobile_workspace.'.$key, $replace, $locale);
        $role = $user->memberships()->where('tenant_id', $t)->where('status', 'ACTIVE')->value('role_code') ?? 'STAFF';
        $can = fn (string $permission) => $user->hasPermission($permission);
        $xaf = fn ($minor) => number_format(((int) $minor) / 100, 0, '.', ' ').' FCFA';
        $scope = $this->scope->resolve($user);

        $roleLabel = __('admin_screens.roles.'.$role, [], $locale);
        if ($roleLabel === 'admin_screens.roles.'.$role) {
            $roleLabel = (string) Str::of($role)->replace('_', ' ')->lower()->ucfirst();
        }
        $heading = $tr('heading', ['role' => $locale === 'fr' ? Str::lcfirst($roleLabel) : $roleLabel]);

        $metrics = [];
        if ($can('policies.read')) {
            $metrics[] = ['key' => 'policies', 'label' => $tr('metrics.policies'), 'value' => (string) $this->scope->policies(DB::table('policies')->where('tenant_id', $t)->where('status', 'ACTIVE'), $user)->count(), 'tone' => 'success'];
        }
        if ($can('claims.view')) {
            $metrics[] = ['key' => 'claims', 'label' => $tr($scope['mode'] === 'assigned' ? 'metrics.claims_assigned' : 'metrics.claims'), 'value' => (string) $this->scope->claims(DB::table('claims')->where('tenant_id', $t)->whereNotIn('status', self::OPEN_CLAIM_EXCLUDED), $user)->count(), 'tone' => 'warning'];
        }
        if ($can('policies.read')) {
            $metrics[] = ['key' => 'premium', 'label' => $tr('metrics.premium'), 'value' => $xaf($this->scope->policies(DB::table('policies')->where('tenant_id', $t)->where('issued_at', '>=', now()->startOfMonth()), $user)->sum('premium_minor')), 'tone' => 'info'];
        }
        if ($can('customers.read')) {
            $metrics[] = ['key' => 'customers', 'label' => $tr('metrics.customers'), 'value' => (string) $this->scope->customers(DB::table('tenant_customers')->where('tenant_id', $t), $user)->count(), 'tone' => 'neutral'];
        }
        if ($can('ledger.read')) {
            $metrics[] = ['key' => 'payments', 'label' => $tr('metrics.payments'), 'value' => (string) $this->scope->paymentIntents(DB::table('payment_intents')->where('tenant_id', $t)->whereIn('status', ['CREATED', 'PENDING_CUSTOMER', 'PROCESSING']), $user)->count(), 'tone' => 'neutral'];
        }
        if ($can('cashier.sessions.view')) {
            $sessions = fn () => $this->scope->cashierSessions(DB::table('cashier_sessions')->where('tenant_id', $t), $user);
            $metrics[] = ['key' => 'cashier_open', 'label' => $tr('metrics.cashier_open'), 'value' => (string) $sessions()->where('status', 'OPEN')->count(), 'tone' => 'info'];
            $metrics[] = ['key' => 'collections_today', 'label' => $tr('metrics.collections_today'), 'value' => $xaf(DB::table('cashier_collections')->where('tenant_id', $t)->whereIn('cashier_session_id', $sessions()->select('id'))->where('collected_at', '>=', now()->startOfDay())->sum('session_amount_minor')), 'tone' => 'success'];
        }
        if ($can('reinsurance.treaties.view')) {
            $metrics[] = ['key' => 'treaties', 'label' => $tr('metrics.treaties'), 'value' => (string) $this->scope->treaties(DB::table('reinsurance_treaties')->where('tenant_id', $t)->where('status', 'ACTIVE'), $user)->count(), 'tone' => 'info'];
        }
        if ($can('support.manage')) {
            $metrics[] = ['key' => 'support', 'label' => $tr('metrics.support'), 'value' => (string) $this->scope->supportTickets(DB::table('support_tickets')->where('tenant_id', $t)->where('status', 'OPEN'), $user)->count(), 'tone' => 'neutral'];
        }
        if ($can(self::ISSUE_REPORTS_PERMISSION)) {
            $metrics[] = ['key' => 'issues', 'label' => $tr('metrics.issues'), 'value' => (string) $this->issueReports($t, $user)->where('status', 'OPEN')->count(), 'tone' => 'danger'];
        }

        $subtitle = $scope['mode'] === 'tenant'
            ? $tr('subtitle', ['date' => now()->locale($locale)->isoFormat('D MMM YYYY')])
            : $tr('subtitle_scoped', ['scope' => $tr('scope.'.$scope['mode']), 'date' => now()->locale($locale)->isoFormat('D MMM YYYY')]);

        // The app reads `label`; `title` is kept for older clients.
        return response()->json(['data' => [
            'label' => $heading,
            'title' => $heading,
            'subtitle' => $subtitle,
            'scope' => $scope['mode'],
            'metrics' => $metrics,
            'modules' => collect(self::MODULES)->filter(fn ($m) => ! $m['permission'] || $can($m['permission']))->map(fn ($m) => [
                'key' => $m['key'], 'label' => $tr('modules.'.$m['key'].'.title'), 'title' => $tr('modules.'.$m['key'].'.title'),
                'description' => $tr('modules.'.$m['key'].'.description'), 'icon' => $m['icon'], 'permission' => $m['permission'],
            ])->values(),
        ]]);
    }

    public function module(string $key, Request $request): JsonResponse
    {
        $t = app(TenantContext::class)->id();
        /** @var User $user */
        $user = $request->user();
        $module = collect(self::MODULES)->firstWhere('key', $key);
        abort_unless($module, 404);
        abort_if($module['permission'] && ! $user->hasPermission($module['permission']), 403, 'Permission denied.');
        $locale = NotificationCatalog::requestLocale($request);
        $col = fn (string $k) => __('mobile_workspace.columns.'.$k, [], $locale);
        $status = function ($code) use ($locale) {
            $label = __('mobile_workspace.status.'.$code, [], $locale);

            return $label === 'mobile_workspace.status.'.$code ? (string) Str::of((string) $code)->replace('_', ' ')->lower()->ucfirst() : $label;
        };
        $d = fn ($v) => $v ? \Carbon\Carbon::parse($v)->locale($locale)->isoFormat('D MMM YYYY') : '—';
        $xaf = fn ($minor) => number_format(((int) $minor) / 100, 0, '.', ' ');
        $page = ListCursor::from($request, 50, 100);
        $s = $this->scope;

        // [column keys, query, row mapper (column key => value)]
        [$keys, $query, $map] = match ($key) {
            'policies' => [['policy', 'customer', 'status', 'premium', 'expires'],
                $s->policies(DB::table('policies')->leftJoin('parties', 'parties.id', '=', 'policies.party_id')->where('policies.tenant_id', $t), $user)
                    ->orderByDesc('policies.issued_at')->orderBy('policies.id')->select('policies.id', 'policy_number', 'display_name', 'policies.status', 'premium_minor', 'coverage_ends_at'),
                fn ($r) => ['policy' => $r->policy_number, 'customer' => $r->display_name, 'status' => $status($r->status), 'premium' => $xaf($r->premium_minor), 'expires' => $d($r->coverage_ends_at)]],
            'claims' => [['claim', 'claimant', 'status', 'reserve', 'submitted'],
                $s->claims(DB::table('claims')->leftJoin('parties', 'parties.id', '=', 'claims.claimant_party_id')->where('claims.tenant_id', $t), $user)
                    ->when($request->query('status'), fn ($q, $st) => $q->where('claims.status', (string) $st))
                    ->orderByDesc('submitted_at')->orderBy('claims.id')->select('claims.id', 'claim_number', 'display_name', 'claims.status', 'estimated_loss_minor', 'submitted_at'),
                fn ($r) => ['claim' => $r->claim_number, 'claimant' => $r->display_name, 'status' => $status($r->status), 'reserve' => $xaf($r->estimated_loss_minor), 'submitted' => $d($r->submitted_at)]],
            'customers' => [['customer', 'customer_number', 'status', 'since'],
                $s->customers(DB::table('tenant_customers')->leftJoin('parties', 'parties.id', '=', 'tenant_customers.party_id')->where('tenant_customers.tenant_id', $t), $user)
                    ->orderByDesc('tenant_customers.created_at')->orderBy('tenant_customers.id')->select('tenant_customers.id', 'display_name', 'customer_number', 'tenant_customers.status', 'tenant_customers.created_at'),
                fn ($r) => ['customer' => $r->display_name, 'customer_number' => $r->customer_number, 'status' => $status($r->status), 'since' => $d($r->created_at)]],
            'payments' => [['reference', 'provider', 'amount', 'status', 'created'],
                $s->paymentIntents(DB::table('payment_intents')->where('tenant_id', $t), $user)->orderByDesc('created_at')->orderBy('id'),
                fn ($r) => ['reference' => $r->provider_reference, 'provider' => $r->provider, 'amount' => $xaf($r->amount_minor), 'status' => $status($r->status), 'created' => $d($r->created_at)]],
            'cashier' => [['opened', 'cashier', 'status', 'float', 'expected', 'closed'],
                $s->cashierSessions(DB::table('cashier_sessions')->leftJoin('users', 'users.id', '=', 'cashier_sessions.cashier_user_id')->where('cashier_sessions.tenant_id', $t), $user)
                    ->orderByDesc('cashier_sessions.opened_at')->orderBy('cashier_sessions.id')->select('cashier_sessions.id', 'cashier_sessions.opened_at', 'users.full_name', 'cashier_sessions.status', 'opening_float_minor', 'expected_cash_minor', 'closed_at'),
                fn ($r) => ['opened' => $d($r->opened_at), 'cashier' => $r->full_name, 'status' => $status($r->status), 'float' => $xaf($r->opening_float_minor), 'expected' => $r->expected_cash_minor === null ? '—' : $xaf($r->expected_cash_minor), 'closed' => $d($r->closed_at)]],
            'collections' => [['receipt', 'method', 'payer', 'amount', 'collected'],
                DB::table('cashier_collections')->leftJoin('parties', 'parties.id', '=', 'cashier_collections.payer_party_id')->where('cashier_collections.tenant_id', $t)
                    ->whereIn('cashier_collections.cashier_session_id', $s->cashierSessions(DB::table('cashier_sessions')->where('tenant_id', $t), $user)->select('id'))
                    ->orderByDesc('cashier_collections.collected_at')->orderBy('cashier_collections.id')->select('cashier_collections.id', 'receipt_number', 'method', 'payer_name', 'display_name', 'session_amount_minor', 'collected_at'),
                fn ($r) => ['receipt' => $r->receipt_number, 'method' => $r->method, 'payer' => $r->display_name ?? $r->payer_name, 'amount' => $xaf($r->session_amount_minor), 'collected' => $d($r->collected_at)]],
            'treaties' => [['treaty', 'name', 'type', 'year', 'status'],
                $s->treaties(DB::table('reinsurance_treaties')->where('tenant_id', $t), $user)->orderByDesc('underwriting_year')->orderBy('code')->orderBy('id'),
                fn ($r) => ['treaty' => $r->treaty_number ?? $r->code, 'name' => $r->name, 'type' => $r->treaty_type, 'year' => (string) $r->underwriting_year, 'status' => $status($r->status)]],
            'cessions' => [['policy', 'treaty', 'ceded_percent', 'ceded_premium', 'status'],
                $s->cessions(DB::table('reinsurance_cessions')->leftJoin('policies', 'policies.id', '=', 'reinsurance_cessions.policy_id')->leftJoin('reinsurance_treaties', 'reinsurance_treaties.id', '=', 'reinsurance_cessions.treaty_id')
                    ->where('reinsurance_cessions.tenant_id', $t), $user)->orderByDesc('reinsurance_cessions.created_at')->orderBy('reinsurance_cessions.id')
                    ->select('reinsurance_cessions.id', 'policies.policy_number', 'reinsurance_treaties.code', 'reinsurance_cessions.ceded_percent', 'reinsurance_cessions.ceded_premium_minor', 'reinsurance_cessions.status'),
                fn ($r) => ['policy' => $r->policy_number, 'treaty' => $r->code, 'ceded_percent' => $r->ceded_percent === null ? '—' : (string) round((float) $r->ceded_percent, 2), 'ceded_premium' => $xaf($r->ceded_premium_minor), 'status' => $status($r->status)]],
            'support' => [['ticket', 'category', 'priority', 'status', 'sla_due'],
                $s->supportTickets(DB::table('support_tickets')->where('tenant_id', $t), $user)->orderByDesc('created_at')->orderBy('id'),
                fn ($r) => ['ticket' => $r->ticket_number, 'category' => $r->category, 'priority' => $r->priority, 'status' => $status($r->status), 'sla_due' => $d($r->sla_due_at)]],
            'compliance' => [['case', 'type', 'severity', 'status', 'review_due'],
                $s->complianceCases(DB::table('compliance_cases')->where('tenant_id', $t), $user)->orderBy('review_due_on')->orderBy('id'),
                fn ($r) => ['case' => $r->case_number, 'type' => $r->type, 'severity' => $r->severity, 'status' => $status($r->status), 'review_due' => $d($r->review_due_on)]],
            'issue-reports' => [['screen', 'note', 'status', 'reported'],
                $this->issueReports($t, $user)->orderByDesc('created_at')->orderBy('id'),
                fn ($r) => ['screen' => $r->route, 'note' => Str::limit((string) $r->note, 80), 'status' => $status($r->status), 'reported' => $d($r->created_at)]],
        };

        $labels = array_map($col, $keys);
        $rows = $page->slice($page->apply($query)->get())->map(function ($r) use ($map, $keys, $labels) {
            $values = $map($r);
            $row = ['id' => $r->id ?? null];
            foreach ($keys as $i => $k) {
                $row[$labels[$i]] = $values[$k] ?? null;
            }

            return $row;
        });
        $title = __('mobile_workspace.modules.'.$key.'.title', [], $locale);

        return response()->json([
            'data' => ['label' => $title, 'title' => $title, 'columns' => $labels, 'column_keys' => $keys, 'rows' => $rows->values(), 'next_cursor' => $page->nextCursor()],
            'meta' => $page->meta(),
        ]);
    }
}
