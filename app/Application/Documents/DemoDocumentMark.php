<?php

declare(strict_types=1);

namespace App\Application\Documents;

use Illuminate\Database\Eloquent\Model;

/**
 * Safety rule: a document generated from a DEMO-FLAGGED record (is_demo on the policy, its insurer, its tenant or its
 * party) carries a large "DEMONSTRATION / DÉMONSTRATION — NOT VALID INSURANCE" overlay (resources/views/pdf/_demo_overlay);
 * a document of a real record prints clean, whatever the demo-mode setting. Every PDF renderer goes through the one
 * canonical shell (pdf.engine-shell), which passes the record's flag ($demoRecord) to the overlay.
 *
 * Only a rendering with no record context at all (html(null): a bare view render) falls back to the demo-mode setting.
 */
final class DemoDocumentMark
{
    public const TEXT = 'DEMONSTRATION / DÉMONSTRATION — NOT VALID INSURANCE';

    public const TEXT_FR = 'DÉMONSTRATION — NE VAUT PAS ASSURANCE';

    /** Demo mode setting (used only when no record context is available). */
    public static function active(): bool
    {
        return (bool) config('demo.enabled');
    }

    /** True when any of the given records (policy, carrier, tenant, party …) is flagged is_demo. */
    public static function forRecords(mixed ...$records): bool
    {
        foreach ($records as $r) {
            if ($r instanceof Model && (bool) $r->getAttribute('is_demo')) {
                return true;
            }
            if (is_object($r) && ! $r instanceof Model && ! empty($r->is_demo)) {
                return true;
            }
        }

        return false;
    }

    /** Should the overlay be printed: the record flag when known, else the demo-mode setting. */
    public static function applies(?bool $demoRecord): bool
    {
        return $demoRecord ?? self::active();
    }

    /** The overlay markup (empty for a real record). */
    public static function html(?bool $demoRecord = null): string
    {
        if (! self::applies($demoRecord)) {
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
