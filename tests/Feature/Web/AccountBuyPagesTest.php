<?php

// Quote & purchase pages of the signed-in account area render (data loads client-side from the API).
$uuid = '01a0dd0a-37bc-72ac-a20e-2ce1a0f6373a';

it('renders the quote & purchase pages', function (string $path) {
    $this->get($path)->assertOk()->assertSee('/landing/portal/buy.js', false);
})->with([
    '/account/buy',
    '/account/buy?product=CHANAS-AUTO',
    '/account/quotes',
    "/account/quotes/{$uuid}",
    "/account/quotes/{$uuid}/customize",
    "/account/quotes/{$uuid}/review",
    "/account/quotes/{$uuid}/confirmation",
]);

it('renders in French', function () {
    $this->get('/account/buy?lang=fr')->assertOk()->assertSee('Nouveau devis');
});

it('keeps the English and French strings in sync', function () {
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
    expect($keys(require lang_path('fr/account_buy.php')))->toEqual($keys(require lang_path('en/account_buy.php')));
});
