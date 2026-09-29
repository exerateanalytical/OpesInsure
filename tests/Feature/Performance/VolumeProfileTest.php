<?php

declare(strict_types=1);

/**
 * R4 (launch 2026-10-02) volume profile — opt-in (R4_PROFILE=1): seeds 20k policies / 5k claims / 50k payments across
 * 30 carriers and 150 brokers (Tests\Support\VolumeBook) and reports, per dashboard / list, the query count, SQL time
 * and wall time of a warm request (DB::listen). R4_PROFILE_OUT=<file> also writes the table there.
 */

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\VolumeBook;

uses(RefreshDatabase::class);

/** @return array{status:int, queries:int, sql_ms:float, wall_ms:float, top:list<string>} */
function r4Measure(Closure $call): array
{
    $n = 0;
    $sql = 0.0;
    $seen = [];
    $slow = [];
    DB::listen(function ($q) use (&$n, &$sql, &$seen, &$slow) {
        if (! ($GLOBALS['r4_on'] ?? false)) {
            return;
        }
        $n++;
        $sql += $q->time;
        $k = preg_replace('/\s+/', ' ', substr($q->sql, 0, 140));
        $seen[$k] = ($seen[$k] ?? 0) + 1;
        $slow[] = [$q->time, $q->sql, $q->bindings];
    });
    $GLOBALS['r4_on'] = true;
    $t0 = hrtime(true);
    $status = $call();
    $wall = (hrtime(true) - $t0) / 1e6;
    $GLOBALS['r4_on'] = false;
    arsort($seen);
    usort($slow, fn ($a, $b) => $b[0] <=> $a[0]);
    $slowest = [];
    foreach (array_slice(array_filter($slow, fn ($s) => $s[0] >= 20), 0, 3) as [$ms, $text, $bindings]) {
        $line = 'SLOW '.round($ms, 1).'ms '.preg_replace('/\s+/', ' ', $text).' '.json_encode($bindings);
        if (getenv('R4_EXPLAIN') && preg_match('/^\s*select/i', $text)) {
            $plan = collect(DB::select('EXPLAIN (ANALYZE, BUFFERS OFF) '.$text, $bindings))->map(fn ($r) => (array) $r)->flatten()->implode(PHP_EOL.'          ');
            $line .= PHP_EOL.'          '.$plan;
        }
        $slowest[] = $line;
    }

    return ['status' => $status, 'queries' => $n, 'sql_ms' => round($sql, 1), 'wall_ms' => round($wall, 1),
        'top' => [...array_map(fn ($k, $c) => $c.'x '.$k, array_slice(array_keys($seen), 0, $shown = (int) (getenv('R4_TOP_N') ?: 4)), array_slice($seen, 0, $shown)), ...$slowest]];
}

it('profiles every dashboard and list at launch volume', function () {
    $v = VolumeBook::seed();
    $t = $v['tenant']->id;
    $h = ['X-Tenant-Id' => $t];

    $web = fn (User $u, string $url) => function () use ($u, $url) {
        $this->flushSession();
        auth()->forgetGuards();

        return $this->actingAs($u)->get($url)->getStatusCode();
    };
    $api = fn (User $u, string $url) => function () use ($u, $url, $h) {
        auth()->forgetGuards();

        return $this->actingAs($u, 'api')->getJson($url, $h)->getStatusCode();
    };
    $U = $v['users'];
    $pages = [
        'insurer /insurer' => $web($U['insurer'], '/insurer'),
        'insurer production' => $web($U['insurer'], '/insurer/production-dashboard'),
        'insurer portfolio' => $web($U['insurer'], '/insurer/portfolio-dashboard'),
        'insurer claims-performance' => $web($U['insurer'], '/insurer/claims-performance'),
        'insurer broker-production' => $web($U['insurer'], '/insurer/broker-production'),
        'insurer product-performance' => $web($U['insurer'], '/insurer/product-performance'),
        'insurer intermediaries' => $web($U['insurer'], '/insurer/intermediaries'),
        'insurer policies list' => $web($U['insurer'], '/insurer/policies'),
        'insurer claims list' => $web($U['insurer'], '/insurer/claims'),
        'insurer uw dashboard' => $web($U['underwriter'], '/insurer/underwriting-dashboard'),
        'insurer uw cases list' => $web($U['underwriter'], '/insurer/underwriting-cases'),
        'broker /broker' => $web($U['broker'], '/broker'),
        'broker customer-dashboard' => $web($U['broker'], '/broker/customer-dashboard'),
        'broker claims-dashboard' => $web($U['broker'], '/broker/claims-dashboard'),
        'broker kyc' => $web($U['broker'], '/broker/kyc'),
        'broker policy-dashboard' => $web($U['broker'], '/broker/policy-dashboard'),
        'broker payment-dashboard' => $web($U['broker'], '/broker/payment-dashboard'),
        'broker quote-dashboard' => $web($U['broker'], '/broker/quote-dashboard'),
        'broker policies list' => $web($U['broker'], '/broker/policies'),
        'admin search' => $web($U['admin'], '/admin/search?q=R4POL-1234'),
        'api search' => $api($U['admin'], '/api/v1/search?q=R4POL-1234'),
        'api carrier partners' => $api($U['insurer'], '/api/v1/mobile/partner/carrier/partners'),
        'api broker policies' => $api($U['broker'], '/api/v1/mobile/partner/broker/policies'),
        'api broker claims' => $api($U['broker'], '/api/v1/mobile/partner/broker/claims'),
        'api agent policies' => $api($U['agent'], '/api/v1/mobile/partner/agent/policies'),
        'api agent claims' => $api($U['agent'], '/api/v1/mobile/partner/agent/claims'),
        'api agent payments' => $api($U['agent'], '/api/v1/mobile/partner/agent/payments'),
        'api agent service-requests' => $api($U['agent'], '/api/v1/mobile/partner/agent/service-requests'),
        'api catalogue (broker)' => $api($U['admin'], '/api/v1/distribution/catalogue?partner_id='.$v['brokers'][1]['partner']),
        'api catalogue (direct)' => $api($U['admin'], '/api/v1/distribution/catalogue'),
        'account wallet' => $api($U['customer'], '/api/v1/mobile/wallet'),
        'account payments' => $api($U['customer'], '/api/v1/mobile/payments'),
        'account claims' => $api($U['customer'], '/api/v1/mobile/claims'),
        'account quotes' => $api($U['customer'], '/api/v1/mobile/quotes'),
        'account proposals' => $api($U['customer'], '/api/v1/mobile/proposals'),
    ];
    $only = getenv('R4_ONLY') ?: null;
    $lines = [sprintf('%-30s %6s %7s %9s %9s', 'page', 'status', 'queries', 'sql_ms', 'wall_ms')];
    foreach ($pages as $name => $call) {
        if ($only !== null && ! str_contains($name, $only)) {
            continue;
        }
        r4Measure($call); // warm (view compilation, boot caches)
        $m = r4Measure($call);
        $lines[] = sprintf('%-30s %6d %7d %9.1f %9.1f', $name, $m['status'], $m['queries'], $m['sql_ms'], $m['wall_ms']);
        if (getenv('R4_TOP')) {
            foreach ($m['top'] as $q) {
                $lines[] = '      '.$q;
            }
        }
    }
    $out = implode(PHP_EOL, $lines).PHP_EOL;
    fwrite(STDERR, PHP_EOL.$out);
    if ($f = getenv('R4_PROFILE_OUT')) {
        file_put_contents($f, $out);
    }
    expect(true)->toBeTrue();
})->skip(fn () => ! getenv('R4_PROFILE'), 'volume profile is opt-in (R4_PROFILE=1)');
