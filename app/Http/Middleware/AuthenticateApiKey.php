<?php

namespace App\Http\Middleware;

use App\Models\ApiKey;
use App\Services\Audit;
use App\Support\DbUpdate;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Autentikasi kunci API per aplikasi: `Authorization: Bearer <kunci>` (atau header X-API-Key).
 * Kunci TIDAK diterima lewat query string (bocor ke log). Parameter middleware = scope yang diwajibkan.
 */
class AuthenticateApiKey
{
    public static function error(string $code, string $message, int $status, array $headers = [])
    {
        return response()->json(['error' => ['code' => $code, 'message' => $message]], $status, $headers);
    }

    public function handle(Request $request, Closure $next, string ...$scopes)
    {
        if (! DbUpdate::apiReady()) {
            return self::error('not_ready', 'Fitur API belum aktif: Admin perlu menjalankan "Perbarui Database Sekarang".', 503);
        }
        $plain = (string) ($request->bearerToken() ?: $request->header('X-API-Key', ''));
        $key = $plain !== '' ? ApiKey::where('key_hash', ApiKey::hashOf($plain))->first() : null;
        if (! $key || ! $key->is_active) {
            return self::error('unauthorized', 'Kunci API tidak ada, salah, atau sudah dicabut. Kirim lewat header "Authorization: Bearer <kunci>".', 401, ['WWW-Authenticate' => 'Bearer']);
        }
        foreach ($scopes as $scope) {
            if (! $key->can($scope)) {
                return self::error('forbidden', "Kunci ini tidak memiliki izin \"$scope\".", 403);
            }
        }

        $limit = max(1, (int) $key->rate_limit);
        $rl = 'apikey:'.$key->id;
        if (RateLimiter::tooManyAttempts($rl, $limit)) {
            return self::error('rate_limited', 'Terlalu banyak permintaan. Coba lagi sebentar lagi.', 429, ['Retry-After' => RateLimiter::availableIn($rl), 'X-RateLimit-Limit' => $limit, 'X-RateLimit-Remaining' => 0]);
        }
        RateLimiter::hit($rl, 60);

        $now = now('UTC');
        $last = $key->last_used_at ? strtotime($key->last_used_at.' UTC') : 0;
        if ($now->timestamp - $last >= 60) {
            $key->update(['last_used_at' => $now->format('Y-m-d H:i:s'), 'last_ip' => $request->ip()]);
            if ($now->timestamp - $last >= 3600) { // satu baris log per jam per kunci (bukan per permintaan)
                Audit::log('Akses API', 'Integrasi API', $key->name, 'IP '.$request->ip());
            }
        }
        $request->attributes->set('api_key', $key);

        $res = $next($request);
        $res->headers->set('X-RateLimit-Limit', (string) $limit);
        $res->headers->set('X-RateLimit-Remaining', (string) RateLimiter::remaining($rl, $limit));

        return $res;
    }
}
