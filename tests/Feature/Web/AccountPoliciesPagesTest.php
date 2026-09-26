<?php

declare(strict_types=1);

namespace Tests\Feature\Web;

use Tests\TestCase;

/** Policies-area /account pages: server-rendered shells (data loads client-side from api/v1). */
final class AccountPoliciesPagesTest extends TestCase
{
    public function test_policies_area_pages_render_in_both_languages(): void
    {
        $pages = [
            '/account' => 'data-recent', '/account/policies' => 'data-page-body', '/account/policies/4607772f-b8bf-4344-84f9-46e5f804b0c4' => 'data-page-body',
            '/account/payments' => 'data-page-body', '/account/payments/new?policy=4607772f-b8bf-4344-84f9-46e5f804b0c4' => 'data-page-body',
            '/account/documents' => 'data-cats', '/account/vehicles' => 'data-detail', '/account/profile' => 'data-cust',
            '/account/notifications' => 'data-page-body', '/account/support' => 'data-new',
        ];
        foreach (['en', 'fr'] as $lang) {
            foreach ($pages as $url => $marker) {
                $this->get($url.(str_contains($url, '?') ? '&' : '?').'lang='.$lang)->assertOk()->assertSee($marker, false)
                    ->assertSee('/landing/portal/policies.js', false)->assertSee('/landing/portal/policies.css', false)->assertSee('window.OPES_POL', false);
            }
        }
    }

    public function test_policies_copy_is_in_sync(): void
    {
        $keys = function (array $a, string $p = '') use (&$keys): array {
            $o = [];
            foreach ($a as $k => $v) {
                $o = is_array($v) && ! array_is_list($v) ? [...$o, ...$keys($v, "$p.$k")] : [...$o, "$p.$k"];
            }

            return $o;
        };
        $en = $keys(require lang_path('en/account_policies.php'));
        $fr = $keys(require lang_path('fr/account_policies.php'));
        $this->assertSame([], array_values(array_diff($en, $fr)));
        $this->assertSame([], array_values(array_diff($fr, $en)));
    }
}
