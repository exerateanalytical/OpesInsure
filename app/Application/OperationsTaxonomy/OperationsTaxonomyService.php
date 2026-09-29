<?php

declare(strict_types=1);

namespace App\Application\OperationsTaxonomy;

use App\Application\Audit\AuditWriter;
use App\Application\Cases\Models\WorkQueue;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Gap Closure Pack file 10 — queue typing and platform notification-template approval, shared by the API
 * (OperationsTaxonomyController, routes/operations_gap_closure.php) and the staff desktop.
 */
final class OperationsTaxonomyService
{
    public function __construct(private readonly AuditWriter $audit) {}

    public function setQueueType(string $tenantId, string $queueId, string $queueType): WorkQueue
    {
        OperationsCatalogue::assert('queue_types', $queueType, 'queue_type');
        $q = WorkQueue::where('tenant_id', $tenantId)->findOrFail($queueId);
        $q->update(['queue_type' => $queueType]);
        $this->audit->record('case.queue.typed', 'queue', $q->id, ['queue_type' => $queueType]);

        return $q->refresh();
    }

    /** Platform (tenant_id NULL) event templates are seeded DRAFT: an administrator other than the author approves each. */
    public function approvePlatformTemplate(string $templateId, User $actor): object
    {
        return DB::transaction(function () use ($templateId, $actor) {
            $t = DB::table('notification_templates')->where('id', $templateId)->whereNull('tenant_id')->whereNotNull('event_code')->lockForUpdate()->first();
            abort_unless($t, 404);
            if ($t->status !== 'DRAFT') {
                throw ValidationException::withMessages(['status' => 'Only a DRAFT template can be approved.']);
            }
            if (($t->created_by ?? null) !== null && $t->created_by === $actor->id) {
                throw ValidationException::withMessages(['actor' => 'The author of a template cannot approve it.']);
            }
            DB::table('notification_templates')->whereNull('tenant_id')->where(['code' => $t->code, 'locale' => $t->locale, 'channel' => $t->channel, 'status' => 'ACTIVE'])
                ->update(['status' => 'RETIRED', 'updated_at' => now()]);
            DB::table('notification_templates')->where('id', $t->id)->update(['status' => 'ACTIVE', 'approved_by' => $actor->id, 'approved_at' => now(), 'updated_at' => now()]);
            $this->audit->record('notification.template.approved', 'notification_template', $t->id, ['event_code' => $t->event_code, 'version' => $t->version]);

            return DB::table('notification_templates')->find($t->id);
        });
    }
}
