<?php

declare(strict_types=1);

namespace App\Application\Documents\Security;

use App\Application\Documents\Engine\DocumentRegister;
use Throwable;

/**
 * Read-only view of DOCUMENT_ENFORCE_CONTROLS readiness for the admin security screens: is enforcement on, and which
 * configuration-dependent §9 gate steps (6 maker-checker, 10 seal / signature authority, 11 secure stock serials)
 * would refuse issuance of a representative S3+ document if it were switched on. Evaluated with the real
 * DocumentSecurityProfile + IssuanceGate; steps 1–5 depend on each document's own source and are not shown.
 * Changes nothing.
 */
final class EnforcementReadiness
{
    /** Representative documents: the motor attestation (SEAL-01, S3+) and the policy schedule. */
    public const SAMPLE_TYPES = ['MOTOR_INSURANCE_ATTESTATION', 'POLICY_SCHEDULE'];

    public const CONFIG_STEPS = [6, 10, 11];

    /** @return array{enforced: bool, types: list<array{code: string, tier: ?string, steps: list<array<string, mixed>>, would_refuse: list<string>}>} */
    public static function summary(): array
    {
        $types = [];
        foreach (self::SAMPLE_TYPES as $code) {
            try {
                $type = app(DocumentRegister::class)->describe($code);
                $security = app(DocumentSecurityProfile::class)->resolve($type);
                $gate = IssuanceGate::evaluate(null, null, $security, ['issuer' => 'INSURER', 'issuer_authorized' => true, 'missing_fields' => [], 'field_enforcement' => 'block']);
            } catch (Throwable) {
                continue;
            }
            $steps = array_values(array_filter($gate['steps'], fn ($s) => in_array($s['step'], self::CONFIG_STEPS, true)));
            $types[] = ['code' => $code, 'tier' => $security['tier'] ?? null, 'steps' => $steps,
                'would_refuse' => IssuanceGate::refusals(['steps' => $steps], true)];
        }

        return ['enforced' => (bool) config('document_security.enforce_controls'), 'types' => $types];
    }
}
