<?php

declare(strict_types=1);

namespace App\Application\Runtime;

use App\Models\TelemetryEvent;
use Illuminate\Validation\ValidationException;

/**
 * Backs POST /mobile/runtime/telemetry (see CLAUDE_MERGE_GUIDE.md, Patch 7
 * "Telemetry contract"): an allowlisted, non-PII, client-reported
 * crash/error/usage event. Deliberately reachable without a session (a
 * crash on the splash screen happens before login — see
 * RuntimeApi.telemetry() in overlay/src/api/client.ts, called with
 * `anonymous: true`) — there is intentionally no user/device identifier
 * anywhere in this contract.
 *
 * The event-name and attribute-key allowlists in config/mobile_runtime.php
 * ARE the "reject arbitrary nested payloads / never accept PII" control:
 * unknown attribute keys or non-scalar values fail the whole request rather
 * than being silently dropped, so a client bug that leaks a stray object or
 * a PII-shaped field is loud, not swallowed.
 */
final class MobileTelemetryService
{
    private const MAX_ATTRIBUTE_VALUE_LENGTH = 200;

    /** @param array<string, mixed> $data */
    public function ingest(array $data, string $ip): TelemetryEvent
    {
        return TelemetryEvent::create([
            'event_name' => $data['event'],
            'correlation_id' => $data['correlation_id'],
            'app_version' => $data['app_version'],
            'release_channel' => $data['release_channel'],
            'attributes' => $this->sanitizeAttributes($data['attributes'] ?? []),
            'ip_hash' => hash('sha256', $ip),
            'created_at' => now(),
        ]);
    }

    /**
     * @param  array<mixed, mixed>  $attributes
     * @return array<string, scalar|null>
     */
    private function sanitizeAttributes(array $attributes): array
    {
        $allowed = config('mobile_runtime.telemetry.attributes');
        $clean = [];

        foreach ($attributes as $key => $value) {
            if (! is_string($key) || ! in_array($key, $allowed, true)) {
                throw ValidationException::withMessages(['attributes' => __('wave12.telemetry_attribute_not_allowed', ['key' => is_string($key) ? $key : (string) $key])]);
            }

            if ($value !== null && ! is_scalar($value)) {
                throw ValidationException::withMessages(['attributes' => __('wave12.telemetry_attribute_not_scalar', ['key' => $key])]);
            }

            $clean[$key] = is_string($value) ? mb_substr($value, 0, self::MAX_ATTRIBUTE_VALUE_LENGTH) : $value;
        }

        return $clean;
    }
}
