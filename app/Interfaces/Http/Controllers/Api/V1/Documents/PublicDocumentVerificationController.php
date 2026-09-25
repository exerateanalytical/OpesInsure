<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\Documents;

use App\Application\Documents\Security\DocumentSigner;
use App\Application\Documents\Security\DocumentVerificationPresenter;
use App\Application\Documents\Security\VerificationCredentials;
use App\Models\Document;
use App\Models\Policy;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Digital verification API (crypto spec §45): GET /api/v1/public/verify-document/{token} with the random
 * QR token (never an internal id). Optional ?document_number= enables the field-consistency tamper check
 * (§14). Returns only privacy-safe fields by confidentiality class (DocumentVerificationPresenter).
 * An unknown / malformed token answers INVALID_TOKEN with no document data. Rate limited at the route;
 * every lookup is logged with hashes only (public_verification_lookups).
 *
 * GET /api/v1/public/document-signing-keys: public Ed25519 keys (current + retired) for offline
 * verification of the platform signature (§13 mode O2 groundwork, §7.5).
 */
final class PublicDocumentVerificationController
{
    public function verify(Request $request, string $token, DocumentVerificationPresenter $presenter): JsonResponse
    {
        $token = mb_substr($token, 0, 128);
        $doc = preg_match('/^[A-Za-z0-9_-]{16,128}$/', $token) ? Document::where('verification_token_hash', VerificationCredentials::tokenHash($token))->first() : null;
        $fingerprint = hash('sha256', $request->ip().'|'.$request->userAgent());
        if (! $doc) {
            DB::table('public_verification_lookups')->insert(['id' => (string) Str::uuid(), 'channel' => 'API', 'reference_hash' => hash('sha256', $token), 'policy_certificate_id' => null,
                'document_id' => null, 'result' => 'not_found', 'token_presented' => true, 'request_fingerprint_hash' => $fingerprint, 'occurred_at' => now()]);

            return response()->json(['data' => ['verification_result' => 'INVALID_TOKEN', 'result' => 'not_found', 'checked_at' => now()->toIso8601String()]]);
        }
        $policy = $doc->policy_id ? Policy::with(['carrier.party', 'party', 'proposal.offer.product'])->find($doc->policy_id) : null;
        $eval = $presenter->evaluate($doc, $policy, ['token' => $token, 'document_number' => $request->query('document_number')]);
        DB::table('public_verification_lookups')->insert(['id' => (string) Str::uuid(), 'channel' => 'API', 'reference_hash' => hash('sha256', $token), 'policy_certificate_id' => null,
            'document_id' => $doc->id, 'result' => $eval['legacy'], 'token_presented' => true, 'request_fingerprint_hash' => $fingerprint, 'occurred_at' => now()]);

        return response()->json(['data' => $presenter->publicPayload($doc, $policy, $eval, VerificationCredentials::display((string) $doc->verification_code))]);
    }

    public function keys(DocumentSigner $signer): JsonResponse
    {
        return response()->json(['data' => ['algorithm' => 'ED25519', 'keys' => $signer->publicKeys(), 'status' => $signer->configured() ? 'CONFIGURED' : 'CONFIG_REQUIRED']]);
    }
}
