<?php

declare(strict_types=1);

namespace App\Application\Audit;

use Illuminate\Support\Facades\DB;

/**
 * REQ-AUD-001: walks audit_log in sequence order and proves the hash chain is intact.
 * v1 rows (legacy writer, non-canonical payload) are verified for linkage only;
 * v2 rows are additionally recomputed from their stored content.
 */
final class AuditChainVerifier
{
    /** @return array{ok: bool, checked: int, first_break: ?array{sequence: int, id: string, problem: string}} */
    public function verify(int $chunk = 1000): array
    {
        $previous = null;
        $checked = 0;
        $break = null;

        DB::table('audit_log')->orderBy('sequence')->chunk($chunk, function ($rows) use (&$previous, &$checked, &$break) {
            foreach ($rows as $r) {
                $checked++;
                if ($r->previous_hash !== $previous) {
                    $break = ['sequence' => (int) $r->sequence, 'id' => $r->id, 'problem' => 'previous_hash does not match prior entry_hash'];

                    return false;
                }
                if ((int) ($r->hash_version ?? 1) >= 2) {
                    $expected = AuditWriter::hashV2([
                        'id' => $r->id, 'tenant_id' => $r->tenant_id, 'branch_id' => $r->branch_id, 'actor_id' => $r->actor_id,
                        'action' => $r->action, 'subject_type' => $r->subject_type, 'subject_id' => $r->subject_id, 'reason_code' => $r->reason_code,
                        'old_values' => $r->old_values === null ? null : json_decode($r->old_values, true),
                        'new_values' => $r->new_values === null ? null : json_decode($r->new_values, true),
                        'metadata' => json_decode($r->metadata ?? '{}', true), 'source' => $r->source, 'approval_id' => $r->approval_id,
                        'previous_hash' => $r->previous_hash,
                    ]);
                    if (! hash_equals($expected, (string) $r->entry_hash)) {
                        $break = ['sequence' => (int) $r->sequence, 'id' => $r->id, 'problem' => 'entry_hash does not match content'];

                        return false;
                    }
                }
                $previous = $r->entry_hash;
            }
        });

        return ['ok' => $break === null, 'checked' => $checked, 'first_break' => $break];
    }
}
