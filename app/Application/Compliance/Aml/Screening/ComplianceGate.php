<?php

declare(strict_types=1);

namespace App\Application\Compliance\Aml\Screening;

use App\Application\Audit\AuditWriter;
use App\Interfaces\Http\Errors\ApiProblemException;
use App\Models\Tenant;

/**
 * Agent E8 — REQ-AML-001 compliance gate at bind, issue and claim payout (same pattern as App\Application\Kyc\KycGate).
 * Mode per tenant: tenants.settings.aml.screening_gate_mode, else config aml.screening.gate_mode — OFF | WARN | ENFORCE.
 * A screening hit that is undisposed (OPEN / PROPOSED) or disposed TRUE_MATCH / ESCALATED blocks. WARN audits only.
 */
final class ComplianceGate
{
    public const BLOCKED = 'AML_SCREENING_HOLD';

    public function __construct(private readonly ScreeningService $screening, private readonly AuditWriter $audit) {}

    public function mode(string $tenantId): string
    {
        $settings = Tenant::whereKey($tenantId)->value('settings');
        $settings = is_string($settings) ? json_decode($settings, true) : (array) $settings;
        $mode = strtoupper((string) ($settings['aml']['screening_gate_mode'] ?? config('aml.screening.gate_mode', 'OFF')));

        return in_array($mode, ['OFF', 'WARN', 'ENFORCE'], true) ? $mode : 'ENFORCE';
    }

    /**
     * Null when the subject may proceed (or mode is OFF / WARN), else the blocking reason code.
     *
     * @param  list<?string>  $partyIds
     * @param  'BIND'|'ISSUE'|'PAYOUT'  $stage
     */
    public function blockingReason(string $tenantId, array $partyIds, string $stage, string $subjectType, string $subjectId): ?string
    {
        $mode = $this->mode($tenantId);
        if ($mode === 'OFF') {
            return null;
        }
        $hits = $this->screening->blockingHits($tenantId, array_values(array_filter($partyIds)));
        if ($hits->isEmpty()) {
            return null;
        }
        $this->audit->record('aml.screening.gate.'.($mode === 'ENFORCE' ? 'blocked' : 'warned'), $subjectType, $subjectId,
            ['stage' => $stage, 'party_ids' => array_values(array_unique($hits->pluck('party_id')->all())), 'hit_ids' => $hits->pluck('id')->all()]);

        return $mode === 'ENFORCE' ? self::BLOCKED : null;
    }

    /** @param list<?string> $partyIds  @param 'BIND'|'ISSUE'|'PAYOUT' $stage */
    public function assertMayProceed(string $tenantId, array $partyIds, string $stage, string $subjectType, string $subjectId): void
    {
        if ($this->blockingReason($tenantId, $partyIds, $stage, $subjectType, $subjectId) !== null) {
            throw new ApiProblemException(self::BLOCKED, 422, "A screening hit must be disposed (and not be a true match or escalated) before {$stage}.",
                ['screening' => [self::BLOCKED]], ['stage' => $stage]);
        }
    }
}
