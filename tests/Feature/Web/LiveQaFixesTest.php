<?php

declare(strict_types=1);

use App\Interfaces\Http\Middleware\PerRouteThrottle;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
 * Live QA 2026-09-30 (docs/LIVE_QA_2026-09-30.md) regression guards: responsive account pages, French strings,
 * /demo escaping, web page throttles and the /download page weight. Static checks plus two light HTTP renders.
 */

function liveQaRoot(): string
{
    return dirname(__DIR__, 3);
}

/** @return array<string, string> relative path => contents */
function liveQaViews(string $dir): array
{
    $out = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(liveQaRoot().'/'.$dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (str_ends_with($file->getFilename(), '.blade.php')) {
            $out[str_replace('\\', '/', substr($file->getPathname(), strlen(liveQaRoot()) + 1))] = (string) file_get_contents($file->getPathname());
        }
    }

    return $out;
}

it('has no fixed inline width or min-width above 360px in the account and auth pages', function () {
    $bad = [];
    foreach (liveQaViews('resources/views/public/account') + liveQaViews('resources/views/public/auth') as $path => $src) {
        if (preg_match_all('/(?<![-\w])(min-)?width:\s*(\d+)px/', $src, $m, PREG_SET_ORDER)) {
            foreach ($m as $hit) {
                if ((int) $hit[2] > 360) {
                    $bad[] = $path.': '.$hit[0];
                }
            }
        }
    }
    expect($bad)->toBe([]);
});

it('keeps the 360px overflow guards in the portal and auth stylesheets', function () {
    $portal = (string) file_get_contents(liveQaRoot().'/public/landing/portal/portal.css');
    $site = (string) file_get_contents(liveQaRoot().'/public/landing/site.css');

    expect($portal)->toContain('.acct-body .acard-h{flex-wrap:wrap')
        ->toContain('.acct-body .op-nlist li{flex-wrap:wrap}')
        ->toContain('.acct-body .atable.op-stack,.acct-body .atable.op-stack tbody')
        ->toContain('.acct-body .agrid>*')
        ->toContain('.acct-body .stepbar>li')
        ->and($site)->toContain('.auth-main>*{min-width:0}');
    // The dashboard action row is styled by class (wraps under the text on phones), not a fixed inline flex row.
    expect((string) file_get_contents(liveQaRoot().'/public/landing/portal/launch.js'))->toContain("class: 'lc-act-cta'");
});

it('translates the claim evidence hints, message statuses and demo role labels', function () {
    $fr = json_decode((string) file_get_contents(liveQaRoot().'/resources/lang/fr.json'), true, flags: JSON_THROW_ON_ERROR);
    foreach ([
        'Front, rear, both sides and close-ups of the damage.', 'Licence of the person driving at the time.',
        'Insurer and registration of the other vehicle.', 'Required for any collision involving a third party.',
        'Photos of the damage', "Driver's licence", 'Third-party details', 'Police report (procès-verbal)',
        'Claims manager', 'Claims officer', 'Open',
    ] as $key) {
        expect($fr)->toHaveKey($key);
        expect($fr[$key])->not->toBe($key);
    }

    expect(trans('account.js.status.OPEN', [], 'fr'))->toBe('Ouvert')
        ->and(trans('account.js.status.OPEN', [], 'en'))->toBe('Open')
        ->and(__('Licence of the person driving at the time.', [], 'fr'))->not->toContain('Licence of');

    expect((string) file_get_contents(liveQaRoot().'/resources/views/public/demo.blade.php'))->toContain("__(\$a['label'])");
});

it('has no literal \x27 escapes in any language file and renders the /demo heading unescaped', function () {
    $hits = [];
    foreach (glob(liveQaRoot().'/resources/lang/*/*.php') as $file) {
        if (str_contains((string) file_get_contents($file), '\x27')) {
            $hits[] = basename(dirname($file)).'/'.basename($file);
        }
    }
    expect($hits)->toBe([])
        ->and(trans('public.demo_heading', [], 'fr'))->toBe('Connectez-vous avec n’importe quel rôle');

    config(['demo.enabled' => true]);
    $this->get('/demo?lang=fr')->assertOk()
        ->assertSee('Connectez-vous avec n’importe quel rôle', false)
        ->assertDontSee('x27', false)
        ->assertSee('Responsable sinistres', false)
        ->assertDontSee('Claims manager', false);
});

it('allows at least 60 page GETs a minute on every throttled web route', function () {
    $low = [];
    foreach (Route::getRoutes() as $route) {
        if (! in_array('GET', $route->methods(), true) || str_starts_with($route->uri(), 'api/')) {
            continue;
        }
        foreach ($route->gatherMiddleware() as $mw) {
            if (is_string($mw) && preg_match('/^throttle:(\d+),(\d+)$/', $mw, $m) && ((int) $m[1] / max(1, (int) $m[2])) < 60) {
                $low[] = $route->uri().' '.$mw;
            }
        }
    }
    expect($low)->toBe([]);
    expect(collect(Route::getRoutes()->getByName('public.verify')?->gatherMiddleware())->contains('throttle:120,1'))->toBeTrue();
});

it('counts guest page GETs per route but keeps guest writes on one shared per-IP bucket', function () {
    $sig = function (string $method, string $uri) {
        $request = Request::create('/'.$uri, $method, server: ['REMOTE_ADDR' => '10.0.0.7']);
        $route = (new Illuminate\Routing\Route([$method], $uri, fn () => null))->bind($request);
        $request->setRouteResolver(fn () => $route);
        $mw = app(PerRouteThrottle::class);

        return (fn ($r) => $this->resolveRequestSignature($r))->call($mw, $request);
    };

    expect($sig('GET', 'verify'))->not->toBe($sig('GET', 'partners/apply'))
        ->and($sig('POST', 'contact'))->toBe($sig('POST', 'account/delete'));
});

it('serves the /download icon as a small resized image, not the 1.4 MB master', function () {
    $view = (string) file_get_contents(liveQaRoot().'/resources/views/public/download.blade.php');
    expect($view)->not->toContain("asset('img/app-icon.png')")->toContain('/landing/img/app-icon-208.webp');
    expect(filesize(liveQaRoot().'/public/landing/img/app-icon-208.png'))->toBeLessThan(80_000)
        ->and(filesize(liveQaRoot().'/public/landing/img/app-icon-208.webp'))->toBeLessThan(30_000);
});
