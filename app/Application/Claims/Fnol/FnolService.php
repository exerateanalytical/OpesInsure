<?php

declare(strict_types=1);

namespace App\Application\Claims\Fnol;

use App\Application\Audit\AuditWriter;
use App\Application\Capabilities\CapabilityPinner;
use App\Application\Claims\Adapters\ClaimProvider;
use App\Application\Claims\ClaimLifecycleService;
use App\Application\Identity\PartyResolver;
use App\Application\PartnerWorkspace\PartnerWorkspaceScope;
use App\Application\Policies\Chronology\PolicyChronologyWriter;
use App\Models\Claim;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * REQ-CLM-002 (WF-048/049) — the one FNOL path. Every front door (staff claims/fnol, mobile
 * customer, agent-assisted AGT-052, the legacy ClaimController) calls submit(): the claim itself
 * is created by ClaimLifecycleService::fnol() (validation, idempotent replay, claim number,
 * FNOL_SUBMITTED event, outbox), then — in the same transaction — the CLAIMS_INTAKE capability is
 * pinned and an immutable claim_fnol_snapshots row freezes the policy version in force at the
 * loss date, the reported facts, the reporter and the channel.
 */
final class FnolService
{
    public const CHANNELS = ['MOBILE', 'WEB', 'AGENT', 'BACK_OFFICE', 'PHONE', 'EMAIL', 'BRANCH', 'API'];

    public function __construct(
        private readonly ClaimLifecycleService $lifecycle,
        private readonly CapabilityPinner $pinner,
        private readonly PolicyChronologyWriter $chronology,
        private readonly PartyResolver $parties,
        private readonly PartnerWorkspaceScope $scope,
        private readonly AuditWriter $audit,
    ) {}

    /**
     * @param  array{policy_id:string,claimant_party_id:string,loss_occurred_at:string,loss_details:array,loss_location?:?string,estimated_loss_minor?:?int,priority?:string,idempotency_key:string}  $data
     * @param  array{channel:string,role:string,partner_id?:?string}  $reporter
     */
    public function submit(string $tenantId, array $data, User $actor, array $reporter): Claim
    {
        if (! in_array($reporter['channel'], self::CHANNELS, true)) {
            throw ValidationException::withMessages(['channel' => 'Unknown FNOL channel.']);
        }

        return DB::transaction(function () use ($tenantId, $data, $actor, $reporter) {
            $claim = $this->lifecycle->fnol($tenantId, $data, $actor);
            if (! DB::table('claim_fnol_snapshots')->where('claim_id', $claim->id)->exists()) {
                $this->snapshot($claim, $data, $actor, $reporter);
            }

            return $claim;
        });
    }

    /** AGT-052 — an active agent files for a customer in their book (ACTIVE customer_attribution). */
    public function submitForCustomer(string $tenantId, array $data, User $agent): Claim
    {
        $partner = $this->scope->activeAgent($agent);
        if (! in_array($data['claimant_party_id'], $this->scope->bookPartyIds($partner), true)) {
            throw ValidationException::withMessages(['claimant_party_id' => 'This customer is not in your book.']);
        }

        return $this->submit($tenantId, $data, $agent, ['channel' => 'AGENT', 'role' => 'AGENT', 'partner_id' => $partner->id]);
    }

    public function snapshotFor(Claim $claim): ?object
    {
        $row = DB::table('claim_fnol_snapshots')->where('claim_id', $claim->id)->first();
        if ($row) {
            foreach (['policy_snapshot', 'reported_facts'] as $k) {
                $row->{$k} = json_decode((string) $row->{$k}, true);
            }
        }

        return $row;
    }

    private function snapshot(Claim $claim, array $data, User $actor, array $reporter): void
    {
        $policy = DB::table('policies')->where('id', $claim->policy_id)->first();
        $loss = CarbonImmutable::parse($claim->loss_occurred_at);
        $version = $this->chronology->asOf($claim->policy_id, $loss);
        $policySnapshot = [
            'policy_number' => $policy->policy_number ?? null,
            'status' => $policy->status,
            'carrier_id' => $policy->carrier_id ?? null,
            'party_id' => $policy->party_id,
            'currency' => $policy->currency,
            'coverage_starts_at' => $policy->coverage_starts_at,
            'coverage_ends_at' => $policy->coverage_ends_at,
            'version' => $version ? [
                'id' => $version->id, 'version_no' => (int) $version->version_no, 'kind' => $version->kind,
                'valid_from' => $version->valid_from, 'snapshot_hash' => $version->snapshot_hash, 'terms_hash' => $version->terms_hash,
                'snapshot' => json_decode((string) $version->snapshot, true),
            ] : null,
            'coverages' => $version ? DB::table('policy_coverages')->where('policy_version_id', $version->id)->orderBy('coverage_code')
                ->get(['coverage_code', 'limit_minor', 'deductible_minor', 'currency'])->map(fn ($r) => (array) $r)->all() : [],
            'limits' => $version ? DB::table('policy_limits')->where('policy_version_id', $version->id)->orderBy('limit_type')
                ->get(['limit_type', 'amount_minor', 'currency'])->map(fn ($r) => (array) $r)->all() : [],
        ];
        $facts = [
            'loss_occurred_at' => $loss->toIso8601String(),
            'loss_details' => $data['loss_details'],
            'loss_location' => $data['loss_location'] ?? null,
            'estimated_loss_minor' => $data['estimated_loss_minor'] ?? null,
            'priority' => $data['priority'] ?? 'NORMAL',
        ];
        $pin = null;
        if (! empty($policy->carrier_id)) {
            $productId = DB::table('proposals')->join('quote_offers', 'quote_offers.id', '=', 'proposals.quote_offer_id')
                ->where('proposals.id', $policy->proposal_id ?? null)->value('quote_offers.product_id');
            $pin = $this->pinner->pin(ClaimProvider::SUBJECT_TYPE, $claim->id, $policy->carrier_id, ClaimProvider::CAPABILITY, $productId, $actor);
        }
        $reporterBlock = [
            'user_id' => $actor->id,
            'party_id' => $this->parties->forUser($actor)?->id,
            'role' => $reporter['role'],
            'partner_id' => $reporter['partner_id'] ?? null,
            'channel' => $reporter['channel'],
        ];
        $hash = hash('sha256', json_encode([$claim->claim_number, $policySnapshot, $facts, $reporterBlock], JSON_THROW_ON_ERROR));
        $id = (string) Str::uuid();
        DB::table('claim_fnol_snapshots')->insert([
            'id' => $id, 'tenant_id' => $claim->tenant_id, 'claim_id' => $claim->id, 'claim_number' => $claim->claim_number,
            'policy_id' => $claim->policy_id, 'policy_version_id' => $version?->id, 'policy_version_no' => $version ? (int) $version->version_no : null,
            'policy_snapshot' => json_encode($policySnapshot, JSON_THROW_ON_ERROR), 'reported_facts' => json_encode($facts, JSON_THROW_ON_ERROR),
            'channel' => $reporter['channel'], 'reporter_user_id' => $actor->id, 'reporter_party_id' => $reporterBlock['party_id'],
            'reporter_role' => $reporter['role'], 'acting_partner_id' => $reporterBlock['partner_id'], 'claimant_party_id' => $claim->claimant_party_id,
            'capability_pin_id' => $pin?->id, 'content_hash' => $hash, 'loss_occurred_at' => $loss, 'reported_at' => $claim->submitted_at ?? now(), 'created_at' => now(),
        ]);
        $this->audit->record('claim.fnol.snapshot_recorded', 'claim', $claim->id, [
            'snapshot_id' => $id, 'channel' => $reporter['channel'], 'reporter_role' => $reporter['role'],
            'acting_partner_id' => $reporterBlock['partner_id'], 'policy_version_id' => $version?->id, 'content_hash' => $hash,
        ]);
    }
}
