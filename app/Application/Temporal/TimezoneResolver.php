<?php

declare(strict_types=1);

namespace App\Application\Temporal;

use App\Application\Settings\PlatformSettings;
use App\Domain\Tenancy\TenantContext;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * REQ-TMP-003 — which IANA timezone governs business dates.
 *
 *   branch.timezone  >  tenant.timezone  >  platform default_timezone  >  config('app.timezone')  >  Africa/Douala
 *
 * A NULL at any level means "inherit". The user display timezone is a
 * presentation preference only: it never changes a business date.
 * Storage stays UTC instants in timestamptz (see OffsetAwarePostgresConnection).
 */
final class TimezoneResolver
{
    /** @var array<string, ?string> */
    private array $tenantCache = [];

    /** @var array<string, array{tenant_id: string, timezone: ?string}|null> */
    private array $branchCache = [];

    public function __construct(private readonly TenantContext $context, private readonly PlatformSettings $settings)
    {
    }

    public function platformDefault(): string
    {
        return self::valid($this->settings->get('default_timezone'))
            ?? self::valid((string) config('app.timezone'))
            ?? BusinessTime::DEFAULT_TIMEZONE;
    }

    public function forTenant(?string $tenantId): string
    {
        return $this->tenantOwn($tenantId) ?? $this->platformDefault();
    }

    /** Branch overrides tenant; the branch's own tenant is used when $tenantId is not given. */
    public function forBranch(?string $branchId, ?string $tenantId = null): string
    {
        $branch = $this->branch($branchId);
        if ($branch !== null && ($tz = self::valid($branch['timezone'])) !== null) {
            return $tz;
        }

        return $this->forTenant($tenantId ?? $branch['tenant_id'] ?? null);
    }

    /** Business timezone of the current request (tenant context when set). */
    public function current(?string $branchId = null): string
    {
        $tenantId = null;
        try {
            $tenantId = $this->context->id();
        } catch (\LogicException) {
        }

        return $branchId !== null ? $this->forBranch($branchId, $tenantId) : $this->forTenant($tenantId);
    }

    /** Display timezone: user preference, else the business timezone. */
    public function forUser(?object $user, ?string $tenantId = null, ?string $branchId = null): string
    {
        $pref = $user !== null ? self::valid($user->display_timezone ?? null) : null;

        return $pref ?? ($branchId !== null ? $this->forBranch($branchId, $tenantId) : ($tenantId !== null ? $this->forTenant($tenantId) : $this->current()));
    }

    /** @return array{timezone: string, source: string, platform: string, tenant: ?string, branch: ?string} */
    public function explain(?string $tenantId, ?string $branchId = null): array
    {
        $branch = self::valid($this->branch($branchId)['timezone'] ?? null);
        $tenant = $this->tenantOwn($tenantId);
        $platform = $this->platformDefault();

        return [
            'timezone' => $branch ?? $tenant ?? $platform,
            'source' => $branch !== null ? 'BRANCH' : ($tenant !== null ? 'TENANT' : 'PLATFORM'),
            'platform' => $platform,
            'tenant' => $tenant,
            'branch' => $branch,
        ];
    }

    public function forget(): void
    {
        $this->tenantCache = [];
        $this->branchCache = [];
    }

    public static function valid(mixed $tz): ?string
    {
        if (! is_string($tz) || $tz === '') {
            return null;
        }
        try {
            new DateTimeZone($tz);
        } catch (Throwable) {
            return null;
        }

        return $tz;
    }

    private function tenantOwn(?string $tenantId): ?string
    {
        if ($tenantId === null) {
            return null;
        }
        if (! array_key_exists($tenantId, $this->tenantCache)) {
            try {
                $this->tenantCache[$tenantId] = DB::table('tenants')->where('id', $tenantId)->value('timezone');
            } catch (Throwable) {
                $this->tenantCache[$tenantId] = null;
            }
        }

        return self::valid($this->tenantCache[$tenantId]);
    }

    /** @return array{tenant_id: string, timezone: ?string}|null */
    private function branch(?string $branchId): ?array
    {
        if ($branchId === null) {
            return null;
        }
        if (! array_key_exists($branchId, $this->branchCache)) {
            $row = DB::table('tenant_branches')->where('id', $branchId)->first(['tenant_id', 'timezone']);
            $this->branchCache[$branchId] = $row ? ['tenant_id' => (string) $row->tenant_id, 'timezone' => $row->timezone] : null;
        }

        return $this->branchCache[$branchId];
    }
}
