<?php

namespace App\Http\Middleware;

use App\Enums\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $allowedRoles = array_map(
            static fn (string $role): ?UserRole => UserRole::tryFrom($role),
            $roles,
        );

        if (! $request->user() || ! in_array($request->user()->role, $allowedRoles, true)) {
            return response()->json([
                'message' => 'You are not authorized to perform this action.',
                'code' => 'role_forbidden',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
