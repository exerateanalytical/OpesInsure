<?php

declare(strict_types=1);

namespace App\Application\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * Reusable 409 optimistic-concurrency guard for PATCH/PUT-style mobile
 * update endpoints. This app had no shared convention for this before —
 * every service either silently last-write-wins or invents its own check.
 *
 * Usage (inside any Application-layer service, before mutating a row):
 *
 *   use EnforcesOptimisticConcurrency;
 *
 *   public function updateAddress(..., ?string $clientVersion = null): FulfilmentOrder
 *   {
 *       $order = $this->owned(...);
 *       $this->assertNotStale($order, $clientVersion);
 *       $order->update([...]);
 *   }
 *
 * $clientVersion is whatever version marker the client last saw — normally
 * the `updated_at` value it received on the resource's last GET/show
 * response, echoed back as a `version` field on the PUT/PATCH body. Compare
 * it against the CURRENT row (fetched fresh, before the mutation) — a
 * mismatch means someone else changed the record since the client last read
 * it, so we reject with 409 rather than silently overwrite their edit.
 *
 * Opt-in / non-breaking by design: passing null (the default) skips the
 * check entirely. This lets an existing endpoint adopt the trait without
 * breaking callers who don't yet send a version — a client that does
 * participate gets real protection; one that doesn't gets today's existing
 * (last-write-wins) behaviour, unchanged. A future batch that wants to make
 * participation mandatory on a given endpoint should validate the field as
 * `required` in its controller (or check for null itself before calling
 * assertNotStale) — that policy decision belongs to the endpoint, not here.
 *
 * Works against either:
 *  - a timestamp column (the default: 'updated_at') — compared as a
 *    datetime via Carbon, so the client's and server's timestamp string
 *    formats don't have to match byte-for-byte, or
 *  - an explicit version column where the resource has one — pass
 *    $column: 'version' and compare as a plain string/int.
 *
 * On mismatch, throws Laravel's standard ValidationException shape
 * ({"message": ..., "errors": {...}}) but with ->status set to 409 instead
 * of the usual 422: this is a conflict with the current state of the
 * resource (RFC 9110 409), not a malformed-input problem, and the mobile
 * client is expected to show a "someone else changed this" review state
 * rather than a plain form-validation error. The errors bag's
 * `current_version` entry carries the authoritative value machine-readably,
 * alongside the human-readable `version` message, while keeping the
 * response inside this app's single-top-level-key {"errors": ...} envelope.
 */
trait EnforcesOptimisticConcurrency
{
    protected function assertNotStale(Model $model, string|int|null $clientVersion, string $column = 'updated_at'): void
    {
        if ($clientVersion === null) {
            return;
        }

        $current = $model->getAttribute($column);

        if ($this->isStale($current, $clientVersion)) {
            throw ValidationException::withMessages([
                'version' => [__('wave12.stale_version')],
                'current_version' => [$this->comparableVersion($current)],
            ])->status(409);
        }
    }

    private function isStale(mixed $current, string|int $clientVersion): bool
    {
        if ($current instanceof \DateTimeInterface) {
            try {
                return ! Carbon::parse((string) $clientVersion)->equalTo($current);
            } catch (\Throwable) {
                return true; // Unparseable client version is itself a mismatch.
            }
        }

        return (string) $clientVersion !== (string) $current;
    }

    private function comparableVersion(mixed $current): string
    {
        return $current instanceof \DateTimeInterface ? $current->toIso8601String() : (string) $current;
    }
}
