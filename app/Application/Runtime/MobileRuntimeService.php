<?php

declare(strict_types=1);

namespace App\Application\Runtime;

/**
 * Backs GET /mobile/runtime/bootstrap — the first call the Expo app makes on
 * every cold start (see CLAUDE_MERGE_GUIDE.md, Patch 7 "Runtime bootstrap"
 * and the RuntimeBootstrap/RuntimeApi.bootstrap() contract in
 * overlay/src/api/client.ts). Public and rate-limited: it must answer before
 * the client has any session, and it is the single source of truth the
 * client is required to defer to for minimum-version/force-update,
 * maintenance state, service health and step-up/device-risk policy — the
 * client never decides these for itself.
 *
 * Config-driven (config/mobile_runtime.php) rather than DB/admin-UI-backed
 * in this batch — see that file's docblock and the batch report's
 * "remaining gaps" section.
 */
final class MobileRuntimeService
{
    /** @return array<string, mixed> */
    public function bootstrap(?string $clientVersion): array
    {
        $release = config('mobile_runtime.release');
        $forceUpdate = $clientVersion !== null && $clientVersion !== ''
            && $this->isOlder($clientVersion, (string) $release['minimum_version']);
        $updateRecommended = ! $forceUpdate && $clientVersion !== null && $clientVersion !== ''
            && $this->isOlder($clientVersion, (string) $release['latest_version']);

        return [
            'release' => [
                'minimum_version' => $release['minimum_version'],
                'latest_version' => $release['latest_version'],
                'force_update' => $forceUpdate,
                'update_recommended' => $updateRecommended,
                'store_url' => $release['store_url'] ?: null,
            ],
            'maintenance' => [
                'active' => (bool) config('mobile_runtime.maintenance.active'),
                'message' => config('mobile_runtime.maintenance.message'),
                'ends_at' => config('mobile_runtime.maintenance.ends_at'),
            ],
            'services' => collect(config('mobile_runtime.services'))
                ->map(fn ($status, $key) => ['key' => $key, 'status' => $status])
                ->values()->all(),
            'security' => [
                'step_up_ttl_seconds' => (int) config('mobile_runtime.step_up.grant_ttl_seconds'),
                'device_risk_action' => config('mobile_runtime.device_risk_action'),
            ],
        ];
    }

    private function isOlder(string $clientVersion, string $reference): bool
    {
        // version_compare() tolerates non-semver-strict input (e.g. missing
        // patch component) gracefully enough for a mobile version string;
        // a malformed client version compares as "older" (fail safe toward
        // prompting an update rather than silently trusting an unparsable
        // string).
        return version_compare($clientVersion, $reference, '<');
    }
}
