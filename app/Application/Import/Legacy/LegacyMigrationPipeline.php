<?php

declare(strict_types=1);

namespace App\Application\Import\Legacy;

use App\Application\Approvals\ApprovalService;
use App\Application\Audit\AuditWriter;
use App\Application\Events\OutboxWriter;
use App\Application\Import\ImportFileReader;
use App\Application\Import\Legacy\Adapters\ClaimAdapter;
use App\Application\Import\Legacy\Adapters\CommissionBalanceAdapter;
use App\Application\Import\Legacy\Adapters\CustomerAdapter;
use App\Application\Import\Legacy\Adapters\PolicyAdapter;
use App\Application\Import\Legacy\Adapters\PremiumAdapter;
use App\Models\ApprovalRequest;
use App\Models\ExternalRecordMapping;
use App\Models\Import\ImportBatch;
use App\Models\IntegrationClient;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * REQ-IMP-002 legacy integration & data migration (BP W26, MPS §92). Extends the REQ-IMP-001 import batches
 * (pipeline = LEGACY) with the migration stages:
 *
 *   stage (upload + map) → validate → dry-run → reconcile → submit / approve (maker-checker, legacy_migration.commit)
 *   → commit → post-commit reconciliation;  rollback discards any batch that is not committed.
 *
 * - One adapter per legacy entity (customers, policies, outstanding premiums, claims + reserves, commission balances)
 *   creates records through the owning domain services.
 * - Idempotent by legacy id: every migrated record gets an external_record_mappings row under the legacy source's
 *   integration client; a legacy id already mapped is skipped (ALREADY_MIGRATED) — in the same or any later batch.
 * - The dry run executes the real adapters inside a transaction that is always rolled back.
 * - Commit is one transaction (all rows or none), so an uncommitted batch never leaves partial data behind.
 * - Balances (premiums, claim reserves, commissions) post on migration.opening_balance only when that accounting
 *   event is configured; otherwise the row is reported CONFIG_REQUIRED and nothing is posted.
 */
final class LegacyMigrationPipeline
{
    public const PIPELINE = 'LEGACY';

    public const APPROVAL_ACTION = 'legacy_migration.commit';

    public const ADAPTERS = [
        'legacy.customers' => CustomerAdapter::class,
        'legacy.policies' => PolicyAdapter::class,
        'legacy.premiums' => PremiumAdapter::class,
        'legacy.claims' => ClaimAdapter::class,
        'legacy.commission_balances' => CommissionBalanceAdapter::class,
    ];

    /** Batches in these states may be re-mapped / re-validated / dry-run again. */
    public const OPEN = ['UPLOADED', 'VALIDATED', 'FAILED', 'DRY_RUN_OK', 'DRY_RUN_FAILED', 'RECONCILED', 'UNRECONCILED'];

    public const FINAL = ['COMMITTED', 'ROLLED_BACK', 'REJECTED'];

    public function __construct(
        private readonly ImportFileReader $reader,
        private readonly ApprovalService $approvals,
        private readonly OpeningBalancePoster $balances,
        private readonly AuditWriter $audit,
        private readonly OutboxWriter $outbox,
    ) {}

    public function adapter(string $key): LegacyAdapter
    {
        if (! isset(self::ADAPTERS[$key])) {
            throw ValidationException::withMessages(['entity' => "Unknown legacy entity {$key}."]);
        }

        return app(self::ADAPTERS[$key]);
    }

    /** @return array<string, string> key => label */
    public function options(): array
    {
        return collect(self::ADAPTERS)->mapWithKeys(fn ($c, $k) => [$k => $this->adapter($k)->label()])->all();
    }

    /**
     * Stages a legacy extract: stores the raw rows (audit trail), the declared control totals, maps and validates.
     *
     * @param  array{count?: int, amount_minor?: int}|null  $controlTotals  totals the legacy system reports for the extract
     */
    public function stage(string $entity, string $sourceClientId, string $path, string $filename, User $maker, string $tenantId, array $mapping = [], ?array $controlTotals = null): ImportBatch
    {
        $a = $this->adapter($entity);
        $source = IntegrationClient::findOrFail($sourceClientId);
        $format = ImportFileReader::format($filename);
        $data = $this->reader->read($path, $format);
        $batch = ImportBatch::create([
            'tenant_id' => $tenantId, 'pipeline' => self::PIPELINE, 'source_system' => mb_substr($source->name, 0, 64), 'target' => $a->key(),
            'target_params' => ['integration_client_id' => $source->id], 'format' => $format, 'filename' => basename($filename), 'file_sha256' => hash_file('sha256', $path),
            'status' => 'UPLOADED', 'source_columns' => $data['columns'], 'raw_rows' => $data['rows'], 'created_by' => $maker->id,
            'control_totals' => $controlTotals === null ? null : array_map('intval', array_intersect_key($controlTotals, ['count' => 1, 'amount_minor' => 1])),
        ]);
        $this->audit->record('legacy_migration.staged', 'import_batch', $batch->id, ['entity' => $a->key(), 'source' => $source->name, 'rows' => count($data['rows']),
            'sha256' => $batch->file_sha256, 'control_totals' => $batch->control_totals]);

        return $this->map($batch, $mapping);
    }

    /** Column mapping (entity field => file column) on top of same-name auto-mapping, then validation. */
    public function map(ImportBatch $batch, array $mapping): ImportBatch
    {
        $this->assertOpen($batch);
        $a = $this->adapter($batch->target);
        $cols = $batch->source_columns ?? [];
        $mapping = array_map(fn ($c) => strtolower(trim((string) $c)), array_filter($mapping, fn ($c, $f) => $c !== null && $c !== '' && isset($a->fields()[$f]), ARRAY_FILTER_USE_BOTH));
        foreach ($mapping as $field => $col) {
            if (! in_array($col, $cols, true)) {
                throw ValidationException::withMessages(['mapping' => "Column \"$col\" (for $field) is not in the file."]);
            }
        }
        foreach (array_keys($a->fields()) as $f) {
            if (! isset($mapping[$f]) && in_array($f, $cols, true)) {
                $mapping[$f] = $f;
            }
        }
        $rows = array_map(function (array $r) use ($a, $mapping) {
            $out = [];
            foreach (array_keys($a->fields()) as $f) {
                $v = isset($mapping[$f]) ? ($r[$mapping[$f]] ?? null) : null;
                $out[$f] = $v === null || trim((string) $v) === '' ? null : trim((string) $v);
            }

            return $out;
        }, $batch->raw_rows ?? []);
        $batch->update(['mapping' => $mapping, 'rows' => $rows, 'dry_run' => null, 'reconciliation' => null, 'reconciled_at' => null]);
        $missing = array_keys(array_filter($a->fields(), fn ($req, $f) => $req && ! isset($mapping[$f]), ARRAY_FILTER_USE_BOTH));
        if ($missing) {
            $batch->update(['status' => 'UPLOADED', 'report' => ['needs_mapping' => $missing, 'valid' => 0, 'already_migrated' => [], 'errors' => []]]);

            return $batch->refresh();
        }

        return $this->validate($batch);
    }

    /** Required fields, adapter rules, duplicate legacy ids in the file, and legacy ids already migrated (skipped). */
    public function validate(ImportBatch $batch): ImportBatch
    {
        $this->assertOpen($batch);
        $a = $this->adapter($batch->target);
        $ctx = $this->context($batch, null, true);
        $report = ['valid' => 0, 'already_migrated' => [], 'errors' => [], 'rules_failed' => []];
        $seen = [];
        foreach ($batch->rows ?? [] as $i => $row) {
            $line = $i + 1;
            $errors = [];
            foreach ($a->fields() as $f => $required) {
                if ($required && $row[$f] === null) {
                    $errors[] = ['rule' => 'REQUIRED', 'field' => $f, 'message' => "$f is required."];
                }
            }
            $legacyId = (string) $row['legacy_id'];
            if ($legacyId !== '' && isset($seen[$legacyId])) {
                $errors[] = ['rule' => 'LEGACY_ID_UNIQUE', 'field' => 'legacy_id', 'message' => "Legacy id {$legacyId} repeats row {$seen[$legacyId]}."];
            }
            $seen[$legacyId] ??= $line;
            if ($errors === [] && $ctx->resolve($a->recordType(), $legacyId)) {
                $report['already_migrated'][] = ['row' => $line, 'legacy_id' => $legacyId];

                continue;
            }
            if ($errors === []) {
                try {
                    $errors = $a->validate($row, $ctx);
                } catch (Throwable $e) {
                    $errors = [['rule' => 'INVALID', 'field' => null, 'message' => $e->getMessage()]];
                }
            }
            foreach ($errors as $err) {
                $report['errors'][] = ['row' => $line, 'legacy_id' => $legacyId] + $err;
                $report['rules_failed'][$err['rule']] = ($report['rules_failed'][$err['rule']] ?? 0) + 1;
            }
            if ($errors === []) {
                $report['valid']++;
            }
        }
        $status = $report['errors'] ? 'FAILED' : 'VALIDATED';
        $batch->update(['status' => $status, 'report' => $report, 'dry_run' => null, 'reconciliation' => null, 'reconciled_at' => null]);
        $this->audit->record('legacy_migration.validated', 'import_batch', $batch->id, ['entity' => $batch->target, 'status' => $status, 'valid' => $report['valid'],
            'already_migrated' => count($report['already_migrated']), 'errors' => count($report['errors'])]);

        return $batch->refresh();
    }

    /** Runs the real adapters in a transaction that is always rolled back: nothing is persisted but the outcome per row. */
    public function dryRun(ImportBatch $batch, User $actor): ImportBatch
    {
        if (! in_array($batch->status, ['VALIDATED', 'DRY_RUN_OK', 'DRY_RUN_FAILED', 'RECONCILED', 'UNRECONCILED'], true)) {
            throw ValidationException::withMessages(['status' => 'Only a validated legacy batch can be dry-run.']);
        }
        $result = null;
        DB::beginTransaction();
        try {
            $result = $this->execute($batch, $this->context($batch, $actor, true), true);
        } finally {
            DB::rollBack();
        }
        $status = $result['failed'] ? 'DRY_RUN_FAILED' : 'DRY_RUN_OK';
        $batch->update(['status' => $status, 'dry_run' => $result + ['ran_at' => now()->toIso8601String(), 'ran_by' => $actor->id]]);
        $this->audit->record('legacy_migration.dry_run', 'import_batch', $batch->id, ['entity' => $batch->target, 'status' => $status,
            'would_create' => count($result['created']), 'failed' => count($result['failed']), 'config_required' => $result['totals']['config_required']]);

        return $batch->refresh();
    }

    /**
     * Source vs migrated report. Before commit: file totals vs the legacy system's control totals vs the dry run.
     * After commit: also the counts / amounts re-measured from the migrated records.
     */
    public function reconcile(ImportBatch $batch): ImportBatch
    {
        if (! in_array($batch->status, ['DRY_RUN_OK', 'RECONCILED', 'UNRECONCILED', 'COMMITTED'], true)) {
            throw ValidationException::withMessages(['status' => 'Reconcile after a successful dry run.']);
        }
        $a = $this->adapter($batch->target);
        $source = ['count' => 0, 'amount_minor' => 0];
        foreach ($batch->rows ?? [] as $row) {
            $source['count']++;
            $source['amount_minor'] += $a->sourceAmount($row);
        }
        $skipped = collect($batch->report['already_migrated'] ?? [])->pluck('row')->all();
        $eligible = ['count' => 0, 'amount_minor' => 0];
        foreach ($batch->rows ?? [] as $i => $row) {
            if (! in_array($i + 1, $skipped, true)) {
                $eligible['count']++;
                $eligible['amount_minor'] += $a->sourceAmount($row);
            }
        }
        $dry = $batch->dry_run['totals'] ?? ['count' => 0, 'amount_minor' => 0];
        $out = ['source' => $source, 'control_totals' => $batch->control_totals, 'already_migrated' => count($skipped), 'eligible' => $eligible,
            'dry_run' => ['count' => $dry['count'], 'amount_minor' => $dry['amount_minor']], 'differences' => []];
        foreach (['count', 'amount_minor'] as $k) {
            if (isset($batch->control_totals[$k]) && (int) $batch->control_totals[$k] !== $source[$k]) {
                $out['differences'][] = ['check' => "control_totals.$k", 'expected' => (int) $batch->control_totals[$k], 'actual' => $source[$k]];
            }
            if ($dry[$k] !== $eligible[$k]) {
                $out['differences'][] = ['check' => "dry_run.$k", 'expected' => $eligible[$k], 'actual' => $dry[$k]];
            }
        }
        if ($batch->status === 'COMMITTED') {
            $migrated = ['count' => 0, 'amount_minor' => 0, 'missing' => []];
            foreach ($batch->result['created'] ?? [] as $c) {
                $m = $a->measure($c['id']);
                if ($m === null) {
                    $migrated['missing'][] = $c['legacy_id'];

                    continue;
                }
                $migrated['count']++;
                $migrated['amount_minor'] += $m;
            }
            $out['migrated'] = $migrated;
            foreach (['count', 'amount_minor'] as $k) {
                if ($migrated[$k] !== $eligible[$k]) {
                    $out['differences'][] = ['check' => "migrated.$k", 'expected' => $eligible[$k], 'actual' => $migrated[$k]];
                }
            }
            $out['opening_balances'] = $batch->result['opening_balances'] ?? null;
        }
        $out['balanced'] = $out['differences'] === [];
        $update = ['reconciliation' => $out, 'reconciled_at' => now()];
        if ($batch->status !== 'COMMITTED') {
            $update['status'] = $out['balanced'] ? 'RECONCILED' : 'UNRECONCILED';
        }
        $batch->update($update);
        $this->audit->record('legacy_migration.reconciled', 'import_batch', $batch->id, ['entity' => $batch->target, 'balanced' => $out['balanced'],
            'differences' => $out['differences'], 'committed' => $batch->status === 'COMMITTED']);

        return $batch->refresh();
    }

    /** Maker asks for commit approval: only a balanced reconciliation of a clean dry run can be submitted. */
    public function submit(ImportBatch $batch, User $maker, ?string $reason = null): ImportBatch
    {
        if ($batch->status !== 'RECONCILED' || ! ($batch->reconciliation['balanced'] ?? false)) {
            throw ValidationException::withMessages(['status' => 'Only a dry-run, balanced (reconciled) batch can be submitted for approval.']);
        }

        return DB::transaction(function () use ($batch, $maker, $reason) {
            $req = $this->approvals->open($maker, [
                'action_code' => self::APPROVAL_ACTION, 'subject_type' => 'import_batch', 'subject_id' => $batch->id,
                'source_table' => 'import_batches', 'source_id' => $batch->id, 'tenant_id' => $batch->tenant_id,
                'payload' => ['entity' => $batch->target, 'source' => $batch->source_system, 'filename' => $batch->filename,
                    'reconciliation' => $batch->reconciliation, 'config_required' => $batch->dry_run['totals']['config_required'] ?? 0],
                'reason' => $reason ?? "Legacy migration {$batch->filename}",
            ]);
            $batch->update(['status' => 'PENDING_APPROVAL', 'approval_request_id' => $req->id]);
            $this->audit->record('legacy_migration.submitted', 'import_batch', $batch->id, ['approval_id' => $req->id, 'entity' => $batch->target], $reason);
            if ($this->approvals->isApproved($req)) {
                $batch->update(['status' => 'APPROVED']);
            }

            return $batch->refresh();
        });
    }

    /** Checker decision (also reached from the approval inbox through LegacyMigrationApprovalHandler). Approval does not commit by itself. */
    public function approve(ImportBatch $batch, User $checker, ?string $note = null): ImportBatch
    {
        $this->assertPending($batch);

        return DB::transaction(function () use ($batch, $checker, $note) {
            $req = $this->approvals->recordDecision($this->request($batch), $checker, 'APPROVED', $note);
            if ($this->approvals->isApproved($req)) {
                $batch->update(['status' => 'APPROVED', 'approved_by' => $checker->id]);
                $this->audit->record('legacy_migration.approved', 'import_batch', $batch->id, ['approval_id' => $req->id, 'entity' => $batch->target], $note);
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
            $this->audit->record('legacy_migration.rejected', 'import_batch', $batch->id, ['entity' => $batch->target], $note);

            return $batch->refresh();
        });
    }

    /** Creates the records of an approved batch — all rows in one transaction, idempotent by legacy id. */
    public function commit(ImportBatch $batch, User $actor): ImportBatch
    {
        if ($batch->status !== 'APPROVED') {
            throw ValidationException::withMessages(['status' => 'Only an approved legacy batch can be committed.']);
        }
        $checker = $batch->approved_by;
        $result = DB::transaction(function () use ($batch, $actor, $checker) {
            $locked = ImportBatch::whereKey($batch->id)->lockForUpdate()->firstOrFail();
            if ($locked->status !== 'APPROVED') {
                throw ValidationException::withMessages(['status' => 'This batch is no longer approved.']);
            }
            $result = $this->execute($locked, $this->context($locked, $actor, false, $checker), false);
            if ($result['failed']) {
                $f = $result['failed'][0];
                throw ValidationException::withMessages(['row' => "Row {$f['row']} ({$f['legacy_id']}): {$f['error']}"]);
            }
            $locked->update(['status' => 'COMMITTED', 'imported_at' => now(), 'imported_count' => count($result['created']), 'result' => $result]);
            $this->audit->record('legacy_migration.committed', 'import_batch', $locked->id, ['entity' => $locked->target, 'created' => count($result['created']),
                'skipped' => count($result['skipped']), 'totals' => $result['totals'], 'approval_id' => $locked->approval_request_id], null, ['approval_id' => $locked->approval_request_id]);
            $this->outbox->record('legacy_migration.committed', 'import_batch', $locked->id, ['batch_id' => $locked->id, 'entity' => $locked->target,
                'source' => $locked->source_system, 'created' => count($result['created']), 'amount_minor' => $result['totals']['amount_minor'],
                'config_required' => $result['totals']['config_required']]);

            return $result;
        });

        return $this->reconcile($batch->refresh());
    }

    /** Discards a batch that has not been committed (withdrawing its approval request if still pending). Committed data is never deleted. */
    public function rollback(ImportBatch $batch, User $actor, string $reason): ImportBatch
    {
        if (in_array($batch->status, self::FINAL, true)) {
            throw ValidationException::withMessages(['status' => "A {$batch->status} legacy batch cannot be rolled back."]);
        }

        return DB::transaction(function () use ($batch, $actor, $reason) {
            if ($batch->status === 'PENDING_APPROVAL') {
                $this->approvals->cancel($this->request($batch), $actor, $reason);   // only the maker may withdraw; a checker rejects instead
            }
            $from = $batch->status;
            $batch->update(['status' => 'ROLLED_BACK', 'rolled_back_at' => now(), 'rolled_back_by' => $actor->id]);
            $this->audit->record('legacy_migration.rolled_back', 'import_batch', $batch->id, ['entity' => $batch->target, 'from_status' => $from], $reason);
            $this->outbox->record('legacy_migration.rolled_back', 'import_batch', $batch->id, ['batch_id' => $batch->id, 'entity' => $batch->target, 'from_status' => $from]);

            return $batch->refresh();
        });
    }

    /**
     * Runs every row through the adapter. Each row runs in its own savepoint so a failing row is reported without
     * aborting the others (the caller decides: the dry run rolls back everything, commit refuses any failure).
     *
     * @return array{created: list<array>, skipped: list<array>, failed: list<array>, opening_balances: list<array>, totals: array}
     */
    private function execute(ImportBatch $batch, LegacyContext $ctx, bool $dryRun): array
    {
        $a = $this->adapter($batch->target);
        $out = ['created' => [], 'skipped' => [], 'failed' => [], 'opening_balances' => [],
            'totals' => ['count' => 0, 'amount_minor' => 0, 'posted' => 0, 'config_required' => 0]];
        foreach ($batch->rows ?? [] as $i => $row) {
            $line = $i + 1;
            $legacyId = (string) $row['legacy_id'];
            if ($existing = $ctx->resolve($a->recordType(), $legacyId)) {
                $out['skipped'][] = ['row' => $line, 'legacy_id' => $legacyId, 'id' => $existing, 'status' => 'ALREADY_MIGRATED'];

                continue;
            }
            try {
                $r = DB::transaction(function () use ($a, $row, $ctx, $legacyId, $batch, $dryRun) {
                    if ($errors = $a->validate($row, $ctx)) {
                        throw ValidationException::withMessages([$errors[0]['field'] ?? 'row' => $errors[0]['rule'].': '.$errors[0]['message']]);
                    }
                    $r = $a->migrate($row, $ctx);
                    ExternalRecordMapping::create([
                        'tenant_id' => $ctx->tenantId, 'integration_client_id' => $ctx->source->id, 'record_type' => $a->recordType(),
                        'external_record_id' => $legacyId, 'opesinsure_record_id' => $r['id'], 'source_of_truth' => 'OPESINSURE',
                        'last_synchronized_at' => now(), 'synchronization_status' => 'SYNCED', 'metadata' => ['batch_id' => $batch->id, 'pipeline' => self::PIPELINE],
                    ]);
                    if (isset($r['opening_balance'])) {
                        $ob = $r['opening_balance'];
                        $r['posting'] = $dryRun
                            ? ['status' => $ob['amount_minor'] <= 0 ? 'NOTHING_TO_POST' : ($this->balances->configured($ctx->tenantId, $ob['currency']) ? 'WILL_POST' : 'CONFIG_REQUIRED'),
                                'event' => OpeningBalancePoster::EVENT, 'amount_minor' => $ob['amount_minor'], 'currency' => $ob['currency']]
                            : $this->balances->post($ctx->tenantId, $r['id'], $ob['amount_minor'], $ob['currency'], 'legacy-migration:'.$batch->id);
                    }

                    return $r;
                });
            } catch (Throwable $e) {
                $msg = $e instanceof ValidationException ? (string) collect($e->errors())->flatten()->first() : $e->getMessage();
                $out['failed'][] = ['row' => $line, 'legacy_id' => $legacyId, 'error' => mb_substr($msg, 0, 500)];

                continue;
            }
            $amount = $a->sourceAmount($row);
            $out['created'][] = ['row' => $line, 'legacy_id' => $legacyId, 'id' => $r['id'], 'amount_minor' => $amount] + (isset($r['notes']) ? ['notes' => $r['notes']] : []);
            $out['totals']['count']++;
            $out['totals']['amount_minor'] += $amount;
            if (isset($r['posting'])) {
                $out['opening_balances'][] = ['row' => $line, 'legacy_id' => $legacyId, 'record_id' => $r['id']] + $r['posting'];
                $out['totals']['posted'] += $r['posting']['status'] === 'POSTED' ? 1 : 0;
                $out['totals']['config_required'] += $r['posting']['status'] === 'CONFIG_REQUIRED' ? 1 : 0;
            }
        }

        return $out;
    }

    private function context(ImportBatch $batch, ?User $actor, bool $dryRun, ?string $checkerId = null): LegacyContext
    {
        $actor ??= User::findOrFail($batch->created_by);

        return new LegacyContext($batch->tenant_id, $batch->id, $actor, IntegrationClient::findOrFail($batch->target_params['integration_client_id'] ?? null),
            $dryRun, $batch->created_by, $checkerId);
    }

    private function request(ImportBatch $batch): ApprovalRequest
    {
        return ApprovalRequest::findOrFail($batch->approval_request_id);
    }

    private function assertOpen(ImportBatch $batch): void
    {
        if ($batch->pipeline !== self::PIPELINE) {
            throw ValidationException::withMessages(['pipeline' => 'Not a legacy migration batch.']);
        }
        if (! in_array($batch->status, self::OPEN, true)) {
            throw ValidationException::withMessages(['status' => "A {$batch->status} legacy batch can no longer be changed."]);
        }
    }

    private function assertPending(ImportBatch $batch): void
    {
        if ($batch->status !== 'PENDING_APPROVAL' || ! $batch->approval_request_id) {
            throw ValidationException::withMessages(['status' => 'This legacy batch is not waiting for approval.']);
        }
    }
}
