<?php

declare(strict_types=1);

namespace App\Application\Identity;

use App\Application\Audit\AuditWriter;
use App\Application\Notifications\Adapters\NotificationAdapterRegistry;
use App\Models\MobileRefreshToken;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\UserDevice;
use App\Models\VerificationChallenge;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
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

    public function __construct(
        private NotificationAdapterRegistry $adapters,
        private AuditWriter $audit,
        private PartyResolver $parties,
    ) {
    }

    /** @return array{challenge_id: string, delivery_status: string, expires_in: int} */
    public function requestOtp(string $phoneE164, string $ip): array
    {
        $this->assertWithinRateLimits($phoneE164, $ip);

        $user = User::where('phone_e164', $phoneE164)->first();
        $code = $this->issueCode($phoneE164);

        $challenge = VerificationChallenge::create([
            'user_id' => $user?->id,
            'purpose' => 'MOBILE_LOGIN',
            'channel' => 'SMS',
            'destination_hash' => hash('sha256', $phoneE164),
            'code_hash' => Hash::make($code),
            'attempts' => 0,
            'max_attempts' => self::MAX_ATTEMPTS,
            'expires_at' => now()->addSeconds(self::OTP_TTL_SECONDS),
            'request_ip_hash' => hash('sha256', $ip),
        ]);

        // Only a registered phone actually gets an SMS — but the response
        // shape/timing below never differs, so a prober can't tell an
        // unregistered number from a real one that had a delivery hiccup.
        if ($user) {
            $this->sendOtpSms($phoneE164, $code);
        }

        $this->audit->record('mobile.otp.requested', 'verification_challenge', $challenge->id, ['known_user' => $user !== null]);

        return ['challenge_id' => $challenge->id, 'delivery_status' => 'QUEUED', 'expires_in' => self::OTP_TTL_SECONDS];
    }

    /**
     * @return array{access_token: string, refresh_token: string, expires_in: int, user: array, workspaces: array}
     */
    public function verifyOtp(string $challengeId, string $code, string $deviceFingerprint, string $ip, ?string $deviceName, ?string $platform): array
    {
        // Deliberately two transactions, not one: DB::transaction() rolls
        // back everything inside it when the callback throws, which would
        // silently discard the attempts-increment below every time a wrong
        // code is submitted — turning "5 attempts then locked" into
        // unlimited guessing. The check/increment commits on its own before
        // any ValidationException is thrown from outside the transaction.
        $userId = DB::transaction(function () use ($challengeId, $code) {
            $challenge = VerificationChallenge::whereKey($challengeId)->lockForUpdate()->first();

            $this->assertChallengeUsable($challenge);

            if (! Hash::check($code, $challenge->code_hash)) {
                $challenge->increment('attempts');

                if ($challenge->attempts >= $challenge->max_attempts) {
                    $challenge->update(['consumed_at' => now()]);
                }

                return null;
            }

            $challenge->update(['consumed_at' => now()]);

            return $challenge->user_id;
        });

        if ($userId === null) {
            throw ValidationException::withMessages(['code' => __('wave12.otp_invalid')]);
        }

        $user = User::find($userId);

        if (! $user || $user->status !== 'ACTIVE') {
            throw ValidationException::withMessages(['code' => __('wave12.otp_invalid')]);
        }

        return DB::transaction(function () use ($user, $deviceFingerprint, $ip, $deviceName, $platform) {
            $device = UserDevice::firstOrNew(['user_id' => $user->id, 'device_fingerprint' => $deviceFingerprint]);
            $device->name = $deviceName;
            $device->platform = $platform;
            $device->last_seen_at = now();
            $device->trusted_at ??= now();
            $device->security_metadata = ['last_ip_hash' => hash('sha256', $ip)];
            $device->save();

            [$accessToken, $refreshToken, $expiresIn] = $this->issueTokenPair($user, $device->id, (string) Str::uuid());

            $this->audit->record('mobile.session.created', 'user', $user->id, ['device_id' => $device->id]);

            return [
                'access_token' => $accessToken,
                'refresh_token' => $refreshToken,
                'expires_in' => $expiresIn,
                'user' => $this->userPayload($user),
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

    private function assertWithinRateLimits(string $phone, string $ip): void
    {
        $phoneOk = RateLimiter::attempt('mobile-otp:phone:'.hash('sha256', $phone), 5, fn () => true, 3600);
        $ipOk = RateLimiter::attempt('mobile-otp:ip:'.hash('sha256', $ip), 20, fn () => true, 3600);

        if (! $phoneOk || ! $ipOk) {
            throw ValidationException::withMessages(['phone_e164' => __('wave12.otp_rate_limited')]);
        }
    }

    private function assertChallengeUsable(?VerificationChallenge $challenge): void
    {
        if (! $challenge
            || $challenge->purpose !== 'MOBILE_LOGIN'
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
        return $user->only(['id', 'full_name', 'email', 'phone_e164', 'locale', 'status']);
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

    private function sendOtpSms(string $phoneE164, string $code): void
    {
        try {
            $this->adapters->for('SMS')->send(
                $phoneE164,
                '',
                "Your OpesInsure verification code is {$code}. It expires in 5 minutes. Never share this code with anyone.",
                (string) Str::uuid(),
            );
        } catch (Throwable $e) {
            // Never let a provider failure change the response shape/timing
            // (enumeration-safety) — ops visibility only.
            report($e);
        }
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
        if (config('demo.enabled') && in_array($phoneE164, \Database\Seeders\DemoMobileAccountSeeder::phones(), true)) {
            return (string) config('demo.otp');
        }

        return (string) random_int(100000, 999999);
    }
}
