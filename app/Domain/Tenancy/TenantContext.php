<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

final class TenantContext
{
    private ?string $tenantId = null;
    public function set(string $tenantId): void { $this->tenantId = $tenantId; }
    public function id(): string { return $this->tenantId ?? throw new \LogicException('Tenant context is missing.'); }
    public function clear(): void { $this->tenantId = null; }
}
