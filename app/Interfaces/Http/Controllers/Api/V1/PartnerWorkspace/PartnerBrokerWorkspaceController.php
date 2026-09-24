<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\PartnerWorkspace;

use App\Application\Identity\InvitationService;
use App\Application\PartnerWorkspace\PartnerWorkspaceScope;
use App\Domain\Tenancy\TenantContext;
use App\Models\Claim;
use App\Models\Policy;
use App\Models\Quote;
use App\Models\Tenant;
use App\Models\TenantInvitation;
use App\Models\TenantMembership;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Broker workspace: quotes, policies, claims for the broker's attributed
 * book; staff memberships + invitations; commission accruals + statements.
 */
final class PartnerBrokerWorkspaceController
{
    private const BROKER_ROLES = ['BROKER_ADMIN', 'BROKER_STAFF'];

    public function __construct(private PartnerWorkspaceScope $scope) {}

    private function tenant(): string
    {
        return app(TenantContext::class)->id();
    }

    /** @return list<string> */
    private function book(Request $request): array
    {
        return $this->scope->bookPartyIds($this->scope->broker($request->user()));
    }

    public function quotes(Request $request): JsonResponse
    {
        $t = $this->tenant();
        $rows = Quote::with(['party', 'offers'])->where('tenant_id', $t)->whereIn('party_id', $this->book($request))->orderByDesc('created_at')->limit(100)->get();

        return response()->json(['data' => $rows->map(fn (Quote $q) => PartnerWorkspaceShapes::quote($q, $t))->values()]);
    }

    public function policies(Request $request): JsonResponse
    {
        $rows = Policy::with(['party', 'carrier.party'])->where('tenant_id', $this->tenant())->whereIn('party_id', $this->book($request))->orderByDesc('issued_at')->limit(100)->get();

        return response()->json(['data' => $rows->map(fn (Policy $p) => PartnerWorkspaceShapes::policy($p))->values()]);
    }

    public function claims(Request $request): JsonResponse
    {
        $book = $this->book($request);
        $rows = Claim::with(['policy.party', 'claimant'])->where('tenant_id', $this->tenant())->whereHas('policy', fn ($p) => $p->whereIn('party_id', $book))
            ->orderByDesc('submitted_at')->limit(100)->get();

        return response()->json(['data' => $rows->map(fn (Claim $c) => PartnerWorkspaceShapes::claim($c))->values()]);
    }

    public function commissions(Request $request): JsonResponse
    {
        $t = $this->tenant();
        $partner = $this->scope->broker($request->user());
        $accruals = $partner ? DB::table('commission_accruals')->join('policies', 'policies.id', '=', 'commission_accruals.policy_id')->leftJoin('parties', 'parties.id', '=', 'policies.party_id')
            ->where('commission_accruals.tenant_id', $t)->where('commission_accruals.partner_id', $partner->id)
            ->select('commission_accruals.*', 'policies.policy_number', 'parties.display_name')->orderByDesc('commission_accruals.created_at')->limit(100)->get() : collect();
        // Drafts are back-office work in progress, not something to show the partner.
        $statements = $partner ? DB::table('partner_statements')->where('tenant_id', $t)->where('partner_id', $partner->id)->where('status', '!=', 'DRAFT')->orderByDesc('period_end')->limit(24)->get() : collect();
        $sum = fn (string $status) => (int) $accruals->where('status', $status)->sum(fn ($a) => $a->amount_minor - $a->paid_minor - $a->clawed_back_minor);

        return response()->json(['data' => [
            'totals' => ['pending_minor' => $sum('PENDING'), 'available_minor' => $sum('AVAILABLE'), 'paid_minor' => (int) $accruals->sum('paid_minor'), 'currency' => 'XAF'],
            'accruals' => $accruals->map(fn ($a) => [
                'id' => $a->id, 'policy_id' => $a->policy_id, 'policy_number' => $a->policy_number, 'customer_name' => $a->display_name, 'status' => $a->status,
                'amount_minor' => (int) $a->amount_minor, 'paid_minor' => (int) $a->paid_minor, 'currency' => $a->currency,
                'available_at' => $a->available_at ? \Carbon\Carbon::parse($a->available_at)->toIso8601String() : null,
            ])->values(),
            'statements' => $statements->map(fn ($s) => [
                'id' => $s->id, 'statement_number' => $s->statement_number, 'status' => $s->status, 'currency' => $s->currency,
                'period_start' => $s->period_start, 'period_end' => $s->period_end, 'earned_minor' => (int) $s->earned_minor,
                'paid_minor' => (int) $s->paid_minor, 'closing_balance_minor' => (int) $s->closing_balance_minor,
            ])->values(),
        ]]);
    }

    public function staff(Request $request): JsonResponse
    {
        $t = $this->tenant();
        $user = $request->user();
        $firmTenant = Tenant::whereKey($t)->value('type') === 'BROKER';

        if ($firmTenant) {
            // The tenant IS the broker firm: every broker-role member is staff.
            $members = TenantMembership::with('user')->where('tenant_id', $t)->whereIn('role_code', self::BROKER_ROLES)->whereIn('status', ['ACTIVE', 'SUSPENDED'])->get();
            $pending = TenantInvitation::where('tenant_id', $t)->whereIn('role_code', self::BROKER_ROLES)->where('status', 'PENDING')->get();
        } else {
            // Shared tenant: only the caller and the people the caller invited.
            $invited = TenantInvitation::where('tenant_id', $t)->whereIn('role_code', self::BROKER_ROLES)->where('invited_by', $user->id)->get();
            $userIds = $this->acceptedUserIds($invited->where('status', 'ACCEPTED'))->push($user->id);
            $members = TenantMembership::with('user')->where('tenant_id', $t)->whereIn('role_code', self::BROKER_ROLES)->whereIn('user_id', $userIds)->whereIn('status', ['ACTIVE', 'SUSPENDED'])->get();
            $pending = $invited->where('status', 'PENDING');
        }

        return response()->json(['data' => [
            'can_invite' => $this->isAdmin($user, $t),
            'members' => $members->sortBy('user.full_name')->map(fn (TenantMembership $m) => [
                'membership_id' => $m->id, 'user_id' => $m->user_id, 'full_name' => $m->user?->full_name ?? 'Staff member', 'phone_e164' => $m->user?->phone_e164,
                'role_code' => $m->role_code, 'status' => $m->status, 'is_me' => $m->user_id === $user->id, 'since' => $m->created_at?->toIso8601String(),
            ])->values(),
            'pending_invitations' => $pending->filter(fn ($i) => $i->expires_at->isFuture())->map(fn (TenantInvitation $i) => [
                'id' => $i->id, 'recipient' => $i->recipient_phone_e164 ?? $i->recipient_email, 'role_code' => $i->role_code, 'expires_at' => $i->expires_at->toIso8601String(),
            ])->values(),
        ]]);
    }

    public function inviteStaff(Request $request, InvitationService $invitations): JsonResponse
    {
        $t = $this->tenant();
        abort_unless($this->isAdmin($request->user(), $t), 403, 'Only a broker administrator can invite staff.');
        $data = $request->validate(['recipient_phone_e164' => 'nullable|required_without:recipient_email|string|max:20', 'recipient_email' => 'nullable|required_without:recipient_phone_e164|email']);
        $result = $invitations->issue(Tenant::findOrFail($t), $request->user(), $data['recipient_email'] ?? null, $data['recipient_phone_e164'] ?? null, 'BROKER_STAFF');
        $i = $result['invitation'];

        return response()->json(['data' => [
            'id' => $i->id, 'recipient' => $i->recipient_phone_e164 ?? $i->recipient_email, 'role_code' => $i->role_code, 'status' => 'PENDING',
            'expires_at' => $i->expires_at->toIso8601String(), 'invite_code' => $result['token'],
        ]], 201);
    }

    private function isAdmin(User $user, string $tenantId): bool
    {
        return TenantMembership::where(['tenant_id' => $tenantId, 'user_id' => $user->id, 'role_code' => 'BROKER_ADMIN', 'status' => 'ACTIVE'])->exists();
    }

    /** Users whose verified phone/email matches an accepted invitation. */
    private function acceptedUserIds(Collection $accepted): Collection
    {
        $phones = $accepted->pluck('recipient_phone_e164')->filter()->values();
        $emails = $accepted->pluck('recipient_email')->filter()->values();
        if ($phones->isEmpty() && $emails->isEmpty()) {
            return collect();
        }

        return User::query()->where(fn ($q) => $q->whereIn('phone_e164', $phones)->orWhereIn(DB::raw('lower(email)'), $emails))->pluck('id');
    }
}
