<?php

declare(strict_types=1);

namespace App\Application\Regulatory;

use App\Application\Approvals\ApprovalService;
use App\Application\Audit\AuditWriter;
use App\Models\ApprovalRequest;
use App\Models\Carrier;
use App\Models\InsurerAuthorization;
use App\Models\Regulatory\InsurerAuthorizedBranch;
use App\Models\Regulatory\InsurerRegulatoryAuthorization;
use App\Models\Regulatory\RegulatoryBranch;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Insurer → CIMA regulatory authorization (agrément) → authorized branches. CANONICAL (REQ-DUP-017):
 * insurer_regulatory_authorizations + insurer_authorized_branches decide publication. The official
 * register (insurer_authorizations, IARD/LIFE per reference year) is the register-year source that
 * FEEDS a record: it is linked, and its licence family must cover the branches claimed. The register
 * never creates a canonical row by itself: authorized branches come only from regulator evidence
 * (owner question Q2), so an insurer with nothing recorded stays blocked for new product versions.
 *
 * Maker-checker runs through the one approval engine (ApprovalService, action cima.insurer_authorization.approve):
 * recorded PENDING_APPROVAL, activated only when the approval request is APPROVED by another user.
 */
final class CimaAuthorizationService
{
    public const APPROVAL_ACTION = 'cima.insurer_authorization.approve';

    public const SOURCES = ['REGULATOR_DECREE', 'REGULATOR_LETTER', 'OFFICIAL_GAZETTE', 'CIMA_CRCA_DECISION', 'DEMO'];

    /** Register licence branch → CIMA branch business families it covers. */
    private const REGISTER_FAMILIES = ['IARD' => ['IARD'], 'LIFE' => ['LIFE'], 'CAPITALIZATION' => ['LIFE']];

    public function __construct(private readonly AuditWriter $audit, private readonly ApprovalService $approvals) {}

    /** Register-year rows (official register) for a carrier, newest year first: the feed shown in the record form. */
    public function registerSource(Carrier|string $carrier): Collection
    {
        return InsurerAuthorization::where('carrier_id', $carrier instanceof Carrier ? $carrier->id : $carrier)
            ->orderByDesc('reference_year')->orderBy('branch')->get();
    }

    /** @param  list<string>  $branchCodes */
    public function record(Carrier $carrier, array $data, array $branchCodes, ?User $actor): InsurerRegulatoryAuthorization
    {
        foreach (['authorization_reference' => 'An authorization reference is required.', 'source' => 'The source of the authorization is required.', 'effective_from' => 'An effective date is required.'] as $field => $msg) {
            if (blank($data[$field] ?? null)) {
                throw ValidationException::withMessages([$field => [$msg]]);
            }
        }
        $source = strtoupper((string) $data['source']);
        if ($source === 'DEMO' && ! $carrier->is_demo) {
            throw ValidationException::withMessages(['source' => ['DEMO authorizations are only allowed for demo carriers.']]);
        }
        if ($source !== 'DEMO' && blank($data['source_document'] ?? null)) {
            throw ValidationException::withMessages(['source_document' => ['Attach the regulator evidence (document URL or archive reference).']]);
        }
        $branchCodes = array_values(array_unique($branchCodes));
        if ($branchCodes === []) {
            throw ValidationException::withMessages(['branches' => ['Select at least one authorized CIMA branch.']]);
        }
        $branches = RegulatoryBranch::current()->whereIn('code', $branchCodes)->get()->keyBy('code');
        foreach ($branchCodes as $code) {
            $b = $branches[$code] ?? null;
            if (! $b) {
                throw ValidationException::withMessages(['branches' => ["Unknown CIMA branch {$code}."]]);
            }
            if ($b->reserved) {
                throw ValidationException::withMessages(['branches' => ["CIMA branch {$b->number} is reserved."]]);
            }
        }
        $families = $branches->pluck('business_family')->unique()->values()->all();
        if (count($families) > 1) {
            throw ValidationException::withMessages(['branches' => ['An agrément covers either non-life (IARD, branches 1-18) or life (branches 20-23); record them separately.']]);
        }
        $register = $this->resolveRegisterRow($carrier, $data['register_authorization_id'] ?? null, $families[0], (string) $data['effective_from']);
        if ($actor === null) {
            throw ValidationException::withMessages(['actor' => ['An authorization is recorded by a signed-in user (maker-checker).']]);
        }

        return DB::transaction(function () use ($carrier, $data, $branchCodes, $actor, $source, $families, $register) {
            $auth = InsurerRegulatoryAuthorization::create([
                'carrier_id' => $carrier->id, 'jurisdiction' => $data['jurisdiction'] ?? 'CM', 'regime' => 'CIMA', 'status' => 'PENDING_APPROVAL',
                'effective_from' => $data['effective_from'], 'effective_until' => $data['effective_until'] ?? null,
                'authorization_reference' => $data['authorization_reference'], 'source' => $source,
                'source_document' => $data['source_document'] ?? null, 'is_demo' => $source === 'DEMO',
                'notes' => $data['notes'] ?? null, 'created_by' => $actor->id,
                'register_authorization_id' => $register?->id, 'licence_family' => $families[0],
            ]);
            foreach ($branchCodes as $code) {
                InsurerAuthorizedBranch::create(['authorization_id' => $auth->id, 'branch_code' => $code, 'status' => 'ACTIVE', 'effective_from' => $data['effective_from']]);
            }
            $req = $this->approvals->open($actor, [
                'action_code' => self::APPROVAL_ACTION, 'subject_type' => 'carrier', 'subject_id' => $carrier->id,
                'source_table' => 'insurer_regulatory_authorizations', 'source_id' => $auth->id, 'tenant_id' => null,
                'payload' => ['authorization_reference' => $auth->authorization_reference, 'source' => $source, 'branches' => $branchCodes,
                    'register_authorization_id' => $register?->id, 'source_document' => $auth->source_document],
                'reason' => 'Record CIMA agrement '.$auth->authorization_reference,
            ]);
            $auth->update(['approval_request_id' => $req->id]);
            $this->audit->record('regulatory.authorization.recorded', 'insurer_regulatory_authorization', $auth->id,
                ['carrier_id' => $carrier->id, 'branches' => $branchCodes, 'register_authorization_id' => $register?->id, 'approval_id' => $req->id]);
            if ($this->approvals->isApproved($req)) {   // the matrix waived maker-checker
                $this->activate($auth, null);
            }

            return $auth->refresh()->load('branches');
        });
    }

    public function approvalRequest(InsurerRegulatoryAuthorization $auth): ApprovalRequest
    {
        return $this->approvals->forSource('insurer_regulatory_authorizations', $auth->id, function () use ($auth) {
            if ($auth->created_by === null) {
                throw ValidationException::withMessages(['approval' => ['This authorization has no recorded maker; record it again from evidence.']]);
            }

            return [$auth->created_by, ['action_code' => self::APPROVAL_ACTION, 'subject_type' => 'carrier', 'subject_id' => $auth->carrier_id, 'tenant_id' => null]];
        });
    }

    public function approve(InsurerRegulatoryAuthorization $auth, User $actor, ?string $note = null): InsurerRegulatoryAuthorization
    {
        if ($auth->status !== 'PENDING_APPROVAL') {
            throw ValidationException::withMessages(['status' => ['Only a pending authorization can be approved.']]);
        }
        if ($auth->created_by !== null && $auth->created_by === $actor->id) {
            throw ValidationException::withMessages(['actor' => ['Maker-checker: the person who recorded this authorization cannot approve it.']]);
        }

        return DB::transaction(function () use ($auth, $actor, $note) {
            $req = $this->approvals->recordDecision($this->approvalRequest($auth), $actor, 'APPROVED', $note);
            if ($this->approvals->isApproved($req)) {
                $this->activate($auth, $actor);
            }

            return $auth->refresh();
        });
    }

    public function reject(InsurerRegulatoryAuthorization $auth, User $actor, string $reason): InsurerRegulatoryAuthorization
    {
        if ($auth->status !== 'PENDING_APPROVAL') {
            throw ValidationException::withMessages(['status' => ['Only a pending authorization can be rejected.']]);
        }

        return DB::transaction(function () use ($auth, $actor, $reason) {
            $this->approvals->recordDecision($this->approvalRequest($auth), $actor, 'REJECTED', $reason);
            $auth->update(['status' => 'REJECTED']);
            $this->audit->record('regulatory.authorization.rejected', 'insurer_regulatory_authorization', $auth->id, [], $reason);

            return $auth->refresh();
        });
    }

    public function changeStatus(InsurerRegulatoryAuthorization $auth, string $status, User $actor, string $reason): InsurerRegulatoryAuthorization
    {
        if ($status === 'REJECTED') {
            return $this->reject($auth, $actor, $reason);
        }
        $allowed = ['SUSPENDED' => ['ACTIVE'], 'REVOKED' => ['ACTIVE', 'SUSPENDED'], 'ACTIVE' => ['SUSPENDED']];
        if (! in_array($auth->status, $allowed[$status] ?? [], true)) {
            throw ValidationException::withMessages(['status' => ["Cannot move an authorization from {$auth->status} to {$status}."]]);
        }
        $auth->update(['status' => $status] + ($status === 'REVOKED' ? ['effective_until' => now()->toDateString()] : []));
        $this->audit->record('regulatory.authorization.'.strtolower($status), 'insurer_regulatory_authorization', $auth->id, [], $reason);

        return $auth->refresh();
    }

    /** Is the carrier currently authorized for this branch? (canonical tables only) */
    public function isAuthorized(string $carrierId, string $branchCode, ?string $on = null): bool
    {
        $on ??= now()->toDateString();

        return InsurerAuthorizedBranch::where('branch_code', $branchCode)->where('status', 'ACTIVE')
            ->where(fn ($w) => $w->whereNull('effective_until')->orWhereDate('effective_until', '>=', $on))
            ->whereHas('authorization', fn ($a) => $a->where('carrier_id', $carrierId)->where('status', 'ACTIVE')->where('regime', 'CIMA')
                ->whereDate('effective_from', '<=', $on)->where(fn ($w) => $w->whereNull('effective_until')->orWhereDate('effective_until', '>=', $on)))
            ->exists();
    }

    private function activate(InsurerRegulatoryAuthorization $auth, ?User $actor): void
    {
        $auth->update(['status' => 'ACTIVE', 'approved_by' => $actor?->id, 'approved_at' => now()]);
        $this->audit->record('regulatory.authorization.approved', 'insurer_regulatory_authorization', $auth->id, ['approval_id' => $auth->approval_request_id]);
    }

    /**
     * The register feeds the record: when the carrier is on an official register, the record links a register
     * row that is AUTHORIZED and whose licence (IARD / LIFE) covers the branch family claimed. Carriers on no
     * register (demo / newly onboarded) record from regulator evidence alone.
     */
    private function resolveRegisterRow(Carrier $carrier, ?string $registerId, string $family, string $effectiveFrom): ?InsurerAuthorization
    {
        $rows = $this->registerSource($carrier);
        $covers = fn (InsurerAuthorization $r) => in_array($family, self::REGISTER_FAMILIES[$r->branch] ?? [], true);
        if ($registerId !== null && $registerId !== '') {
            $row = $rows->firstWhere('id', $registerId);
            if (! $row) {
                throw ValidationException::withMessages(['register_authorization_id' => ['That register entry does not belong to this insurer.']]);
            }
        } else {
            if ($rows->isEmpty()) {
                return null;
            }
            $year = (int) substr($effectiveFrom, 0, 4);
            $row = $rows->first(fn ($r) => $r->status === 'AUTHORIZED' && $covers($r) && $r->reference_year <= max($year, (int) now()->year))
                ?? $rows->first($covers);
            if (! $row) {
                $listed = $rows->pluck('branch')->unique()->join(', ');
                throw ValidationException::withMessages(['branches' => ["The official register lists this insurer for {$listed} only; it cannot hold a {$family} agrement."]]);
            }
        }
        if ($row->status !== 'AUTHORIZED') {
            throw ValidationException::withMessages(['register_authorization_id' => ["The register entry for {$row->reference_year} is {$row->status}, not AUTHORIZED."]]);
        }
        if (! $covers($row)) {
            throw ValidationException::withMessages(['branches' => ["The {$row->reference_year} register licenses this insurer for {$row->branch}; {$family} branches are not covered."]]);
        }

        return $row;
    }
}
