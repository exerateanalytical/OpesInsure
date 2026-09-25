<?php

declare(strict_types=1);

namespace App\Application\Documents\Retention;

use App\Application\Audit\AuditWriter;
use App\Application\Cases\CaseService;
use App\Application\Cases\Models\WorkCase;
use App\Application\Documents\DocumentGovernanceProblem;
use App\Application\Events\OutboxWriter;
use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * REQ-DOC-009 destruction through the case engine (case type DOCUMENT_DESTRUCTION):
 *  request  -> retention period elapsed + no legal hold; opens the case.
 *  decide   -> a second user approves/rejects (case decision, append-only). Approval re-checks legal holds
 *              (INV-5.5: hold overrides destruction -> BLOCKED) and then destroys: stored file deleted,
 *              extracted data purged, the documents row kept as a tombstone (id, hash, destroyed_at/by).
 */
final class DocumentDestructionService
{
    public function __construct(
        private CaseService $cases,
        private LegalHoldService $holds,
        private RetentionScheduleService $schedules,
        private AuditWriter $audit,
        private OutboxWriter $outbox,
    ) {}

    public function request(string $tenantId, string $documentId, string $reason, User $actor): object
    {
        $doc = Document::where('tenant_id', $tenantId)->whereKey($documentId)->first();
        if (! $doc) {
            throw DocumentGovernanceProblem::make('NOT_FOUND', 404, 'Document not found.');
        }
        if (DB::table('documents')->where('id', $doc->id)->value('destroyed_at') !== null) {
            throw DocumentGovernanceProblem::make('ALREADY_DESTROYED', 409, 'Document already destroyed.');
        }
        if ($this->holds->isHeld($doc)) {
            throw DocumentGovernanceProblem::make('LEGAL_HOLD', 409, 'Document is under legal hold and cannot be destroyed.');
        }
        $from = $this->schedules->disposableFrom($doc);
        if ($from === null || $from->isFuture()) {
            throw DocumentGovernanceProblem::make('RETENTION_NOT_ELAPSED', 409, $from === null ? 'No active retention schedule covers this document.' : 'Retention period runs until '.$from->toDateString().'.');
        }
        if (DB::table('document_destruction_requests')->where('document_id', $doc->id)->whereIn('status', ['PENDING', 'APPROVED'])->exists()) {
            throw DocumentGovernanceProblem::make('ALREADY_REQUESTED', 409, 'A destruction request is already open for this document.');
        }
        $schedule = $this->schedules->scheduleFor($doc);

        return DB::transaction(function () use ($tenantId, $doc, $reason, $actor, $schedule) {
            $id = (string) Str::uuid();
            $case = $this->cases->open($tenantId, 'DOCUMENT_DESTRUCTION', [
                'title' => 'Destroy document '.($doc->document_number ?? $doc->id), 'subject_type' => 'DOCUMENT', 'subject_id' => $doc->id,
                'source_type' => 'document_destruction_request', 'source_id' => $id, 'idempotency_key' => 'doc-destruction:'.$id,
            ], $actor);
            DB::table('document_destruction_requests')->insert([
                'id' => $id, 'tenant_id' => $tenantId, 'document_id' => $doc->id, 'case_id' => $case->id, 'retention_schedule_id' => $schedule?->id,
                'status' => 'PENDING', 'reason' => $reason, 'requested_by' => $actor->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
            $this->audit->record('document.destruction.requested', 'document', $doc->id, ['request_id' => $id, 'case_id' => $case->id], $reason);
            $this->outbox->record('document.destruction.requested', 'document', $doc->id, ['request_id' => $id, 'case_id' => $case->id]);

            return DB::table('document_destruction_requests')->find($id);
        });
    }

    public function decide(string $tenantId, string $requestId, bool $approve, string $note, User $actor): object
    {
        $req = DB::table('document_destruction_requests')->where('tenant_id', $tenantId)->where('id', $requestId)->first();
        if (! $req) {
            throw DocumentGovernanceProblem::make('NOT_FOUND', 404, 'Destruction request not found.');
        }
        if ($req->status !== 'PENDING') {
            throw DocumentGovernanceProblem::make('NOT_PENDING', 409, 'Destruction request already decided.');
        }
        if ($req->requested_by === $actor->id) {
            throw DocumentGovernanceProblem::make('MAKER_CHECKER', 403, 'The requester cannot decide their own destruction request.');
        }
        $doc = Document::findOrFail($req->document_id);
        $held = $approve && $this->holds->isHeld($doc);
        $status = ! $approve ? 'REJECTED' : ($held ? 'BLOCKED' : 'DESTROYED');

        DB::transaction(function () use ($req, $doc, $status, $note, $actor) {
            $case = WorkCase::withoutGlobalScopes()->findOrFail($req->case_id);
            $this->cases->transition($case, 'start', $actor);
            $this->cases->decide($case, ['decision_type' => 'DOCUMENT_DESTRUCTION', 'outcome' => $status === 'DESTROYED' ? 'APPROVED' : $status, 'rationale' => $note], $actor);
            $this->cases->transition($case, 'submit_for_decision', $actor);
            $this->cases->transition($case, 'resolve', $actor);
            if ($status === 'DESTROYED') {
                $this->destroy($doc, $actor);
            }
            DB::table('document_destruction_requests')->where('id', $req->id)->update([
                'status' => $status, 'decided_by' => $actor->id, 'decided_at' => now(), 'decision_note' => $note,
                'executed_at' => $status === 'DESTROYED' ? now() : null, 'updated_at' => now(),
            ]);
            $this->cases->transition(WorkCase::withoutGlobalScopes()->findOrFail($req->case_id), 'close', $actor, null, ['outcome' => $status]);
            $this->audit->record('document.destruction.decided', 'document', $doc->id, ['request_id' => $req->id, 'status' => $status], $note);
            $this->outbox->record('document.destruction.decided', 'document', $doc->id, ['request_id' => $req->id, 'status' => $status]);
        });

        return DB::table('document_destruction_requests')->find($req->id);
    }

    private function destroy(Document $doc, User $actor): void
    {
        rescue(fn () => Storage::disk(config('filesystems.default'))->delete($doc->storage_key), null, false);
        DB::table('documents')->where('id', $doc->id)->update(['destroyed_at' => now(), 'destroyed_by' => $actor->id, 'ocr_data' => '{}', 'updated_at' => now()]);
        $this->outbox->record('document.destroyed', 'document', $doc->id, ['sha256' => $doc->sha256, 'document_type_code' => $doc->document_type_code]);
    }
}
