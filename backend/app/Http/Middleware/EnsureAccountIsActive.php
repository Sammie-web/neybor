<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user()?->isSuspended()) {
            return response()->json([
                'message' => 'This account has been suspended.',
                'code' => 'account_suspended',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
