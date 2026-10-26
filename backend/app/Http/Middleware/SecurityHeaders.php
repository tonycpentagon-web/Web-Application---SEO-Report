<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        foreach (['X-Content-Type-Options' => 'nosniff', 'Referrer-Policy' => 'no-referrer', 'X-Frame-Options' => 'DENY', 'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()', 'X-Robots-Tag' => 'noindex, nofollow, noarchive'] as $key => $value) {
            $response->headers->set($key, $value);
        }
        if (! $response->headers->has('Content-Security-Policy')) {
            $response->headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'; base-uri 'none'");
        }
        if ($request->is('api/*') || $request->is('login', 'logout', 'forgot-password', 'reset-password', 'password', 'sanctum/*')) {
            $response->headers->set('Cache-Control', 'private, no-store');
        }
        if (config('app.env') === 'production' && $request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $response;
    }
}
