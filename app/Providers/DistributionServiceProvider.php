<?php

declare(strict_types=1);

namespace App\Providers;

use App\Application\Claims\Adapters\ClaimProviderRegistry;
use App\Application\Distribution\Execution\ExecutionPlanner;
use App\Application\Distribution\Execution\TransactionPinner;
use App\Application\Distribution\SellabilityService;
use App\Application\Distribution\SellableCatalogue;
use App\Application\Policies\Adapters\PolicyIssuerRegistry;
use App\Application\Quotes\Adapters\QuoteProviderRegistry;
use App\Application\Underwriting\Adapters\UnderwritingProviderRegistry;
use App\Models\Claim;
use App\Models\PolicyIssuanceRequest;
use App\Models\Proposal;
use App\Models\QuoteOffer;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * REQ-DST-001 / REQ-DST-002 / REQ-AOM-002 — sellability + sellable catalogue, execution adapter registries,
 * and capability pinning when quote offers, proposals, issuance requests and claims are created.
 * Needs CapabilitySetupServiceProvider (CapabilityResolver / CapabilityPinner).
 */
final class DistributionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        foreach ([SellabilityService::class, SellableCatalogue::class, ExecutionPlanner::class, TransactionPinner::class,
            QuoteProviderRegistry::class, UnderwritingProviderRegistry::class, PolicyIssuerRegistry::class, ClaimProviderRegistry::class] as $class) {
            $this->app->singleton($class);
        }
    }

    public function boot(): void
    {
        QuoteOffer::created(fn (QuoteOffer $m) => $this->app->make(TransactionPinner::class)->quoteOffer($m));
        Proposal::created(fn (Proposal $m) => $this->app->make(TransactionPinner::class)->proposal($m));
        PolicyIssuanceRequest::created(fn (PolicyIssuanceRequest $m) => $this->app->make(TransactionPinner::class)->issuanceRequest($m));
        Claim::created(fn (Claim $m) => $this->app->make(TransactionPinner::class)->claim($m));

        if (! $this->app->routesAreCached()) {
            Route::middleware('api')->prefix('api')->group(base_path('routes/distribution.php'));
        }
    }
}
