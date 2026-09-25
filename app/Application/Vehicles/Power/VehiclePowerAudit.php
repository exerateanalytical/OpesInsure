<?php

declare(strict_types=1);

namespace App\Application\Vehicles\Power;

use App\Application\Audit\AuditWriter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Append-only verification trail (vehicle_power_verification_audits) mirrored into the platform audit log. */
final class VehiclePowerAudit
{
    public function __construct(private readonly AuditWriter $audit) {}

    public function record(string $subjectType, string $subjectId, string $action, ?string $from, ?string $to, ?string $actorId, array $payload = [], ?string $reason = null): void
    {
        DB::table('vehicle_power_verification_audits')->insert(['id' => (string) Str::uuid(), 'subject_type' => $subjectType, 'subject_id' => $subjectId,
            'action' => $action, 'from_state' => $from, 'to_state' => $to, 'actor_id' => $actorId, 'payload' => json_encode($payload, JSON_THROW_ON_ERROR), 'created_at' => now()]);
        $this->audit->record('vehicle_power.'.$action, $subjectType, $subjectId, ['from' => $from, 'to' => $to] + $payload, $reason);
    }
}
