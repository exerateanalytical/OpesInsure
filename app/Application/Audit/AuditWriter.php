<?php

declare(strict_types=1);

namespace App\Application\Audit;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AuditWriter
{
    public function record(string $action, string $subjectType, ?string $subjectId, array $metadata = [], ?string $reason = null): void
    {
        $previous = DB::table('audit_log')->orderByDesc('sequence')->value('entry_hash');
        $id = (string) Str::uuid();
        $payload = json_encode([$id, $action, $subjectType, $subjectId, $metadata, $previous], JSON_THROW_ON_ERROR);
        DB::table('audit_log')->insert([
            'id' => $id, 'tenant_id' => app()->bound(\App\Domain\Tenancy\TenantContext::class) ? rescue(fn () => app(\App\Domain\Tenancy\TenantContext::class)->id(), null, false) : null,
            'actor_id' => auth()->id(), 'action' => $action, 'subject_type' => $subjectType, 'subject_id' => $subjectId, 'reason_code' => $reason,
            'metadata' => json_encode($metadata), 'correlation_id' => request()->header('X-Request-Id', (string) Str::uuid()), 'previous_hash' => $previous,
            'entry_hash' => hash('sha256', $payload), 'created_at' => now(),
        ]);
    }
}
