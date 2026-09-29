<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route as RouteFacade;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

/**
 * Desktop UI coverage report: enumerates every state-changing /api/v1 route and detects whether a web entry point
 * exists (a Filament file calling the same service method, or account-portal JS calling the same path).
 * Uncovered actions are classified STAFF_DESKTOP_NEEDED / CUSTOMER_WEB_NEEDED / MOBILE_ONLY_OK / SYSTEM_ONLY.
 * Static analysis only; heuristics are documented in docs/UI_COVERAGE_2026-09-27.md.
 */
final class UiCoverage extends Command
{
    protected $signature = 'ui:coverage {--json=docs/ui-coverage.json : JSON output path (relative to base_path)} {--md= : optional markdown summary path} {--no-write : print summary only}';

    protected $description = 'Report which state-changing API actions have a web (Filament or account portal) entry point.';

    /** Domain priority (most-used first) for build ordering. */
    public const DOMAIN_RANK = ['claims', 'policies', 'payments', 'quotes', 'proposals', 'issuance', 'kyc', 'customers', 'parties', 'renewals',
        'commissions', 'settlements', 'bordereaux', 'documents', 'endorsements', 'underwriting', 'referrals', 'finance', 'products', 'staff',
        'provider', 'support', 'account', 'security', 'notifications', 'distribution', 'crm'];

    /** Cross-cutting helpers that every page touches; a match on these proves nothing about the action. */
    public const INFRASTRUCTURE = ['TenantContext', 'AuditWriter', 'OwnershipScope', 'PartyResolver', 'PartnerBook', 'PlatformSettings'];

    /**
     * S3 2026-09-29: superseded routes kept only for old app builds. They get NO web UI (that would duplicate the canonical
     * flow); they are reported as DEPRECATED and left out of the UI-relevant totals. "VERB path" => why.
     * Routes carrying the DeprecatedRouteAlias middleware are treated the same way automatically.
     */
    public const DEPRECATED = [
        // Legacy claim decision / recovery endpoints (ClaimLifecycleController::decision|recovery|recordRecovery). Superseded by
        // ClaimDecisionService (ClaimDecisionController, REQ-CLM-012) and ClaimRecoveryService (ClaimRecoveryController), which
        // the claims web actions (ClaimActions / ClaimCaseActions) already drive. Kept for old app builds only.
        'POST /claims/{id}/decisions' => 'superseded by ClaimDecisionService',
        'POST /claims/{id}/recoveries' => 'superseded by ClaimRecoveryService',
        'POST /claims/{id}/recoveries/{recovery}/receipts' => 'superseded by ClaimRecoveryService',
        // Legacy alias of POST /quote-comparisons (REQ-DST-003, Wave10Controller::saveComparison forwards to the canonical controller).
        'POST /web-experiences/marketplace/comparisons' => 'alias of POST /quote-comparisons',
    ];

    /** @var array<string, string> filament file => contents */
    private array $filament = [];

    /** @var array<string, list<string>> method name => files calling ->method( */
    private array $methodIndex = [];

    /** @var array<string, list<string>> model short name => Filament resource files */
    private array $resourcesByModel = [];

    /** @var array<string, list<string>> "ShortController@method" => files running it via ControllerCall::invoke (inline-logic routes) */
    private array $controllerCalls = [];

    /** @var list<string> path regexes from account JS */
    private array $portalPaths = [];

    public function handle(): int
    {
        $this->loadFilament();
        $this->loadPortalPaths();
        $rows = [];
        foreach (RouteFacade::getRoutes()->getRoutes() as $route) {
            $uri = $route->uri();
            if (! str_starts_with($uri, 'api/v1/')) {
                continue;
            }
            foreach (array_diff($route->methods(), ['GET', 'HEAD', 'OPTIONS']) as $verb) {
                $rows[] = $this->analyse($route, $verb);
            }
        }
        usort($rows, fn ($a, $b) => [$a['domain'], $a['path'], $a['verb']] <=> [$b['domain'], $b['path'], $b['verb']]);

        $total = count($rows);
        $covered = count(array_filter($rows, fn ($r) => $r['covered']));
        $byClass = [];
        foreach ($rows as $r) {
            if (! $r['covered']) {
                $byClass[$r['classification']] = ($byClass[$r['classification']] ?? 0) + 1;
            }
        }
        ksort($byClass);
        // S3: denominator and numerator over the same rows (a MOBILE_ONLY_OK / SYSTEM_ONLY / DEPRECATED row that happens to be
        // covered used to count in the denominator only, so finding more web entry points could lower the percentage).
        $needsUi = count(array_filter($rows, fn ($r) => ! in_array($r['classification'], ['MOBILE_ONLY_OK', 'SYSTEM_ONLY', 'DEPRECATED'], true)));
        $coveredUi = count(array_filter($rows, fn ($r) => $r['covered'] && ! in_array($r['classification'], ['MOBILE_ONLY_OK', 'SYSTEM_ONLY', 'DEPRECATED'], true)));
        $report = [
            'generated_at' => now()->toIso8601String(),
            'totals' => [
                'actions' => $total,
                'covered' => $covered,
                'uncovered' => $total - $covered,
                'coverage_pct' => $total ? round($covered * 100 / $total, 1) : 0.0,
                'ui_relevant_actions' => $needsUi,
                'ui_relevant_covered' => $coveredUi,
                'ui_coverage_pct' => $needsUi ? round($coveredUi * 100 / $needsUi, 1) : 0.0,
                'uncovered_by_classification' => $byClass,
                'covered_via' => [
                    'filament' => count(array_filter($rows, fn ($r) => $r['filament_entry'] !== [])),
                    'account_portal' => count(array_filter($rows, fn ($r) => $r['portal_entry'])),
                ],
            ],
            'actions' => $rows,
        ];

        if (! $this->option('no-write')) {
            File::put(base_path((string) $this->option('json')), json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
            if ($md = $this->option('md')) {
                File::put(base_path((string) $md), $this->markdown($report));
            }
        }
        $t = $report['totals'];
        $this->line("actions={$t['actions']} covered={$t['covered']} coverage_pct={$t['coverage_pct']} ui_coverage_pct={$t['ui_coverage_pct']}");
        foreach ($byClass as $k => $v) {
            $this->line("  uncovered {$k}={$v}");
        }

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function analyse(Route $route, string $verb): array
    {
        $path = substr($route->uri(), strlen('api/v1'));
        $action = $route->getActionName();
        $middleware = $route->gatherMiddleware();
        $permissions = [];
        foreach ($middleware as $m) {
            if (is_string($m) && (str_starts_with($m, 'permission:') || str_starts_with($m, 'can:'))) {
                array_push($permissions, ...explode('|', explode(':', $m, 2)[1]));
            }
        }
        $services = $this->serviceCalls($action);
        $filament = [];
        foreach ($services as $call) {
            [$cls, $method] = explode('::', $call);
            $short = class_basename($cls);
            if (in_array($short, self::INFRASTRUCTURE, true)) {
                continue;
            }
            foreach ($this->methodIndex[$method] ?? [] as $file) {
                if (str_contains($this->filament[$file], $short)) {
                    $filament[] = $file;
                }
            }
        }
        if ($filament === [] && array_filter($services, fn ($c) => ! in_array(class_basename(explode('::', $c)[0]), self::INFRASTRUCTURE, true)) === []) {
            // No domain service: fall back to a Filament resource for the Eloquent model the controller writes.
            foreach ($this->modelsWritten($action) as $model) {
                foreach ($this->resourcesByModel[$model] ?? [] as $file) {
                    $src = $this->filament[$file];
                    $ok = match ($verb) {
                        'POST' => ! preg_match('#/\{[^/]+\}/[^/{]+$#', $path) && str_contains($src, "'create'"),
                        'PUT', 'PATCH' => str_contains($src, "'edit'") || str_contains($src, 'EditAction'),
                        'DELETE' => str_contains($src, 'DeleteAction') || str_contains($src, 'DeleteBulkAction'),
                        default => false,
                    };
                    if ($ok) {
                        $filament[] = $file;
                    }
                }
            }
        }
        [$ctlClass, $ctlMethod] = str_contains($action, '@') ? explode('@', $action) : [$action, '__invoke'];
        array_push($filament, ...($this->controllerCalls[class_basename($ctlClass).'@'.$ctlMethod] ?? []));
        $filament = array_values(array_unique($filament));
        $portal = $this->portalCalls($path);
        $covered = $filament !== [] || $portal;
        $deprecated = isset(self::DEPRECATED[$verb.' '.$path]) || str_contains(implode(' ', array_filter($middleware, 'is_string')), 'DeprecatedRouteAlias');
        $domain = $this->domain($path);

        return [
            'verb' => $verb,
            'path' => $path,
            'name' => $route->getName(),
            'controller' => $action,
            'services' => $services,
            'permissions' => array_values(array_unique($permissions)),
            'middleware' => array_values(array_filter($middleware, 'is_string')),
            'domain' => $domain,
            'filament_entry' => array_slice($filament, 0, 5),
            'portal_entry' => $portal,
            'covered' => $covered,
            'classification' => $deprecated ? 'DEPRECATED' : $this->classify($path, $middleware, $permissions),
        ];
    }

    /** @return list<string> Class::method calls on injected services inside the controller method. */
    private function serviceCalls(string $action): array
    {
        [$class, $method] = str_contains($action, '@') ? explode('@', $action) : [$action, '__invoke'];
        try {
            $ref = new ReflectionMethod($class, $method);
        } catch (Throwable) {
            return [];
        }
        $types = [];
        $classRef = new ReflectionClass($class);
        foreach ($classRef->getConstructor()?->getParameters() ?? [] as $p) {
            if ($p->getType() instanceof ReflectionNamedType && ! $p->getType()->isBuiltin()) {
                $types['this->'.$p->getName()] = $p->getType()->getName();
            }
        }
        foreach ($classRef->getProperties() as $prop) {
            if ($prop->getType() instanceof ReflectionNamedType && ! $prop->getType()->isBuiltin()) {
                $types['this->'.$prop->getName()] ??= $prop->getType()->getName();
            }
        }
        foreach ($ref->getParameters() as $p) {
            if ($p->getType() instanceof ReflectionNamedType && ! $p->getType()->isBuiltin()) {
                $types[$p->getName()] = $p->getType()->getName();
            }
        }
        $lines = file((string) $ref->getFileName());
        $body = implode('', array_slice($lines, $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1));
        // Q10 2026-09-29: follow one level of delegation to the controller's own helpers ($this->saveProfile(...),
        // $this->submitFiscal(...)), so an action whose service call sits in a shared private method is not missed.
        if (preg_match_all('/\$this->(\w+)\s*\(/', $body, $own)) {
            foreach (array_unique($own[1]) as $helper) {
                if ($helper !== $method && $classRef->hasMethod($helper) && ($h = $classRef->getMethod($helper))->getFileName() === $ref->getFileName()) {
                    $body .= implode('', array_slice($lines, $h->getStartLine() - 1, $h->getEndLine() - $h->getStartLine() + 1));
                }
            }
        }
        $calls = [];
        foreach ($types as $var => $type) {
            if (! preg_match('/\\\\(Application|Services?|Domain)\\\\|Service$/', $type)) {
                continue;
            }
            if (preg_match_all('/\$'.preg_quote($var, '/').'->(\w+)\s*\(/', $body, $m)) {
                foreach ($m[1] as $fn) {
                    $calls[] = $type.'::'.$fn;
                }
            }
        }
        // Q10 2026-09-29: stateless domain helpers built inline ((new ExpressionValidator)->validate(...)).
        if (preg_match_all('/\(new\s+\\\\?([\w\\\\]+)(?:\(\s*\))?\)->(\w+)\s*\(/', $body, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $calls[] = $hit[1].'::'.$hit[2];
            }
        }
        if (preg_match_all('/app\(\s*\\\\?([\w\\\\]+)::class\s*\)->(\w+)\s*\(/', $body, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                $calls[] = $hit[1].'::'.$hit[2];
            }
        }

        return array_values(array_unique($calls));
    }

    /** @return list<string> short names of App\Models classes the controller method writes or queries */
    private function modelsWritten(string $action): array
    {
        [$class, $method] = str_contains($action, '@') ? explode('@', $action) : [$action, '__invoke'];
        try {
            $ref = new ReflectionMethod($class, $method);
        } catch (Throwable) {
            return [];
        }
        $lines = file((string) $ref->getFileName());
        $body = implode('', array_slice($lines, $ref->getStartLine() - 1, $ref->getEndLine() - $ref->getStartLine() + 1));
        preg_match_all('/([A-Z]\w+)::(?:create|query|where|find|findOrFail|updateOrCreate|firstOrCreate|forceCreate|whereKey)|new ([A-Z]\w+)\(|function\s*\w*\([^)]*?([A-Z]\w+) \$\w+/', $body, $m);
        $names = array_filter(array_merge($m[1], $m[2], $m[3]));

        return array_values(array_unique(array_filter($names, fn ($n) => class_exists('App\\Models\\'.$n))));
    }

    private function portalCalls(string $path): bool
    {
        $re = '#^'.preg_replace('#\\\\\{[^/]+?\\\\\}#', '[^/]+', preg_quote($path, '#')).'$#';
        foreach ($this->portalPaths as $p) {
            if (preg_match($re, $p)) {
                return true;
            }
        }

        return false;
    }

    private function loadFilament(): void
    {
        foreach (File::allFiles(app_path('Filament')) as $f) {
            $this->filament[str_replace('\\', '/', $f->getRelativePathname())] = $f->getContents();
        }
        foreach (['Livewire', 'Http/Controllers/Web', 'Interfaces/Http/Controllers/Web'] as $extra) {
            if (is_dir(app_path($extra))) {
                foreach (File::allFiles(app_path($extra)) as $f) {
                    $this->filament[$extra.'/'.str_replace('\\', '/', $f->getRelativePathname())] = $f->getContents();
                }
            }
        }
        // Workspace portals (e.g. /provider) keep their Filament panels under app/Application/<Context>/Workspace/Filament.
        foreach (glob(app_path('Application/*/Workspace/Filament'), GLOB_ONLYDIR) ?: [] as $dir) {
            $rel = str_replace('\\', '/', substr($dir, strlen(app_path()) + 1));
            foreach (File::allFiles($dir) as $f) {
                $this->filament[$rel.'/'.str_replace('\\', '/', $f->getRelativePathname())] = $f->getContents();
            }
            // callWorkspace('<method>') runs that method of the workspace's API controller (referenced as X::class in the panel):
            // it covers the controller route exactly like ControllerCall::invoke.
            $panel = implode("\n", array_map(fn ($f) => $f->getContents(), File::allFiles($dir)));
            preg_match_all('/\b(\w+Controller)::class/', $panel, $ctl);
            foreach (File::allFiles($dir) as $f) {
                if (preg_match_all("/callWorkspace\(\s*'(\w+)'/", $f->getContents(), $cw)) {
                    foreach (array_unique($ctl[1]) as $controller) {
                        foreach (array_unique($cw[1]) as $method) {
                            if (method_exists($this->controllerFqcn($controller, $panel), $method)) {
                                $this->controllerCalls[$controller.'@'.$method][] = $rel.'/'.str_replace('\\', '/', $f->getRelativePathname());
                            }
                        }
                    }
                }
            }
        }
        foreach ($this->filament as $file => $src) {
            if (preg_match('/protected static \?string \$model = \\\\?(?:App\\\\Models\\\\)?(\w+)::class/', $src, $mm)) {
                $this->resourcesByModel[$mm[1]][] = $file;
            }
            if (preg_match_all("/ControllerCall::invoke\(\s*(\w+)::class,\s*'(\w+)'/", $src, $cc, PREG_SET_ORDER)) {
                foreach ($cc as $hit) {
                    $this->controllerCalls[$hit[1].'@'.$hit[2]][] = $file;
                }
            }
            if (preg_match_all('/->(\w+)\s*\(/', $src, $m)) {
                foreach (array_unique($m[1]) as $fn) {
                    $this->methodIndex[$fn][] = $file;
                }
            }
        }
    }

    /** Resolve a short controller name via the `use` statements of the panel sources. */
    private function controllerFqcn(string $short, string $src): string
    {
        return preg_match('/^use\s+([\w\\\\]+\\\\'.preg_quote($short, '/').');/m', $src, $m) ? $m[1] : $short;
    }

    private function loadPortalPaths(): void
    {
        $paths = [];
        // S3 2026-09-29: the website sign-up / sign-in script (public/landing/auth.js, /signup + /login) calls the API too.
        $files = File::allFiles(resource_path('views/public/account'));
        if (is_file(public_path('landing/auth.js'))) {
            $files[] = new \Symfony\Component\Finder\SplFileInfo(public_path('landing/auth.js'), '', 'auth.js');
        }
        foreach ($files as $f) {
            // A path expression: a leading '/...' literal followed by any `+ literal` / `+ variable` chain, e.g.
            // '/claims/' + c.id + '/payments/' + p.id + '/paid'. Variables become X, which only matches a {param}
            // segment of a route (a variable action suffix is therefore conservatively not counted).
            $lit = '(?:\'[^\'\n]*\'|"[^"\n]*"|`[^`\n]*`)';
            $pattern = '#([\'"`]/(?:mobile|me|quotes|proposals|payments|policies|claims|partner|public|kyc|documents|search|account|auth|signature-requests|invitations|quote-comparisons|support)[^\'"`\n]*[\'"`](?:\s*\+\s*(?:'.$lit.'|[\w.$\[\]]+(?:\([^()]*\))?))*)#';
            if (preg_match_all($pattern, $f->getContents(), $m)) {
                foreach ($m[1] as $expr) {
                    preg_match_all('#\s*(?:\+\s*)?('.$lit.'|[\w.$\[\]]+(?:\([^()]*\))?)#', $expr, $parts);
                    $p = '';
                    foreach ($parts[1] as $part) {
                        $p .= in_array($part[0], ["'", '"', '`'], true) ? substr($part, 1, -1) : 'X';
                    }
                    $p = preg_replace('#\$\{[^}]*\}#', 'X', explode('?', $p)[0]);
                    $paths[] = str_ends_with($p, '/') ? $p.'X' : $p;
                }
            }
        }
        $this->portalPaths = array_values(array_unique($paths));
    }

    private function domain(string $path): string
    {
        $segs = array_values(array_filter(explode('/', $path), fn ($s) => $s !== '' && ! str_starts_with($s, '{')
            && ! in_array($s, ['mobile', 'partner', 'agent', 'broker', 'carrier', 'insurer', 'me', 'admin', 'ops', 'public'], true)));
        $d = $segs[0] ?? 'root';
        $aliases = ['claim' => 'claims', 'policy' => 'policies', 'payment' => 'payments', 'quote' => 'quotes', 'proposal' => 'proposals',
            'bordereau' => 'bordereaux', 'settlement' => 'settlements', 'commission' => 'commissions', 'withdrawals' => 'commissions',
            'renewal' => 'renewals', 'policy-service-requests' => 'policies', 'wallet' => 'policies', 'purchases' => 'payments',
            'provider-workspace' => 'provider', 'providers' => 'provider', 'health' => 'provider'];

        return $aliases[$d] ?? $d;
    }

    /** @param  list<mixed>  $middleware  @param  list<string>  $permissions */
    private function classify(string $path, array $middleware, array $permissions): string
    {
        $mw = implode(' ', array_filter($middleware, 'is_string'));
        if (preg_match('#webhook|callback|/inbound|/outbox|/jobs?/|/scheduler|/cron|/sync/|/telemetry|/events/ingest|/ussd|/ipn#i', $path)
            || str_contains($mw, 'integration.client')) {
            return 'SYSTEM_ONLY';
        }
        if (preg_match('#/devices?(/|$)|/push|/fcm|attestation|/app-version|/biometric|/device-|/offline|/drafts/sync|/sessions/refresh|/auth/refresh|/pin(/|$)|/app/|^/mobile/uploads|^/auth/mobile/|crash-reports|offline-queue#i', $path)) {
            return 'MOBILE_ONLY_OK';
        }
        $staffSeg = preg_match('#/(partner|agent|broker|carrier|insurer|admin|ops|provider-workspace|provider)/#', $path.'/');
        $customerPerm = $permissions === [] || array_filter($permissions, fn ($p) => str_starts_with($p, 'customer.') || str_starts_with($p, 'self.')) !== [];
        if (! $staffSeg && preg_match('#^/(mobile|me|public|auth)/#', $path) && $customerPerm) {
            return 'CUSTOMER_WEB_NEEDED';
        }
        if (! $staffSeg && $permissions === [] && preg_match('#^/(proposals|quotes|quote-comparisons|claims|payments|policies|signature-requests|invitations|support)(/|$)#', $path)) {
            return 'CUSTOMER_WEB_NEEDED';
        }

        return 'STAFF_DESKTOP_NEEDED';
    }

    /** @param  array<string, mixed>  $report */
    private function markdown(array $report): string
    {
        $t = $report['totals'];
        $out = "# UI coverage (generated by `php artisan ui:coverage`)\n\n";
        $out .= "- State-changing actions: {$t['actions']}\n- Covered: {$t['covered']} ({$t['coverage_pct']}%)\n";
        $out .= "- UI-relevant (excl. MOBILE_ONLY_OK, SYSTEM_ONLY): {$t['ui_relevant_actions']}, covered {$t['ui_relevant_covered']} ({$t['ui_coverage_pct']}%)\n";
        foreach ($t['uncovered_by_classification'] as $k => $v) {
            $out .= "- Uncovered {$k}: {$v}\n";
        }
        $groups = [];
        foreach ($report['actions'] as $r) {
            if (! $r['covered']) {
                $groups[$r['classification']][$r['domain']][] = $r;
            }
        }
        foreach ($groups as $cls => $domains) {
            $out .= "\n## {$cls}\n";
            ksort($domains);
            foreach ($domains as $d => $rs) {
                $out .= "\n### {$d} (".count($rs).")\n\n";
                foreach ($rs as $r) {
                    $svc = $r['services'][0] ?? '-';
                    $perm = implode('|', $r['permissions']) ?: '-';
                    $out .= "- `{$r['verb']} {$r['path']}` — ".class_basename(explode('@', $r['controller'])[0]).'@'.(explode('@', $r['controller'])[1] ?? '')." — {$svc} — {$perm}\n";
                }
            }
        }

        return $out;
    }
}
