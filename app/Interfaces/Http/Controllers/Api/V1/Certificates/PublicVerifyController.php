<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\Certificates;

use App\Application\Documents\Verification\PublicVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * POST public/verify — the canonical public verification API (REQ-DUP-015,
 * WF-082). `reference` is a document verification code, a certificate
 * serial, a policy certificate number or a policy number. With `token` (the
 * QR token) the lookup is token-gated like the QR page: a wrong token gets the
 * same NOT_FOUND answer as an unknown reference. Minimal disclosure only.
 * data.status: VALID | EXPIRED | REVOKED | REPLACED | NOT_FOUND | NOT_YET_ACTIVE.
 */
final class PublicVerifyController
{
    public function __invoke(Request $request, PublicVerificationService $verification): JsonResponse
    {
        $data = $request->validate(['reference' => 'required|string|min:3|max:100', 'token' => 'nullable|string|max:128']);
        $token = $data['token'] ?? null;
        $channel = $token !== null && $token !== '' ? 'QR_PAGE' : 'API';

        return response()->json(['data' => $verification->lookup($data['reference'], $token, $channel, $request->ip().'|'.$request->userAgent())]);
    }
}
