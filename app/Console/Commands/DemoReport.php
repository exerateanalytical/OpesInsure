<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/** S13 — shared console report for demo:exit / demo:enter. */
final class DemoReport
{
    public static function print(Command $c, array $plan, string $userVerb): void
    {
        $m = $plan['mode'];
        $c->line(sprintf('  %-28s %s', 'Demo mode now', ($m['enabled'] ? 'ON' : 'OFF').' (source: '.($m['stored'] === null ? '.env DEMO_MODE_ENABLED' : 'platform_settings').')'));
        $c->line(sprintf('  %-28s %s', 'Touched', 'demo:seed deploy step, fixed demo OTP, /demo + /api/v1/public/demo-accounts, dev-login + login-page demo list, demo payment settler, is_demo read filter'));
        $c->newLine();
        $c->line('  is_demo records (kept, hidden while demo mode is off):');
        $c->table(['table', 'demo rows'], collect($plan['records'])->map(fn ($n, $t) => [$t, $n])->values()->all());
        $c->line("  Demo users ({$userVerb}):");
        $c->table(['email', 'phone', 'status'], array_map(fn ($u) => [$u['email'], $u['phone'], $u['status']], $plan['users']));
        if ($plan['demo_tenant']) {
            $c->line(sprintf('  %-28s %s', 'Demo brokerage tenant', $plan['demo_tenant']['status']));
        }
    }
}
