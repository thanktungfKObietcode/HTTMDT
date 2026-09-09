<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class PermissionMiddleware
{
    public function handle(Request $request, Closure $next, string ...$permissions): Response
    {
        $user = $request->user();

        if (! $user || ! $user->canAccessBackoffice()) {
            abort(403);
        }

        if (! collect($permissions)->contains(fn (string $permission): bool => $user->hasPermission($permission))) {
            abort(403);
        }

        return $next($request);
    }
}
