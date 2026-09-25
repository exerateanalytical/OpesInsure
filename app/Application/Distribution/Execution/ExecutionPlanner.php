<?php

declare(strict_types=1);

namespace App\Application\Distribution\Execution;

use App\Application\Claims\Adapters\ClaimProviderRegistry;
use App\Application\Policies\Adapters\PolicyIssuerRegistry;
use App\Application\Quotes\Adapters\QuoteProviderRegistry;
use App\Application\Underwriting\Adapters\UnderwritingProviderRegistry;
use InvalidArgumentException;

/**
 * REQ-AOM-002 — one entry point over the four execution registries: which adapter handles
 * quotation, underwriting, issuance and claims intake for a carrier/product (or a pinned transaction).
 */
final class ExecutionPlanner
{
    public const PORTS = ['quote' => QuoteProviderRegistry::class, 'underwriting' => UnderwritingProviderRegistry::class,
        'issuance' => PolicyIssuerRegistry::class, 'claim' => ClaimProviderRegistry::class];

    public function registry(string $port): AdapterRegistry
    {
        return app(self::PORTS[$port] ?? throw new InvalidArgumentException("Unknown execution port {$port}."));
    }

    /** @return array<string, array<string,mixed>> port => outcome preview (no side effects) */
    public function plan(string $carrierId, ?string $productId = null): array
    {
        $out = [];
        foreach (array_keys(self::PORTS) as $port) {
            $registry = $this->registry($port);
            $out[$port] = $registry->execute(new ExecutionContext('preview', null, $carrierId, $productId))->toArray();
        }

        return $out;
    }
}
