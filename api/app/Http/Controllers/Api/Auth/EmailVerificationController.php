<?php

namespace App\Http\Controllers\Api\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class EmailVerificationController extends Controller
{
    /**
     * Signed link target from the verification email. Marks the user verified
     * then redirects to the frontend.
     */
    public function verify(Request $request, string $id, string $hash): RedirectResponse
    {
        $frontend = rtrim((string) config('app.frontend_url'), '/');
        $user = User::find($id);

        if (! $user || ! hash_equals($hash, sha1($user->getEmailForVerification()))) {
            return redirect()->away($frontend.'/login?verified=0');
        }

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailVerified();
        }

        return redirect()->away($frontend.'/login?verified=1');
    }

    /**
     * Re-send the verification email to the authenticated user.
     */
    public function resend(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = auth('api')->user();

        if ($user->hasVerifiedEmail()) {
            return ApiResponse::success(null, 'Email sudah terverifikasi.');
        }

        $user->notify(new VerifyEmailNotification);

        return ApiResponse::success(null, 'Email verifikasi telah dikirim ulang.');
    }
}
