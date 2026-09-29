<?php

declare(strict_types=1);

/**
 * R4 (launch 2026-10-02) regression: the main dashboards and lists stay under a query ceiling. Before R4 the same pages
 * issued 100-640 queries (a permission / membership / carrier lookup per tile, column and row action, one KPI query per
 * month of a chart, one visibility or "has X" query per row). A new N+1 shows up here as a count above the ceiling.
 * Measured on a warm page (views compiled) with the cross-request caches cleared, so cached KPI series cannot hide one.
 */

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\VolumeBook;

uses(RefreshDatabase::class);

const DQC_CEILING = 30;

function dqcCount(Closure $call): array
{
    $call(); // warm-up: Blade / Filament compile, boot-time caches
    Cache::flush();
    $n = 0;
    $seen = [];
    DB::listen(function ($q) use (&$n, &$seen) {
        $n++;
        $seen[] = getenv('DQC_DUMP') === 'full' ? $q->sql.' '.json_encode($q->bindings) : substr($q->sql, 0, 160);
    });
    $status = $call();
    if (getenv('DQC_DUMP') && $n > DQC_CEILING) {
        fwrite(STDERR, PHP_EOL.implode(PHP_EOL, $seen).PHP_EOL);
    }

    return [$status, $n];
}

it('keeps every main dashboard and list under the query ceiling', function () {
    // Small book, same shape as the launch volume profile: rows per page are what an N+1 multiplies.
    $v = VolumeBook::seed(policies: 600, claims: 150, payments: 600, carriers: 5, brokers: 10, customers: 200);
    $h = ['X-Tenant-Id' => $v['tenant']->id];
    $U = $v['users'];
    $web = fn (User $u, string $url) => function () use ($u, $url) {
        $this->flushSession();
        auth()->forgetGuards();

        return $this->actingAs($u)->get($url)->getStatusCode();
    };
    $api = fn (User $u, string $url) => function () use ($u, $url, $h) {
        auth()->forgetGuards();

        return $this->actingAs($u, 'api')->getJson($url, $h)->getStatusCode();
    };

    $pages = [
        '/insurer' => $web($U['insurer'], '/insurer'),
        '/insurer/production-dashboard' => $web($U['insurer'], '/insurer/production-dashboard'),
        '/insurer/portfolio-dashboard' => $web($U['insurer'], '/insurer/portfolio-dashboard'),
        '/insurer/claims-performance' => $web($U['insurer'], '/insurer/claims-performance'),
        '/insurer/broker-production' => $web($U['insurer'], '/insurer/broker-production'),
        '/insurer/product-performance' => $web($U['insurer'], '/insurer/product-performance'),
        '/insurer/policies' => $web($U['insurer'], '/insurer/policies'),
        '/insurer/claims' => $web($U['insurer'], '/insurer/claims'),
        '/insurer/underwriting-dashboard' => $web($U['underwriter'], '/insurer/underwriting-dashboard'),
        '/insurer/underwriting-cases' => $web($U['underwriter'], '/insurer/underwriting-cases'),
        '/broker' => $web($U['broker'], '/broker'),
        '/broker/customer-dashboard' => $web($U['broker'], '/broker/customer-dashboard'),
        '/broker/claims-dashboard' => $web($U['broker'], '/broker/claims-dashboard'),
        '/broker/kyc' => $web($U['broker'], '/broker/kyc'),
        '/broker/policy-dashboard' => $web($U['broker'], '/broker/policy-dashboard'),
        '/broker/payment-dashboard' => $web($U['broker'], '/broker/payment-dashboard'),
        '/broker/quote-dashboard' => $web($U['broker'], '/broker/quote-dashboard'),
        '/broker/policies' => $web($U['broker'], '/broker/policies'),
        '/admin/search' => $web($U['admin'], '/admin/search?q=R4POL-12'),
        'GET search' => $api($U['admin'], '/api/v1/search?q=R4POL-12'),
        'GET agent payments' => $api($U['agent'], '/api/v1/mobile/partner/agent/payments'),
        'GET agent claims' => $api($U['agent'], '/api/v1/mobile/partner/agent/claims'),
        'GET distribution catalogue' => $api($U['admin'], '/api/v1/distribution/catalogue'),
        'GET mobile wallet' => $api($U['customer'], '/api/v1/mobile/wallet'),
        'GET mobile proposals' => $api($U['customer'], '/api/v1/mobile/proposals'),
    ];

    $over = [];
    foreach ($pages as $page => $call) {
        [$status, $n] = dqcCount($call);
        expect($status)->toBe(200, $page);
        if ($n > DQC_CEILING) {
            $over[$page] = $n;
        }
    }
    expect($over)->toBe([], 'pages above '.DQC_CEILING.' queries: '.json_encode($over));
});

it('answers row-action visibility for a whole list page in one query per rule', function () {
    // 10 rows on the broker quote dashboard, 5 "has X" rules per row: without batching this was 50+ exists() queries.
    $v = VolumeBook::seed(policies: 200, claims: 20, payments: 50, carriers: 2, brokers: 2, customers: 60);
    $this->actingAs($v['users']['broker'])->get('/broker/quote-dashboard')->assertOk();
    $n = 0;
    DB::listen(function ($q) use (&$n) {
        if (preg_match('/^select exists\(select \* from "(quote_offers|carrier_quote_requests)"/', $q->sql)) {
            $n++;
        }
    });
    $this->flushSession();
    $this->actingAs($v['users']['broker'])->get('/broker/quote-dashboard')->assertOk();
    expect($n)->toBe(0);
});
