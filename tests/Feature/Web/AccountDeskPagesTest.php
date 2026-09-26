<?php

declare(strict_types=1);

// Claims Desk pages (/account/claims-desk/*): server-rendered shells that load data client-side via desk.js.

dataset('desk pages', [
    'list' => ['/account/claims-desk', 'Claims Management'],
    'show' => ['/account/claims-desk/ea5d30a0-05e7-4839-8c9f-8a0c85ce6c01', 'Claim Details'],
    'assess' => ['/account/claims-desk/ea5d30a0-05e7-4839-8c9f-8a0c85ce6c01/assess', 'Assess Claim'],
    'approve' => ['/account/claims-desk/ea5d30a0-05e7-4839-8c9f-8a0c85ce6c01/approve', 'Approve Claim'],
    'payment' => ['/account/claims-desk/ea5d30a0-05e7-4839-8c9f-8a0c85ce6c01/payment', 'Process Payment'],
    'paid' => ['/account/claims-desk/ea5d30a0-05e7-4839-8c9f-8a0c85ce6c01/paid', 'Payment Confirmed'],
]);

it('renders the claims desk page shell', function (string $url, string $title) {
    $this->get($url)->assertOk()->assertSee($title)->assertSee('/landing/portal/desk.js', false)->assertSee('window.DESK_T', false);
})->with('desk pages');

it('renders the claims desk in French', function () {
    $this->get('/account/claims-desk?lang=fr')->assertOk()->assertSee('Gestion des sinistres');
});

it('keeps the EN and FR desk copy in sync', function () {
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
    $en = $keys(require lang_path('en/account_desk.php'));
    $fr = $keys(require lang_path('fr/account_desk.php'));
    expect(array_values(array_diff($en, $fr)))->toBe([])->and(array_values(array_diff($fr, $en)))->toBe([]);
});
