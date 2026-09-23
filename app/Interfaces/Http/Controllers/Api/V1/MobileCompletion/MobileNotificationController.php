<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\MobileCompletion;

use App\Models\UserNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** notifications/* screens — the signed-in user's own inbox only. */
final class MobileNotificationController
{
    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => UserNotification::where('user_id', $request->user()->id)->orderByDesc('created_at')->limit(100)->get()->map->toMobile()->values()]);
    }

    public function show(string $notification, Request $request): JsonResponse
    {
        return response()->json(['data' => $this->owned($notification, $request)->toMobile()]);
    }

    public function markRead(string $notification, Request $request): JsonResponse
    {
        $row = $this->owned($notification, $request);
        if (! $row->read_at) {
            $row->update(['read_at' => now()]);
        }

        return response()->json(['data' => $row->refresh()->toMobile()]);
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
