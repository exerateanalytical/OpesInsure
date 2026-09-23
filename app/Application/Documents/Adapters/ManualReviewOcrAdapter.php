<?php

declare(strict_types=1);

namespace App\Application\Documents\Adapters;

use App\Models\Document;

/**
 * The honest placeholder used because no OCR/data-extraction provider is
 * configured or integrated anywhere in this application — there is no
 * equivalent of ClamAvMalwareScanAdapter for OCR to fall back from. Every
 * call to Mobile{Kyc,RiskAsset}Service that triggers a "scan" therefore
 * comes back MANUAL_REVIEW_REQUIRED with empty fields: the honest state is
 * that a human must read the attached document and confirm the facts
 * themselves (see MobileRiskAssetService::confirmScan), exactly like an
 * unconfigured malware scanner holds documents for manual review instead of
 * pretending to have scanned them (FailClosedMalwareScanAdapter).
 */
final class ManualReviewOcrAdapter implements OcrAdapter
{
    public function extract(Document $document): OcrResult
    {
        return OcrResult::manualReviewRequired();
    }
}
