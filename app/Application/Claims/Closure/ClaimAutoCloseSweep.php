<?php

declare(strict_types=1);

namespace App\Application\Claims\Closure;

use App\Domain\Tenancy\TenantContext;
use App\Models\Claim;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * REQ-CLM-014 auto-close sweep (scheduled daily, `claims:auto-close`): a settled (PAID) claim with no
 * claim_events activity for $days days is closed with reason AUTO_INACTIVE_SETTLED, but only when the
 * full closure checklist passes — anything outstanding leaves the claim open for a handler.
 * Acts as the first active SYSTEM_ADMIN/PLATFORM_ADMIN of the tenant (the lifecycle needs an actor).
 */
final class ClaimAutoCloseSweep
{
    public const SETTLED_STATUSES = ['PAID', 'SETTLED'];

    public function __construct(private ClaimClosureService $closure, private ClaimClosureChecklist $checklist, private TenantContext $context) {}

    /** @return array{evaluated:int,closed:int,blocked:int,skipped:int} */
    public function run(int $days = 30): array
    {
        $cutoff = now()->subDays($days);
        $s = ['evaluated' => 0, 'closed' => 0, 'blocked' => 0, 'skipped' => 0];
        $claims = Claim::whereIn('status', self::SETTLED_STATUSES)->where('updated_at', '<=', $cutoff)
            ->whereNotExists(fn ($q) => $q->from('claim_events')->whereColumn('claim_events.claim_id', 'claims.id')->where('occurred_at', '>', $cutoff))
            ->orderBy('tenant_id')->get();
        foreach ($claims->groupBy('tenant_id') as $tenantId => $group) {
            $actor = $this->systemActor((string) $tenantId);
            $this->context->set((string) $tenantId);
            try {
                foreach ($group as $claim) {
                    $s['evaluated']++;
                    if (! $actor) {
                        $s['skipped']++;

                        continue;
                    }
                    if ($this->checklist->firstFailure($claim, 'AUTO_INACTIVE_SETTLED') !== null) {
                        $s['blocked']++;

                        continue;
                    }
                    try {
                        $this->closure->close($claim, 'AUTO_INACTIVE_SETTLED', "Auto-closed after {$days} days of inactivity.", $actor, true);
                        $s['closed']++;
                    } catch (Throwable) {
                        $s['blocked']++;
                    }
                }
            } finally {
                $this->context->clear();
            }
        }

        return $s;
    }

    private function systemActor(string $tenantId): ?User
    {
        return User::where('status', 'ACTIVE')
            ->whereHas('memberships', fn ($q) => $q->where('tenant_id', $tenantId)->where('status', 'ACTIVE')->whereIn('role_code', ['SYSTEM_ADMIN', 'PLATFORM_ADMIN']))
            ->orderBy('created_at')->first();
    }
}
