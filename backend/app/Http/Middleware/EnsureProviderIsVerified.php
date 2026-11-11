<?php

namespace App\Http\Middleware;

use App\Enums\ProviderVerificationStatus;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureProviderIsVerified
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->provider_verification_status !== ProviderVerificationStatus::Verified) {
            return response()->json([
                'message' => 'Provider verification is required for this operation.',
                'code' => 'provider_verification_required',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
