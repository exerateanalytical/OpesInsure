<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * R10 launch gate: static accessibility / responsive checks over the /account
 * web app and the custom Filament views (no DB, no HTTP).
 */
final class LaunchAccessibilityTest extends TestCase
{
    private static function root(): string
    {
        return dirname(__DIR__, 3);
    }

    /** @return array<string, string> path => contents */
    private static function views(string $relativeDir): array
    {
        $dir = self::root().'/'.$relativeDir;
        $out = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if (str_ends_with($file->getFilename(), '.blade.php')) {
                $out[str_replace(self::root().'/', '', str_replace('\\', '/', $file->getPathname()))] = (string) file_get_contents($file->getPathname());
            }
        }

        return $out;
    }

    /** @return array<string, string> */
    private static function scope(): array
    {
        return self::views('resources/views/public/account') + self::views('resources/views/filament');
    }

    public function test_every_image_has_alt_text(): void
    {
        $bad = [];
        foreach (self::scope() as $path => $src) {
            preg_match_all('/<img\b[^>]*>/is', $src, $m);
            foreach ($m[0] as $tag) {
                if (! preg_match('/\balt\s*=/i', $tag)) {
                    $bad[] = "$path: $tag";
                }
            }
        }
        $this->assertSame([], $bad);
    }

    public function test_every_form_control_has_a_label(): void
    {
        $bad = [];
        foreach (self::scope() as $path => $src) {
            preg_match_all('/<(input|select|textarea)\b[^>]*>/is', $src, $m, PREG_OFFSET_CAPTURE);
            foreach ($m[0] as [$tag, $offset]) {
                if (preg_match('/type\s*=\s*"(hidden|submit|button)"/i', $tag) || preg_match('/aria-label(ledby)?\s*=/i', $tag)) {
                    continue;
                }
                if (preg_match('/\bid\s*=\s*"([^"]+)"/', $tag, $id) && str_contains($src, 'for="'.$id[1].'"')) {
                    continue;
                }
                // Wrapped in an open <label> (the nearest preceding label is not yet closed).
                $before = substr($src, 0, $offset);
                if (strripos($before, '<label') !== false && strripos($before, '<label') > (int) strripos($before, '</label>')) {
                    continue;
                }
                $bad[] = "$path: $tag";
            }
        }
        $this->assertSame([], $bad);
    }

    public function test_icon_only_buttons_have_accessible_names(): void
    {
        $bad = [];
        foreach (self::scope() as $path => $src) {
            preg_match_all('/<button\b([^>]*)>(.*?)<\/button>/is', $src, $m, PREG_SET_ORDER);
            foreach ($m as [$whole, $attrs, $inner]) {
                $text = trim(strip_tags(preg_replace('/<svg\b.*?<\/svg>/is', '', $inner)));
                if ($text === '' && ! preg_match('/aria-label(ledby)?\s*=|title\s*=/i', $attrs)) {
                    $bad[] = "$path: ".substr($whole, 0, 120);
                }
            }
            // JS-built buttons: h('button', {...}, X.icon('...')) with no text needs aria-label.
            preg_match_all("/h\\('button',\\s*\\{([^}]*)\\},\\s*[A-Za-z]+\\.icon\\('[^']+'\\)\\s*\\)/", $src, $js, PREG_SET_ORDER);
            foreach ($js as [$whole, $attrs]) {
                if (! str_contains($attrs, 'aria-label')) {
                    $bad[] = "$path: $whole";
                }
            }
        }
        $this->assertSame([], $bad);
    }

    public function test_account_pages_have_no_inline_fixed_width_wider_than_a_phone(): void
    {
        $bad = [];
        foreach (self::views('resources/views/public/account') as $path => $src) {
            preg_match_all('/(?<![-\w])(min-width|width)\s*:\s*(\d+)px/i', $src, $m, PREG_SET_ORDER);
            foreach ($m as [$decl, , $px]) {
                if ((int) $px > 360) {
                    $bad[] = "$path: $decl";
                }
            }
        }
        $this->assertSame([], $bad);
    }

    public function test_portal_css_meets_touch_target_focus_and_scroll_rules(): void
    {
        $css = (string) file_get_contents(self::root().'/public/landing/portal/portal.css');
        $this->assertMatchesRegularExpression('/\.dbtn\.sm\{min-height:40px\}/', $css, 'small buttons must be >= 40px');
        $this->assertMatchesRegularExpression('/\.atable-wrap\{overflow-x:auto\}/', $css, 'tables must scroll inside a container');
        $this->assertStringContainsString(':focus-visible', $css);
        $this->assertStringContainsString('text-overflow:ellipsis', $css);
    }

    public function test_status_badge_tones_have_aa_contrast(): void
    {
        $css = (string) file_get_contents(self::root().'/public/landing/portal/portal.css');
        preg_match_all('/\.(st-[a-z]+|acct-side \.badge)\{[^}]*?background:(#[0-9A-Fa-f]{6})[^}]*?\}/', $css, $bg, PREG_SET_ORDER);
        $this->assertNotEmpty($bg);
        $tones = [];
        foreach ($bg as [$rule, $name, $back]) {
            $fore = preg_match('/(?<!-)color:(#[0-9A-Fa-f]{6})/', $rule, $c) ? $c[1] : null;
            $tones[$name] = ['bg' => $back, 'fg' => $fore ?? ($tones[$name]['fg'] ?? '#FFFFFF')];
        }
        foreach ($tones as $name => $t) {
            $ratio = self::contrast($t['fg'], $t['bg']);
            $this->assertGreaterThanOrEqual(4.5, $ratio, "$name {$t['fg']} on {$t['bg']} = ".round($ratio, 2));
        }
    }

    public function test_insurer_names_prefer_short_names_in_account_pages(): void
    {
        $bad = [];
        foreach (self::views('resources/views/public/account') as $path => $src) {
            foreach (preg_split('/\R/', $src) as $n => $line) {
                if (preg_match('/\b[a-z]\w*\.carrier_name\b/', $line) && ! str_contains($line, 'short_name') && ! preg_match('/mark\(|carrier_name:/', $line)) {
                    $bad[] = "$path:".($n + 1);
                }
            }
        }
        $this->assertSame([], $bad);
    }

    private static function contrast(string $a, string $b): float
    {
        $l = static function (string $hex): float {
            $rgb = array_map(static function (string $h): float {
                $c = hexdec($h) / 255;

                return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
            }, str_split(ltrim($hex, '#'), 2));

            return 0.2126 * $rgb[0] + 0.7152 * $rgb[1] + 0.0722 * $rgb[2];
        };
        [$x, $y] = [$l($a), $l($b)];

        return (max($x, $y) + 0.05) / (min($x, $y) + 0.05);
    }
}
