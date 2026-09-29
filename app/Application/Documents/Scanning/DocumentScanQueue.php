<?php

declare(strict_types=1);

namespace App\Application\Documents\Scanning;

use App\Application\Audit\AuditWriter;
use App\Application\Claims\ClaimEvidenceService;
use App\Application\Documents\Adapters\MalwareScanAdapter;
use App\Application\Notifications\CustomerNotifier;
use App\Domain\Tenancy\TenantContext;
use App\Models\Claim;
use App\Models\Document;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Queue-based malware scanning for uploaded documents (Q1).
 *
 *   upload ──> PENDING_SCAN ──ScanDocumentJob──> CLEAN            (pending attachments performed automatically)
 *                                          ├──> INFECTED         (quarantined: never accessible, attachments cancelled,
 *                                          │                      uploader + documents.review staff notified)
 *                                          └──> SCAN_UNAVAILABLE (scanner missing/unreachable: bytes stay stored and
 *                                                                 held; documents:rescan-pending retries with backoff)
 *
 * CLEAN is only ever written from a real MalwareScanAdapter verdict (ScanResult::clean()); nothing here — no timeout,
 * no retry limit, no missing scanner — ever promotes a file to CLEAN. Legacy FAILED uploads (the pre-queue fail-closed
 * placeholder) that no human reviewed are adopted into the queue by rescanPending().
 */
final class DocumentScanQueue
{
    public const PENDING_SCAN = 'PENDING_SCAN';

    public const CLEAN = 'CLEAN';

    public const INFECTED = 'INFECTED';

    public const SCAN_UNAVAILABLE = 'SCAN_UNAVAILABLE';

    /** Legacy value written by the synchronous fail-closed scan before the queue existed. */
    public const LEGACY_FAILED = 'FAILED';

    /** Statuses of a stored file waiting for a real scan. */
    public const HELD = [self::PENDING_SCAN, self::SCAN_UNAVAILABLE];

    /** Pending attachment target: claim evidence (ClaimEvidenceService::attach). */
    public const TARGET_CLAIM_EVIDENCE = 'CLAIM_EVIDENCE';

    /** Returned to the client instead of a failure while the file is held. */
    public const ATTACHMENT_PENDING = 'PENDING_SECURITY_CHECK';

    /** Backoff (minutes) between attempts for a file the running scanner could not scan; capped at the last value. */
    private const BACKOFF_MINUTES = [5, 15, 60, 180, 360];

    public function __construct(
        private MalwareScanAdapter $scanner,
        private MalwareScannerHealth $health,
        private AuditWriter $audit,
    ) {
    }

    public static function isHeld(?string $status): bool
    {
        return in_array($status, self::HELD, true);
    }

    /** Registers a freshly stored upload for scanning and dispatches ScanDocumentJob after the surrounding commit. */
    public function enqueue(Document $document, string $disk, ?string $uploadedBy = null): void
    {
        DB::table('document_scan_queue')->updateOrInsert(['document_id' => $document->id], [
            'tenant_id' => $document->tenant_id, 'disk' => $disk, 'status' => self::PENDING_SCAN, 'uploaded_by' => $uploadedBy,
            'next_attempt_at' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);

        ScanDocumentJob::dispatch($document->id)->afterCommit();
    }

    /**
     * Runs one real scan of a held document and records the verdict. Returns the resulting scan_status (or the
     * current one when the document is not held any more — a second job for the same file is a no-op).
     */
    public function scan(string $documentId): ?string
    {
        $document = Document::find($documentId);
        if (! $document) {
            DB::table('document_scan_queue')->where('document_id', $documentId)->delete();

            return null;
        }
        if (! self::isHeld($document->scan_status) && $document->scan_status !== self::LEGACY_FAILED) {
            return $document->scan_status;
        }
        $row = DB::table('document_scan_queue')->where('document_id', $documentId)->first();

        $bytes = $this->readBytes($document, $row?->disk);
        if ($bytes === null) {
            return $this->unavailable($document, 'The stored file could not be read for scanning.');
        }

        $tempPath = tempnam(sys_get_temp_dir(), 'doc-scan-');
        file_put_contents($tempPath, $bytes);
        try {
            $result = $this->scanner->scan($tempPath, (string) $document->mime_type);
        } catch (Throwable $e) {
            report($e);

            return $this->unavailable($document, 'Scanner error: '.Str::limit($e->getMessage(), 300));
        } finally {
            @unlink($tempPath);
        }

        // The file must still be the one that was scanned.
        if ($document->sha256 && ! hash_equals((string) $document->sha256, hash('sha256', $bytes))) {
            return $this->unavailable($document, 'Stored bytes no longer match the recorded sha256.');
        }

        return match ($result->status) {
            self::CLEAN => $this->clean($document, $result->detail),
            self::INFECTED => $this->infected($document, (string) $result->detail),
            default => $this->unavailable($document, (string) ($result->detail ?? 'Scanner unavailable.')),
        };
    }

    /**
     * documents:rescan-pending — adopts unreviewed legacy FAILED uploads, then, only while the scanner answers,
     * rescans every held file that is due, and finishes attachments of files that are already CLEAN.
     *
     * @return array{scanner: bool, adopted: int, scanned: int, clean: int, infected: int, unavailable: int, attached: int}
     */
    public function rescanPending(int $limit = 200, bool $ignoreBackoff = false): array
    {
        $out = ['scanner' => false, 'adopted' => $this->adoptLegacy(), 'scanned' => 0, 'clean' => 0, 'infected' => 0, 'unavailable' => 0, 'attached' => 0];

        // Attachments whose document became CLEAN some other way (e.g. a race with the upload request).
        foreach (DB::table('document_pending_attachments as p')->join('documents as d', 'd.id', '=', 'p.document_id')
            ->where('p.status', 'PENDING')->where('d.scan_status', self::CLEAN)->distinct()->limit($limit)->pluck('p.document_id') as $docId) {
            $out['attached'] += $this->performPendingAttachments((string) $docId);
        }

        if (! $this->health->available()) {
            return $out; // no attempt is spent (and no backoff grows) while the scanner is missing or down
        }
        $out['scanner'] = true;

        $due = DB::table('document_scan_queue')->whereIn('status', self::HELD)
            ->when(! $ignoreBackoff, fn ($q) => $q->where(fn ($w) => $w->whereNull('next_attempt_at')->orWhere('next_attempt_at', '<=', now())))
            ->orderBy('created_at')->limit($limit)->pluck('document_id');

        foreach ($due as $documentId) {
            $status = $this->scan((string) $documentId);
            $out['scanned']++;
            match ($status) {
                self::CLEAN => $out['clean']++,
                self::INFECTED => $out['infected']++,
                default => $out['unavailable']++,
            };
        }
        $out['attached'] += (int) DB::table('document_pending_attachments')->whereIn('document_id', $due)->where('status', 'ATTACHED')
            ->where('completed_at', '>=', now()->subMinutes(10))->count();

        return $out;
    }

    /** Admin "rescan now" for one held file: scans immediately, ignoring the backoff. */
    public function rescanNow(string $documentId): ?string
    {
        DB::table('document_scan_queue')->where('document_id', $documentId)->update(['next_attempt_at' => null, 'updated_at' => now()]);

        return $this->scan($documentId);
    }

    /**
     * Attach a document to a target now if it is CLEAN, or remember the request and perform it automatically once
     * the file scans CLEAN. Returns the pending row id, or null when the attachment was not deferred.
     *
     * @param  array<string, mixed>  $payload
     */
    public function deferAttachment(Document $document, string $targetType, string $targetId, array $payload, User $requestedBy): string
    {
        $existing = DB::table('document_pending_attachments')
            ->where(['document_id' => $document->id, 'target_type' => $targetType, 'target_id' => $targetId])->first();
        if ($existing) {
            $id = (string) $existing->id;
            if ($existing->status !== 'PENDING') {
                DB::table('document_pending_attachments')->where('id', $id)->update(['status' => 'PENDING', 'error' => null, 'payload' => json_encode($payload), 'updated_at' => now()]);
            }
        } else {
            $id = (string) Str::uuid();
            DB::table('document_pending_attachments')->insert([
                'id' => $id, 'tenant_id' => $document->tenant_id, 'document_id' => $document->id, 'target_type' => $targetType,
                'target_id' => $targetId, 'payload' => json_encode($payload), 'requested_by' => $requestedBy->id, 'status' => 'PENDING',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        // Make sure a held file is actually queued (e.g. a legacy FAILED upload).
        if (! DB::table('document_scan_queue')->where('document_id', $document->id)->exists()) {
            $this->enqueue($document, (string) config('filesystems.default'), $requestedBy->id);
        }

        // The scan may have finished between the caller's status check and this insert.
        if (Document::whereKey($document->id)->value('scan_status') === self::CLEAN) {
            DB::afterCommit(fn () => $this->performPendingAttachments($document->id));
        }

        $this->audit->record('document.attachment.deferred', 'document', $document->id, ['target_type' => $targetType, 'target_id' => $targetId]);

        return $id;
    }

    /** @return array<string, int> scan_status => count (tenant-scoped when $tenantId is given) */
    public function counts(?string $tenantId): array
    {
        $rows = DB::table('documents')->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->whereIn('scan_status', [self::PENDING_SCAN, self::SCAN_UNAVAILABLE, self::CLEAN, self::INFECTED, self::LEGACY_FAILED])
            ->selectRaw('scan_status, count(*) as n')->groupBy('scan_status')->pluck('n', 'scan_status');

        $out = [];
        foreach ([self::PENDING_SCAN, self::SCAN_UNAVAILABLE, self::CLEAN, self::INFECTED, self::LEGACY_FAILED] as $s) {
            $out[$s] = (int) ($rows[$s] ?? 0);
        }
        $out['PENDING_ATTACHMENTS'] = (int) DB::table('document_pending_attachments')->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))->where('status', 'PENDING')->count();

        return $out;
    }

    private function clean(Document $document, ?string $detail): string
    {
        $this->setStatus($document, self::CLEAN);
        DB::table('document_scan_queue')->where('document_id', $document->id)->update([
            'status' => self::CLEAN, 'scanned_at' => now(), 'verdict' => Str::limit((string) ($detail ?? 'OK'), 490), 'last_error' => null,
            'next_attempt_at' => null, 'last_attempt_at' => now(), 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now(),
        ]);
        $this->audit->record('document.scan.clean', 'document', $document->id, ['tenant_id' => $document->tenant_id]);
        $this->performPendingAttachments($document->id);

        return self::CLEAN;
    }

    private function infected(Document $document, string $signature): string
    {
        $this->setStatus($document, self::INFECTED);
        DB::table('document_scan_queue')->where('document_id', $document->id)->update([
            'status' => self::INFECTED, 'scanned_at' => now(), 'quarantined_at' => now(), 'verdict' => Str::limit($signature, 490),
            'next_attempt_at' => null, 'last_attempt_at' => now(), 'attempts' => DB::raw('attempts + 1'), 'updated_at' => now(),
        ]);
        DB::table('document_pending_attachments')->where('document_id', $document->id)->where('status', 'PENDING')
            ->update(['status' => 'CANCELLED', 'error' => 'INFECTED', 'completed_at' => now(), 'updated_at' => now()]);
        $this->audit->record('document.scan.infected', 'document', $document->id, ['tenant_id' => $document->tenant_id, 'signature' => Str::limit($signature, 200)]);
        $this->notifyInfected($document);

        return self::INFECTED;
    }

    private function unavailable(Document $document, string $error): string
    {
        $this->setStatus($document, self::SCAN_UNAVAILABLE);
        $row = DB::table('document_scan_queue')->where('document_id', $document->id)->first();
        $attempts = (int) ($row->attempts ?? 0) + 1;
        // Backoff only grows while the scanner itself answers (the file is the problem); while the scanner is
        // missing or down the file stays immediately due, so it is scanned on the first run after it comes up.
        $next = $this->health->available() ? now()->addMinutes(self::BACKOFF_MINUTES[min($attempts, count(self::BACKOFF_MINUTES)) - 1]) : null;

        DB::table('document_scan_queue')->updateOrInsert(['document_id' => $document->id], [
            'tenant_id' => $document->tenant_id, 'disk' => $row->disk ?? (string) config('filesystems.default'), 'status' => self::SCAN_UNAVAILABLE,
            'attempts' => $attempts, 'last_attempt_at' => now(), 'next_attempt_at' => $next, 'last_error' => Str::limit($error, 490),
            'created_at' => $row->created_at ?? now(), 'updated_at' => now(),
        ]);

        return self::SCAN_UNAVAILABLE;
    }

    /** Guarded write: only a held (or legacy FAILED) document changes status here, so a staff decision is never overwritten. */
    private function setStatus(Document $document, string $status): void
    {
        DB::table('documents')->where('id', $document->id)->whereIn('scan_status', [...self::HELD, self::LEGACY_FAILED])
            ->update(['scan_status' => $status, 'updated_at' => now()]);
        $document->scan_status = $status;
    }

    private function readBytes(Document $document, ?string $disk): ?string
    {
        if (! $document->storage_key) {
            return null;
        }
        foreach (array_unique(array_filter([$disk, (string) config('filesystems.default'), 'local'])) as $name) {
            try {
                $d = Storage::disk($name);
                if ($d->exists($document->storage_key)) {
                    return $d->get($document->storage_key);
                }
            } catch (Throwable $e) {
                Log::warning('documents.scan.read_failed', ['document_id' => $document->id, 'disk' => $name, 'error' => $e->getMessage()]);
            }
        }

        return null;
    }

    /** @return int attachments performed */
    private function performPendingAttachments(string $documentId): int
    {
        $done = 0;
        $pending = DB::table('document_pending_attachments')->where('document_id', $documentId)->where('status', 'PENDING')->get();
        foreach ($pending as $p) {
            $document = Document::find($documentId);
            if (! $document || $document->scan_status !== self::CLEAN) {
                return $done;
            }
            [$status, $resultId, $error] = $this->performOne($p, $document);
            DB::table('document_pending_attachments')->where('id', $p->id)->where('status', 'PENDING')->update([
                'status' => $status, 'result_id' => $resultId, 'error' => $error ? Str::limit($error, 490) : null,
                'completed_at' => now(), 'updated_at' => now(),
            ]);
            if ($status === 'ATTACHED') {
                $done++;
                $this->audit->record('document.attachment.completed', 'document', $documentId, ['target_type' => $p->target_type, 'target_id' => $p->target_id]);
                $this->notifyAttached($p);
            }
        }

        return $done;
    }

    /** @return array{0: string, 1: ?string, 2: ?string} status, result id, error */
    private function performOne(object $pending, Document $document): array
    {
        $payload = json_decode((string) $pending->payload, true) ?: [];
        $actor = User::find($pending->requested_by);
        if (! $actor) {
            return ['FAILED', null, 'The requesting user no longer exists.'];
        }

        $context = app(TenantContext::class);
        $previous = rescue(fn () => $context->id(), null, false);
        $context->set((string) $pending->tenant_id);
        try {
            if ($pending->target_type === self::TARGET_CLAIM_EVIDENCE) {
                $claim = Claim::where('tenant_id', $pending->tenant_id)->find($pending->target_id);
                if (! $claim) {
                    return ['FAILED', null, 'The claim no longer exists.'];
                }
                $result = app(ClaimEvidenceService::class)->attach($claim, $document, (string) ($payload['evidence_type'] ?? 'OTHER'), (string) ($payload['purpose'] ?? 'CLAIM_EVIDENCE'), $actor);

                return ['ATTACHED', (string) ($result['id'] ?? ''), null];
            }

            return ['FAILED', null, 'Unknown attachment target '.$pending->target_type];
        } catch (ValidationException $e) {
            // Already attached (e.g. the customer retried after the file came back CLEAN) counts as done.
            if (DB::table('claim_documents')->where(['claim_id' => $pending->target_id, 'document_id' => $document->id])->exists()) {
                return ['ATTACHED', null, null];
            }

            return ['FAILED', null, collect($e->errors())->flatten()->implode(' ')];
        } catch (Throwable $e) {
            report($e);

            return ['FAILED', null, $e->getMessage()];
        } finally {
            $previous !== null ? $context->set($previous) : $context->clear();
        }
    }

    private function notifyAttached(object $pending): void
    {
        $user = User::find($pending->requested_by);
        if (! $user) {
            return;
        }
        $locale = $this->locale($user);
        $path = $pending->target_type === self::TARGET_CLAIM_EVIDENCE ? "/claim/{$pending->target_id}" : null;
        app(CustomerNotifier::class)->toUser($user, (string) $pending->tenant_id, 'CLAIM',
            __('scan_queue.notify.attached_title', [], $locale), __('scan_queue.notify.attached_body', [], $locale), 'SUCCESS', $path);
    }

    private function notifyInfected(Document $document): void
    {
        $row = DB::table('document_scan_queue')->where('document_id', $document->id)->first();
        if ($row?->notified_at) {
            return;
        }
        try {
            $uploaders = collect([$row?->uploaded_by, $document->uploaded_by ?? null])->filter()->unique();
            $users = User::whereIn('id', $uploaders)->get();
            if ($users->isEmpty() && $document->party_id) {
                $users = User::where('party_id', $document->party_id)->get();
            }
            foreach ($users as $user) {
                $locale = $this->locale($user);
                app(CustomerNotifier::class)->toUser($user, $document->tenant_id, 'DOCUMENT',
                    __('scan_queue.notify.infected_title', [], $locale), __('scan_queue.notify.infected_body', [], $locale), 'WARNING', '/documents');
            }

            foreach ($this->reviewers((string) $document->tenant_id) as $staff) {
                $locale = $this->locale($staff);
                UserNotification::notify($staff, 'DOCUMENT', __('scan_queue.notify.staff_infected_title', [], $locale),
                    __('scan_queue.notify.staff_infected_body', ['category' => (string) $document->category, 'id' => substr((string) $document->id, 0, 8)], $locale),
                    'WARNING', '/admin/upload-scanning', $document->tenant_id);
            }
        } catch (Throwable $e) {
            report($e); // a notification failure never undoes the quarantine
        }
        DB::table('document_scan_queue')->where('document_id', $document->id)->update(['notified_at' => now(), 'updated_at' => now()]);
    }

    /** Active staff of the tenant holding documents.review (evaluated in that tenant's context). @return list<User> */
    private function reviewers(string $tenantId): array
    {
        $context = app(TenantContext::class);
        $previous = rescue(fn () => $context->id(), null, false);
        $context->set($tenantId);
        try {
            $ids = DB::table('tenant_memberships')->where('tenant_id', $tenantId)->where('status', 'ACTIVE')->distinct()->limit(200)->pluck('user_id');

            return User::whereIn('id', $ids)->get()->filter(fn (User $u) => rescue(fn () => $u->hasPermission('documents.review'), false, false))->values()->all();
        } finally {
            $previous !== null ? $context->set($previous) : $context->clear();
        }
    }

    private function locale(User $user): string
    {
        $l = strtolower(substr((string) ($user->locale ?? ''), 0, 2));

        return in_array($l, ['en', 'fr'], true) ? $l : (string) config('app.locale', 'fr');
    }

    /** Pre-queue uploads left FAILED by the fail-closed placeholder and never reviewed by a human join the queue. */
    private function adoptLegacy(): int
    {
        $ids = DB::table('documents as d')->where('d.scan_status', self::LEGACY_FAILED)->whereNotNull('d.storage_key')
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('document_scan_queue as q')->whereColumn('q.document_id', 'd.id'))
            ->whereNotExists(fn ($q) => $q->select(DB::raw(1))->from('audit_log as a')->where('a.action', 'document.reviewed')->whereColumn('a.subject_id', 'd.id'))
            ->limit(500)->get(['d.id', 'd.tenant_id']);

        foreach ($ids as $d) {
            DB::table('document_scan_queue')->insertOrIgnore([
                'document_id' => $d->id, 'tenant_id' => $d->tenant_id, 'disk' => (string) config('filesystems.default'), 'status' => self::SCAN_UNAVAILABLE,
                'last_error' => 'Uploaded before queue-based scanning; awaiting a real scan.', 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('documents')->where('id', $d->id)->where('scan_status', self::LEGACY_FAILED)->update(['scan_status' => self::SCAN_UNAVAILABLE, 'updated_at' => now()]);
        }

        return $ids->count();
    }
}
