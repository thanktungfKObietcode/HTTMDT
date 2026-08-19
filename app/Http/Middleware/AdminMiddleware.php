<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return redirect()->route('login');
        }

        if (! $user->is_active || ! $user->roles()->where('name', 'admin')->exists()) {
            abort(403, 'Bạn không có quyền truy cập khu vực quản trị.');
        }

        return $next($request);
    }
}