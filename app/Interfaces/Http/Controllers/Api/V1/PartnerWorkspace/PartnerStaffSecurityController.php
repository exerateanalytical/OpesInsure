<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\PartnerWorkspace;

use App\Application\Identity\CarrierScopeResolver;
use App\Application\Identity\Rbac\PermissionEvaluator;
use App\Application\Identity\RoleCatalogue;
use App\Application\Partners\BookScope;
use App\Application\Security\Login\ClientContext;
use App\Application\Security\StaffAccessService;
use App\Domain\Tenancy\TenantContext;
use App\Models\MobileRefreshToken;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\UserDevice;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Laravel\Passport\Passport;

/**
 * Mobile audit B4: an organisation admin's view of a colleague's account security, and the two containment actions.
 *
 *  GET  partner/staff/{user}/security        staff.security.read   {status, last_sign_in_at, active_sessions, security_state}
 *                                                                   + devices / recent_activity only with security.centre.read
 *  POST partner/staff/{user}/suspend-access  staff.security.manage membership SUSPENDED in this tenant + every session revoked
 *  POST partner/staff/{user}/force-reauth    staff.security.manage every session revoked (sign in again everywhere)
 *
 * The colleague must be a member of the current tenant inside the caller's data scope: BookScope::users (company /
 * team / branch colleagues) and, for an insurer-linked caller, the same carrier (CarrierScopeResolver). Platform and
 * tenant-administration memberships are never reachable. Anything else is 404, never a hint that the user exists.
 */
final class PartnerStaffSecurityController
{
    /** Staff roles an organisation admin may act on (never platform / tenant-administration roles). */
    private const MANAGEABLE = ['BROKER_ADMIN', 'BROKER_SUPERVISOR', 'BROKER_STAFF', 'AGENT', 'BRANCH_MANAGER', 'CASHIER', 'CARRIER_SUPER_ADMIN', 'CARRIER_ADMIN', 'CARRIER_STAFF',
        'UNDERWRITER', 'SENIOR_UNDERWRITER', 'REINSURANCE_OFFICER', 'CUSTOMER_SERVICE', 'ADJUSTER', 'FINANCE_OFFICER', 'CLAIMS_OFFICER', ...RoleCatalogue::PROVIDER_ROLES];

    public function show(string $user, Request $request, PermissionEvaluator $permissions): JsonResponse
    {
        $membership = $this->membership($request->user(), $user);
        $target = $membership->user;
        $lastSignIn = DB::table('login_activities')->where('user_id', $target->id)->where('event_type', 'LOGIN')->where('outcome', 'SUCCESS')->max('occurred_at');
        $recentFailures = DB::table('login_activities')->where('user_id', $target->id)->where('outcome', 'FAILED')->where('occurred_at', '>=', now()->subDay())->count();
        $flagged = DB::table('login_activities')->where('user_id', $target->id)->where('occurred_at', '>=', now()->subDays(7))->whereRaw('jsonb_array_length(anomaly_flags) > 0')->exists();
        $integrityFail = UserDevice::where('user_id', $target->id)->whereNull('revoked_at')->where('attestation_status', 'FAIL')->exists();

        $data = [
            'user_id' => $target->id, 'full_name' => $target->full_name, 'role_code' => $membership->role_code,
            'status' => $membership->status,
            'last_sign_in_at' => $lastSignIn ? Carbon::parse($lastSignIn)->toIso8601String() : null,
            'active_sessions' => $this->activeSessions($target),
            'security_state' => match (true) {
                $membership->status === 'SUSPENDED' => 'SUSPENDED',
                $integrityFail || $flagged || $recentFailures >= 3 => 'AT_RISK',
                default => 'NORMAL',
            },
            'recent_failed_sign_ins' => $recentFailures,
        ];
        // Device and network detail is security-centre data: only with security.centre.read.
        if ($permissions->allows($request->user(), 'security.centre.read')) {
            $data['devices'] = UserDevice::where('user_id', $target->id)->whereNull('revoked_at')->orderByDesc('last_seen_at')->get()->map(fn (UserDevice $d) => [
                'id' => $d->id, 'name' => $d->name, 'platform' => $d->platform, 'model' => $d->model, 'app_version' => $d->app_version,
                'last_seen_at' => $d->last_seen_at?->toIso8601String(), 'attestation_status' => $d->attestation_status ?? 'UNVERIFIED',
                'approx_location' => ClientContext::formatLocation($d->approx_country, $d->approx_city),
            ])->values();
            $data['recent_activity'] = DB::table('login_activities')->where('user_id', $target->id)->orderByDesc('occurred_at')->limit(20)
                ->get(['event_type', 'outcome', 'method', 'platform', 'country_code', 'masked_ip', 'occurred_at'])->values();
        }

        return response()->json(['data' => $data]);
    }

    public function suspendAccess(string $user, Request $request, StaffAccessService $access): JsonResponse
    {
        $data = $request->validate(['reason' => 'required|string|min:5|max:500']);
        $membership = $this->membership($request->user(), $user, forAction: true);
        $access->suspend($membership, $request->user(), $data['reason'], $request);

        return response()->json(['data' => ['user_id' => $membership->user_id, 'status' => 'SUSPENDED', 'sessions_revoked' => true]]);
    }

    public function forceReauth(string $user, Request $request, StaffAccessService $access): JsonResponse
    {
        $data = $request->validate(['reason' => 'nullable|string|max:500']);
        $membership = $this->membership($request->user(), $user, forAction: true);
        $access->forceReauth($membership, $request->user(), $data['reason'] ?? null, $request);

        return response()->json(['data' => ['user_id' => $membership->user_id, 'status' => $membership->status, 'sessions_revoked' => true]]);
    }

    private function membership(User $caller, string $userId, bool $forAction = false): TenantMembership
    {
        $tenant = app(TenantContext::class)->id();
        abort_if($forAction && $userId === $caller->id, 422, 'You cannot apply this action to your own account.');

        $q = TenantMembership::with('user')->where('tenant_id', $tenant)->where('user_id', $userId)->whereIn('status', ['ACTIVE', 'SUSPENDED'])->whereIn('role_code', self::MANAGEABLE);
        $colleagues = app(BookScope::class)->users($caller);
        if ($colleagues !== null) {
            $q->whereIn('user_id', $colleagues);
        }
        $carrier = app(CarrierScopeResolver::class)->carrierIdFor($caller, $tenant);
        if ($carrier !== null) {
            $q->where('carrier_id', $carrier);
        }
        $m = $q->orderByRaw("CASE status WHEN 'ACTIVE' THEN 0 ELSE 1 END")->first();
        abort_unless($m && $m->user, 404);

        return $m;
    }

    /** Open sessions: live mobile refresh-token families, or 1 when only a live access token remains. */
    private function activeSessions(User $u): int
    {
        $refresh = MobileRefreshToken::where('user_id', $u->id)->whereNull('revoked_at')->where('expires_at', '>', now())->distinct()->count('family_id');
        if ($refresh > 0) {
            return $refresh;
        }

        return Passport::token()->newQuery()->where('user_id', $u->id)->where('revoked', false)->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->exists() ? 1 : 0;
    }
}
