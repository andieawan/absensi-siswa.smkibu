<?php
declare(strict_types=1);

// ============================================================================
// Core: konfigurasi, utilitas waktu/token, dan helper HTTP (request/response).
// ============================================================================

final class Config
{
    private static array $c = [];

    private const DEFAULTS = [
        'db_driver'       => 'mysql',          // 'mysql' | 'sqlite'
        'mysql_host'      => '127.0.0.1',
        'mysql_port'      => '3306',
        'mysql_user'      => 'absensi',
        'mysql_password'  => '',
        'mysql_database'  => 'absensi_siswa',
        'mysql_ssl'       => false,
        'mysql_ssl_ca'    => '',
        'sqlite_path'     => '',               // default: app/storage/absensi.sqlite3
        'backup_dir'      => '',               // default: app/storage/backups
        'auto_backup'     => true,             // backup otomatis (dipicu saat login) tiap 24 jam
        'admin_username'  => '',
        'admin_password'  => '',
        'admin_nama'      => 'Administrator',
        'school_name'     => 'SMK Islam Bustanul Ulum Pakusari',
        'trust_proxy'     => false,            // true jika di balik reverse proxy/CDN
        'debug'           => false,
    ];

    public static function load(string $file): void
    {
        $user = is_file($file) ? (require $file) : [];
        self::$c = array_merge(self::DEFAULTS, is_array($user) ? $user : []);
        // Environment variable (mis. DB_DRIVER, MYSQL_HOST) menimpa config.php
        foreach (self::$c as $k => $v) {
            $env = getenv(strtoupper($k));
            if ($env !== false && $env !== '') {
                self::$c[$k] = is_bool($v) ? in_array(strtolower($env), ['1', 'true', 'yes'], true) : $env;
            }
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$c[$key] ?? $default;
    }
}

final class Util
{
    public static function nowMillis(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    /** Setara Date.toISOString() JavaScript: 2026-09-29T13:25:16.168Z */
    public static function iso(?int $millis = null): string
    {
        $millis ??= self::nowMillis();
        return gmdate('Y-m-d\TH:i:s', intdiv($millis, 1000)) . sprintf('.%03dZ', $millis % 1000);
    }

    /** Format "YYYY-MM-DD HH:MM:SS" (UTC), sama seperti kolom created_at/updated_at. */
    public static function sqlNow(): string
    {
        return gmdate('Y-m-d H:i:s');
    }

    public static function base64url(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function uuid4(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
        $h = bin2hex($b);
        return sprintf('%s-%s-%s-%s-%s', substr($h, 0, 8), substr($h, 8, 4), substr($h, 12, 4), substr($h, 16, 4), substr($h, 20, 12));
    }

    /** Tanggal sesi harus YYYY-MM-DD valid. Mengembalikan epoch detik (UTC 00:00) atau null. */
    public static function parseSessionDate(mixed $tanggal): ?int
    {
        if (!is_string($tanggal) || !preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $tanggal, $m)) {
            return null;
        }
        if (!checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }
        return gmmktime(0, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1]);
    }

    /** Selisih hari (dibulatkan ke bawah) antara sekarang dan tanggal sesi. Negatif = masa depan. */
    public static function daysSince(int $epochSeconds): int
    {
        return (int) floor((time() - $epochSeconds) / 86400);
    }

    /** Setara toLocaleString('id-ID') untuk pesan error (zona WIB). */
    public static function idLocale(int $millis): string
    {
        $d = (new DateTimeImmutable('@' . intdiv($millis, 1000)))->setTimezone(new DateTimeZone('Asia/Jakarta'));
        return $d->format('j/n/Y, H.i.s');
    }

    public static function hasAdminRole(array $roles): bool
    {
        return in_array('admin', $roles, true) || in_array('superadmin', $roles, true);
    }

    /** sha256:<salt>:<sha256(salt:plain)> — format yang sama dengan versi Node & frontend. */
    public static function hashPassword(string $plain): string
    {
        $salt = bin2hex(random_bytes(16));
        return 'sha256:' . $salt . ':' . hash('sha256', $salt . ':' . $plain);
    }

    public static function verifyPassword(string $plain, ?string $stored): bool
    {
        if (!$stored) {
            return false;
        }
        $parts = explode(':', $stored);
        if (count($parts) !== 3 || $parts[0] !== 'sha256') {
            return false;
        }
        return hash_equals($stored, 'sha256:' . $parts[1] . ':' . hash('sha256', $parts[1] . ':' . $plain));
    }
}

/** Request saat ini. */
final class Req
{
    public static string $method = 'GET';
    public static string $path = '/';
    public static array $query = [];
    public static array $body = [];

    public static function init(): void
    {
        self::$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $pos = strpos($uri, '/api/');
        $path = $pos !== false ? substr($uri, $pos + 4) : '/';
        self::$path = '/' . trim($path, '/');
        self::$query = $_GET;

        $raw = file_get_contents('php://input') ?: '';
        $body = [];
        if ($raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $body = $decoded;
            } elseif (json_last_error() !== JSON_ERROR_NONE && !empty($_POST)) {
                $body = $_POST;
            } elseif (json_last_error() !== JSON_ERROR_NONE) {
                Res::json(['success' => false, 'error' => 'Body request bukan JSON yang valid.'], 400);
            }
        } elseif (!empty($_POST)) {
            $body = $_POST;
        }
        self::$body = $body;
    }

    public static function body(string $key, mixed $default = null): mixed
    {
        return self::$body[$key] ?? $default;
    }

    public static function query(string $key): ?string
    {
        $v = self::$query[$key] ?? null;
        return is_string($v) ? $v : null;
    }

    public static function bearer(): ?string
    {
        $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? null;
        if ($h === null && function_exists('getallheaders')) {
            foreach (getallheaders() as $k => $v) {
                if (strtolower($k) === 'authorization') {
                    $h = $v;
                    break;
                }
            }
        }
        if (!is_string($h) || !preg_match('/^Bearer\s+(.+)$/i', $h, $m)) {
            return null;
        }
        return trim($m[1]);
    }

    public static function ip(): string
    {
        if (Config::get('trust_proxy') && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            return trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0]);
        }
        return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    }
}

/** Response JSON. */
final class Res
{
    public static function json(mixed $data, int $status = 200, array $headers = []): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        header('X-Content-Type-Options: nosniff');
        foreach ($headers as $k => $v) {
            header("$k: $v");
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        exit;
    }

    public static function error(string $message, int $status, array $extra = []): never
    {
        self::json(array_merge(['success' => false, 'error' => $message], $extra), $status);
    }
}
