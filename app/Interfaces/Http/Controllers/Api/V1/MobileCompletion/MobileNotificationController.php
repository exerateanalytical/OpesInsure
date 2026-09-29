<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\MobileCompletion;

use App\Application\Notifications\NotificationCatalog;
use App\Models\UserNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * notifications/* screens — the signed-in user's own inbox only. Title/body
 * come back in the app language (Accept-Language, else users.locale); see
 * NotificationCatalog.
 */
final class MobileNotificationController
{
    public function index(Request $request): JsonResponse
    {
        $locale = NotificationCatalog::requestLocale($request);

        return response()->json(['data' => UserNotification::where('user_id', $request->user()->id)->orderByDesc('created_at')->limit(100)->get()->map(fn (UserNotification $n) => $n->toMobile($locale))->values()]);
    }

    public function show(string $notification, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->owned($notification, $request)->toMobile(NotificationCatalog::requestLocale($request))]);
    }

    public function markRead(string $notification, Request $request): JsonResponse
    {
        $row = $this->owned($notification, $request);
        if (! $row->read_at) {
            $row->update(['read_at' => now()]);
        }

        return response()->json(['data' => $row->refresh()->toMobile(NotificationCatalog::requestLocale($request))]);
    }

    public function markAllRead(Request $request): JsonResponse
    {
        $updated = UserNotification::where('user_id', $request->user()->id)->whereNull('read_at')->update(['read_at' => now()]);

        return response()->json(['data' => ['updated' => $updated]]);
    }

    private function owned(string $id, Request $request): UserNotification
    {
        return UserNotification::where('user_id', $request->user()->id)->findOrFail($id);
    }
}
