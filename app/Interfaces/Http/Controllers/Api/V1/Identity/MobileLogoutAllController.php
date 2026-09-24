<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\Identity;

use App\Application\Audit\AuditWriter;
use App\Models\MobileRefreshToken;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Passport\Passport;

/**
 * POST /auth/mobile/logout-all — "sign out everywhere": revokes every
 * Passport access token and every refresh token (all families, all devices)
 * of the calling user, including the one making this request.
 */
final class MobileLogoutAllController
{
    public function __invoke(Request $request, AuditWriter $audit): JsonResponse
    {
        $user = $request->user();

        $tokenIds = Passport::token()->newQuery()->where('user_id', $user->id)->where('revoked', false)->pluck('id');
        $access = Passport::token()->newQuery()->whereIn('id', $tokenIds)->update(['revoked' => true]);
        Passport::refreshToken()->newQuery()->whereIn('access_token_id', $tokenIds)->update(['revoked' => true]);
        $refresh = MobileRefreshToken::where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);

        $audit->record('mobile.session.revoked_all', 'user', $user->id, ['access_tokens' => $access, 'refresh_tokens' => $refresh]);

        return response()->json(['data' => ['revoked' => true, 'access_tokens_revoked' => $access, 'refresh_tokens_revoked' => $refresh]]);
    }
}
