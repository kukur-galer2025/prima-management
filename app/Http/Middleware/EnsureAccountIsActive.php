<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Mengeluarkan pengguna yang akunnya dinonaktifkan admin (juga sesi yang sedang berjalan),
 * dan mencatat waktu terakhir aktif untuk panel admin.
 */
class EnsureAccountIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        if ($user->isDisabled()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            $message = 'Akunmu dinonaktifkan. Hubungi admin untuk mengaktifkannya lagi.';

            if ($request->expectsJson()) {
                abort(403, $message);
            }

            return redirect()->route('login')->with('error', $message);
        }

        $user->touchLastActive();

        return $next($request);
    }
}
