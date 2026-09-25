<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Web;

use App\Application\Documents\Verification\PublicVerificationService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * GET /verify?ref=SERIAL&t=TOKEN — the page the certificate QR code opens.
 * Needs the token from the QR; anything else gets the generic not-found
 * answer (anti-enumeration). No names, premiums or contact data shown.
 */
final class PublicVerifyPageController
{
    public function __invoke(Request $request, PublicVerificationService $verification): View
    {
        $ref = trim((string) $request->query('ref', ''));
        $code = trim((string) $request->query('code', ''));
        $result = null;
        if ($code !== '') {
            // Document engine verification code (printed + in the QR of every issued document).
            $result = $verification->lookup(mb_substr($code, 0, 40), null, 'QR_PAGE', $request->ip().'|'.$request->userAgent());
        } elseif ($ref !== '') {
            $result = $verification->lookup(mb_substr($ref, 0, 100), mb_substr((string) $request->query('t', ''), 0, 128) ?: null, 'QR_PAGE', $request->ip().'|'.$request->userAgent());
        }

        return view('public.verify', ['result' => $result]);
    }
}
