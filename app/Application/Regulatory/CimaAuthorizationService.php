<?php

declare(strict_types=1);

namespace App\Application\Regulatory;

use App\Application\Audit\AuditWriter;
use App\Models\Carrier;
use App\Models\Regulatory\InsurerAuthorizedBranch;
use App\Models\Regulatory\InsurerRegulatoryAuthorization;
use App\Models\Regulatory\RegulatoryBranch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Insurer → CIMA regulatory authorization (agrément) → authorized branches.
 * Recorded from regulator evidence only: source and reference are mandatory.
 * Maker-checker: recorded PENDING_APPROVAL, activated by a different user.
 */
final class CimaAuthorizationService
{
    public function __construct(private readonly AuditWriter $audit) {}

    /** @param  list<string>  $branchCodes */
    public function record(Carrier $carrier, array $data, array $branchCodes, ?User $actor): InsurerRegulatoryAuthorization
    {
        foreach (['authorization_reference' => 'An authorization reference is required.', 'source' => 'The source of the authorization is required.', 'effective_from' => 'An effective date is required.'] as $field => $msg) {
            if (blank($data[$field] ?? null)) {
                throw ValidationException::withMessages([$field => [$msg]]);
            }
        }
        if (strtoupper((string) $data['source']) === 'DEMO' && ! $carrier->is_demo) {
            throw ValidationException::withMessages(['source' => ['DEMO authorizations are only allowed for demo carriers.']]);
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

        return DB::transaction(function () use ($carrier, $data, $branchCodes, $actor) {
            $auth = InsurerRegulatoryAuthorization::create([
                'carrier_id' => $carrier->id, 'jurisdiction' => $data['jurisdiction'] ?? 'CM', 'regime' => 'CIMA', 'status' => 'PENDING_APPROVAL',
                'effective_from' => $data['effective_from'], 'effective_until' => $data['effective_until'] ?? null,
                'authorization_reference' => $data['authorization_reference'], 'source' => strtoupper((string) $data['source']),
                'source_document' => $data['source_document'] ?? null, 'is_demo' => strtoupper((string) $data['source']) === 'DEMO',
                'notes' => $data['notes'] ?? null, 'created_by' => $actor?->id,
            ]);
            foreach ($branchCodes as $code) {
                InsurerAuthorizedBranch::create(['authorization_id' => $auth->id, 'branch_code' => $code, 'status' => 'ACTIVE', 'effective_from' => $data['effective_from']]);
            }
            $this->audit->record('regulatory.authorization.recorded', 'insurer_regulatory_authorization', $auth->id, ['carrier_id' => $carrier->id, 'branches' => $branchCodes]);

            return $auth->load('branches');
        });
    }

    public function approve(InsurerRegulatoryAuthorization $auth, User $actor): InsurerRegulatoryAuthorization
    {
        if ($auth->status !== 'PENDING_APPROVAL') {
            throw ValidationException::withMessages(['status' => ['Only a pending authorization can be approved.']]);
        }
        if ($auth->created_by !== null && $auth->created_by === $actor->id) {
            throw ValidationException::withMessages(['actor' => ['Maker-checker: the person who recorded this authorization cannot approve it.']]);
        }
        $auth->update(['status' => 'ACTIVE', 'approved_by' => $actor->id, 'approved_at' => now()]);
        $this->audit->record('regulatory.authorization.approved', 'insurer_regulatory_authorization', $auth->id);

        return $auth->refresh();
    }

    public function changeStatus(InsurerRegulatoryAuthorization $auth, string $status, User $actor, string $reason): InsurerRegulatoryAuthorization
    {
        $allowed = ['REJECTED' => ['PENDING_APPROVAL'], 'SUSPENDED' => ['ACTIVE'], 'REVOKED' => ['ACTIVE', 'SUSPENDED'], 'ACTIVE' => ['SUSPENDED']];
        if (! in_array($auth->status, $allowed[$status] ?? [], true)) {
            throw ValidationException::withMessages(['status' => ["Cannot move an authorization from {$auth->status} to {$status}."]]);
        }
        $auth->update(['status' => $status] + ($status === 'REVOKED' ? ['effective_until' => now()->toDateString()] : []));
        $this->audit->record('regulatory.authorization.'.strtolower($status), 'insurer_regulatory_authorization', $auth->id, [], $reason);

        return $auth->refresh();
    }

    /** Is the carrier currently authorized for this branch? */
    public function isAuthorized(string $carrierId, string $branchCode, ?string $on = null): bool
    {
        $on ??= now()->toDateString();

        return InsurerAuthorizedBranch::where('branch_code', $branchCode)->where('status', 'ACTIVE')
            ->where(fn ($w) => $w->whereNull('effective_until')->orWhereDate('effective_until', '>=', $on))
            ->whereHas('authorization', fn ($a) => $a->where('carrier_id', $carrierId)->where('status', 'ACTIVE')->where('regime', 'CIMA')
                ->whereDate('effective_from', '<=', $on)->where(fn ($w) => $w->whereNull('effective_until')->orWhereDate('effective_until', '>=', $on)))
            ->exists();
    }
}
