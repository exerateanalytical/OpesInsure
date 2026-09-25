<?php

declare(strict_types=1);

namespace App\Application\Security\AppLinks;

use Illuminate\Http\JsonResponse;

/**
 * REQ-MOB-007 verified app links, from config security_centre.app_links.
 * Unconfigured → an empty statement list / empty details (valid JSON that
 * verifies nothing), never a guessed package or team id.
 */
final class AppLinksController
{
    public function assetLinks(): JsonResponse
    {
        $c = config('security_centre.app_links.android', []);
        $statements = (! empty($c['package_name']) && ! empty($c['sha256_cert_fingerprints'])) ? [[
            'relation' => ['delegate_permission/common.handle_all_urls'],
            'target' => ['namespace' => 'android_app', 'package_name' => $c['package_name'], 'sha256_cert_fingerprints' => array_values($c['sha256_cert_fingerprints'])],
        ]] : [];

        return response()->json($statements, 200, ['Cache-Control' => 'public, max-age=3600'], JSON_UNESCAPED_SLASHES);
    }

    public function appleAppSiteAssociation(): JsonResponse
    {
        $c = config('security_centre.app_links.ios', []);
        $ids = array_values($c['app_ids'] ?? []);
        $details = $ids === [] ? [] : [['appIDs' => $ids, 'components' => array_map(fn ($p) => ['/' => $p], array_values($c['paths'] ?? []))]];

        return response()->json(['applinks' => ['details' => $details], 'webcredentials' => ['apps' => $ids]], 200, ['Cache-Control' => 'public, max-age=3600'], JSON_UNESCAPED_SLASHES);
    }
}
