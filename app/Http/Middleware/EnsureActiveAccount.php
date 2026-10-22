<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnsureActiveAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        $current = $user?->fresh();
        if ($user && ! $current?->is_active) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            abort(403, 'هذا الحساب معطل.');
        }
        $user?->setRawAttributes($current->getAttributes(), true);

        return $next($request);
    }
}
