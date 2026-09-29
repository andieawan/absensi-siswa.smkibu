<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/** Tautan lama "/?token=kk_…" (ketua kelas) dan "/?wali=wm_…" (wali murid) tetap berfungsi. */
class LegacyLinks
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->is('/') && $request->isMethod('GET')) {
            $t = $request->query('token');
            if (is_string($t) && str_starts_with($t, 'kk_')) {
                return redirect()->route('delegation', $t);
            }
            $w = $request->query('wali');
            if (is_string($w) && str_starts_with($w, 'wm_')) {
                return redirect()->route('parent', $w);
            }
        }

        return $next($request);
    }
}
