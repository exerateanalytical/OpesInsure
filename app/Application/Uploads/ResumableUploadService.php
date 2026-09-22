<?php

declare(strict_types=1);

namespace App\Application\Uploads;

use App\Models\UploadSession;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

/**
 * Generic chunked/resumable upload for large mobile-client files (claims
 * evidence photos/video, support attachments, documents) over unreliable
 * networks — deliberately not tied to any one document/photo type so
 * Documents/Support/Claims batches can all build their own registration
 * step on top of it rather than each inventing chunk transport themselves.
 *
 * Lifecycle: start() claims an upload_sessions row declaring the total
 * chunk count/byte size/mime type up front; putChunk() writes one chunk at
 * a time to the 'local' disk under upload-sessions/{id}/chunk-{n} — safe to
 * call again for an index already received (each write is an upsert, so a
 * client that lost connection mid-transfer can resume by calling status()
 * to see what's missing and re-sending only those chunks, no restart from
 * zero); finalize() concatenates every chunk in order, verifies the
 * assembled size (and sha256, if the caller supplied expected_sha256 up
 * front), deletes the chunk files and marks the session COMPLETED with a
 * storage_key. finalize() is itself idempotent — calling it again on an
 * already-COMPLETED session just returns that session, no re-assembly.
 *
 * NOT wired to S3/production object storage in this batch — see the final
 * report's "remaining gaps" for what a production hardening pass still
 * owes here (this mirrors the mobile app's own Patch 6 merge guide, which
 * explicitly flags large-file storage as needing "a dedicated encrypted-file
 * strategy before production").
 */
final class ResumableUploadService
{
    private const DISK = 'local';
    private const ROOT = 'upload-sessions';
    private const MAX_TOTAL_BYTES = 104_857_600; // 100 MiB per assembled upload.
    private const MAX_CHUNKS = 2000;
    private const MAX_CHUNK_BYTES = 10_485_760; // 10 MiB per chunk.
    private const SESSION_TTL_HOURS = 48;
    private const ALLOWED_MIME = ['application/pdf', 'image/jpeg', 'image/png', 'video/mp4'];

    public function start(array $data, User $user, string $tenantId): UploadSession
    {
        if (! in_array($data['mime_type'], self::ALLOWED_MIME, true)) {
            throw ValidationException::withMessages(['mime_type' => [__('wave12.upload_mime_not_allowed')]]);
        }
        if ((int) $data['total_size_bytes'] > self::MAX_TOTAL_BYTES) {
            throw ValidationException::withMessages(['total_size_bytes' => [__('wave12.upload_too_large')]]);
        }
        if ((int) $data['total_chunks'] > self::MAX_CHUNKS) {
            throw ValidationException::withMessages(['total_chunks' => [__('wave12.upload_too_many_chunks')]]);
        }

        return UploadSession::create([
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'resource_type' => $data['resource_type'],
            'mime_type' => $data['mime_type'],
            'total_chunks' => $data['total_chunks'],
            'total_size_bytes' => $data['total_size_bytes'],
            'status' => 'IN_PROGRESS',
            'expected_sha256' => $data['expected_sha256'] ?? null,
            'expires_at' => now()->addHours(self::SESSION_TTL_HOURS),
        ]);
    }

    public function putChunk(string $uploadId, int $index, string $bytes, User $user, string $tenantId): array
    {
        $session = $this->owned($uploadId, $user, $tenantId);

        if ($session->status !== 'IN_PROGRESS') {
            throw ValidationException::withMessages(['upload' => [__('wave12.upload_not_in_progress')]]);
        }
        if ($index < 0 || $index >= $session->total_chunks) {
            throw ValidationException::withMessages(['index' => [__('wave12.upload_chunk_index_invalid')]]);
        }
        if (strlen($bytes) > self::MAX_CHUNK_BYTES) {
            throw ValidationException::withMessages(['data' => [__('wave12.upload_chunk_too_large')]]);
        }

        Storage::disk(self::DISK)->put(self::chunkPath($uploadId, $index), $bytes);

        DB::table('upload_chunks')->updateOrInsert(
            ['upload_session_id' => $uploadId, 'chunk_index' => $index],
            ['size_bytes' => strlen($bytes), 'received_at' => now()]
        );

        return $this->statusPayload($session);
    }

    /** "What have I already uploaded?" — lets a resumed client know exactly
     * which chunk indexes are still missing instead of restarting from 0. */
    public function status(string $uploadId, User $user, string $tenantId): array
    {
        return $this->statusPayload($this->owned($uploadId, $user, $tenantId));
    }

    public function finalize(string $uploadId, User $user, string $tenantId): UploadSession
    {
        $session = $this->owned($uploadId, $user, $tenantId);

        if ($session->status === 'COMPLETED') {
            return $session; // finalize() is idempotent: no re-assembly on replay.
        }
        if ($session->status !== 'IN_PROGRESS') {
            throw ValidationException::withMessages(['upload' => [__('wave12.upload_not_in_progress')]]);
        }

        $received = array_map('intval', DB::table('upload_chunks')->where('upload_session_id', $uploadId)->orderBy('chunk_index')->pluck('chunk_index')->all());

        if ($received !== range(0, $session->total_chunks - 1)) {
            throw ValidationException::withMessages(['upload' => [__('wave12.upload_incomplete')]]);
        }

        $disk = Storage::disk(self::DISK);
        $finalRelativePath = self::ROOT.'/'.$uploadId.'/assembled';
        $disk->makeDirectory(self::ROOT.'/'.$uploadId);
        $finalAbsolutePath = $disk->path($finalRelativePath);

        $out = fopen($finalAbsolutePath, 'wb');
        for ($i = 0; $i < $session->total_chunks; $i++) {
            $in = fopen($disk->path(self::chunkPath($uploadId, $i)), 'rb');
            stream_copy_to_stream($in, $out);
            fclose($in);
        }
        fclose($out);

        if (filesize($finalAbsolutePath) !== $session->total_size_bytes) {
            @unlink($finalAbsolutePath);

            throw ValidationException::withMessages(['upload' => [__('wave12.upload_size_mismatch')]]);
        }
        if ($session->expected_sha256 && hash_file('sha256', $finalAbsolutePath) !== $session->expected_sha256) {
            @unlink($finalAbsolutePath);

            throw ValidationException::withMessages(['upload' => [__('wave12.upload_checksum_mismatch')]]);
        }

        for ($i = 0; $i < $session->total_chunks; $i++) {
            $disk->delete(self::chunkPath($uploadId, $i));
        }

        $session->update(['status' => 'COMPLETED', 'storage_key' => $finalRelativePath]);

        return $session->refresh();
    }

    private function statusPayload(UploadSession $session): array
    {
        $received = array_map('intval', DB::table('upload_chunks')->where('upload_session_id', $session->id)->orderBy('chunk_index')->pluck('chunk_index')->all());
        $missing = array_values(array_diff(range(0, $session->total_chunks - 1), $received));

        return [
            'id' => $session->id,
            'status' => $session->status,
            'total_chunks' => $session->total_chunks,
            'received_chunk_indexes' => $received,
            'missing_chunk_indexes' => $missing,
            'storage_key' => $session->storage_key,
        ];
    }

    private static function chunkPath(string $uploadId, int $index): string
    {
        return self::ROOT.'/'.$uploadId.'/chunk-'.$index;
    }

    private function owned(string $uploadId, User $user, string $tenantId): UploadSession
    {
        $session = UploadSession::where('tenant_id', $tenantId)->where('user_id', $user->id)->find($uploadId);

        if (! $session) {
            $exists = UploadSession::where('tenant_id', $tenantId)->where('id', $uploadId)->exists();

            throw $exists ? new AuthorizationException : new ModelNotFoundException;
        }

        return $session;
    }
}
