<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsureActiveAccount
{
    public function handle(Request $request, Closure $next)
    {
        $user = $request->user();
        if (! $user->is_active || (int) $request->session()->get('session_version', 0) !== $user->session_version) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return response()->json(['message' => 'Votre session a expiré ou votre compte a été désactivé. Reconnectez-vous.'], 401);
        }

        return $next($request);
    }
}
