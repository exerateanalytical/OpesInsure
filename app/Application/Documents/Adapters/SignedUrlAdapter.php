<?php

declare(strict_types=1);

namespace App\Application\Documents\Adapters;

use App\Models\Document;

/**
 * One adapter per storage backend, mirroring PaymentProviderAdapter /
 * NotificationChannelAdapter: the caller (MobileDocumentService) resolves
 * ownership and the malware-scan gate before ever asking for a URL: this
 * contract only ever has to answer "give me a short-lived link to bytes
 * that already exist," never "is this safe to hand out."
 */
interface SignedUrlAdapter
{
    public function sign(Document $document, int $ttlSeconds): SignedUrl;
}
