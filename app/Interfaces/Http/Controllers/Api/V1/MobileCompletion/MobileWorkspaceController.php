<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\MobileCompletion;

use App\Application\Identity\Rbac\PlatformAuthority;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The permission-aware operational workspace (app/workspace/[role]) for
 * platform staff roles that have no dedicated portal. Modules appear only
 * when the caller holds the permission the read model needs.
 */
final class MobileWorkspaceController
{
    private const MODULES = [
        ['key' => 'policies', 'title' => 'Policies', 'permission' => 'policies.read', 'icon' => 'FileText'],
        ['key' => 'claims', 'title' => 'Claims', 'permission' => 'claims.view', 'icon' => 'ShieldAlert'],
        ['key' => 'payments', 'title' => 'Payments', 'permission' => 'ledger.read', 'icon' => 'CreditCard'],
        ['key' => 'support', 'title' => 'Support cases', 'permission' => 'support.manage', 'icon' => 'LifeBuoy'],
        ['key' => 'compliance', 'title' => 'Compliance', 'permission' => 'compliance.read', 'icon' => 'Scale'],
        // Launch fix 2026-09-29: issue reports hold free text from any user, so the module needs an operations
        // permission and rows are scoped to the caller's tenant (see issueReports()).
        ['key' => 'issue-reports', 'title' => 'App issue reports', 'permission' => self::ISSUE_REPORTS_PERMISSION, 'icon' => 'Flag'],
    ];

    public const ISSUE_REPORTS_PERMISSION = 'operations.console.view';

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
        $user = $request->user();
        $role = $user->memberships()->where('tenant_id', $t)->where('status', 'ACTIVE')->value('role_code') ?? 'STAFF';
        $count = fn (string $table, array $where = []) => (string) DB::table($table)->where('tenant_id', $t)->where($where)->count();
        $xaf = fn ($minor) => number_format(((int) $minor) / 100, 0, '.', ' ').' FCFA';

        $heading = (string) Str::of($role)->replace('_', ' ')->lower()->ucfirst().' workspace';

        $metrics = [
            ['key' => 'policies', 'label' => 'Policies in force', 'value' => $count('policies', ['status' => 'ACTIVE']), 'tone' => 'success'],
            ['key' => 'claims', 'label' => 'Open claims', 'value' => (string) DB::table('claims')->where('tenant_id', $t)->whereNotIn('status', ['PAID', 'CLOSED', 'DECLINED'])->count(), 'tone' => 'warning'],
            ['key' => 'premium', 'label' => 'Premium this month', 'value' => $xaf(DB::table('policies')->where('tenant_id', $t)->where('issued_at', '>=', now()->startOfMonth())->sum('premium_minor')), 'tone' => 'info'],
            ['key' => 'payments', 'label' => 'Payments pending', 'value' => (string) DB::table('payment_intents')->where('tenant_id', $t)->whereIn('status', ['CREATED', 'PENDING_CUSTOMER', 'PROCESSING'])->count(), 'tone' => 'neutral'],
            ['key' => 'support', 'label' => 'Open support cases', 'value' => $count('support_tickets', ['status' => 'OPEN']), 'tone' => 'neutral'],
        ];
        if ($user->hasPermission(self::ISSUE_REPORTS_PERMISSION)) {
            $metrics[] = ['key' => 'issues', 'label' => 'App issues reported', 'value' => (string) $this->issueReports($t, $user)->where('status', 'OPEN')->count(), 'tone' => 'danger'];
        }

        // The app reads `label`; `title` is kept for older clients.
        return response()->json(['data' => [
            'label' => $heading,
            'title' => $heading,
            'subtitle' => 'Live platform operations · '.now()->format('d M Y'),
            'metrics' => $metrics,
            'modules' => collect(self::MODULES)->filter(fn ($m) => ! $m['permission'] || $user->hasPermission($m['permission']))->map(fn ($m) => ['key' => $m['key'], 'label' => $m['title'], 'title' => $m['title'], 'description' => 'Server read model', 'icon' => $m['icon'], 'permission' => $m['permission']])->values(),
        ]]);
    }

    public function module(string $key, Request $request): JsonResponse
    {
        $t = app(TenantContext::class)->id();
        $module = collect(self::MODULES)->firstWhere('key', $key);
        abort_unless($module, 404);
        abort_if($module['permission'] && ! $request->user()->hasPermission($module['permission']), 403, 'Permission denied.');
        $d = fn ($v) => $v ? \Carbon\Carbon::parse($v)->format('d M Y') : '—';
        $xaf = fn ($minor) => number_format(((int) $minor) / 100, 0, '.', ' ');
        [$columns, $rows] = match ($key) {
            'policies' => [['Policy', 'Customer', 'Status', 'Premium (FCFA)', 'Expires'], DB::table('policies')->leftJoin('parties', 'parties.id', '=', 'policies.party_id')->where('policies.tenant_id', $t)->orderByDesc('issued_at')->limit(50)->get(['policy_number', 'display_name', 'policies.status', 'premium_minor', 'coverage_ends_at'])->map(fn ($r) => ['Policy' => $r->policy_number, 'Customer' => $r->display_name, 'Status' => $r->status, 'Premium (FCFA)' => $xaf($r->premium_minor), 'Expires' => $d($r->coverage_ends_at)])],
            'claims' => [['Claim', 'Claimant', 'Status', 'Reserve (FCFA)', 'Submitted'], DB::table('claims')->leftJoin('parties', 'parties.id', '=', 'claims.claimant_party_id')->where('claims.tenant_id', $t)->orderByDesc('submitted_at')->limit(50)->get(['claim_number', 'display_name', 'claims.status', 'estimated_loss_minor', 'submitted_at'])->map(fn ($r) => ['Claim' => $r->claim_number, 'Claimant' => $r->display_name, 'Status' => $r->status, 'Reserve (FCFA)' => $xaf($r->estimated_loss_minor), 'Submitted' => $d($r->submitted_at)])],
            'payments' => [['Reference', 'Provider', 'Amount (FCFA)', 'Status', 'Created'], DB::table('payment_intents')->where('tenant_id', $t)->orderByDesc('created_at')->limit(50)->get()->map(fn ($r) => ['Reference' => $r->provider_reference, 'Provider' => $r->provider, 'Amount (FCFA)' => $xaf($r->amount_minor), 'Status' => $r->status, 'Created' => $d($r->created_at)])],
            'support' => [['Ticket', 'Category', 'Priority', 'Status', 'SLA due'], DB::table('support_tickets')->where('tenant_id', $t)->orderByDesc('created_at')->limit(50)->get()->map(fn ($r) => ['Ticket' => $r->ticket_number, 'Category' => $r->category, 'Priority' => $r->priority, 'Status' => $r->status, 'SLA due' => $d($r->sla_due_at)])],
            'compliance' => [['Case', 'Type', 'Severity', 'Status', 'Review due'], DB::table('compliance_cases')->where('tenant_id', $t)->orderBy('review_due_on')->limit(50)->get()->map(fn ($r) => ['Case' => $r->case_number, 'Type' => $r->type, 'Severity' => $r->severity, 'Status' => $r->status, 'Review due' => $d($r->review_due_on)])],
            'issue-reports' => [['Screen', 'Note', 'Status', 'Reported'], $this->issueReports($t, $request->user())->orderByDesc('created_at')->limit(50)->get()->map(fn ($r) => ['Screen' => $r->route, 'Note' => Str::limit($r->note, 80), 'Status' => $r->status, 'Reported' => $d($r->created_at)])],
        };

        return response()->json(['data' => ['label' => $module['title'], 'title' => $module['title'], 'columns' => $columns, 'rows' => $rows->values(), 'next_cursor' => null]]);
    }
}
