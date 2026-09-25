<?php

declare(strict_types=1);

namespace App\Application\Documents;

/**
 * Owner decision (2026-09-25): production keeps demo mode ON, so every document rendered while demo mode is on
 * carries a large "DEMONSTRATION / DÉMONSTRATION — NOT VALID INSURANCE" overlay (resources/views/pdf/_demo_overlay).
 * All PDF renderers (DocumentEngine, CertificateService, PolicyDocumentService, MobilePaymentService receipts,
 * QuoteDocumentRenderer) include it.
 */
final class DemoDocumentMark
{
    public const TEXT = 'DEMONSTRATION / DÉMONSTRATION — NOT VALID INSURANCE';

    public const TEXT_FR = 'DÉMONSTRATION — NE VAUT PAS ASSURANCE';

    public static function active(): bool
    {
        return (bool) config('demo.enabled');
    }

    /** The overlay markup (empty when demo mode is off). */
    public static function html(): string
    {
        if (! self::active()) {
            return '';
        }
        $t = htmlspecialchars(self::TEXT, ENT_QUOTES, 'UTF-8');
        $fr = htmlspecialchars(self::TEXT_FR, ENT_QUOTES, 'UTF-8');

        return '<div class="demo-overlay" data-demo-watermark="1" style="position:fixed;top:32%;left:-8%;width:116%;text-align:center;transform:rotate(-30deg);'
            .'font-family:DejaVu Sans,sans-serif;font-size:40px;font-weight:bold;color:#c62828;opacity:0.28;z-index:1000;line-height:1.25">'
            .$t.'<br><span style="font-size:26px">'.$fr.'</span></div>'
            .'<div class="demo-banner" style="border:2px solid #c62828;color:#c62828;font-weight:bold;text-align:center;padding:4px;margin-bottom:8px;font-size:11px">'.$t.'</div>';
    }
}
