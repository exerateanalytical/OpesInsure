<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\Certificates;

use App\Application\Certificates\PublicVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public "is this vehicle/holder insured?" check by reference alone — the
 * number printed on a certificate or sticker (roadside check). Discloses
 * only validity, insurer, product class and the coverage window; never the
 * holder's name, premium or any personal data. Rate limited at the route;
 * every lookup, found or not, is logged (PublicVerificationService).
 *
 * The reference is matched against, in order: a certificate serial number,
 * a policy's certificate_number, a policy_number.
 * result: valid | expired | not_yet_active | suspended | cancelled | invalid | not_found
 */
final class PublicInsuranceVerifyController
{
    public function __invoke(Request $request, PublicVerificationService $verification): JsonResponse
    {
        $data = $request->validate(['reference' => 'required|string|min:3|max:100']);
        $result = $verification->lookup($data['reference'], null, 'API', $request->ip().'|'.$request->userAgent());
        if ($result['result'] === 'revoked' && ! isset($result['document'])) {
            $result['result'] = 'invalid'; // API vocabulary predates "revoked"
        }

        return response()->json(['data' => $result]);
    }
}
