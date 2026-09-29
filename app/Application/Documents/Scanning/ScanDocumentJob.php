<?php

declare(strict_types=1);

namespace App\Application\Documents\Scanning;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Scans one uploaded document (DocumentScanQueue::scan). Dispatched for every upload; a scanner outage leaves the file
 * SCAN_UNAVAILABLE and documents:rescan-pending (every 5 minutes) retries it, so this job never needs queue retries.
 */
final class ScanDocumentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public string $documentId) {}

    public function handle(DocumentScanQueue $queue): void
    {
        try {
            $queue->scan($this->documentId);
        } catch (Throwable $e) {
            // The file stays held (PENDING_SCAN / SCAN_UNAVAILABLE) and is picked up by documents:rescan-pending.
            report($e);
            Log::warning('documents.scan.job_failed', ['document_id' => $this->documentId, 'error' => $e->getMessage()]);
        }
    }
}
