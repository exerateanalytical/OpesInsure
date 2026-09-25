<?php

declare(strict_types=1);

namespace App\Application\Documents\Intake;

use App\Application\Audit\AuditWriter;
use App\Application\Cases\CaseService;
use App\Application\Cases\Models\WorkCase;
use App\Application\Documents\DocumentGovernanceProblem;
use App\Application\Documents\DocumentOrigin;
use App\Application\Events\OutboxWriter;
use App\Models\Document;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * REQ-DOC-010 incoming document intake. The file itself is already a `documents` row (upload pipeline:
 * hash, malware scan); intake records the channel, classifies it against the register and stamps the
 * canonical type / origin / stage on the document. Unclassifiable items open a DOC_INTAKE_EXCEPTION case.
 */
final class DocumentIntakeService
{
    public function __construct(
        private DocumentClassifier $classifier,
        private CaseService $cases,
        private AuditWriter $audit,
        private OutboxWriter $outbox,
    ) {}

    /** @param array{document_id: string, channel: string, declared_type_code?: ?string, original_filename?: ?string, origin?: ?string, stage?: ?string, subject_type?: ?string, subject_id?: ?string} $d */
    public function receive(string $tenantId, array $d, ?User $actor): object
    {
        $doc = Document::where('tenant_id', $tenantId)->whereKey($d['document_id'])->first();
        if (! $doc) {
            throw DocumentGovernanceProblem::make('NOT_FOUND', 404, 'Document not found.');
        }
        if (! empty($d['origin']) && ! DocumentOrigin::isValid($d['origin'])) {
            throw DocumentGovernanceProblem::make('UNKNOWN_ORIGIN', 422, 'Unknown document origin.');
        }
        if (DB::table('document_intake_items')->where('document_id', $doc->id)->whereIn('status', ['RECEIVED', 'CLASSIFIED', 'EXCEPTION'])->exists()) {
            throw DocumentGovernanceProblem::make('ALREADY_RECEIVED', 409, 'Document already taken in.');
        }
        $text = is_array($doc->ocr_data) ? (string) ($doc->ocr_data['text'] ?? '') : '';
        $hit = $this->classifier->classify($d['declared_type_code'] ?? null, $d['original_filename'] ?? null, $text);
        $id = (string) Str::uuid();

        DB::transaction(function () use ($id, $tenantId, $doc, $d, $actor, $hit) {
            DB::table('document_intake_items')->insert([
                'id' => $id, 'tenant_id' => $tenantId, 'document_id' => $doc->id, 'channel' => $d['channel'],
                'original_filename' => $d['original_filename'] ?? null, 'declared_type_code' => $d['declared_type_code'] ?? null,
                'suggested_type_code' => $hit['code'] ?? null, 'suggested_confidence' => $hit['confidence'] ?? null,
                'status' => 'RECEIVED', 'subject_type' => $d['subject_type'] ?? null, 'subject_id' => $d['subject_id'] ?? null,
                'received_by' => $actor?->id, 'received_at' => now(), 'created_at' => now(), 'updated_at' => now(),
            ]);
            if (! empty($d['origin']) || ! empty($d['stage'])) {
                DB::table('documents')->where('id', $doc->id)->update(array_filter(['document_origin' => $d['origin'] ?? null, 'document_stage' => $d['stage'] ?? null]) + ['updated_at' => now()]);
            }
            $this->outbox->record('document.intake.received', 'document_intake', $id, ['document_id' => $doc->id, 'channel' => $d['channel'], 'suggested_type_code' => $hit['code'] ?? null]);
            if ($hit !== null && $hit['method'] === 'DECLARED') {
                $this->stamp($id, $hit['code'], $hit['type_id'], $actor, ! empty($d['origin']));
            } elseif ($hit === null) {
                $case = $this->cases->open($tenantId, 'DOC_INTAKE_EXCEPTION', [
                    'title' => 'Unclassified incoming document '.($d['original_filename'] ?? $doc->id),
                    'subject_type' => 'DOCUMENT', 'subject_id' => $doc->id, 'source_type' => 'document_intake', 'source_id' => $id, 'idempotency_key' => 'doc-intake:'.$id,
                ], $actor);
                DB::table('document_intake_items')->where('id', $id)->update(['status' => 'EXCEPTION', 'exception_case_id' => $case->id, 'updated_at' => now()]);
                $this->outbox->record('document.intake.exception', 'document_intake', $id, ['document_id' => $doc->id, 'case_id' => $case->id]);
            }
        });

        return DB::table('document_intake_items')->find($id);
    }

    public function classify(string $tenantId, string $itemId, string $typeCode, User $actor): object
    {
        $item = $this->item($tenantId, $itemId);
        if (! in_array($item->status, ['RECEIVED', 'EXCEPTION'], true)) {
            throw DocumentGovernanceProblem::make('NOT_OPEN', 409, 'Intake item is already '.$item->status.'.');
        }
        $t = $this->classifier->resolve($typeCode);
        if (! $t) {
            throw DocumentGovernanceProblem::make('UNKNOWN_DOCUMENT_TYPE', 422, 'Document type is not in the document register.');
        }
        DB::transaction(function () use ($item, $t, $actor) {
            $this->stamp($item->id, $t['code'], $t['id'] ?? null, $actor, false);
            $this->closeCase($item->exception_case_id, $actor, 'CLASSIFIED');
        });

        return DB::table('document_intake_items')->find($item->id);
    }

    public function reject(string $tenantId, string $itemId, string $reason, User $actor): object
    {
        $item = $this->item($tenantId, $itemId);
        if (! in_array($item->status, ['RECEIVED', 'EXCEPTION'], true)) {
            throw DocumentGovernanceProblem::make('NOT_OPEN', 409, 'Intake item is already '.$item->status.'.');
        }
        DB::transaction(function () use ($item, $reason, $actor) {
            DB::table('document_intake_items')->where('id', $item->id)->update(['status' => 'REJECTED', 'rejection_reason' => $reason, 'classified_by' => $actor->id, 'classified_at' => now(), 'updated_at' => now()]);
            $this->audit->record('document.intake.rejected', 'document_intake', $item->id, ['document_id' => $item->document_id], $reason);
            $this->closeCase($item->exception_case_id, $actor, 'REJECTED');
        });

        return DB::table('document_intake_items')->find($item->id);
    }

    private function item(string $tenantId, string $itemId): object
    {
        return DB::table('document_intake_items')->where('tenant_id', $tenantId)->where('id', $itemId)->first()
            ?? throw DocumentGovernanceProblem::make('NOT_FOUND', 404, 'Intake item not found.');
    }

    private function stamp(string $itemId, string $code, ?string $typeId, ?User $actor, bool $keepOrigin): void
    {
        $item = DB::table('document_intake_items')->find($itemId);
        $t = $this->classifier->resolve($code) ?? [];
        $origin = $t['origin'] ?? null;
        DB::table('documents')->where('id', $item->document_id)->update(array_filter([
            'document_type_code' => $code, 'document_type_id' => $typeId,
            'document_origin' => ! $keepOrigin && $origin && DocumentOrigin::isValid($origin) ? $origin : null,
            'security_level' => $t['security_level'] ?? null,
        ]) + ['updated_at' => now()]);
        DB::table('document_intake_items')->where('id', $itemId)->update([
            'status' => 'CLASSIFIED', 'classified_type_code' => $code, 'classified_type_id' => $typeId,
            'classified_by' => $actor?->id, 'classified_at' => now(), 'updated_at' => now(),
        ]);
        $this->audit->record('document.intake.classified', 'document_intake', $itemId, ['document_id' => $item->document_id, 'type_code' => $code]);
        $this->outbox->record('document.intake.classified', 'document_intake', $itemId, ['document_id' => $item->document_id, 'document_type_code' => $code, 'document_type_id' => $typeId]);
    }

    private function closeCase(?string $caseId, User $actor, string $outcome): void
    {
        if (! $caseId) {
            return;
        }
        $case = WorkCase::withoutGlobalScopes()->find($caseId);
        if (! $case || $case->closed_at !== null) {
            return;
        }
        $path = match ($case->status) {
            'OPEN' => ['start', 'resolve', 'close'],
            'WAITING_CUSTOMER', 'WAITING_THIRD_PARTY' => ['info_received', 'resolve', 'close'],
            'IN_PROGRESS', 'PENDING_DECISION' => ['resolve', 'close'],
            'RESOLVED' => ['close'],
            default => [],
        };
        foreach ($path as $event) {
            $case = $this->cases->transition($case, $event, $actor, null, ['outcome' => $outcome]);
        }
    }
}
