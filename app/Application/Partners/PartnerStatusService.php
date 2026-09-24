<?php

declare(strict_types=1);

namespace App\Application\Partners;

use App\Application\Audit\AuditWriter;
use App\Models\Partner;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Manual partner status change by an administrator (activate a PENDING
 * partner, suspend, reject). Records partner_status_history exactly like
 * LicensingService::decide() does for the automatic path.
 */
final class PartnerStatusService
{
    public function __construct(private readonly AuditWriter $audit)
    {
    }

    public function transition(Partner $partner, string $to, string $notes, User $actor): Partner
    {
        return DB::transaction(function () use ($partner, $to, $notes, $actor) {
            $from = $partner->status;
            if ($from === $to) {
                return $partner;
            }
            $partner->update(['status' => $to]);
            DB::table('partner_status_history')->insert(['id' => (string) Str::uuid(), 'partner_id' => $partner->id, 'from_status' => $from, 'to_status' => $to, 'reason_code' => 'ADMIN_'.$to, 'notes' => $notes, 'actor_id' => $actor->id, 'occurred_at' => now()]);
            $this->audit->record('partner.status.changed', 'partner', $partner->id, ['from' => $from, 'to' => $to]);

            return $partner->refresh();
        });
    }
}
