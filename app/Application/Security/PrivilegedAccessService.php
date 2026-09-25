<?php

namespace App\Application\Security;

use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Models\PrivilegedAccessGrant;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REQ-SEC-001 privileged access: request (maker) → approve by a SECOND person
 * (checker, neither requester nor grantee) → time-boxed use → expiry/revocation.
 * The window is capped at security_centre.privileged_access.max_window_hours.
 * The Batch 15 one-step grant (caller = approver) is retired: unless
 * security_centre.privileged_access.legacy_single_step_grant is switched on,
 * grant() opens a REQUESTED grant that still needs a second approver.
 */
final class PrivilegedAccessService
{
    public function __construct(private AuditWriter $a, private OutboxWriter $outbox) {}

    public function request(string $tenant, User $target, array $d, User $requester): PrivilegedAccessGrant
    {
        $this->assertWindow($d, true);
        $scope = $d['scope'];
        sort($scope);
        $g = PrivilegedAccessGrant::create([...$d, 'tenant_id' => $tenant, 'user_id' => $target->id, 'requested_by' => $requester->id, 'approved_by' => $requester->id, 'status' => 'REQUESTED', 'scope' => $scope, 'scope_hash' => hash('sha256', json_encode($scope))]);
        $this->event($g, 'REQUESTED', $requester, $g->purpose);
        $this->a->record('privileged_access.requested', 'privileged_access_grant', $g->id, ['user_id' => $target->id, 'tenant_id' => $tenant]);

        return $g;
    }

    /** REQ-DUP-009 legacy compliance/privileged-access body (no scope). Retired to a request unless explicitly re-enabled. */
    public function grant(string $tenant, User $target, array $d, User $approver): PrivilegedAccessGrant
    {
        if ($target->id === $approver->id) {
            abort(403, 'Users cannot approve their own privileged access.');
        }
        if (! config('security_centre.privileged_access.legacy_single_step_grant', false)) {
            return $this->request($tenant, $target, ['purpose' => $d['purpose'], 'justification' => $d['justification'], 'starts_at' => $d['starts_at'], 'expires_at' => $d['expires_at'], 'scope' => $d['scope'] ?? []], $approver);
        }
        $this->assertWindow($d, false);
        $scope = $d['scope'] ?? [];
        sort($scope);
        $g = PrivilegedAccessGrant::create(['purpose' => $d['purpose'], 'justification' => $d['justification'], 'starts_at' => $d['starts_at'], 'expires_at' => $d['expires_at'], 'tenant_id' => $tenant, 'user_id' => $target->id, 'requested_by' => $approver->id, 'approved_by' => $approver->id, 'status' => 'APPROVED', 'scope' => $scope, 'scope_hash' => hash('sha256', json_encode($scope))]);
        $this->event($g, 'APPROVED', $approver, $g->purpose);
        $this->a->record('privileged_access.granted', 'privileged_access_grant', $g->id, ['user_id' => $target->id, 'tenant_id' => $tenant, 'legacy_single_step' => true]);

        return $g;
    }

    public function approve(PrivilegedAccessGrant $g, User $u): PrivilegedAccessGrant
    {
        if ($g->status !== 'REQUESTED' || $g->requested_by === $u->id || $g->user_id === $u->id) {
            throw ValidationException::withMessages(['status' => __('wave9.maker_checker')]);
        }
        if ($g->expires_at <= now()) {
            throw ValidationException::withMessages(['expires_at' => __('wave9.access_window_invalid')]);
        }
        $g->update(['status' => 'APPROVED', 'approved_by' => $u->id]);
        $this->event($g, 'APPROVED', $u, $g->purpose);
        $this->a->record('privileged_access.approved', 'privileged_access_grant', $g->id, ['user_id' => $g->user_id, 'requested_by' => $g->requested_by]);

        return $g->refresh();
    }

    public function use(PrivilegedAccessGrant $g, string $type, string $id, string $purpose, User $u): void
    {
        if ($g->user_id !== $u->id || $g->status !== 'APPROVED' || ! now()->between($g->starts_at, $g->expires_at) || $g->revoked_at) {
            throw ValidationException::withMessages(['access' => __('wave9.access_denied')]);
        }
        $this->event($g, 'USED', $u, $purpose, $type, $id);
        $this->a->record('privileged_access.used', $type, $id, ['grant_id' => $g->id, 'purpose' => $purpose]);
    }

    public function revoke(PrivilegedAccessGrant $g, string $reason, User $u): PrivilegedAccessGrant
    {
        $g->update(['status' => 'REVOKED', 'revoked_at' => now(), 'revoked_by' => $u->id, 'revocation_reason' => $reason]);
        $this->event($g, 'REVOKED', $u, $reason);

        return $g->refresh();
    }

    /** Marks REQUESTED/APPROVED grants whose window has closed as EXPIRED. Returns the count. */
    public function expireDue(): int
    {
        $n = 0;
        PrivilegedAccessGrant::query()->whereIn('status', ['REQUESTED', 'APPROVED'])->where('expires_at', '<=', now())->each(function (PrivilegedAccessGrant $g) use (&$n) {
            $g->update(['status' => 'EXPIRED']);
            $this->event($g, 'EXPIRED', null, $g->purpose);
            $this->outbox->record('security.privileged_access.expired', 'privileged_access_grant', $g->id, ['grant_id' => $g->id, 'user_id' => $g->user_id, 'tenant_id' => $g->tenant_id]);
            $n++;
        });

        return $n;
    }

    private function assertWindow(array $d, bool $mustBeFuture): void
    {
        $starts = Carbon::parse($d['starts_at']);
        $expires = Carbon::parse($d['expires_at']);
        $max = (int) config('security_centre.privileged_access.max_window_hours', 72);
        if (($mustBeFuture && $expires <= now()) || $starts >= $expires || $starts->diffInHours($expires, true) > $max) {
            throw ValidationException::withMessages(['expires_at' => __('wave9.access_window_invalid')]);
        }
    }

    private function event(PrivilegedAccessGrant $g, string $e, ?User $u, string $p, ?string $t = null, ?string $i = null): void
    {
        DB::table('privileged_access_events')->insert(['id' => (string) Str::uuid(), 'privileged_access_grant_id' => $g->id, 'event_type' => $e, 'actor_id' => $u?->id, 'purpose' => $p, 'resource_type' => $t, 'resource_id' => $i, 'metadata' => '{}', 'occurred_at' => now()]);
    }
}
