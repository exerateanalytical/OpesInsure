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
        $mine = UserNotification::where('user_id', $request->user()->id);
        // meta.unread_count feeds the web header badge and the action centre (?unread=1&per_page=1 asks for just the count).
        $unread = (clone $mine)->whereNull('read_at')->count();
        $rows = $request->boolean('unread') ? (clone $mine)->whereNull('read_at') : $mine;
        $limit = max(1, min(100, (int) $request->query('per_page', 100)));

        return response()->json(['data' => $rows->orderByDesc('created_at')->limit($limit)->get()->map(fn (UserNotification $n) => $n->toMobile($locale))->values(),
            'meta' => ['unread_count' => $unread]]);
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
