<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next)
    {
        $res = $next($request);
        $res->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $res->headers->set('X-Content-Type-Options', 'nosniff');
        $res->headers->set('Referrer-Policy', 'same-origin');
        if (! $res->headers->has('Cache-Control') || str_contains((string) $res->headers->get('Content-Type'), 'text/html')) {
            $res->headers->set('Cache-Control', 'no-store, private');
        }

        return $res;
    }
}
