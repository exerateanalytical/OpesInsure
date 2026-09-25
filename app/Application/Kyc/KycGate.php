<?php

declare(strict_types=1);

namespace App\Application\Kyc;

use App\Application\Audit\AuditWriter;
use App\Interfaces\Http\Errors\ApiProblemException;
use App\Models\KycSubmission;
use App\Models\Tenant;

/**
 * REQ-KYC-001 KYC gating on bind (policy issuance request) and issue (issuance approval).
 * Mode per tenant: tenants.settings.kyc.gate_mode, else config kyc.gate_mode — OFF | WARN | ENFORCE.
 * The platform default is OFF (UNVERIFIED owner decision); WARN audits, ENFORCE refuses with KYC_REQUIRED.
 */
final class KycGate
{
    public function __construct(private readonly AuditWriter $audit) {}

    public function mode(string $tenantId): string
    {
        $settings = Tenant::whereKey($tenantId)->value('settings');
        $settings = is_string($settings) ? json_decode($settings, true) : (array) $settings;
        $mode = strtoupper((string) ($settings['kyc']['gate_mode'] ?? config('kyc.gate_mode', 'OFF')));

        return in_array($mode, ['OFF', 'WARN', 'ENFORCE'], true) ? $mode : 'ENFORCE';
    }

    /** @return array{verified: bool, reason: string, submission_id: ?string, expires_at: ?string, kyc_level: ?string} */
    public function status(string $tenantId, ?string $partyId): array
    {
        if ($partyId === null) {
            return ['verified' => false, 'reason' => 'NO_PARTY', 'submission_id' => null, 'expires_at' => null, 'kyc_level' => null];
        }
        $approved = KycSubmission::where('tenant_id', $tenantId)->where('party_id', $partyId)->where('status', 'APPROVED')->latest('approved_at')->first();
        if ($approved && (! $approved->expires_at || $approved->expires_at->isFuture())) {
            return ['verified' => true, 'reason' => 'APPROVED', 'submission_id' => $approved->id, 'expires_at' => $approved->expires_at?->toIso8601String(), 'kyc_level' => $approved->kyc_level];
        }
        $latest = KycSubmission::where('tenant_id', $tenantId)->where('party_id', $partyId)->latest('created_at')->first();
        $reason = match (true) {
            $latest === null => 'NOT_STARTED',
            $approved !== null || $latest->status === 'EXPIRED' => 'EXPIRED',
            default => 'KYC_'.$latest->status,
        };

        return ['verified' => false, 'reason' => $reason, 'submission_id' => $latest?->id, 'expires_at' => null, 'kyc_level' => $latest?->kyc_level];
    }

    /** @param 'BIND'|'ISSUE' $stage */
    public function assertMayProceed(string $tenantId, ?string $partyId, string $stage, string $subjectType, string $subjectId): void
    {
        $mode = $this->mode($tenantId);
        if ($mode === 'OFF') {
            return;
        }
        $st = $this->status($tenantId, $partyId);
        if ($st['verified']) {
            return;
        }
        $this->audit->record('kyc.gate.'.($mode === 'ENFORCE' ? 'blocked' : 'warned'), $subjectType, $subjectId, ['stage' => $stage, 'party_id' => $partyId, 'reason' => $st['reason']]);
        if ($mode === 'ENFORCE') {
            throw new ApiProblemException('KYC_REQUIRED', 422, "An approved, unexpired KYC is required before {$stage} ({$st['reason']}).", ['kyc' => [$st['reason']]], ['kyc' => $st, 'stage' => $stage]);
        }
    }
}
