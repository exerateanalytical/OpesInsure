<?php

declare(strict_types=1);

namespace App\Application\Capabilities;

use App\Application\Capabilities\Models\CapabilityPin;
use App\Domain\Shared\Clock\Clock;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * REQ-AOM-001 — pins the resolved mode + profile version on a transaction (quote, proposal,
 * issuance request, claim…) so replays and audits stay reproducible after the insurer changes
 * its profile (plan A2, AT2). Idempotent per (subject, capability); pins are immutable.
 * Workflow services call pin() when they create the transaction (REQ-AOM-002 adapters).
 */
final class CapabilityPinner
{
    public const SUBJECT_TYPES = ['quote', 'proposal', 'policy_issuance_request', 'policy', 'claim', 'endorsement', 'renewal', 'payment_intent'];

    public function __construct(private readonly CapabilityResolver $resolver, private readonly Clock $clock) {}

    public function pin(string $subjectType, string $subjectId, string $carrierId, string $capability, ?string $productId = null, ?User $actor = null): CapabilityPin
    {
        if ($existing = $this->pinned($subjectType, $subjectId, $capability)) {
            return $existing;
        }
        $r = $this->resolver->mode($carrierId, $capability, $productId);
        try {
            return CapabilityPin::create([
                'subject_type' => $subjectType, 'subject_id' => $subjectId, 'carrier_id' => $carrierId, 'product_id' => $productId,
                'capability' => $capability, 'mode' => $r['mode'], 'execution_mode' => $r['execution_mode'],
                'profile_id' => $r['profile_id'], 'profile_version' => $r['profile_version'], 'source' => $r['source'],
                'pinned_by' => $actor?->id, 'pinned_at' => $this->clock->now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return $this->pinned($subjectType, $subjectId, $capability);
        }
    }

    public function pinned(string $subjectType, string $subjectId, string $capability): ?CapabilityPin
    {
        return CapabilityPin::where(['subject_type' => $subjectType, 'subject_id' => $subjectId, 'capability' => $capability])->first();
    }
}
