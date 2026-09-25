<?php

declare(strict_types=1);

namespace App\Application\Security\Purpose;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * REQ-SEC-003 purpose-of-use guard. Every guarded processing operation names a
 * purpose from the processing_purposes catalogue. The decision is:
 *  - unknown / inactive purpose  → DENY (UNKNOWN_PURPOSE)
 *  - lawful basis CONSENT        → ALLOW only with a GRANTED consent for the
 *                                  purpose's consent_purpose (else NO_CONSENT)
 *  - any other lawful basis      → ALLOW (basis recorded)
 * Every decision (allow and deny) is written to purpose_of_use_checks.
 */
final class PurposeOfUseGuard
{
    /** @param array{tenant_id?: ?string, reference_type?: ?string, reference_id?: ?string, actor_id?: ?string} $ctx */
    public function check(string $purposeCode, string $operation, ?string $partyId, array $ctx = []): PurposeDecision
    {
        $purpose = DB::table('processing_purposes')->where('code', $purposeCode)->where('is_active', true)->first();
        $consentId = null;
        if (! $purpose) {
            $decision = new PurposeDecision(false, $purposeCode, null, null, 'UNKNOWN_PURPOSE');
        } elseif ($purpose->lawful_basis === 'CONSENT') {
            $tenantId = $ctx['tenant_id'] ?? null;
            $consentId = $partyId ? DB::table('consents')->where('party_id', $partyId)->where('purpose', $purpose->consent_purpose ?? $purpose->code)->where('status', 'GRANTED')
                ->when($tenantId !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('tenant_id')->orWhere('tenant_id', $tenantId)))
                ->orderByDesc('given_at')->value('id') : null;
            $decision = new PurposeDecision($consentId !== null, $purposeCode, 'CONSENT', $consentId, $consentId ? null : 'NO_CONSENT');
        } else {
            $decision = new PurposeDecision(true, $purposeCode, $purpose->lawful_basis, null, null);
        }

        DB::table('purpose_of_use_checks')->insert([
            'id' => (string) Str::uuid(), 'tenant_id' => $ctx['tenant_id'] ?? null, 'party_id' => $partyId, 'purpose_code' => $purposeCode, 'operation' => $operation,
            'decision' => $decision->allowed ? 'ALLOW' : 'DENY', 'lawful_basis' => $decision->lawfulBasis, 'consent_id' => $consentId, 'refusal_code' => $decision->refusalCode,
            'reference_type' => $ctx['reference_type'] ?? null, 'reference_id' => isset($ctx['reference_id']) ? (string) $ctx['reference_id'] : null,
            'actor_id' => $ctx['actor_id'] ?? null, 'occurred_at' => now(),
        ]);

        return $decision;
    }

    /** Same as check(), but a DENY throws PurposeOfUseDenied (rendered as 422 PURPOSE_OF_USE_DENIED). */
    public function enforce(string $purposeCode, string $operation, ?string $partyId, array $ctx = []): PurposeDecision
    {
        $d = $this->check($purposeCode, $operation, $partyId, $ctx);
        if (! $d->allowed) {
            throw new PurposeOfUseDenied($d);
        }

        return $d;
    }
}
