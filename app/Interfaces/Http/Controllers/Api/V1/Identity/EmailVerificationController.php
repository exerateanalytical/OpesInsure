<?php

declare(strict_types=1);

namespace App\Interfaces\Http\Controllers\Api\V1\Identity;

use App\Application\Audit\AuditWriter;
use App\Application\Settings\PlatformSettings;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Throwable;

/**
 * Email verification by signed link. Sends only when the admin SMTP
 * settings are enabled; otherwise answers 202 {"sent": false} so the app
 * can keep showing "verify later" without treating it as an error.
 */
final class EmailVerificationController
{
    public function send(Request $request, PlatformSettings $settings): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->email || $user->email_verified_at !== null) {
            return response()->json(['data' => ['sent' => false, 'reason' => $user->email ? 'already_verified' : 'no_email']], 202);
        }

        if (! $settings->mailEnabled()) {
            return response()->json(['data' => ['sent' => false, 'reason' => 'mail_disabled']], 202);
        }

        $url = URL::temporarySignedRoute('email.verify', now()->addHours(24), ['user' => $user->id, 'hash' => sha1($user->email)]);

        try {
            Mail::raw("Confirm your OpesInsure email address by opening this link:\n\n{$url}\n\nThe link expires in 24 hours. If you did not create an account, ignore this email.", function ($message) use ($user) {
                $message->to($user->email)->subject('Confirm your OpesInsure email');
            });
        } catch (Throwable $e) {
            Log::critical('email.verification.send_failed', ['reason' => $e->getMessage()]);
            report($e);

            return response()->json(['data' => ['sent' => false, 'reason' => 'send_failed']], 202);
        }

        return response()->json(['data' => ['sent' => true]], 202);
    }

    public function verify(Request $request, string $user, string $hash, AuditWriter $audit): JsonResponse
    {
        $model = User::find($user);

        abort_unless($model && $model->email && hash_equals(sha1($model->email), $hash), 403, 'Invalid verification link.');

        if ($model->email_verified_at === null) {
            $model->forceFill(['email_verified_at' => now()])->save();
            $audit->record('user.email.verified', 'user', $model->id, []);
        }

        return response()->json(['data' => ['verified' => true]]);
    }
}
