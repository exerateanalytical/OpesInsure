<?php

declare(strict_types=1);

namespace App\Application\Policies\IssuanceQueue;

use App\Models\Carrier;
use App\Models\Proposal;
use App\Models\Tenant;

/**
 * REQ-POL-004: the issuance territory comes from data, never a hard-coded 'CM'. Order:
 *   1. the risk itself (quote risk_facts territory / country_code / jurisdiction),
 *   2. the issuing carrier's country (carriers.country_code, official register),
 *   3. the selling tenant's country (tenants.country_code).
 * Null when none is recorded — the caller then queues the payment instead of guessing.
 */
final class IssuanceTerritoryResolver
{
    /** @return array{territory: ?string, source: ?string} */
    public function resolve(Proposal $proposal): array
    {
        $facts = $proposal->offer?->quote?->risk_facts ?? [];
        foreach (['territory', 'country_code', 'jurisdiction'] as $key) {
            if (is_string($facts[$key] ?? null) && ($v = $this->normalise($facts[$key])) !== null) {
                return ['territory' => $v, 'source' => 'RISK_FACTS.'.$key];
            }
        }

        $carrierId = $proposal->offer?->carrier_id;
        if ($carrierId && ($v = $this->normalise(Carrier::whereKey($carrierId)->value('country_code')))) {
            return ['territory' => $v, 'source' => 'CARRIER'];
        }

        if ($v = $this->normalise(Tenant::whereKey($proposal->tenant_id)->value('country_code'))) {
            return ['territory' => $v, 'source' => 'TENANT'];
        }

        return ['territory' => null, 'source' => null];
    }

    private function normalise(mixed $v): ?string
    {
        $v = strtoupper(trim((string) $v));

        return preg_match('/^[A-Z0-9_-]{2,32}$/', $v) ? $v : null;
    }
}
