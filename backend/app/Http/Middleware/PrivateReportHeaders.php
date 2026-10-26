<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class PrivateReportHeaders
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        foreach (['Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex, nofollow, noarchive', 'Referrer-Policy' => 'no-referrer', 'X-Content-Type-Options' => 'nosniff'] as $key => $value) {
            $response->headers->set($key, $value);
        }

        return $response;
    }
}
