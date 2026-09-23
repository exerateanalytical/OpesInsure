<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Middleware;

use App\Application\Security\MobileStepUpService;
use App\Domain\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gates a sensitive mobile write behind a fresh step-up grant, the same
 * shape as `permission:` (RequirePermission) gates staff actions but
 * checking a short-lived elevated credential instead of a role/permission.
 *
 * Usage: ->middleware('step-up:PAYMENT_REFUND_REQUEST')
 *
 * The client presents the grant token it received from
 * POST /mobile/security/step-up/verify via the X-Step-Up-Grant header (see
 * overlay/src/api/client.ts's api() helper, which attaches it automatically
 * whenever a call declares a matching `stepUpPurpose`). Missing, unknown,
 * expired, already-consumed or wrong-purpose/tenant/user grants are all
 * rejected identically (401, STEP_UP_REQUIRED) — never distinguished in the
 * response, so a caller can't use this endpoint to probe which failure mode
 * applies.
 *
 * Per the merge guide's session/token rules ("Return 401 only for invalid
 * authentication; use 403 for denied permission and 422 for invalid step-up
 * codes"): a missing/invalid/expired *grant* on the protected endpoint is
 * treated as an authentication gap (401), distinct from an invalid *OTP
 * code* during verify() itself (422, see MobileStepUpController::verify()).
 */
final class RequireStepUpGrant
{
    public function __construct(private MobileStepUpService $stepUp)
    {
    }

    public function handle(Request $request, Closure $next, string $purpose): Response
    {
        $token = $request->header('X-Step-Up-Grant');
        $user = $request->user();

        if (! $token || ! $user || ! $this->stepUp->consume($user, app(TenantContext::class)->id(), $purpose, $token)) {
            return response()->json([
                'message' => __('wave12.step_up_required'),
                'code' => 'STEP_UP_REQUIRED',
            ], 401);
        }

        return $next($request);
    }
}
