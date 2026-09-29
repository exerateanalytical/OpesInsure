<?php

declare(strict_types=1);

namespace App\Application\Help;

use App\Models\User;
use Illuminate\Support\Str;

/**
 * S11 in-app help. One markdown guide per audience under resources/help/{en,fr}/{guide}.md. A guide is a title line
 * ("# ...") followed by sections ("## Heading {#anchor}"); anchors are identical in EN and FR so contextual "?" links
 * work in both languages. Markdown is rendered with raw HTML escaped and unsafe links dropped (no HTML from the files
 * ever reaches the page unescaped).
 */
final class HelpGuides
{
    public const GUIDES = [
        'broker_admin', 'broker_staff', 'branch_manager', 'commercial_agent', 'insurer_admin', 'underwriter',
        'claims_handler', 'finance', 'provider', 'customer', 'platform_admin',
    ];

    /** Guides offered in each surface; the first one is the fallback. */
    public const SURFACES = [
        'admin' => ['platform_admin', 'finance', 'claims_handler', 'underwriter'],
        'insurer' => ['insurer_admin', 'underwriter', 'claims_handler', 'finance'],
        'broker' => ['broker_staff', 'broker_admin', 'branch_manager', 'finance'],
        'provider' => ['provider'],
        'account' => ['customer', 'commercial_agent', 'claims_handler'],
    ];

    /** Membership role code => guide (first match wins; patterns are tried after exact codes). */
    private const ROLE_GUIDES = [
        'PLATFORM_ADMIN' => 'platform_admin', 'SYSTEM_ADMIN' => 'platform_admin', 'COMPLIANCE_ADMIN' => 'platform_admin', 'DEVELOPER' => 'platform_admin',
        'CARRIER_SUPER_ADMIN' => 'insurer_admin', 'CARRIER_STAFF' => 'insurer_admin', 'CUSTOMER_SERVICE' => 'insurer_admin', 'CASHIER' => 'finance',
        'SENIOR_UNDERWRITER' => 'underwriter', 'REINSURANCE_OFFICER' => 'underwriter', 'ADJUSTER' => 'claims_handler', 'CLAIMS_OFFICER' => 'claims_handler',
        'BROKER_ADMIN' => 'broker_admin', 'BROKER_SUPERVISOR' => 'broker_admin', 'BROKER_STAFF' => 'broker_staff', 'BRANCH_MANAGER' => 'branch_manager',
        'BROKER_FINANCE' => 'finance', 'CARRIER_FINANCE' => 'finance', 'FINANCE' => 'finance', 'FINANCE_OFFICER' => 'finance', 'ACCOUNTANT' => 'finance',
        'CARRIER_ADMIN' => 'insurer_admin', 'INSURER_ADMIN' => 'insurer_admin',
        'UNDERWRITER' => 'underwriter', 'CARRIER_UNDERWRITER' => 'underwriter',
        'CLAIMS_HANDLER' => 'claims_handler', 'CLAIMS_ADJUSTER' => 'claims_handler', 'CLAIMS_MANAGER' => 'claims_handler', 'LOSS_ADJUSTER' => 'claims_handler', 'CARRIER_CLAIMS' => 'claims_handler',
        'AGENT' => 'commercial_agent', 'COMMERCIAL_AGENT' => 'commercial_agent', 'SALES_AGENT' => 'commercial_agent',
        'PROVIDER_ADMIN' => 'provider', 'PROVIDER_STAFF' => 'provider', 'CUSTOMER' => 'customer',
    ];

    private const ROLE_PATTERNS = [
        '/FINANC|ACCOUNT|TREASUR/' => 'finance', '/CLAIM|ADJUST|ASSESS/' => 'claims_handler', '/UNDERWRIT/' => 'underwriter',
        '/BRANCH/' => 'branch_manager', '/AGENT/' => 'commercial_agent', '/PROVIDER|HOSPITAL|CLINIC/' => 'provider',
        '/BROKER.*ADMIN/' => 'broker_admin', '/BROKER/' => 'broker_staff', '/(CARRIER|INSURER).*ADMIN/' => 'insurer_admin',
        '/PLATFORM|SUPER/' => 'platform_admin',
    ];

    public static function exists(string $guide): bool
    {
        return in_array($guide, self::GUIDES, true);
    }

    public static function guideForRole(?string $role): ?string
    {
        $role = strtoupper((string) $role);
        if ($role === '') {
            return null;
        }
        if (isset(self::ROLE_GUIDES[$role])) {
            return self::ROLE_GUIDES[$role];
        }
        foreach (self::ROLE_PATTERNS as $pattern => $guide) {
            if (preg_match($pattern, $role)) {
                return $guide;
            }
        }

        return null;
    }

    /** The guide for the signed-in user in $surface: the requested one if offered there, else the user's role guide, else the surface default. */
    public static function resolve(string $surface, ?User $user, ?string $requested = null): string
    {
        $offered = self::SURFACES[$surface] ?? self::GUIDES;
        if ($requested !== null && in_array($requested, $offered, true)) {
            return $requested;
        }
        foreach (self::rolesOf($user) as $role) {
            $g = self::guideForRole($role);
            if ($g !== null && in_array($g, $offered, true)) {
                return $g;
            }
        }

        return $offered[0];
    }

    /** @return list<string> */
    private static function rolesOf(?User $user): array
    {
        if (! $user instanceof User) {
            return [];
        }

        return rescue(function () use ($user): array {
            $tenant = rescue(fn () => app(\App\Domain\Tenancy\TenantContext::class)->id(), null, false);
            $q = \Illuminate\Support\Facades\DB::table('tenant_memberships')->where('user_id', $user->id)->where('status', 'ACTIVE');
            $rows = $q->get(['tenant_id', 'role_code']);
            $sorted = $rows->sortBy(fn ($r) => $r->tenant_id === $tenant ? 0 : 1);

            return $sorted->pluck('role_code')->filter()->map(fn ($r) => (string) $r)->values()->all();
        }, [], false);
    }

    public static function locale(): string
    {
        return app()->getLocale() === 'fr' ? 'fr' : 'en';
    }

    public static function path(string $guide, ?string $locale = null): string
    {
        return resource_path('help/'.($locale ?? self::locale()).'/'.$guide.'.md');
    }

    /**
     * @return array{title: string, intro: string, sections: list<array{id: string, title: string, html: string, text: string}>}
     */
    public static function load(string $guide, ?string $locale = null): array
    {
        abort_unless(self::exists($guide), 404);
        $file = self::path($guide, $locale);
        if (! is_file($file)) {
            $file = self::path($guide, 'en');
        }
        $raw = str_replace("\r\n", "\n", (string) file_get_contents($file));
        $title = '';
        $intro = [];
        $sections = [];
        $current = null;
        foreach (explode("\n", $raw) as $line) {
            if ($title === '' && str_starts_with($line, '# ')) {
                $title = trim(substr($line, 2));

                continue;
            }
            if (preg_match('/^## (.+?)\s*\{#([a-z0-9-]+)\}\s*$/', $line, $m)) {
                if ($current !== null) {
                    $sections[] = $current;
                }
                $current = ['id' => $m[2], 'title' => trim($m[1]), 'md' => ''];

                continue;
            }
            if ($current === null) {
                $intro[] = $line;
            } else {
                $current['md'] .= $line."\n";
            }
        }
        if ($current !== null) {
            $sections[] = $current;
        }

        return [
            'title' => $title,
            'intro' => self::markdown(implode("\n", $intro)),
            'sections' => array_map(fn (array $s): array => [
                'id' => $s['id'], 'title' => $s['title'], 'html' => self::markdown($s['md']),
                'text' => Str::lower($s['title'].' '.strip_tags(self::markdown($s['md']))),
            ], $sections),
        ];
    }

    /** Sections whose title or text contains every word of $q (case-insensitive). */
    public static function search(array $guide, string $q): array
    {
        $words = array_filter(preg_split('/\s+/', Str::lower(trim($q))) ?: [], 'strlen');
        if ($words === []) {
            return $guide['sections'];
        }

        return array_values(array_filter($guide['sections'], function (array $s) use ($words): bool {
            $hay = Str::ascii($s['text']);
            foreach ($words as $w) {
                if (! str_contains($hay, Str::ascii($w))) {
                    return false;
                }
            }

            return true;
        }));
    }

    /** @return list<string> */
    public static function anchors(string $guide, string $locale): array
    {
        return array_column(self::load($guide, $locale)['sections'], 'id');
    }

    public static function markdown(string $md): string
    {
        return (string) Str::markdown($md, ['html_input' => 'escape', 'allow_unsafe_links' => false]);
    }

    /** Printable version of a guide. */
    public static function printUrl(string $guide, ?string $locale = null): string
    {
        return url('/help/'.$guide.'/print').'?lang='.($locale ?? self::locale());
    }

    /** Help page URL of a surface for a guide section. */
    public static function url(string $surface, string $guide, ?string $anchor = null): string
    {
        $base = $surface === 'account' ? url('/account/help') : url('/'.$surface.'/help');

        return $base.'?guide='.$guide.($anchor ? '#'.$anchor : '');
    }
}
