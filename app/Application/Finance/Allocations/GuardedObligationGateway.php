<?php

declare(strict_types=1);

namespace App\Application\Finance\Allocations;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Default ObligationGateway: talks to App\Application\Finance\Obligations\ObligationService
 * (agent 9-1) only when it exists; otherwise obligations are not available and allocation
 * runs against premium components alone.
 */
final class GuardedObligationGateway implements ObligationGateway
{
    public const SERVICE = 'App\\Application\\Finance\\Obligations\\ObligationService';

    public static function available(): bool
    {
        return class_exists(self::SERVICE) && Schema::hasTable('financial_obligations');
    }

    public function openReceivables(string $tenantId, array $ids, ?string $policyId): array
    {
        if (! self::available() || ($ids === [] && $policyId === null)) {
            return [];
        }
        $q = DB::table('financial_obligations')->where('tenant_id', $tenantId)->where('kind', 'RECEIVABLE')
            ->whereIn('status', ['OPEN', 'PARTIALLY_SETTLED'])->where('outstanding_minor', '>', 0);
        if ($ids !== []) {
            $q->whereIn('id', array_values(array_filter($ids, fn ($i) => Str::isUuid($i))));
        } else {
            $q->where('source_type', 'policy')->where('source_id', $policyId);
        }

        return $q->get(['id', 'type', 'currency', 'outstanding_minor', 'due_at'])
            ->map(fn ($o) => ['id' => $o->id, 'type' => (string) $o->type, 'currency' => $o->currency, 'outstanding_minor' => (int) $o->outstanding_minor, 'due_at' => $o->due_at])
            ->all();
    }

    public function settle(string $obligationId, int $amountMinor, string $reference): void
    {
        if (! class_exists(self::SERVICE)) {
            return;
        }
        $svc = app(self::SERVICE);
        if ($amountMinor < 0) {
            if (method_exists($svc, 'unsettle')) {
                $svc->unsettle($obligationId, -$amountMinor, $reference);
            }

            return;
        }
        $svc->settle($obligationId, $amountMinor, $reference);
    }
}
