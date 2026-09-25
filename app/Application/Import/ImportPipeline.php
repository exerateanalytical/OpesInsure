<?php

declare(strict_types=1);

namespace App\Application\Import;

use App\Application\Approvals\ApprovalService;
use App\Application\Audit\AuditWriter;
use App\Models\ApprovalRequest;
use App\Models\Import\ImportBatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * REQ-IMP-001 (+REQ-MDM-007) — the one import pipeline:
 *   upload → map → validate → detect duplicates → preview → submit → approve (ApprovalService, maker ≠ checker) → import → audit.
 * Works for any ImportTarget (master data, vehicle generations/variants, later insurer products, tariffs, …).
 * Existing records are never overwritten; duplicate rows are skipped; nothing is deleted. The batch row keeps
 * the raw file rows, the mapping, the report and the per-row result as the permanent audit trail.
 */
final class ImportPipeline
{
    public const APPROVAL_ACTION = 'master_data.import.approve';

    public const OPEN = ['UPLOADED', 'VALIDATED', 'FAILED'];

    public function __construct(
        private readonly ImportTargetRegistry $targets,
        private readonly ImportFileReader $reader,
        private readonly ApprovalService $approvals,
        private readonly AuditWriter $audit,
    ) {}

    /** Stores the file rows and, when every required field can be mapped, maps and validates straight away. */
    public function upload(string $target, array $params, string $path, string $filename, User|string|null $actor, array $mapping = [], ?string $tenantId = null): ImportBatch
    {
        $t = $this->targets->get($target);
        $params = $t->params($params);
        $format = ImportFileReader::format($filename);
        $data = $this->reader->read($path, $format);
        $batch = ImportBatch::create([
            'tenant_id' => $tenantId, 'target' => $t->key(), 'target_params' => $params, 'format' => $format, 'filename' => basename($filename),
            'file_sha256' => hash_file('sha256', $path), 'status' => 'UPLOADED', 'source_columns' => $data['columns'], 'raw_rows' => $data['rows'],
            'created_by' => $this->id($actor),
        ]);
        $this->audit->record('import.uploaded', 'import_batch', $batch->id, ['target' => $t->key(), 'params' => $params, 'rows' => count($data['rows']),
            'format' => $format, 'filename' => $batch->filename, 'sha256' => $batch->file_sha256]);

        return $this->map($batch, $mapping);
    }

    /** Suggested mapping: each target field to the file column of the same name (case-insensitive). */
    public function autoMapping(ImportBatch $batch): array
    {
        $cols = $batch->source_columns ?? [];
        $map = [];
        foreach (array_keys($this->targets->get($batch->target)->fields()) as $field) {
            if (in_array($field, $cols, true)) {
                $map[$field] = $field;
            }
        }

        return $map;
    }

    /** Applies a column mapping (target field => file column) on top of the automatic one, then validates. */
    public function map(ImportBatch $batch, array $mapping): ImportBatch
    {
        $this->assertOpen($batch);
        $t = $this->targets->get($batch->target);
        $cols = $batch->source_columns ?? [];
        $mapping = array_map(fn ($c) => strtolower(trim((string) $c)), array_filter($mapping, fn ($c, $f) => $c !== null && $c !== '' && isset($t->fields()[$f]), ARRAY_FILTER_USE_BOTH));
        foreach ($mapping as $field => $col) {
            if (! in_array($col, $cols, true)) {
                throw ValidationException::withMessages(['mapping' => "Column \"$col\" (for $field) is not in the file."]);
            }
        }
        $mapping = $mapping + $this->autoMapping($batch);
        $missing = array_keys(array_filter($t->fields(), fn ($req, $f) => $req && ! isset($mapping[$f]), ARRAY_FILTER_USE_BOTH));
        $rows = array_map(function (array $r) use ($t, $mapping) {
            $out = [];
            foreach (array_keys($t->fields()) as $f) {
                $v = isset($mapping[$f]) ? ($r[$mapping[$f]] ?? null) : null;
                $out[$f] = $v === null ? null : (trim((string) $v) === '' ? null : trim((string) $v));
            }

            return $out;
        }, $batch->raw_rows ?? []);
        $batch->update(['mapping' => $mapping, 'rows' => $rows]);
        if ($missing) {
            $batch->update(['status' => 'UPLOADED', 'report' => ['needs_mapping' => $missing, 'valid' => 0, 'new' => [], 'errors' => [], 'duplicates' => [], 'preview' => []]]);

            return $batch;
        }

        return $this->validate($batch);
    }

    /** Validates each mapped row and flags duplicates (existing records and repeats inside the file). */
    public function validate(ImportBatch $batch): ImportBatch
    {
        $this->assertOpen($batch);
        $t = $this->targets->get($batch->target);
        $report = $this->check($t, $batch->rows ?? [], $batch->target_params ?? []);
        $status = $report['errors'] ? 'FAILED' : 'VALIDATED';
        $batch->update(['status' => $status, 'report' => $report]);
        $this->audit->record('import.validated', 'import_batch', $batch->id, ['target' => $batch->target, 'status' => $status, 'valid' => $report['valid'],
            'duplicates' => count($report['duplicates']), 'errors' => count($report['errors'])]);

        return $batch;
    }

    /** Maker submits the previewed batch for approval (approval action master_data.import.approve). */
    public function submit(ImportBatch $batch, User $maker, ?string $reason = null): ImportBatch
    {
        if ($batch->status !== 'VALIDATED') {
            throw ValidationException::withMessages(['status' => 'Only a validated import (no errors) can be submitted.']);
        }
        if (($batch->report['valid'] ?? 0) < 1) {
            throw ValidationException::withMessages(['status' => 'Nothing new to import: every row is a duplicate.']);
        }

        return DB::transaction(function () use ($batch, $maker, $reason) {
            $req = $this->approvals->open($maker, [
                'action_code' => self::APPROVAL_ACTION, 'subject_type' => 'import_batch', 'subject_id' => $batch->id,
                'source_table' => 'import_batches', 'source_id' => $batch->id, 'tenant_id' => $batch->tenant_id,
                'payload' => ['target' => $batch->target, 'params' => $batch->target_params, 'filename' => $batch->filename, 'new' => $batch->report['valid'] ?? 0,
                    'duplicates' => count($batch->report['duplicates'] ?? [])],
                'reason' => $reason ?? "Import {$batch->filename}",
            ]);
            $batch->update(['status' => 'PENDING_APPROVAL', 'approval_request_id' => $req->id]);
            $this->audit->record('import.submitted', 'import_batch', $batch->id, ['approval_id' => $req->id, 'target' => $batch->target], $reason);
            if ($this->approvals->isApproved($req)) {
                $this->run($batch, $maker);
            }

            return $batch->refresh();
        });
    }

    /** Checker decision (also reached from the generic approval inbox through ImportBatchApprovalHandler). */
    public function approve(ImportBatch $batch, User $checker, ?string $note = null): ImportBatch
    {
        $this->assertPending($batch);

        return DB::transaction(function () use ($batch, $checker, $note) {
            $req = $this->approvals->recordDecision($this->request($batch), $checker, 'APPROVED', $note);
            if ($this->approvals->isApproved($req)) {
                $this->run($batch, $checker);
            }

            return $batch->refresh();
        });
    }

    public function reject(ImportBatch $batch, User $checker, string $note): ImportBatch
    {
        $this->assertPending($batch);

        return DB::transaction(function () use ($batch, $checker, $note) {
            $this->approvals->recordDecision($this->request($batch), $checker, 'REJECTED', $note);
            $batch->update(['status' => 'REJECTED', 'approved_by' => $checker->id]);
            $this->audit->record('import.rejected', 'import_batch', $batch->id, ['target' => $batch->target], $note);

            return $batch->refresh();
        });
    }

    /** Maker withdraws an open or pending batch. */
    public function cancel(ImportBatch $batch, User $actor, string $reason = 'Cancelled'): ImportBatch
    {
        if (! in_array($batch->status, [...self::OPEN, 'PENDING_APPROVAL'], true)) {
            throw ValidationException::withMessages(['status' => "A {$batch->status} import cannot be cancelled."]);
        }

        return DB::transaction(function () use ($batch, $actor, $reason) {
            if ($batch->status === 'PENDING_APPROVAL') {
                $this->approvals->cancel($this->request($batch), $actor, $reason);
            }
            $batch->update(['status' => 'CANCELLED']);
            $this->audit->record('import.cancelled', 'import_batch', $batch->id, ['target' => $batch->target], $reason);

            return $batch->refresh();
        });
    }

    /** Imports the rows that are still new at approval time (the catalogue may have moved since the preview). */
    private function run(ImportBatch $batch, User $actor): void
    {
        $t = $this->targets->get($batch->target);
        $params = $batch->target_params ?? [];
        $seen = [];
        $result = ['created' => [], 'skipped' => []];
        foreach ($batch->rows ?? [] as $i => $row) {
            $c = $t->check($row, $params, $seen);
            if ($c['status'] !== 'NEW') {
                $result['skipped'][] = ['row' => $i + 1, 'status' => $c['status'], 'reason' => $c['error'] ?? ($c['matches'] ?? null)];
                continue;
            }
            try {
                $id = $t->import($row, $params, $actor, $batch->id);
            } catch (ValidationException $e) {
                throw ValidationException::withMessages(['row' => 'Row '.($i + 1).': '.collect($e->errors())->flatten()->first()]);
            }
            $result['created'][] = ['row' => $i + 1, 'key' => $c['key'] ?? null, 'id' => $id];
        }
        $t->finish($params);
        $batch->update(['status' => 'IMPORTED', 'approved_by' => $actor->id, 'imported_at' => now(), 'imported_count' => count($result['created']), 'result' => $result]);
        $this->audit->record('import.imported', 'import_batch', $batch->id, ['target' => $batch->target, 'params' => $params, 'created' => count($result['created']),
            'skipped' => count($result['skipped']), 'approval_id' => $batch->approval_request_id], null, ['approval_id' => $batch->approval_request_id]);
    }

    /** @return array{valid: int, new: list<string>, errors: list<array>, duplicates: list<array>, preview: list<array>} */
    private function check(ImportTarget $t, array $rows, array $params): array
    {
        $report = ['valid' => 0, 'new' => [], 'errors' => [], 'duplicates' => [], 'preview' => []];
        $seen = [];
        foreach ($rows as $i => $row) {
            try {
                $c = $t->check($row, $params, $seen);
            } catch (Throwable $e) {
                $c = ['status' => 'ERROR', 'error' => $e instanceof ValidationException ? (string) collect($e->errors())->flatten()->first() : $e->getMessage()];
            }
            $line = $i + 1;
            match ($c['status']) {
                'ERROR' => $report['errors'][] = ['row' => $line, 'error' => $c['error'] ?? 'Invalid row'],
                'DUPLICATE' => $report['duplicates'][] = ['row' => $line, 'code' => $c['key'] ?? null, 'matches' => $c['matches'] ?? null],
                default => null,
            };
            if ($c['status'] === 'NEW') {
                $report['valid']++;
                $report['new'][] = (string) ($c['key'] ?? $line);
                if (count($report['preview']) < 25) {
                    $report['preview'][] = ['row' => $line] + array_filter($row, fn ($v) => $v !== null);
                }
            }
        }

        return $report;
    }

    private function request(ImportBatch $batch): ApprovalRequest
    {
        return ApprovalRequest::findOrFail($batch->approval_request_id);
    }

    private function assertOpen(ImportBatch $batch): void
    {
        if (! in_array($batch->status, self::OPEN, true)) {
            throw ValidationException::withMessages(['status' => "A {$batch->status} import can no longer be changed."]);
        }
    }

    private function assertPending(ImportBatch $batch): void
    {
        if ($batch->status !== 'PENDING_APPROVAL' || ! $batch->approval_request_id) {
            throw ValidationException::withMessages(['status' => 'This import is not waiting for approval.']);
        }
    }

    private function id(User|string|null $actor): ?string
    {
        return $actor instanceof User ? $actor->id : $actor;
    }
}
