<?php
namespace App\Interfaces\Http\Controllers\Api\V1\Identity;

use App\Models\User;
use Illuminate\Http\Request;
use App\Application\Identity\MobileAuthService;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final class AccountController
{
    /**
     * POST public/accounts: phone + password (+ optional email). See
     * MobileAuthService::register() for the verification switch.
     */
    public function register(Request $r, MobileAuthService $service)
    {
        MobileAuthController::normalizePhone($r);
        MobileAuthController::normalizeDevice($r);

        $d = $r->validate([
            'phone_e164' => ['required', 'regex:/^\+[1-9]\d{7,14}$/', 'unique:users,phone_e164'],
            'password' => 'required|string|min:8|max:128',
            'password_confirmation' => 'sometimes|nullable|same:password',
            'email' => 'nullable|email:rfc|max:190|unique:users,email',
            'full_name' => 'nullable|string|max:120',
            'locale' => 'nullable|in:en,fr',
            'terms_version' => 'nullable|string|max:32',
            'verification_channel' => 'nullable|in:whatsapp,sms,email',
            'device_fingerprint' => 'nullable|string|max:128',
            'device_name' => 'nullable|string|max:100',
            'platform' => 'nullable|string|max:24',
        ]);

        $result = $service->register(
            $d,
            $d['device_fingerprint'] ?? 'registration-'.Str::uuid(),
            (string) $r->ip(),
            $d['device_name'] ?? null,
            $d['platform'] ?? null,
        );

        return response()->json(['data' => $result], 201);
    }

    public function me(Request $r) { return response()->json(['data'=>$r->user()->only(['id','full_name','email','phone_e164','locale','status','email_verified_at','phone_verified_at'])]); }
    public function changePassword(Request $r, MobileAuthService $service)
    {
        $d = $r->validate(['current_password' => 'required', 'password' => 'required|string|min:8|max:128|confirmed|different:current_password']);
        $user = $r->user();

        if (MobileAuthService::isDemoPersonaPhone($user->phone_e164)) {
            throw \Illuminate\Validation\ValidationException::withMessages(['password' => __('wave12.demo_password_locked')]);
        }

        abort_unless(Hash::check($d['current_password'], $user->password), 422, 'Current password is incorrect.');
        $user->forceFill(['password' => $d['password']])->save();
        // Ends every session: Passport access tokens and mobile refresh tokens.
        $service->revokeAllSessions($user);

        return response()->json(['data' => ['password_changed' => true, 'sessions_revoked' => true]]);
    }

    /** POST me/phone/verification: "verify later" — sends a code to the account's own phone. */
    public function requestPhoneVerification(Request $r, MobileAuthService $service)
    {
        $d = $r->validate(['channel' => 'nullable|in:whatsapp,sms']);

        if ($r->user()->phone_verified_at !== null) {
            return response()->json(['data' => ['sent' => false, 'reason' => 'already_verified']], 202);
        }

        return response()->json(['data' => $service->requestPhoneVerification($r->user(), (string) $r->ip(), $d['channel'] ?? null)]);
    }

    /** POST me/phone/verification/confirm {challenge_id, code}. */
    public function confirmPhoneVerification(Request $r, MobileAuthService $service)
    {
        $d = $r->validate(['challenge_id' => 'required|uuid', 'code' => 'required|string|size:6']);

        return response()->json(['data' => $service->confirmPhoneVerification($r->user(), $d['challenge_id'], $d['code'])]);
    }
}
