<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use Tests\TestCase;

/** /account/claims pages: server-rendered shells (data loads client-side from api/v1). */
final class AccountClaimsPagesTest extends TestCase
{
    public function test_claims_pages_render_in_both_languages(): void
    {
        foreach (['en', 'fr'] as $lang) {
            $this->get('/account/claims?lang='.$lang)->assertOk()->assertSee('data-tab="progress"', false)->assertSee('/landing/portal/claims.js', false);
            $this->get('/account/claims/new?lang='.$lang)->assertOk()->assertSee('data-form', false);
            $this->get('/account/claims/CLM-2026-000731?lang='.$lang)->assertOk()->assertSee('data-page-body', false);
        }
    }

    public function test_claims_copy_is_in_sync(): void
    {
        $keys = function (array $a, string $p = '') use (&$keys): array {
            $o = [];
            foreach ($a as $k => $v) {
                $o = is_array($v) && ! array_is_list($v) ? [...$o, ...$keys($v, "$p.$k")] : [...$o, "$p.$k"];
            }

            return $o;
        };
        $en = $keys(require lang_path('en/account_claims.php'));
        $fr = $keys(require lang_path('fr/account_claims.php'));
        $this->assertSame([], array_values(array_diff($en, $fr)));
        $this->assertSame([], array_values(array_diff($fr, $en)));
    }
}
