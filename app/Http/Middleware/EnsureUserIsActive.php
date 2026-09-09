<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $isActive = $user
            ? User::query()->whereKey($user->getAuthIdentifier())->value('is_active')
            : null;

        if ($user && ! (bool) $isActive) {
            Auth::guard()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                return response()->json(['message' => 'Tài khoản đã bị vô hiệu hóa.'], 403);
            }

            return redirect()->route('login')->withErrors([
                'email' => 'Tài khoản đã bị vô hiệu hóa.',
            ]);
        }

        if ($user) {
            $user->setAttribute('is_active', true);
        }

        return $next($request);
    }
}
