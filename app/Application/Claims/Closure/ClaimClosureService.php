<?php

declare(strict_types=1);

namespace App\Application\Claims\Closure;

use App\Application\Audit\AuditWriter;
use App\Application\Authority\AuthorityService;
use App\Application\Claims\ClaimLifecycleService;
use App\Application\Events\OutboxWriter;
use App\Domain\Tenancy\TenantContext;
use App\Models\Claim;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REQ-CLM-014 — WF-060 closure and WF-061 reopening.
 *  - close(): closure reason + checklist (ClaimClosureChecklist), append-only claim_closures row, then the
 *    existing ClaimLifecycleService::transition(→ CLOSED).
 *  - requestReopen()/approveReopen()/rejectReopen(): maker-checker with reason + authority; on approval
 *    the claim moves CLOSED → REOPENED and the reserve is restored as a NEW claim_reserve_changes movement.
 *  - transferRecovery(): hands an open recovery to another owner so it no longer blocks closure.
 */
final class ClaimClosureService
{
    public const REOPEN_REASONS = ['NEW_EVIDENCE', 'LATE_INVOICE', 'RECOVERY_RECEIVED', 'DISPUTE_UPHELD', 'ERROR_CORRECTION', 'REGULATORY_REQUEST', 'COURT_ORDER'];

    private const CLAIM_ROLES = ['SYSTEM_ADMIN', 'PLATFORM_ADMIN', 'CLAIMS_MANAGER'];

    private const ADMIN_ROLES = ['SYSTEM_ADMIN', 'PLATFORM_ADMIN'];

    public function __construct(
        private ClaimClosureChecklist $checklist,
        private ClaimLifecycleService $lifecycle,
        private AuthorityService $authority,
        private AuditWriter $audit,
        private OutboxWriter $outbox,
        private TenantContext $tenant,
    ) {}

    /** @return array{closable:bool,items:list<array>} */
    public function checklist(Claim $c, ?string $reason = null): array
    {
        $this->owned($c);
        $items = $this->checklist->evaluate($c, $reason);

        return ['closable' => collect($items)->every(fn ($i) => $i['passed']), 'items' => $items];
    }

    public function close(Claim $c, string $reason, ?string $summary, User $actor, bool $automatic = false): Claim
    {
        if (! in_array($reason, ClaimClosureChecklist::REASONS, true) || ($reason === 'AUTO_INACTIVE_SETTLED') !== $automatic) {
            throw ValidationException::withMessages(['reason_code' => __('validation.in', ['attribute' => 'reason_code'])]);
        }

        return DB::transaction(function () use ($c, $reason, $summary, $actor, $automatic) {
            $c = Claim::whereKey($c->id)->lockForUpdate()->firstOrFail();
            $this->owned($c);
            $items = $this->checklist->evaluate($c, $reason);
            $failed = array_values(array_map(fn ($i) => $i['code'], array_filter($items, fn ($i) => ! $i['passed'])));
            if ($failed !== []) {
                throw ValidationException::withMessages(['checklist' => array_map(fn ($code) => 'CLOSURE_'.$code, $failed)]);
            }
            $from = $c->status;
            $closureId = (string) Str::uuid();
            // Lifecycle first: it validates the transition (and runs C1's ClaimTransitionGuards when present).
            $c = $this->lifecycle->transition($c, 'CLOSED', $reason, ['closure_summary' => $summary, 'closure_reason' => $reason, 'closure_id' => $closureId, 'automatic' => $automatic], $actor);
            DB::table('claim_closures')->insert([
                'id' => $closureId, 'tenant_id' => $c->tenant_id, 'claim_id' => $c->id, 'reason_code' => $reason, 'summary' => $summary,
                'checklist' => json_encode($items, JSON_THROW_ON_ERROR), 'automatic' => $automatic, 'from_status' => $from,
                'closed_by' => $actor->id, 'closed_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $payload = ['closure_id' => $closureId, 'reason_code' => $reason, 'automatic' => $automatic, 'from' => $from];
            $this->audit->record('claim.closed', 'claim', $c->id, $payload, $reason);
            $this->outbox->record('claim.closed', 'claim', $c->id, $payload);

            return $c;
        });
    }

    public function requestReopen(Claim $c, string $reason, string $justification, int $restoreReserveMinor, User $actor): object
    {
        if (! in_array($reason, self::REOPEN_REASONS, true)) {
            throw ValidationException::withMessages(['reason_code' => __('validation.in', ['attribute' => 'reason_code'])]);
        }
        if ($restoreReserveMinor < 0) {
            throw ValidationException::withMessages(['restore_reserve_minor' => __('wave7.amount_invalid')]);
        }

        return DB::transaction(function () use ($c, $reason, $justification, $restoreReserveMinor, $actor) {
            $c = Claim::whereKey($c->id)->lockForUpdate()->firstOrFail();
            $this->owned($c);
            if ($c->status !== 'CLOSED') {
                throw ValidationException::withMessages(['status' => 'CLAIM_NOT_CLOSED']);
            }
            if (DB::table('claim_reopen_requests')->where(['claim_id' => $c->id, 'status' => 'PENDING_APPROVAL'])->exists()) {
                throw ValidationException::withMessages(['status' => 'REOPEN_ALREADY_PENDING']);
            }
            $id = (string) Str::uuid();
            DB::table('claim_reopen_requests')->insert([
                'id' => $id, 'tenant_id' => $c->tenant_id, 'claim_id' => $c->id,
                'claim_closure_id' => DB::table('claim_closures')->where('claim_id', $c->id)->orderByDesc('closed_at')->value('id'),
                'reason_code' => $reason, 'justification' => $justification, 'restore_reserve_minor' => $restoreReserveMinor,
                'status' => 'PENDING_APPROVAL', 'requested_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->record('claim.reopen.requested', 'claim_reopen_request', $id, ['claim_id' => $c->id, 'restore_reserve_minor' => $restoreReserveMinor], $reason);
            $this->outbox->record('claim.reopen.requested', 'claim', $c->id, ['reopen_request_id' => $id, 'reason_code' => $reason]);

            return DB::table('claim_reopen_requests')->find($id);
        });
    }

    public function approveReopen(string $requestId, User $actor, ?string $note = null): Claim
    {
        return DB::transaction(function () use ($requestId, $actor, $note) {
            $r = $this->pending($requestId, $actor);
            $c = Claim::whereKey($r->claim_id)->lockForUpdate()->firstOrFail();
            $snapshot = $this->authorize($c, (int) $r->restore_reserve_minor, $actor);
            $c = $this->lifecycle->transition($c, 'REOPENED', $r->reason_code, ['reopen_request_id' => $r->id, 'justification' => $r->justification], $actor);
            $reserveChangeId = null;
            if ((int) $r->restore_reserve_minor > 0) {
                // A new, already-approved reserve movement (maker = requester, checker = approver). Prior movements are untouched.
                $reserveChangeId = (string) Str::uuid();
                DB::table('claim_reserve_changes')->insert([
                    'id' => $reserveChangeId, 'claim_id' => $c->id, 'previous_amount_minor' => (int) $c->current_reserve_minor,
                    'requested_amount_minor' => (int) $c->current_reserve_minor + (int) $r->restore_reserve_minor, 'currency' => $c->currency ?? 'XAF',
                    'status' => 'APPROVED', 'reason_code' => 'REOPEN_RESTORE', 'notes' => 'Reserve restored at reopening '.$r->id,
                    'requested_by' => $r->requested_by, 'approved_by' => $actor->id, 'approved_at' => now(), 'created_at' => now(), 'updated_at' => now(),
                ]);
                $c->update(['current_reserve_minor' => (int) $c->current_reserve_minor + (int) $r->restore_reserve_minor, 'version' => $c->version + 1]);
                $this->audit->record('claim.reserve.approved', 'claim_reserve_change', $reserveChangeId, ['amount_minor' => (int) $c->current_reserve_minor, 'source' => 'REOPEN']);
                $this->outbox->record('claim.reserve.approved', 'claim', $c->id, ['reserve_change_id' => $reserveChangeId]);
            }
            DB::table('claim_reopen_requests')->where('id', $r->id)->update([
                'status' => 'APPROVED', 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => $note,
                'authority_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), 'reserve_change_id' => $reserveChangeId, 'updated_at' => now(),
            ]);
            $payload = ['reopen_request_id' => $r->id, 'reason_code' => $r->reason_code, 'reserve_change_id' => $reserveChangeId];
            $this->audit->record('claim.reopened', 'claim', $c->id, $payload, $r->reason_code);
            $this->outbox->record('claim.reopened', 'claim', $c->id, $payload);

            return $c->refresh();
        });
    }

    public function rejectReopen(string $requestId, User $actor, string $note): object
    {
        return DB::transaction(function () use ($requestId, $actor, $note) {
            $r = $this->pending($requestId, $actor);
            DB::table('claim_reopen_requests')->where('id', $r->id)->update(['status' => 'REJECTED', 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => $note, 'updated_at' => now()]);
            $this->audit->record('claim.reopen.rejected', 'claim_reopen_request', $r->id, ['claim_id' => $r->claim_id], $note);
            $this->outbox->record('claim.reopen.rejected', 'claim', $r->claim_id, ['reopen_request_id' => $r->id]);

            return DB::table('claim_reopen_requests')->find($r->id);
        });
    }

    /** Resolve an open recovery by transferring it (e.g. to a carrier/recovery agency) so it no longer blocks closure. */
    public function transferRecovery(string $recoveryId, string $transferee, User $actor): object
    {
        return DB::transaction(function () use ($recoveryId, $transferee, $actor) {
            $r = DB::table('claim_recoveries')->where('id', $recoveryId)->lockForUpdate()->first();
            $c = $r ? Claim::find($r->claim_id) : null;
            if (! $c) {
                abort(404);
            }
            $this->owned($c);
            if ($r->status !== 'OPEN') {
                throw ValidationException::withMessages(['status' => 'RECOVERY_NOT_OPEN']);
            }
            DB::table('claim_recoveries')->where('id', $r->id)->update(['status' => 'TRANSFERRED', 'closed_at' => now(), 'notes' => trim(($r->notes ?? '')."\nTransferred to: ".$transferee), 'updated_at' => now()]);
            $this->audit->record('claim.recovery.transferred', 'claim_recovery', $r->id, ['claim_id' => $c->id, 'transferee' => $transferee, 'actor_id' => $actor->id]);
            $this->outbox->record('claim.recovery.transferred', 'claim', $c->id, ['recovery_id' => $r->id]);

            return DB::table('claim_recoveries')->find($r->id);
        });
    }

    private function pending(string $requestId, User $actor): object
    {
        $r = DB::table('claim_reopen_requests')->where('id', $requestId)->lockForUpdate()->first();
        if (! $r || $r->tenant_id !== $this->tenant->id()) {
            abort(404);
        }
        if ($r->status !== 'PENDING_APPROVAL') {
            throw ValidationException::withMessages(['status' => 'REOPEN_NOT_PENDING']);
        }
        if ($r->requested_by === $actor->id) {
            throw ValidationException::withMessages(['status' => 'MAKER_CHECKER_VIOLATION']);
        }

        return $r;
    }

    /** Checker authority: a claims-manager/admin role, and for a reserve restore a RESERVE_APPROVE limit covering the amount. */
    private function authorize(Claim $c, int $restore, User $actor): array
    {
        $roles = $actor->memberships()->where('tenant_id', $c->tenant_id)->where('status', 'ACTIVE')->pluck('role_code')->all();
        if (! array_intersect($roles, self::CLAIM_ROLES)) {
            throw ValidationException::withMessages(['authority' => 'REOPEN_AUTHORITY_MISSING']);
        }
        $snapshot = ['roles' => array_values($roles), 'restore_reserve_minor' => $restore, 'checked_at' => now()->toIso8601String()];
        if ($restore === 0) {
            return $snapshot + ['mode' => 'ROLE'];
        }
        $carrierId = $c->policy?->carrier_id;
        $day = now()->toDateString();
        $limit = $this->authority->effectiveLimit('USER', $actor->id, $carrierId, 'RESERVE_APPROVE', null, $day);
        foreach ($roles as $role) {
            $limit ??= $this->authority->effectiveLimit('ROLE', $role, $carrierId, 'RESERVE_APPROVE', null, $day);
        }
        if ($limit) {
            if ($limit->max_amount_minor !== null && $restore > (int) $limit->max_amount_minor) {
                throw ValidationException::withMessages(['authority' => 'REOPEN_AUTHORITY_EXCEEDED']);
            }

            return $snapshot + ['mode' => 'LIMIT', 'authority_limit_id' => $limit->id, 'max_amount_minor' => $limit->max_amount_minor];
        }
        if (! array_intersect($roles, self::ADMIN_ROLES)) {
            throw ValidationException::withMessages(['authority' => 'REOPEN_AUTHORITY_EXCEEDED']);
        }

        return $snapshot + ['mode' => 'ADMIN_ROLE'];
    }

    private function owned(Claim $c): void
    {
        if ($c->tenant_id !== $this->tenant->id()) {
            abort(404);
        }
    }
}
