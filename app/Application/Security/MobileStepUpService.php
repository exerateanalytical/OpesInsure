<?php

declare(strict_types=1);

namespace App\Application\Security;

use App\Application\Audit\AuditWriter;
use App\Application\Notifications\Adapters\NotificationAdapterRegistry;
use App\Models\StepUpGrant;
use App\Models\User;
use App\Models\VerificationChallenge;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Step-up authentication (CLAUDE_MERGE_GUIDE.md, Patch 7 "Step-up
 * authentication" section): a normal mobile session token is enough for
 * everyday reads, but a sensitive write — PAYMENT_REFUND_REQUEST (wired
 * onto MobilePaymentService::requestRefund() in this batch),
 * COMMISSION_WITHDRAWAL and CLAIM_SETTLEMENT_DECISION (both to be adopted
 * by their owning batches once those endpoints exist) — requires a fresh,
 * short-lived elevated grant first.
 *
 * Reuses the same verification_challenges OTP mechanism
 * MobileAuthService::requestOtp()/verifyOtp() already use for login, with a
 * distinguishing `purpose` value so a login OTP can never be replayed as a
 * step-up code or vice versa. "SU:" rather than the more readable
 * "STEP_UP:" because verification_challenges.purpose is varchar(32) and
 * "STEP_UP:CLAIM_SETTLEMENT_DECISION" alone is 33 characters — widening a
 * shared core table for this batch's convenience was judged worse than a
 * terser prefix.
 *
 * Successful verification issues a StepUpGrant: a random 64-byte token
 * (only its SHA-256 hash is ever persisted, mirroring MobileRefreshToken)
 * bound to user + tenant + purpose, short-lived (grant_ttl_seconds) and
 * single-use — see consume(), called from
 * App\Interfaces\Http\Middleware\RequireStepUpGrant. The token itself is
 * handed back to the client exactly once, in the verify() response, never
 * logged (see sendOtpSms()'s report()-only failure handling for the same
 * discipline applied to the OTP code).
 */
final class MobileStepUpService
{
    private const CHALLENGE_PURPOSE_PREFIX = 'SU:';

    public function __construct(
        private NotificationAdapterRegistry $adapters,
        private AuditWriter $audit,
    ) {
    }

    /** @return array{challenge_id: string, delivery_hint: string, expires_in: int} */
    public function request(User $user, string $purpose, string $ip): array
    {
        $this->assertAllowlistedPurpose($purpose);
        $this->assertWithinRateLimits($user, $purpose, $ip);

        $ttl = (int) config('mobile_runtime.step_up.otp_ttl_seconds');
        // Same demo affordance as login (MobileAuthService::issueCode()): a
        // fixed code only for seeded demo phones and only in demo mode.
        $code = (config('demo.enabled') && $user->phone_e164 && in_array($user->phone_e164, \Database\Seeders\DemoMobileAccountSeeder::otpPhones(), true))
            ? (string) config('demo.otp')
            : (string) random_int(100000, 999999);

        $challenge = VerificationChallenge::create([
            'user_id' => $user->id,
            'purpose' => self::CHALLENGE_PURPOSE_PREFIX.$purpose,
            'channel' => 'SMS',
            'destination_hash' => hash('sha256', $user->phone_e164 ?? (string) $user->id),
            'code_hash' => Hash::make($code),
            'attempts' => 0,
            'max_attempts' => (int) config('mobile_runtime.step_up.max_attempts'),
            'expires_at' => now()->addSeconds($ttl),
            'request_ip_hash' => hash('sha256', $ip),
        ]);

        $this->sendOtpSms($user, $code);

        $this->audit->record('mobile.step_up.requested', 'verification_challenge', $challenge->id, ['purpose' => $purpose, 'user_id' => $user->id]);

        return ['challenge_id' => $challenge->id, 'delivery_hint' => 'SMS', 'expires_in' => $ttl];
    }

    /** @return array{grant_token: string, purpose: string, expires_at: string} */
    public function verify(User $user, string $tenantId, string $challengeId, string $purpose, string $code): array
    {
        $this->assertAllowlistedPurpose($purpose);

        // Same two-transaction split as MobileAuthService::verifyOtp(), and
        // for the same reason: the attempts-increment on a wrong code must
        // commit even though this request is ultimately rejected, or
        // DB::transaction()'s rollback-on-throw would silently discard it
        // every time, turning "N attempts then locked" into unlimited
        // guessing.
        $consumedChallengeId = DB::transaction(function () use ($user, $challengeId, $purpose, $code) {
            $challenge = VerificationChallenge::whereKey($challengeId)->lockForUpdate()->first();

            $this->assertChallengeUsable($challenge, $user, $purpose);

            if (! Hash::check($code, $challenge->code_hash)) {
                $challenge->increment('attempts');

                if ($challenge->attempts >= $challenge->max_attempts) {
                    $challenge->update(['consumed_at' => now()]);
                }

                return null;
            }

            $challenge->update(['consumed_at' => now()]);

            return $challenge->id;
        });

        if ($consumedChallengeId === null) {
            $this->audit->record('mobile.step_up.denied', 'user', $user->id, ['purpose' => $purpose], 'invalid_code');

            throw ValidationException::withMessages(['code' => __('wave12.step_up_code_invalid')]);
        }

        $ttl = (int) config('mobile_runtime.step_up.grant_ttl_seconds');
        $rawToken = Str::random(64);
        $expiresAt = now()->addSeconds($ttl);

        StepUpGrant::create([
            'user_id' => $user->id,
            'tenant_id' => $tenantId,
            'device_id' => null,
            'challenge_id' => $consumedChallengeId,
            'purpose' => $purpose,
            'token_hash' => hash('sha256', $rawToken),
            'expires_at' => $expiresAt,
        ]);

        $this->audit->record('mobile.step_up.granted', 'user', $user->id, ['purpose' => $purpose]);

        return ['grant_token' => $rawToken, 'purpose' => $purpose, 'expires_at' => $expiresAt->toIso8601String()];
    }

    /**
     * Atomically claims (single-use consumes) a presented grant token for
     * the given user/tenant/purpose. True means the grant was valid and has
     * just been consumed by THIS call. Consumption happens even though the
     * caller (RequireStepUpGrant) hasn't yet run the protected action —
     * same "burn the credential before the outer operation can still fail"
     * posture as verify()'s challenge consumption, so a probing replay
     * never gets a second try at the same grant merely because the
     * protected action itself then rejects for an unrelated reason (e.g. a
     * validation error on the refund amount).
     */
    public function consume(User $user, string $tenantId, string $purpose, string $rawToken): bool
    {
        $grant = StepUpGrant::where('token_hash', hash('sha256', $rawToken))->first();

        if (! $grant
            || $grant->user_id !== $user->id
            || $grant->tenant_id !== $tenantId
            || $grant->purpose !== $purpose
            || $grant->consumed_at !== null
            || $grant->expires_at->isPast()
        ) {
            $this->audit->record('mobile.step_up.consume_denied', 'user', $user->id, ['purpose' => $purpose], 'invalid_or_expired_grant');

            return false;
        }

        // Atomic claim, not a read-then-write: closes the race a concurrent
        // duplicate submit could otherwise exploit to spend the same grant
        // twice.
        $claimed = StepUpGrant::whereKey($grant->id)->whereNull('consumed_at')->update(['consumed_at' => now()]);

        if ($claimed === 0) {
            $this->audit->record('mobile.step_up.consume_denied', 'user', $user->id, ['purpose' => $purpose], 'already_consumed');

            return false;
        }

        $this->audit->record('mobile.step_up.consumed', 'user', $user->id, ['purpose' => $purpose]);

        return true;
    }

    private function assertAllowlistedPurpose(string $purpose): void
    {
        if (! in_array($purpose, config('mobile_runtime.step_up.purposes'), true)) {
            throw ValidationException::withMessages(['purpose' => __('wave12.step_up_purpose_invalid')]);
        }
    }

    private function assertWithinRateLimits(User $user, string $purpose, string $ip): void
    {
        $userOk = RateLimiter::attempt('step-up:user:'.$user->id.':'.$purpose, 5, fn () => true, 3600);
        $ipOk = RateLimiter::attempt('step-up:ip:'.hash('sha256', $ip), 30, fn () => true, 3600);

        if (! $userOk || ! $ipOk) {
            throw ValidationException::withMessages(['purpose' => __('wave12.step_up_rate_limited')]);
        }
    }

    private function assertChallengeUsable(?VerificationChallenge $challenge, User $user, string $purpose): void
    {
        if (! $challenge
            || $challenge->user_id !== $user->id
            || $challenge->purpose !== self::CHALLENGE_PURPOSE_PREFIX.$purpose
            || $challenge->consumed_at !== null
            || $challenge->expires_at->isPast()
            || $challenge->attempts >= $challenge->max_attempts
        ) {
            throw ValidationException::withMessages(['code' => __('wave12.step_up_code_invalid')]);
        }
    }

    private function sendOtpSms(User $user, string $code): void
    {
        if (! $user->phone_e164) {
            return;
        }

        try {
            $this->adapters->for('SMS')->send(
                $user->phone_e164,
                '',
                "Your OpesInsure security code is {$code}. It expires in 5 minutes. Never share this code with anyone.",
                (string) Str::uuid(),
            );
        } catch (Throwable $e) {
            // Never let a provider failure change response shape/timing —
            // ops visibility only, same discipline as MobileAuthService.
            \Illuminate\Support\Facades\Log::critical('mobile.step_up.sms_delivery_failed', ['reason' => $e->getMessage()]);
            report($e);
        }
    }
}
