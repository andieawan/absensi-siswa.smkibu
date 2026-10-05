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
        // CSP dasar: hanya sumber sendiri (skrip/gaya inline masih dipakai halaman cetak & atribut style).
        $res->headers->set('Content-Security-Policy', "default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'");
        if (! $res->headers->has('Cache-Control') || str_contains((string) $res->headers->get('Content-Type'), 'text/html')) {
            $res->headers->set('Cache-Control', 'no-store, private');
        }

        return $res;
    }
}
