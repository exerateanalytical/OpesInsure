<?php

declare(strict_types=1);

namespace App\Application\Partners;

use App\Application\Audit\AuditWriter;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REQ-ORG-001 — broker agent hierarchy (branch → supervisor → agent →
 * sub-agent) on the canonical partners table, and WF-080 agent suspension
 * without broken references.
 *
 * Agent types are the SCF §32 list (employee, independent, sub-agent,
 * branch, corporate representative). Suspension only changes status (via
 * PartnerStatusService): policies, attributions, commissions and licences
 * keep pointing at the suspended partner. Direct reports are re-pointed to
 * the suspended agent's own supervisor (or an explicit target) so nobody is
 * left under an inactive supervisor; every move is in partner_hierarchy_history.
 */
final class AgentHierarchyService
{
    public const AGENT_TYPES = ['EMPLOYEE', 'INDEPENDENT', 'SUB_AGENT', 'BRANCH', 'CORPORATE_REPRESENTATIVE'];

    private const MAX_DEPTH = 10;

    public function __construct(private readonly AuditWriter $audit, private readonly PartnerStatusService $status)
    {
    }

    /** @param array{supervisor_partner_id?: ?string, branch_id?: ?string, agent_type?: ?string} $data */
    public function place(Partner $partner, array $data, string $reason, ?User $actor): Partner
    {
        $supervisor = array_key_exists('supervisor_partner_id', $data) ? $data['supervisor_partner_id'] : $partner->getAttribute('supervisor_partner_id');
        $branch = array_key_exists('branch_id', $data) ? $data['branch_id'] : $partner->getAttribute('branch_id');
        $type = array_key_exists('agent_type', $data) ? $data['agent_type'] : $partner->getAttribute('agent_type');

        if ($type !== null && ! in_array($type, self::AGENT_TYPES, true)) {
            throw ValidationException::withMessages(['agent_type' => 'Unknown agent type.']);
        }
        if ($type === 'SUB_AGENT' && $supervisor === null) {
            throw ValidationException::withMessages(['supervisor_partner_id' => 'A sub-agent must report to a supervising agent.']);
        }
        if ($branch !== null && DB::table('tenant_branches')->where(['id' => $branch, 'tenant_id' => $partner->tenant_id])->doesntExist()) {
            throw ValidationException::withMessages(['branch_id' => 'Branch does not belong to the partner\'s tenant.']);
        }
        if ($supervisor !== null) {
            $this->assertSupervisor($partner, $supervisor);
        }

        return $this->move($partner, $supervisor, $branch, $type, $reason, null, $actor);
    }

    /** @return array{partner: Partner, reassigned: list<string>} */
    public function suspend(Partner $partner, string $notes, User $actor, ?string $reassignTo = null): array
    {
        return DB::transaction(function () use ($partner, $notes, $actor, $reassignTo) {
            $target = $reassignTo ?? $partner->getAttribute('supervisor_partner_id');
            $reports = Partner::query()->where('supervisor_partner_id', $partner->id)->lockForUpdate()->get();
            if ($target !== null) {
                foreach ($reports as $r) {
                    $this->assertSupervisor($r, $target, $partner->id);
                }
            }
            $moved = [];
            foreach ($reports as $r) {
                $this->move($r, $target, $r->getAttribute('branch_id'), $r->getAttribute('agent_type'), 'SUPERVISOR_SUSPENDED', 'Supervisor '.$partner->id.' suspended', $actor);
                $moved[] = $r->id;
            }
            $suspended = $this->status->transition($partner, 'SUSPENDED', $notes, $actor);
            $this->audit->record('partner.agent.suspended', 'partner', $partner->id, ['reassigned' => $moved, 'reassigned_to' => $target]);

            return ['partner' => $suspended, 'reassigned' => $moved];
        });
    }

    /** @return list<array<string, mixed>> the partner's downline (depth-first) */
    public function downline(Partner $partner): array
    {
        $walk = function (string $id, int $depth) use (&$walk): array {
            if ($depth > self::MAX_DEPTH) {
                return [];
            }
            $out = [];
            foreach (DB::table('partners')->where('supervisor_partner_id', $id)->orderBy('id')->get(['id', 'type', 'agent_type', 'status', 'branch_id', 'legal_name']) as $r) {
                $out[] = [...(array) $r, 'reports' => $walk($r->id, $depth + 1)];
            }

            return $out;
        };

        return $walk($partner->id, 1);
    }

    private function assertSupervisor(Partner $partner, string $supervisorId, ?string $ignoreId = null): void
    {
        $sup = DB::table('partners')->where('id', $supervisorId)->first(['id', 'tenant_id', 'status', 'supervisor_partner_id']);
        if ($sup === null || $sup->tenant_id !== $partner->tenant_id) {
            throw ValidationException::withMessages(['supervisor_partner_id' => 'Supervisor must be a partner of the same tenant.']);
        }
        if ($sup->status !== 'ACTIVE') {
            throw ValidationException::withMessages(['supervisor_partner_id' => 'Supervisor must be ACTIVE.']);
        }
        $cursor = $supervisorId;
        for ($depth = 0; $cursor !== null; $depth++) {
            if ($cursor === $partner->id || $depth >= self::MAX_DEPTH) {
                throw ValidationException::withMessages(['supervisor_partner_id' => 'Hierarchy would form a cycle or exceed '.self::MAX_DEPTH.' levels.']);
            }
            $cursor = DB::table('partners')->where('id', $cursor)->value('supervisor_partner_id');
            if ($cursor === $ignoreId) {
                $cursor = null;
            }
        }
    }

    private function move(Partner $partner, ?string $supervisor, ?string $branch, ?string $type, string $reason, ?string $notes, ?User $actor): Partner
    {
        $from = DB::table('partners')->where('id', $partner->id)->first(['supervisor_partner_id', 'branch_id', 'agent_type']);
        DB::table('partners')->where('id', $partner->id)->update(['supervisor_partner_id' => $supervisor, 'branch_id' => $branch, 'agent_type' => $type, 'updated_at' => now()]);
        if ($from->supervisor_partner_id !== $supervisor || $from->branch_id !== $branch) {
            DB::table('partner_hierarchy_history')->insert([
                'id' => (string) Str::uuid(), 'partner_id' => $partner->id,
                'from_supervisor_partner_id' => $from->supervisor_partner_id, 'to_supervisor_partner_id' => $supervisor,
                'from_branch_id' => $from->branch_id, 'to_branch_id' => $branch,
                'reason_code' => $reason, 'notes' => $notes, 'actor_id' => $actor?->id, 'occurred_at' => now(),
            ]);
        }
        $this->audit->record('partner.hierarchy.changed', 'partner', $partner->id, ['from' => (array) $from, 'to' => ['supervisor_partner_id' => $supervisor, 'branch_id' => $branch, 'agent_type' => $type]], $reason);

        return $partner->refresh();
    }
}
