<?php

declare(strict_types=1);

namespace App\Application\Distribution\Execution;

use App\Application\Capabilities\CapabilityPinner;
use App\Application\Claims\Adapters\ClaimProvider;
use App\Application\Policies\Adapters\PolicyIssuer;
use App\Application\Quotes\Adapters\QuoteProvider;
use App\Application\Underwriting\Adapters\UnderwritingProvider;
use App\Models\Claim;
use App\Models\PolicyIssuanceRequest;
use App\Models\Proposal;
use App\Models\QuoteOffer;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * REQ-AOM-002 — pins the resolved capability mode when a quote offer, proposal, issuance request or
 * claim is created (CapabilityPinner, REQ-AOM-001). Registered as Eloquent `created` listeners by
 * DistributionServiceProvider so the canonical services (QuoteService, ProposalService,
 * PolicyIssuanceService, ClaimLifecycleService) pin without a second code path. A quote is pinned
 * per carrier offer (subject_type "quote", subject_id = quote_offers.id): one quote request fans out
 * to several carriers, each with its own mode.
 */
final class TransactionPinner
{
    public function __construct(private readonly CapabilityPinner $pinner) {}

    public function quoteOffer(QuoteOffer $offer): void
    {
        $this->pinner->pin(QuoteProvider::SUBJECT_TYPE, $offer->id, $offer->carrier_id, QuoteProvider::CAPABILITY, $offer->product_id, $this->actor());
    }

    public function proposal(Proposal $proposal): void
    {
        $offer = DB::table('quote_offers')->where('id', $proposal->quote_offer_id)->first(['carrier_id', 'product_id']);
        if ($offer) {
            $this->pinner->pin(UnderwritingProvider::SUBJECT_TYPE, $proposal->id, $offer->carrier_id, UnderwritingProvider::CAPABILITY, $offer->product_id, $this->actor());
        }
    }

    public function issuanceRequest(PolicyIssuanceRequest $request): void
    {
        $productId = DB::table('proposals')->join('quote_offers', 'quote_offers.id', '=', 'proposals.quote_offer_id')
            ->where('proposals.id', $request->proposal_id)->value('quote_offers.product_id');
        $this->pinner->pin(PolicyIssuer::SUBJECT_TYPE, $request->id, $request->carrier_id, PolicyIssuer::CAPABILITY, $productId, $this->actor());
    }

    public function claim(Claim $claim): void
    {
        $policy = DB::table('policies')->leftJoin('proposals', 'proposals.id', '=', 'policies.proposal_id')
            ->leftJoin('quote_offers', 'quote_offers.id', '=', 'proposals.quote_offer_id')
            ->where('policies.id', $claim->policy_id)->first(['policies.carrier_id', 'quote_offers.product_id']);
        if ($policy?->carrier_id) {
            $this->pinner->pin(ClaimProvider::SUBJECT_TYPE, $claim->id, $policy->carrier_id, ClaimProvider::CAPABILITY, $policy->product_id, $this->actor());
        }
    }

    private function actor(): ?User
    {
        $user = auth()->user();

        return $user instanceof User ? $user : null;
    }
}
