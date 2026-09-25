<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Application\Audit\AuditWriter;
use App\Application\Notifications\Otp\SendOtpJob;
use App\Application\Settings\PlatformSettings;
use App\Models\MobileRefreshToken;
use App\Models\Party;
use App\Models\PartyContact;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\UserDevice;
use App\Models\VerificationChallenge;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Passport\Passport;
use Throwable;

/**
 * Passport 13 has no password grant, so there is no stock "log in with a
 * custom credential, get Passport tokens" flow — this mints a personal
 * access token directly (Passport::personalAccessTokensExpireIn() keeps it
 * short-lived) after our own OTP verification, and layers our own
 * refresh-token rotation with replay-family revocation on top via
 * MobileRefreshToken, since personal access tokens don't carry Passport's
 * own refresh machinery.
 *
 * Reuses the existing (previously unused) verification_challenges table for
 * hashed/expiring/attempt-limited OTP storage, and UserDevice for device
 * trust tracking — both already modeled, just never wired up before this.
 */
final class MobileAuthService
{
    private const OTP_TTL_SECONDS = 300;

    private const MAX_ATTEMPTS = 5;

    private const REFRESH_TOKEN_TTL_DAYS = 30;

    /*
     * Brute-force ceilings. Set well above anything a person or a tester
     * reaches (the old route throttles locked testers out, so those were
     * removed); demo persona phones are exempt from all of them.
     */
    private const PASSWORD_FAILS_PER_15_MIN = 10;

    private const CODE_SENDS_PER_HOUR = 6;

    private const IP_REQUESTS_PER_HOUR = 300;

    public function __construct(
        private AuditWriter $audit,
        private PartyResolver $parties,
        private PlatformSettings $settings,
    ) {
    }

    /**
     * Demo persona phone (customer/agent/broker/carrier) while demo mode is
     * on: fixed code 123456, nothing sent, no brute-force ceilings, and the
     * shared demo password cannot be changed or reset.
     */
    public static function isDemoPersonaPhone(?string $phoneE164): bool
    {
        return $phoneE164 !== null && config('demo.enabled') && in_array($phoneE164, \Database\Seeders\DemoMobileAccountSeeder::otpPhones(), true);
    }

    /**
     * Sends a login (or, with $purpose PASSWORD_RESET, a reset) code. The
     * response never depends on whether the number is registered, and the
     * send itself is queued.
     *
     * @param  string|null  $channel  'whatsapp'|'sms' to try first
     * @return array{challenge_id: string, delivery_status: string, expires_in: int}
     */
    public function requestOtp(string $phoneE164, string $ip, ?string $channel = null, string $purpose = 'MOBILE_LOGIN'): array
    {
        $demo = self::isDemoPersonaPhone($phoneE164);
        $this->guardIp($ip, $demo);
        $this->guardCodeSends($phoneE164, $demo);

        $user = User::where('phone_e164', $phoneE164)->first();

        $challenge = $this->createChallenge($user, $phoneE164, $ip, $purpose, $channel, sendIfUser: true);

        $this->audit->record($purpose === 'PASSWORD_RESET' ? 'mobile.password_reset.requested' : 'mobile.otp.requested', 'verification_challenge', $challenge->id, ['known_user' => $user !== null]);

        return ['challenge_id' => $challenge->id, 'delivery_status' => 'QUEUED', 'expires_in' => self::OTP_TTL_SECONDS];
    }

    /**
     * Phone + password sign-in. Same response as verifyOtp(). Every failure
     * (unknown phone, wrong password, inactive account) gives one generic
     * message so the endpoint cannot be used to discover registered numbers.
     */
    public function passwordLogin(string $phoneE164, string $password, string $deviceFingerprint, string $ip, ?string $deviceName, ?string $platform): array
    {
        $demo = self::isDemoPersonaPhone($phoneE164);
        $this->guardIp($ip, $demo);

        $failKey = 'mobile-auth:pwfail:'.hash('sha256', $phoneE164);
        if (! $demo && RateLimiter::tooManyAttempts($failKey, self::PASSWORD_FAILS_PER_15_MIN)) {
            throw $this->tooMany(RateLimiter::availableIn($failKey));
        }

        $user = User::where('phone_e164', $phoneE164)->first();

        if (! $user || ! Hash::check($password, (string) $user->password) || $user->status !== 'ACTIVE') {
            if (! $demo) {
                RateLimiter::hit($failKey, 900);
            }
            if ($user) {
                $this->audit->record('mobile.password_login.failed', 'user', $user->id, []);
            }

            throw ValidationException::withMessages(['phone_e164' => __('wave12.password_login_invalid')]);
        }

        RateLimiter::clear($failKey);

        return $this->issueSession($user, $deviceFingerprint, $ip, $deviceName, $platform, 'password');
    }

    /**
     * Forgot password, step 2: a code from requestOtp(..., 'PASSWORD_RESET')
     * plus the new password. Wrong codes count toward that challenge's
     * 5-guess lock. Signs the user in on success.
     */
    public function resetPassword(string $phoneE164, string $code, string $password, ?string $challengeId, string $deviceFingerprint, string $ip, ?string $deviceName, ?string $platform): array
    {
        if (self::isDemoPersonaPhone($phoneE164)) {
            throw ValidationException::withMessages(['phone_e164' => __('wave12.demo_password_locked')]);
        }

        $this->guardIp($ip, false);
        $destination = hash('sha256', $phoneE164);

        $userId = DB::transaction(function () use ($destination, $code, $challengeId) {
            $query = VerificationChallenge::where('purpose', 'PASSWORD_RESET')->where('destination_hash', $destination)->whereNull('consumed_at');
            $challenge = ($challengeId ? $query->whereKey($challengeId) : $query->latest())->lockForUpdate()->first();

            return $this->checkCode($challenge, $code, ['PASSWORD_RESET']);
        });

        $user = $userId ? User::find($userId) : null;

        if (! $user || ! in_array($user->status, ['ACTIVE', 'PENDING_VERIFICATION'], true)) {
            throw ValidationException::withMessages(['code' => __('wave12.otp_invalid')]);
        }

        // Receiving the code on this phone proves ownership, same as OTP login.
        $user->forceFill(['password' => $password, 'status' => 'ACTIVE', 'phone_verified_at' => $user->phone_verified_at ?? now()])->save();
        $this->provisionCustomerAccess($user);

        // A new password ends every existing session.
        $this->revokeAllSessions($user);
        $this->audit->record('mobile.password_reset.completed', 'user', $user->id, []);

        return $this->issueSession($user, $deviceFingerprint, $ip, $deviceName, $platform, 'password_reset');
    }

    /** Revokes every mobile refresh token and every Passport access token of $user. */
    public function revokeAllSessions(User $user): void
    {
        MobileRefreshToken::where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
        Passport::token()->newQuery()->where('user_id', $user->id)->update(['revoked' => true]);
    }

    /**
     * Self-registration with phone + password (+ optional email).
     *
     * With require_contact_verification off (the default until a delivery
     * channel is proven), the account is ACTIVE at once with contacts left
     * unverified, and the response signs the app in (same shape as
     * verifyOtp() plus verification_required=false). With it on, the account
     * stays PENDING_VERIFICATION, a code goes out on the chosen channel, and
     * the app finishes with POST auth/mobile/otp/verify.
     *
     * @param  array{phone_e164: string, password: string, email?: ?string, full_name?: ?string, locale?: ?string, verification_channel?: ?string}  $data
     */
    public function register(array $data, string $deviceFingerprint, string $ip, ?string $deviceName, ?string $platform): array
    {
        $this->guardIp($ip, false);
        $requireVerification = $this->settings->requireContactVerification();

        $user = User::create([
            'full_name' => filled($data['full_name'] ?? null) ? $data['full_name'] : $data['phone_e164'],
            'phone_e164' => $data['phone_e164'],
            'email' => filled($data['email'] ?? null) ? mb_strtolower($data['email']) : null,
            'password' => $data['password'],
            'locale' => $data['locale'] ?? 'en',
            'status' => $requireVerification ? 'PENDING_VERIFICATION' : 'ACTIVE',
        ]);

        $this->audit->record('mobile.account.registered', 'user', $user->id, ['verification_required' => $requireVerification, 'with_email' => $user->email !== null]);

        if (! $requireVerification) {
            $this->provisionCustomerAccess($user);

            return ['verification_required' => false, ...$this->issueSession($user, $deviceFingerprint, $ip, $deviceName, $platform, 'registration')];
        }

        $this->guardCodeSends($data['phone_e164'], false);
        $channel = $data['verification_channel'] ?? null;

        // REGISTRATION (not MOBILE_LOGIN) so verifyOtp() knows the person
        // proving the phone is the one who just chose the password.
        if ($channel === 'email' && $user->email && $this->settings->mailEnabled()) {
            $challenge = $this->createChallenge($user, $data['phone_e164'], $ip, 'REGISTRATION', null, sendIfUser: false, emailTo: $user->email);
            $sentVia = 'email';
        } else {
            $phoneChannel = in_array($channel, ['sms', 'whatsapp'], true) ? $channel : null;
            $challenge = $this->createChallenge($user, $data['phone_e164'], $ip, 'REGISTRATION', $phoneChannel, sendIfUser: true);
            $sentVia = $phoneChannel ?? $this->settings->otpChannelPriority()[0];
        }

        return [
            'id' => $user->id,
            'status' => $user->status,
            'verification_required' => true,
            'challenge_id' => $challenge->id,
            'verification_channel' => $sentVia,
            'expires_in' => self::OTP_TTL_SECONDS,
        ];
    }

    /** Signed-in "verify later": sends a code to the account's own phone. */
    public function requestPhoneVerification(User $user, string $ip, ?string $channel): array
    {
        $demo = self::isDemoPersonaPhone($user->phone_e164);
        $this->guardIp($ip, $demo);
        $this->guardCodeSends((string) $user->phone_e164, $demo);

        $challenge = $this->createChallenge($user, (string) $user->phone_e164, $ip, 'PHONE_VERIFY', $channel, sendIfUser: true);

        return ['challenge_id' => $challenge->id, 'delivery_status' => 'QUEUED', 'expires_in' => self::OTP_TTL_SECONDS];
    }

    /**
     * Signed-in confirmation. The caller already knows the password AND now
     * holds the phone, so (unlike OTP login, see verifyOtp) the password stays.
     */
    public function confirmPhoneVerification(User $user, string $challengeId, string $code): array
    {
        $userId = DB::transaction(function () use ($challengeId, $code, $user) {
            $challenge = VerificationChallenge::whereKey($challengeId)->where('user_id', $user->id)->lockForUpdate()->first();

            return $this->checkCode($challenge, $code, ['PHONE_VERIFY']);
        });

        if ($userId === null) {
            throw ValidationException::withMessages(['code' => __('wave12.otp_invalid')]);
        }

        $user->forceFill(['phone_verified_at' => $user->phone_verified_at ?? now()])->save();
        $this->audit->record('user.phone.verified', 'user', $user->id, []);

        return ['user' => $this->userPayload($user->fresh())];
    }

    /**
     * @return array{access_token: string, refresh_token: string, expires_in: int, user: array, workspaces: array, password_reset_required: bool}
     */
    public function verifyOtp(string $challengeId, string $code, string $deviceFingerprint, string $ip, ?string $deviceName, ?string $platform): array
    {
        $lookup = VerificationChallenge::find($challengeId);
        $this->guardIp($ip, $lookup !== null && $this->isDemoDestination($lookup->destination_hash));

        // Deliberately two transactions, not one: DB::transaction() rolls
        // back everything inside it when the callback throws, which would
        // silently discard the attempts-increment below every time a wrong
        // code is submitted — turning "5 attempts then locked" into
        // unlimited guessing. The check/increment commits on its own before
        // any ValidationException is thrown from outside the transaction.
        $purpose = null;
        $userId = DB::transaction(function () use ($challengeId, $code, &$purpose) {
            $challenge = VerificationChallenge::whereKey($challengeId)->lockForUpdate()->first();
            $purpose = $challenge?->purpose;

            return $this->checkCode($challenge, $code, ['MOBILE_LOGIN', 'REGISTRATION']);
        });

        if ($userId === null) {
            throw ValidationException::withMessages(['code' => __('wave12.otp_invalid')]);
        }

        $user = User::find($userId);

        // A user created by self-registration starts PENDING_VERIFICATION —
        // this OTP check is exactly the phone-ownership proof that status
        // exists to require, so a correct code promotes them here rather
        // than needing a separate activation step nothing else provides.
        if (! $user || ! in_array($user->status, ['ACTIVE', 'PENDING_VERIFICATION'], true)) {
            throw ValidationException::withMessages(['code' => __('wave12.otp_invalid')]);
        }

        $passwordResetRequired = false;

        if ($user->phone_verified_at === null) {
            $changes = ['phone_verified_at' => now(), 'status' => 'ACTIVE'];

            // First proof of this phone via a plain login code: whoever set
            // the password never proved they own the number (verification is
            // off at sign-up), so that password is discarded and the owner
            // sets a new one via Forgot password. A REGISTRATION code was
            // requested by the person who just chose the password: kept.
            if ($purpose === 'MOBILE_LOGIN') {
                $changes['password'] = Str::random(64);
                $passwordResetRequired = true;
                $this->revokeAllSessions($user);
                $this->audit->record('mobile.password.invalidated_on_phone_proof', 'user', $user->id, []);
            }

            $user->forceFill($changes)->save();
            $this->provisionCustomerAccess($user);
        }

        return [...$this->issueSession($user, $deviceFingerprint, $ip, $deviceName, $platform, 'otp'), 'password_reset_required' => $passwordResetRequired];
    }

    /** Device upsert + token pair + bootstrap payload shared by every sign-in path. */
    private function issueSession(User $user, string $deviceFingerprint, string $ip, ?string $deviceName, ?string $platform, string $method): array
    {
        return DB::transaction(function () use ($user, $deviceFingerprint, $ip, $deviceName, $platform, $method) {
            $device = UserDevice::firstOrNew(['user_id' => $user->id, 'device_fingerprint' => $deviceFingerprint]);
            $isNewDevice = ! $device->exists;
            $device->name = $deviceName;
            $device->platform = $platform;
            $device->last_seen_at = now();
            $device->trusted_at ??= now();
            $device->security_metadata = ['last_ip_hash' => hash('sha256', $ip)];
            $device->save();
            // REQ-SEC-001 login activity + anomaly flags (never blocks the sign-in).
            app(\App\Application\Security\Login\LoginActivityRecorder::class)->record($user, $method, $device->id, $deviceFingerprint, $deviceName, $platform, $isNewDevice, $ip);

            [$accessToken, $refreshToken, $expiresIn] = $this->issueTokenPair($user, $device->id, (string) Str::uuid());

            $this->audit->record('mobile.session.created', 'user', $user->id, ['device_id' => $device->id, 'method' => $method]);

            return [
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'expires_in' => $expiresIn,
                'user' => $this->userPayload($user->fresh() ?? $user),
                'workspaces' => $this->workspaces($user),
            ];
        });
    }

    /** @return array{user: array, workspaces: array} */
    public function session(User $user): array
    {
        return ['user' => $this->userPayload($user), 'workspaces' => $this->workspaces($user)];
    }

    /** @return array{access_token: string, refresh_token: string, expires_in: int} */
    public function refresh(string $refreshToken): array
    {
        // Same rollback hazard as verifyOtp(): the replay-detected family
        // revocation below MUST commit even though this method then rejects
        // the request — so the transaction always returns normally (an "ok"
        // descriptor), and the exception is thrown from outside it.
        $outcome = DB::transaction(function () use ($refreshToken) {
            $record = MobileRefreshToken::where('token_hash', hash('sha256', $refreshToken))->lockForUpdate()->first();

            if (! $record) {
                return ['ok' => false];
            }

            if ($record->revoked_at !== null) {
                // Already rotated (or explicitly revoked) — presenting it again is a reuse
                // signal, not a race: kill the whole device family, not just this token.
                MobileRefreshToken::where('family_id', $record->family_id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
                $this->revokeAccessToken($record->access_token_id);
                $this->audit->record('mobile.refresh.replay_detected', 'user', $record->user_id, ['family_id' => $record->family_id]);

                return ['ok' => false];
            }

            if ($record->expires_at->isPast()) {
                return ['ok' => false];
            }

            $user = User::find($record->user_id);

            if (! $user || $user->status !== 'ACTIVE') {
                return ['ok' => false];
            }

            $record->update(['revoked_at' => now(), 'replaced_at' => now()]);
            $this->revokeAccessToken($record->access_token_id);

            [$accessToken, $newRefreshToken, $expiresIn] = $this->issueTokenPair($user, $record->device_id, $record->family_id);

            $this->audit->record('mobile.refresh.rotated', 'user', $user->id, ['family_id' => $record->family_id]);

            return ['ok' => true, 'access_token' => $accessToken, 'refresh_token' => $newRefreshToken, 'expires_in' => $expiresIn];
        });

        if (! $outcome['ok']) {
            throw ValidationException::withMessages(['refresh_token' => __('wave12.refresh_invalid')]);
        }

        return ['access_token' => $outcome['access_token'], 'refresh_token' => $outcome['refresh_token'], 'expires_in' => $outcome['expires_in']];
    }

    public function logout(User $user, string $currentAccessTokenId, ?string $refreshToken): void
    {
        $this->revokeAccessToken($currentAccessTokenId);

        if ($refreshToken) {
            $record = MobileRefreshToken::where('token_hash', hash('sha256', $refreshToken))->where('user_id', $user->id)->first();

            if ($record) {
                MobileRefreshToken::where('family_id', $record->family_id)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            }
        }

        $this->audit->record('mobile.session.revoked', 'user', $user->id, []);
    }

    /**
     * Checks a code against a locked challenge row. A wrong guess increments
     * attempts and, at max_attempts, burns the code (the per-code guess
     * limit that is deliberately kept). Returns the challenge's user id on
     * success, null on a wrong code.
     */
    private function checkCode(?VerificationChallenge $challenge, string $code, array $purposes): ?string
    {
        $this->assertChallengeUsable($challenge, $purposes);

        if (! Hash::check($code, $challenge->code_hash)) {
            $challenge->increment('attempts');

            if ($challenge->attempts >= $challenge->max_attempts) {
                $challenge->update(['consumed_at' => now()]);
            }

            return null;
        }

        $challenge->update(['consumed_at' => now()]);

        return $challenge->user_id;
    }

    private function createChallenge(?User $user, string $phoneE164, string $ip, string $purpose, ?string $channel, bool $sendIfUser, ?string $emailTo = null): VerificationChallenge
    {
        $code = $this->issueCode($phoneE164);

        $challenge = VerificationChallenge::create([
            'user_id' => $user?->id,
            'purpose' => $purpose,
            'channel' => $emailTo ? 'EMAIL' : strtoupper($channel ?? 'SMS'),
            'destination_hash' => hash('sha256', $phoneE164),
            'code_hash' => Hash::make($code),
            'attempts' => 0,
            'max_attempts' => self::MAX_ATTEMPTS,
            'expires_at' => now()->addSeconds(self::OTP_TTL_SECONDS),
            'request_ip_hash' => hash('sha256', $ip),
        ]);

        // Demo accounts use the fixed demo code and are never sent anything.
        if ($this->usesDemoCode($phoneE164)) {
            return $challenge;
        }

        if ($emailTo !== null) {
            $this->sendOtpEmail($emailTo, $code);
        } elseif ($user && $sendIfUser) {
            // Only a registered phone is actually messaged, but the response
            // never differs, so a prober cannot tell registered numbers apart.
            SendOtpJob::send($phoneE164, $code, "Your OpesInsure verification code is {$code}. It expires in 5 minutes. Never share this code with anyone.", $channel, $challenge->id);
        }

        return $challenge;
    }

    private function sendOtpEmail(string $email, string $code): void
    {
        try {
            Mail::raw("Your OpesInsure verification code is {$code}. It expires in 5 minutes. Never share this code with anyone.", function ($message) use ($email) {
                $message->to($email)->subject('Your OpesInsure verification code');
            });
        } catch (Throwable $e) {
            Log::critical('mobile.otp.email_delivery_failed', ['reason' => $e->getMessage()]);
            report($e);
        }
    }

    /**
     * Fixed demo code only for a demo persona phone, and never for an
     * account holding any role outside the persona roles (admin, finance,
     * compliance, claims...), even if its number were on the list.
     */
    private function usesDemoCode(string $phoneE164): bool
    {
        if (! self::isDemoPersonaPhone($phoneE164)) {
            return false;
        }

        $user = User::where('phone_e164', $phoneE164)->first();

        return $user === null || ! $user->memberships()->whereNotIn('role_code', (array) config('demo.persona_roles', []))->exists();
    }

    private function isDemoDestination(string $destinationHash): bool
    {
        foreach (\Database\Seeders\DemoMobileAccountSeeder::otpPhones() as $phone) {
            if (config('demo.enabled') && hash_equals(hash('sha256', $phone), $destinationHash)) {
                return true;
            }
        }

        return false;
    }

    /** Generous per-IP ceiling across the auth endpoints (demo phones exempt). */
    private function guardIp(string $ip, bool $exempt): void
    {
        if ($exempt) {
            return;
        }

        $key = 'mobile-auth:ip:'.hash('sha256', $ip);

        if (RateLimiter::tooManyAttempts($key, self::IP_REQUESTS_PER_HOUR)) {
            throw $this->tooMany(RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, 3600);
    }

    /** Per-phone ceiling on code sends (login, reset, registration, verify). */
    private function guardCodeSends(string $phoneE164, bool $exempt): void
    {
        if ($exempt) {
            return;
        }

        $key = 'mobile-auth:send:'.hash('sha256', $phoneE164);

        if (RateLimiter::tooManyAttempts($key, self::CODE_SENDS_PER_HOUR)) {
            throw $this->tooMany(RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, 3600);
    }

    private function tooMany(int $seconds): ThrottleRequestsException
    {
        $seconds = max(1, $seconds);

        return new ThrottleRequestsException(__('wave12.auth_rate_limited', ['minutes' => (int) ceil($seconds / 60)]), null, ['Retry-After' => $seconds]);
    }

    private function assertChallengeUsable(?VerificationChallenge $challenge, array $purposes): void
    {
        if (! $challenge
            || ! in_array($challenge->purpose, $purposes, true)
            || $challenge->consumed_at !== null
            || $challenge->expires_at->isPast()
            || $challenge->attempts >= $challenge->max_attempts
        ) {
            throw ValidationException::withMessages(['code' => __('wave12.otp_invalid')]);
        }
    }

    /** @return array{0: string, 1: string, 2: int} [access_token, refresh_token, expires_in] */
    private function issueTokenPair(User $user, ?string $deviceId, string $familyId): array
    {
        $result = $user->createToken('mobile', []);
        $refreshToken = Str::random(64);

        MobileRefreshToken::create([
            'user_id' => $user->id,
            'device_id' => $deviceId,
            'family_id' => $familyId,
            'token_hash' => hash('sha256', $refreshToken),
            'access_token_id' => $result->accessTokenId,
            'expires_at' => now()->addDays(self::REFRESH_TOKEN_TTL_DAYS),
        ]);

        return [$result->accessToken, $refreshToken, $result->expiresIn];
    }

    private function revokeAccessToken(?string $accessTokenId): void
    {
        if ($accessTokenId === null) {
            return;
        }

        Passport::token()->newQuery()->whereKey($accessTokenId)->update(['revoked' => true]);
    }

    private function userPayload(User $user): array
    {
        return [
            ...$user->only(['id', 'full_name', 'email', 'phone_e164', 'locale', 'status']),
            'email_verified_at' => $user->email_verified_at?->toIso8601String(),
            'phone_verified_at' => $user->phone_verified_at?->toIso8601String(),
            'phone_verified' => $user->phone_verified_at !== null,
            'email_verified' => $user->email_verified_at !== null,
            // True once every contact on file is verified (email only counts when given).
            'contacts_verified' => $user->phone_verified_at !== null && ($user->email === null || $user->email_verified_at !== null),
        ];
    }

    private function workspaces(User $user): array
    {
        return $user->memberships()->where('status', 'ACTIVE')->with(['roles', 'tenant'])->get()
            ->map(function (TenantMembership $membership) use ($user) {
                $tenant = $membership->tenant;
                $permissions = $membership->roles->flatMap(fn ($role) => $role->permissions)->unique()->values()->all();

                return [
                    'membership_id' => $membership->id,
                    'tenant_id' => $membership->tenant_id,
                    'tenant_name' => $tenant?->trade_name ?: $tenant?->legal_name,
                    'tenant_type' => $tenant?->type,
                    'role_code' => $membership->role_code,
                    'permissions' => $permissions,
                    'customer_id' => $this->parties->tenantCustomerId($user, $membership->tenant_id),
                ];
            })->values()->all();
    }

    /**
     * Real users always get a cryptographically random code. Demo accounts get
     * a fixed one, and only while demo mode is enabled, because the mobile app
     * signs in by SMS OTP and no SMS provider is configured — a demo tester
     * would otherwise have no way to receive a code at all.
     *
     * This is deliberately done at generation time rather than in verifyOtp():
     * the code is still hashed, still rate limited, still attempt limited and
     * still expires, so the verification path has no special case and no
     * bypass. Turning demo mode off restores random codes everywhere with no
     * other change.
     */
    private function issueCode(string $phoneE164): string
    {
        if ($this->usesDemoCode($phoneE164)) {
            return (string) config('demo.otp');
        }

        return (string) random_int(100000, 999999);
    }

    /**
     * Self-registration (POST /public/accounts) only ever creates the login
     * User row — no Party, no PartyContact, no TenantMembership. Without a
     * Party, PartyResolver can't resolve this user at all, so every
     * ownership-scoped mobile endpoint (wallet, payments, quotes, claims...)
     * would return empty results forever. Without a TenantMembership,
     * ResolveTenant refuses every tenant-scoped request outright and the app
     * has nothing to show but "no active workspace".
     *
     * Runs once, at the same moment status flips to ACTIVE — a verified phone
     * is the point at which a self-registered visitor becomes a real
     * customer. Mirrors DemoMobileAccountSeeder's own pattern (the same
     * platform tenant, the same CUSTOMER role, no staff permissions) rather
     * than inventing a second way to wire up a customer identity.
     */
    private function provisionCustomerAccess(User $user): void
    {
        if ($user->party_id !== null) {
            return;
        }

        DB::transaction(function () use ($user) {
            $party = Party::create([
                'type' => 'INDIVIDUAL',
                'display_name' => $user->full_name,
                'status' => 'ACTIVE',
            ]);

            PartyContact::create([
                'party_id' => $party->id,
                'type' => 'PHONE',
                'normalized_value' => $user->phone_e164,
                'is_primary' => true,
            ]);

            $user->forceFill(['party_id' => $party->id])->save();

            $tenant = Tenant::firstOrCreate(
                ['slug' => 'opesinsure-platform'],
                ['id' => (string) Str::uuid(), 'type' => 'PLATFORM', 'legal_name' => 'Opesware Technologies', 'trade_name' => 'OpesInsure', 'status' => 'ACTIVE', 'country_code' => 'CM', 'currency' => 'XAF', 'primary_locale' => 'en', 'settings' => [], 'activated_at' => now()],
            );

            $membership = TenantMembership::create([
                'tenant_id' => $tenant->id,
                'user_id' => $user->id,
                'role_code' => 'CUSTOMER',
                'status' => 'ACTIVE',
            ]);

            // quotes.rate is the one permission-gated step on the customer
            // purchase path (POST quotes/{id}/rate); without it a customer can
            // create a quote but never see an offer.
            $role = Role::firstOrCreate(
                ['tenant_id' => $tenant->id, 'code' => 'CUSTOMER'],
                ['id' => (string) Str::uuid(), 'permissions' => ['quotes.rate'], 'is_system' => true],
            );
            if (! in_array('quotes.rate', $role->permissions ?? [], true)) {
                $role->update(['permissions' => [...($role->permissions ?? []), 'quotes.rate']]);
            }

            $membership->roles()->syncWithoutDetaching([$role->id]);

            // QuoteService::submit and RiskAssetService both require an ACTIVE
            // tenant customer, not just a membership.
            \App\Models\TenantCustomer::firstOrCreate(
                ['tenant_id' => $tenant->id, 'party_id' => $party->id],
                ['customer_number' => 'CUST-'.strtoupper(Str::random(8)), 'status' => 'ACTIVE'],
            );

            $this->audit->record('mobile.customer.provisioned', 'user', $user->id, ['party_id' => $party->id, 'tenant_id' => $tenant->id]);
        });
    }
}
