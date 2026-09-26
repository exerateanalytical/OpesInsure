<?php

declare(strict_types=1);

// Agent / broker pages (/account/customers, leads, reports, commissions): server-rendered shells that load data client-side via agent.js.

dataset('agent pages', [
    'customers' => ['/account/customers', 'Customers'],
    'client' => ['/account/customers/ea5d30a0-05e7-4839-8c9f-8a0c85ce6c01', 'Client Details'],
    'leads' => ['/account/leads', 'Leads'],
    'reports' => ['/account/reports', 'Reports'],
    'commissions' => ['/account/commissions', 'Commissions'],
]);

it('renders the agent page shell', function (string $url, string $title) {
    $this->get($url)->assertOk()->assertSee($title)->assertSee('/landing/portal/agent.js', false)->assertSee('window.AGENT_T', false);
})->with('agent pages');

it('renders the agent pages in French', function () {
    $this->get('/account/leads?lang=fr')->assertOk()->assertSee('Prospects');
    $this->get('/account/reports?lang=fr')->assertOk()->assertSee('Rapports');
});

it('keeps the EN and FR agent copy in sync', function () {
    $keys = function (array $a, string $p = '') use (&$keys): array {
        $out = [];
        foreach ($a as $k => $v) {
            $out[] = $p.$k;
            if (is_array($v) && ! array_is_list($v)) {
                $out = array_merge($out, $keys($v, $p.$k.'.'));
            }
        }

        return $out;
    };
    $en = $keys(require lang_path('en/account_agent.php'));
    $fr = $keys(require lang_path('fr/account_agent.php'));
    expect(array_values(array_diff($en, $fr)))->toBe([])->and(array_values(array_diff($fr, $en)))->toBe([]);
});
