<?php

declare(strict_types=1);

namespace App\Application\Documents\Adapters;

use App\Models\Document;

/**
 * One adapter per OCR/data-extraction backend, mirroring MalwareScanAdapter.
 * Populates the same Document::ocr_data column the staff
 * DocumentController::review() endpoint already writes to by hand — this is
 * what a real extraction backend (a vendor OCR API, a self-hosted model,
 * whatever gets provisioned) would implement to populate it automatically
 * instead. There is no real implementation of this interface anywhere in
 * this codebase today (confirmed by grep before writing this batch) — see
 * ManualReviewOcrAdapter, the only bound implementation, and the batch
 * report's "remaining gaps" section.
 *
 * Takes the Document itself (not raw bytes) so a real adapter can decide for
 * itself how to fetch the file (its own storage_key/disk) rather than this
 * interface forcing a read for every call — the current placeholder never
 * needs the bytes at all.
 */
interface OcrAdapter
{
    public function extract(Document $document): OcrResult;
}
