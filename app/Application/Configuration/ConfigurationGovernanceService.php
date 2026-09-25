<?php

declare(strict_types=1);

namespace App\Application\Configuration;

use App\Application\Approvals\ApprovalService;
use App\Application\Audit\AuditWriter;
use App\Domain\Tenancy\TenantContext;
use App\Models\ApprovalRequest;
use App\Models\ConfigurationChangeSet;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * REQ-SET-005 — uniform configuration governance (SCF §31-33): DRAFT → IN_REVIEW → APPROVED → PUBLISHED,
 * maker-checker through the central ApprovalService (configuration.publish), audit of old/new/reason/effective date,
 * effective-dated read side. Domains that already have their own governed workflow (tariffs, templates, rules)
 * keep it; this is the path for configuration that had none. Appliers may be registered per config_type to push
 * a published value into its owning store; without one, effectiveValue() is the read side.
 */
final class ConfigurationGovernanceService
{
    /** @var array<string, callable(ConfigurationChangeSet): void> */
    private array $appliers = [];

    public function __construct(private readonly ApprovalService $approvals, private readonly AuditWriter $audit) {}

    public function registerApplier(string $configType, callable $applier): void
    {
        $this->appliers[$configType] = $applier;
    }

    public function draft(User $maker, string $type, string $key, array $proposed, string $reason, ?string $effectiveFrom = null): ConfigurationChangeSet
    {
        if (mb_strlen(trim($reason)) < 5) {
            throw ValidationException::withMessages(['reason' => 'A reason (5+ characters) is required.']);
        }
        $tenant = rescue(fn () => app(TenantContext::class)->id(), null, false);
        $previous = $this->effectiveValue($type, $key, null, $tenant);
        $cs = ConfigurationChangeSet::create(['tenant_id' => $tenant, 'config_type' => $type, 'config_key' => $key, 'previous_value' => $previous,
            'proposed_value' => $proposed, 'reason' => trim($reason), 'status' => 'DRAFT', 'effective_from' => $effectiveFrom, 'created_by' => $maker->id]);
        $this->audit->record('configuration.drafted', 'configuration_change_set', $cs->id, ['config_type' => $type, 'config_key' => $key], $reason);

        return $cs;
    }

    public function submit(ConfigurationChangeSet $cs, User $maker): ConfigurationChangeSet
    {
        return DB::transaction(function () use ($cs, $maker): ConfigurationChangeSet {
            $cs = ConfigurationChangeSet::whereKey($cs->id)->lockForUpdate()->firstOrFail();
            if ($cs->status !== 'DRAFT' || $cs->created_by !== $maker->id) {
                throw ValidationException::withMessages(['status' => 'Only the author can submit a draft.']);
            }
            $req = $this->approvals->open($maker, ['action_code' => 'configuration.publish', 'subject_type' => 'configuration', 'subject_id' => $cs->id,
                'source_table' => 'configuration_change_sets', 'source_id' => $cs->id, 'reason' => $cs->reason, 'tenant_id' => $cs->tenant_id,
                'payload' => ['config_type' => $cs->config_type, 'config_key' => $cs->config_key, 'old' => $cs->previous_value, 'new' => $cs->proposed_value]]);
            $cs->update(['status' => $this->approvals->isApproved($req) ? 'APPROVED' : 'IN_REVIEW', 'submitted_at' => now(), 'approval_request_id' => $req->id]);

            return $cs->refresh();
        });
    }

    public function approve(ConfigurationChangeSet $cs, User $checker, ?string $note = null): ConfigurationChangeSet
    {
        return $this->decide($cs, $checker, 'APPROVED', $note);
    }

    public function reject(ConfigurationChangeSet $cs, User $checker, string $note): ConfigurationChangeSet
    {
        return $this->decide($cs, $checker, 'REJECTED', $note);
    }

    public function publish(ConfigurationChangeSet $cs, User $publisher): ConfigurationChangeSet
    {
        return DB::transaction(function () use ($cs, $publisher): ConfigurationChangeSet {
            $cs = ConfigurationChangeSet::whereKey($cs->id)->lockForUpdate()->firstOrFail();
            if ($cs->status !== 'APPROVED') {
                throw ValidationException::withMessages(['status' => 'Only an approved change set can be published.']);
            }
            if ($cs->created_by === $publisher->id) {
                throw ValidationException::withMessages(['actor' => 'Maker-checker: the author cannot publish their own change.']);
            }
            $cs->update(['status' => 'PUBLISHED', 'published_by' => $publisher->id, 'published_at' => now(), 'effective_from' => $cs->effective_from ?? now()->toDateString()]);
            if (isset($this->appliers[$cs->config_type])) {
                ($this->appliers[$cs->config_type])($cs->refresh());
            }
            $this->audit->recordChange('configuration.published', 'configuration_change_set', $cs->id, (array) ($cs->previous_value ?? []), (array) $cs->proposed_value, $cs->reason,
                ['config_type' => $cs->config_type, 'config_key' => $cs->config_key, 'effective_from' => (string) $cs->effective_from, 'approval_request_id' => $cs->approval_request_id]);

            return $cs->refresh();
        });
    }

    /** Effective-dated read side: the latest published value whose effective_from is on/before $at. */
    public function effectiveValue(string $type, string $key, ?string $at = null, ?string $tenantId = null): ?array
    {
        $row = ConfigurationChangeSet::where('config_type', $type)->where('config_key', $key)->where('status', 'PUBLISHED')
            ->where(fn ($q) => $q->whereNull('tenant_id')->when($tenantId, fn ($q) => $q->orWhere('tenant_id', $tenantId)))
            ->where('effective_from', '<=', $at ?? now()->toDateString())
            ->orderByRaw('tenant_id IS NULL')->orderByDesc('effective_from')->orderByDesc('published_at')->first();

        return $row?->proposed_value;
    }

    private function decide(ConfigurationChangeSet $cs, User $checker, string $decision, ?string $note): ConfigurationChangeSet
    {
        return DB::transaction(function () use ($cs, $checker, $decision, $note): ConfigurationChangeSet {
            $cs = ConfigurationChangeSet::whereKey($cs->id)->lockForUpdate()->firstOrFail();
            if ($cs->status !== 'IN_REVIEW') {
                throw ValidationException::withMessages(['status' => 'Only a change set in review can be decided.']);
            }
            $req = $this->approvals->recordDecision(ApprovalRequest::findOrFail($cs->approval_request_id), $checker, $decision, $note);
            if ($req->status === 'APPROVED' || $req->status === 'REJECTED') {
                $cs->update(['status' => $req->status]);
            }

            return $cs->refresh();
        });
    }
}
