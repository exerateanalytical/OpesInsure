<?php

declare(strict_types=1);

namespace App\Application\PartnerWorkspace;

use App\Application\Audit\AuditWriter;
use App\Application\Claims\ClaimLifecycleService;
use App\Application\Policies\PolicyIssuanceService;
use App\Models\Claim;
use App\Models\ClaimDecision;
use App\Application\Regulatory\CimaPublicationGuard;
use App\Models\InsuranceProduct;
use App\Models\Policy;
use App\Models\PolicyIssuanceRequest;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Insurer-side mutations for the Wave 16 carrier workspace. Every method
 * takes a record the controller has ALREADY scoped to the caller's carrier.
 *
 * Claims go through the Wave 7 ClaimLifecycleService::transition() (state
 * machine, claim_events, audit, outbox). Decisions keep Wave 7's
 * maker-checker (claim_decisions.proposed_by <> approved_by, enforced by a
 * DB constraint too): one insurer user proposes, a different one holding
 * carrier.authority.approve approves. The Wave 7 proposeDecision() /
 * approveDecision() pair is not reused as-is because its authority check
 * only admits the intermediary's own claims staff; the insurer IS the
 * authority, recorded as mode CARRIER_DIRECT.
 */
final class CarrierWorkspaceActions
{
    public function __construct(
        private readonly ClaimLifecycleService $claims,
        private readonly PolicyIssuanceService $issuance,
        private readonly AuditWriter $audit,
    ) {}

    // ------------------------------------------------------------- claims

    public function acknowledge(Claim $claim, User $actor): Claim
    {
        return $this->step($claim, 'ACKNOWLEDGED', 'CARRIER_ACKNOWLEDGED', [], $actor);
    }

    public function requestInformation(Claim $claim, string $note, User $actor): Claim
    {
        return DB::transaction(function () use ($claim, $note, $actor) {
            if ($claim->status === 'SUBMITTED') {
                $claim = $this->step($claim, 'ACKNOWLEDGED', 'CARRIER_ACKNOWLEDGED', [], $actor);
            }

            return $this->step($claim, 'EVIDENCE_PENDING', 'CARRIER_INFORMATION_REQUESTED', ['note' => $note], $actor);
        });
    }

    public function proposeDecision(Claim $claim, array $data, User $actor): ClaimDecision
    {
        return DB::transaction(function () use ($claim, $data, $actor) {
            // Walk the claim into CARRIER_REVIEW through the legal path.
            if (in_array($claim->status, ['ACKNOWLEDGED', 'EVIDENCE_PENDING'], true)) {
                $claim = $this->step($claim, 'ASSESSMENT', 'CARRIER_ASSESSMENT_STARTED', [], $actor);
            }
            if ($claim->status === 'ASSESSMENT') {
                $claim = $this->step($claim, 'CARRIER_REVIEW', 'CARRIER_REVIEW_STARTED', [], $actor);
            }
            if ($claim->status !== 'CARRIER_REVIEW') {
                throw ValidationException::withMessages(['status' => [__('wave7.decision_stage_invalid')]]);
            }
            if (ClaimDecision::where(['claim_id' => $claim->id, 'status' => 'PENDING_APPROVAL'])->exists()) {
                throw ValidationException::withMessages(['status' => [__('wave7.decision_pending')]]);
            }
            $amount = $data['decision'] === 'DECLINE' ? 0 : (int) $data['approved_amount_minor'];
            if ($data['decision'] !== 'DECLINE' && $amount <= 0) {
                throw ValidationException::withMessages(['approved_amount_minor' => [__('wave7.amount_invalid')]]);
            }
            $decision = ClaimDecision::create([
                'claim_id' => $claim->id, 'decision' => $data['decision'], 'approved_amount_minor' => $amount, 'currency' => $claim->currency,
                'reason_code' => $data['reason_code'], 'rationale' => $data['rationale'], 'status' => 'PENDING_APPROVAL', 'proposed_by' => $actor->id,
                'authority_snapshot' => ['mode' => 'CARRIER_DIRECT', 'carrier_id' => $claim->policy?->carrier_id, 'checked_at' => now()->toIso8601String()],
            ]);
            $this->audit->record('claim.decision.proposed', 'claim_decision', $decision->id, ['claim_id' => $claim->id, 'decision' => $decision->decision]);

            return $decision;
        });
    }

    public function approveDecision(ClaimDecision $decision, User $actor): Claim
    {
        return DB::transaction(function () use ($decision, $actor) {
            $decision = ClaimDecision::whereKey($decision->id)->lockForUpdate()->firstOrFail();
            if ($decision->status !== 'PENDING_APPROVAL' || $decision->proposed_by === $actor->id) {
                throw ValidationException::withMessages(['status' => [__('wave7.approval_invalid')]]);
            }
            $to = match ($decision->decision) { 'APPROVE' => 'APPROVED', 'PARTIAL' => 'PARTIALLY_APPROVED', default => 'DECLINED' };
            $decision->update(['status' => 'APPROVED', 'approved_by' => $actor->id, 'approved_at' => now()]);
            $claim = $this->step(Claim::findOrFail($decision->claim_id), $to, $decision->reason_code, ['decision_id' => $decision->id], $actor);
            $claim->update(['approved_amount_minor' => $decision->approved_amount_minor]);
            $this->audit->record('claim.decision.approved', 'claim_decision', $decision->id, ['claim_id' => $claim->id, 'amount_minor' => $decision->approved_amount_minor]);

            return $claim->refresh();
        });
    }

    private function step(Claim $claim, string $to, string $reason, array $details, User $actor): Claim
    {
        try {
            return $this->claims->transition($claim, $to, $reason, $details, $actor);
        } catch (DomainException $e) {
            throw ValidationException::withMessages(['status' => [$e->getMessage()]]);
        }
    }

    // ----------------------------------------------------------- issuance

    public function approveIssuance(PolicyIssuanceRequest $request, array $data, User $actor): Policy
    {
        return $this->issuance->approve($request, [
            'carrier_reference' => $data['carrier_reference'] ?? $data['policy_number'] ?? 'INS-'.strtoupper(Str::random(10)),
        ], $actor);
    }

    public function rejectIssuance(PolicyIssuanceRequest $request, string $reason, User $actor): PolicyIssuanceRequest
    {
        return $this->issuance->reject($request, $reason, $actor);
    }

    // ----------------------------------------------------------- products

    /**
     * Pause (ACTIVE → RETIRED) or resume (RETIRED → ACTIVE) sales of one
     * product version, with product_status_history like CatalogueService.
     * Resuming needs an APPROVED tariff and no other ACTIVE version of the
     * same product code (that would put two prices on the shelf).
     */
    public function setProductActive(InsuranceProduct $product, bool $active, string $reason, User $actor): InsuranceProduct
    {
        return DB::transaction(function () use ($product, $active, $reason, $actor) {
            $product = InsuranceProduct::whereKey($product->id)->lockForUpdate()->firstOrFail();
            [$from, $to] = $active ? ['RETIRED', 'ACTIVE'] : ['ACTIVE', 'RETIRED'];
            if ($product->status !== $from) {
                throw ValidationException::withMessages(['status' => [__('wave2.invalid_product_transition')]]);
            }
            if ($active) {
                if (! $product->tariffs()->where('status', 'APPROVED')->exists()) {
                    throw ValidationException::withMessages(['tariff' => [__('wave2.approved_tariff_required')]]);
                }
                if (InsuranceProduct::where(['carrier_id' => $product->carrier_id, 'code' => $product->code, 'status' => 'ACTIVE'])->where('id', '!=', $product->id)->exists()) {
                    throw ValidationException::withMessages(['status' => ['A newer version of this product is already on sale.']]);
                }
                // CIMA publication guard; products on sale before the dictionary went live are grandfathered.
                app(CimaPublicationGuard::class)->assertResumable($product);
            }
            $product->update(['status' => $to]);
            DB::table('product_status_history')->insert([
                'id' => (string) Str::uuid(), 'insurance_product_id' => $product->id, 'from_status' => $from, 'to_status' => $to,
                'reason_code' => $active ? 'CARRIER_RESUMED' : 'CARRIER_PAUSED', 'notes' => $reason, 'actor_id' => $actor->id, 'occurred_at' => now(),
            ]);
            $this->audit->record($active ? 'catalogue.product.resumed' : 'catalogue.product.paused', 'insurance_product', $product->id, [], $reason);

            return $product->refresh();
        });
    }
}
