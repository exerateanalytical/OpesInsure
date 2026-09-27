<?php

declare(strict_types=1);

namespace App\Application\Security;

use App\Application\Security\Login\LoginActivityRecorder;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;

/**
 * The one step-up check (X-Step-Up-Grant, consumed through MobileStepUpService): used by the `step-up:<PURPOSE>`
 * middleware and by endpoints where only part of the payload is sensitive (a payout destination or e-mail change
 * inside a wider profile PATCH). Outside a tenant (auth/mobile/logout-all) the grant is matched on user and purpose
 * only. Every attempt lands on the security timeline (event STEP_UP, SUCCESS / FAILED).
 */
final class StepUpGate
{
    public function __construct(private readonly MobileStepUpService $stepUp) {}

    public function passes(Request $request, string $purpose): bool
    {
        $token = $request->header('X-Step-Up-Grant');
        $user = $request->user();
        if (! $user) {
            return false;
        }
        $tenant = rescue(fn () => app(TenantContext::class)->id(), null, false);
        $ok = is_string($token) && $token !== '' && $this->stepUp->consume($user, $tenant, $purpose, $token);
        app(LoginActivityRecorder::class)->recordEvent($user, 'STEP_UP', $ok ? 'SUCCESS' : 'FAILED', $purpose, null, $request);

        return $ok;
    }

    public function assert(Request $request, string $purpose): void
    {
        if (! $this->passes($request, $purpose)) {
            throw new HttpResponseException(self::denied());
        }
    }

    public static function denied(): \Illuminate\Http\JsonResponse
    {
        return response()->json(['message' => __('wave12.step_up_required'), 'code' => 'STEP_UP_REQUIRED'], 401);
    }
}
