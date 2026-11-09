<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserHasRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();
        $hasRole = $user && (
            in_array($user->role, $roles, true)
            || ($user->isAdmin() && in_array(User::ROLE_ADMIN, $roles, true))
        );

        abort_unless(
            $hasRole,
            Response::HTTP_FORBIDDEN,
        );

        return $next($request);
    }
}
