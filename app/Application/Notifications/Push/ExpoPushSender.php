<?php

declare(strict_types=1);

namespace App\Application\Notifications\Push;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends to Expo's push service (https://exp.host/--/api/v2/push/send) for
 * every "expo" token a user registered via POST /mobile/account/push-tokens.
 * FCM tokens are stored but not sent here: the app registers Expo tokens,
 * and Expo relays to FCM/APNs itself.
 *
 * Per-ticket errors are logged and recorded on the token row;
 * DeviceNotRegistered tokens are deleted, per Expo's guidance, so a dead
 * install stops being retried.
 */
final class ExpoPushSender
{
    /** @return array{sent:int, failed:int} */
    public function sendToUser(string $userId, string $title, string $body, array $data = []): array
    {
        $tokens = DB::table('user_push_tokens')->where('user_id', $userId)->where('provider', 'expo')->pluck('token')->all();
        if ($tokens === []) {
            return ['sent' => 0, 'failed' => 0];
        }

        $messages = array_map(fn (string $token) => [
            'to' => $token, 'title' => $title, 'body' => $body, 'data' => (object) $data,
            'sound' => 'default', 'priority' => 'high', 'channelId' => 'default',
        ], $tokens);

        $request = Http::acceptJson()->asJson()->timeout(15);
        if ($accessToken = config('lifecycle.push.expo_access_token')) {
            $request = $request->withToken((string) $accessToken);
        }

        try {
            $response = $request->post((string) config('lifecycle.push.expo_url'), $messages);
        } catch (Throwable $e) {
            Log::warning('push.expo.transport_failed', ['user_id' => $userId, 'error' => $e->getMessage()]);
            throw $e; // let the queue retry transport failures
        }

        if (! $response->successful()) {
            Log::warning('push.expo.rejected', ['user_id' => $userId, 'status' => $response->status(), 'body' => mb_substr($response->body(), 0, 500)]);

            return ['sent' => 0, 'failed' => count($tokens)];
        }

        $sent = 0;
        $failed = 0;
        foreach ((array) $response->json('data', []) as $i => $ticket) {
            $token = $tokens[$i] ?? null;
            if (($ticket['status'] ?? null) === 'ok') {
                $sent++;
                continue;
            }
            $failed++;
            $reason = (string) ($ticket['details']['error'] ?? $ticket['message'] ?? 'UNKNOWN');
            Log::warning('push.expo.ticket_error', ['user_id' => $userId, 'reason' => $reason]);
            if ($token === null) {
                continue;
            }
            if ($reason === 'DeviceNotRegistered') {
                DB::table('user_push_tokens')->where('token', $token)->delete();
            } else {
                DB::table('user_push_tokens')->where('token', $token)->update(['last_failure_at' => now(), 'last_failure_reason' => mb_substr($reason, 0, 255)]);
            }
        }

        return ['sent' => $sent, 'failed' => $failed];
    }
}
