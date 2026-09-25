<?php

declare(strict_types=1);

namespace App\Application\DataReadiness;

/**
 * Central production-use guard (Workflow Data Master rule: PENDING_SOURCE, CONFIG_REQUIRED, UNVERIFIED and DEMO_ONLY
 * are not production values). Production code paths — rating charges (ChargeTableService::assertUsable), insurer
 * authorizations (CimaAuthorizationService::isAuthorized), SLA targets, KYC requirements and regulatory reports —
 * call it before using a stored value as a production value.
 *
 * Enforcement follows the existing rule of ChargeTableService (owner decision 10): refused on a real production host
 * (APP_ENV=production with demo mode OFF). Elsewhere — and in production while demo mode is ON — the value may be
 * used but inspect() reports it so the result is visibly non-production. config('data_readiness.enforce') (bool)
 * overrides the environment decision (tests, staging drills).
 */
final class ProductionUseGuard
{
    public static function enforced(): bool
    {
        $forced = config('data_readiness.enforce');
        if ($forced !== null) {
            return (bool) $forced;
        }

        return app()->isProduction() && ! (bool) config('demo.enabled');
    }

    /** @return array{domain: string, subject: string, stored_status: ?string, status: string, production_usable: bool, enforced: bool} */
    public static function inspect(string $domain, ?string $storedStatus, string $subject = ''): array
    {
        $status = DataStatus::normalize($storedStatus);

        return ['domain' => $domain, 'subject' => $subject, 'stored_status' => $storedStatus, 'status' => $status,
            'production_usable' => in_array($status, DataStatus::PRODUCTION, true), 'enforced' => self::enforced()];
    }

    public static function allows(?string $storedStatus): bool
    {
        return DataStatus::isProduction($storedStatus) || ! self::enforced();
    }

    /** @throws NonProductionDataException when enforced and the value is not VERIFIED / PLATFORM_NORMALIZED */
    public static function assertUsable(string $domain, ?string $storedStatus, string $subject = '', ?string $hint = null): void
    {
        if (self::allows($storedStatus)) {
            return;
        }
        $status = DataStatus::normalize($storedStatus);

        throw new NonProductionDataException($domain, $status, trim("{$domain} {$subject} is {$status}".($storedStatus && $storedStatus !== $status ? " (stored {$storedStatus})" : '')
            .': it cannot be used as a production value.'.($hint ? ' '.$hint : '')));
    }

    /** Filters a list of rows down to production-usable ones when enforced (e.g. SLA targets, KYC requirement rows). */
    public static function usableRows(iterable $rows, string $statusField): array
    {
        $out = [];
        foreach ($rows as $r) {
            $v = is_array($r) ? ($r[$statusField] ?? null) : ($r->{$statusField} ?? null);
            if (self::allows($v)) {
                $out[] = $r;
            }
        }

        return $out;
    }
}
