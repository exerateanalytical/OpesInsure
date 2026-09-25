<?php

declare(strict_types=1);

namespace App\Application\Operations;

use App\Application\Audit\AuditWriter;
use App\Models\RecoveryExercise;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Agent B6 — REQ-OPS-002 automated restore verification. Restores the newest backup into a configured SCRATCH
 * database (never the primary), compares row counts + ordered key checksums of the configured tables for rows created
 * at or before the backup time, and records the outcome in the existing recovery_exercises register
 * (exercise_type BACKUP_RESTORE). RPO/RTO targets come only from config('operations.dr'); when unset they are stored
 * as null and the result says "target not configured" — nothing is invented.
 */
final class RestoreVerificationService
{
    public const PASSED = 'PASSED';

    public const FAILED = 'FAILED';

    public function __construct(private AuditWriter $audit) {}

    /** @param array{backup_file?:string|null, skip_restore?:bool} $options */
    public function run(array $options = []): RecoveryExercise
    {
        $cfg = (array) config('operations.dr');
        $started = CarbonImmutable::now();
        $evidence = ['steps' => [], 'tables' => []];
        $status = self::FAILED;
        $backupAt = null;

        try {
            $connection = (string) ($cfg['restore']['scratch_connection'] ?? '');
            $this->guardScratch($connection);
            $file = $options['backup_file'] ?? $this->newestBackup((string) ($cfg['restore']['backup_path'] ?? ''), (string) ($cfg['restore']['pattern'] ?? '*'));
            if (! is_file($file)) {
                throw new RuntimeException("Backup file not found: {$file}");
            }
            $backupAt = CarbonImmutable::createFromTimestamp((int) filemtime($file));
            $evidence['backup'] = ['file' => basename($file), 'bytes' => filesize($file), 'sha256' => hash_file('sha256', $file), 'taken_at' => $backupAt->toIso8601String()];

            if (! ($options['skip_restore'] ?? false)) {
                $this->restore($file, $connection, (string) $cfg['restore']['restore_command'], (int) ($cfg['restore']['timeout_seconds'] ?? 3600));
                $evidence['steps'][] = 'restored';
            }

            $ok = true;
            foreach ((array) ($cfg['restore']['tables'] ?? []) as $table => $spec) {
                $cmp = $this->compare((string) $table, (array) $spec, $connection, $backupAt);
                $evidence['tables'][$table] = $cmp;
                $ok = $ok && $cmp['match'];
            }
            $evidence['steps'][] = 'compared';
            $status = $ok ? self::PASSED : self::FAILED;
        } catch (Throwable $e) {
            $evidence['error'] = Str::limit($e->getMessage(), 500);
        }

        $finished = CarbonImmutable::now();
        $rto = (int) ceil(abs($finished->diffInSeconds($started)) / 60);
        $rpo = $backupAt ? (int) floor(abs($started->diffInSeconds($backupAt)) / 60) : null;
        $targetRto = $cfg['target_rto_minutes'] ?? null;
        $targetRpo = $cfg['target_rpo_minutes'] ?? null;
        $evidence['targets'] = [
            'rto' => $targetRto === null ? 'target not configured' : ($rto <= $targetRto ? 'met' : 'missed'),
            'rpo' => $targetRpo === null ? 'target not configured' : ($rpo !== null && $rpo <= $targetRpo ? 'met' : 'missed'),
        ];
        $evidence['automated'] = true;

        $exercise = RecoveryExercise::query()->create([
            'environment' => Str::limit((string) ($cfg['environment'] ?? 'production'), 24, ''),
            'exercise_type' => 'BACKUP_RESTORE',
            'status' => $status,
            'target_rto_minutes' => $targetRto,
            'target_rpo_minutes' => $targetRpo,
            'actual_rto_minutes' => $rto,
            'actual_rpo_minutes' => $rpo,
            'evidence' => $evidence,
            'conducted_at' => $finished,
        ]);
        $this->audit->record('operations.restore_verification.completed', 'recovery_exercise', $exercise->id, ['status' => $status, 'targets' => $evidence['targets']]);

        return $exercise;
    }

    private function guardScratch(string $connection): void
    {
        if ($connection === '' || config("database.connections.{$connection}") === null) {
            throw new RuntimeException('operations.dr.restore.scratch_connection is not configured.');
        }
        $primary = (string) config('database.default');
        $p = config("database.connections.{$primary}");
        $s = config("database.connections.{$connection}");
        if ($connection === $primary || (($p['host'] ?? null) === ($s['host'] ?? null) && ($p['port'] ?? null) == ($s['port'] ?? null) && ($p['database'] ?? null) === ($s['database'] ?? null))) {
            throw new RuntimeException('Refusing to restore into the primary database.');
        }
    }

    private function newestBackup(string $dir, string $pattern): string
    {
        if ($dir === '' || ! is_dir($dir)) {
            throw new RuntimeException('operations.dr.restore.backup_path is not configured or missing.');
        }
        $files = glob(rtrim($dir, '/').'/'.$pattern, GLOB_BRACE) ?: [];
        usort($files, fn ($a, $b) => filemtime($b) <=> filemtime($a));

        return $files[0] ?? throw new RuntimeException("No backup matching {$pattern} in {$dir}.");
    }

    private function restore(string $file, string $connection, string $template, int $timeout): void
    {
        $c = (array) config("database.connections.{$connection}");
        $cmd = strtr($template, [
            '{file}' => escapeshellarg($file), '{host}' => escapeshellarg((string) ($c['host'] ?? '127.0.0.1')), '{port}' => escapeshellarg((string) ($c['port'] ?? '5432')),
            '{database}' => escapeshellarg((string) ($c['database'] ?? '')), '{username}' => escapeshellarg((string) ($c['username'] ?? '')),
        ]);
        $result = Process::timeout($timeout)->env(['PGPASSWORD' => (string) ($c['password'] ?? '')])->run($cmd);
        if (! $result->successful()) {
            throw new RuntimeException('Restore command failed: '.Str::limit(trim($result->errorOutput()), 300));
        }
    }

    /** @return array{match:bool, source_rows:int, restored_rows:int|null, source_checksum:string, restored_checksum:string|null, error?:string} */
    private function compare(string $table, array $spec, string $connection, CarbonImmutable $cutoff): array
    {
        $key = (string) ($spec['key'] ?? 'id');
        $created = $spec['created'] ?? null;
        $probe = function (string $conn) use ($table, $key, $created, $cutoff): array {
            $q = DB::connection($conn)->table($table)->when($created, fn ($q) => $q->where($created, '<=', $cutoff));
            $row = $q->selectRaw('count(*) as n, md5(coalesce(string_agg('.$this->quote($key).'::text, \',\' order by '.$this->quote($key).'), \'\')) as h')->first();

            return [(int) $row->n, (string) $row->h];
        };
        [$n, $h] = $probe((string) config('database.default'));
        try {
            [$rn, $rh] = $probe($connection);
        } catch (Throwable $e) {
            return ['match' => false, 'source_rows' => $n, 'restored_rows' => null, 'source_checksum' => $h, 'restored_checksum' => null, 'error' => Str::limit($e->getMessage(), 200)];
        }

        return ['match' => $n === $rn && $h === $rh, 'source_rows' => $n, 'restored_rows' => $rn, 'source_checksum' => $h, 'restored_checksum' => $rh];
    }

    private function quote(string $identifier): string
    {
        if (! preg_match('/^[a-z_][a-z0-9_]*$/i', $identifier)) {
            throw new RuntimeException("Invalid column name {$identifier}.");
        }

        return '"'.$identifier.'"';
    }
}
