<?php
declare(strict_types=1);

// ============================================================================
// Web: infrastruktur halaman server-rendered — sesi cookie, CSRF, flash,
// URL, layout (navbar), dan helper tampilan.
// ============================================================================

function h(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

final class Web
{
    private const COOKIE = 'absen_sid';
    private static ?string $sid = null;
    private static ?array $user = null;
    private static bool $started = false;

    // ---- Sesi ---------------------------------------------------------------------
    public static function boot(): void
    {
        if (self::$started) return;
        self::$started = true;
        $sid = $_COOKIE[self::COOKIE] ?? '';
        if (is_string($sid) && preg_match('/^[a-f0-9]{64}$/', $sid)) {
            $uid = Repo::sessionFindValid($sid);
            if ($uid !== null) {
                $u = Repo::userById($uid);
                if ($u && $u['is_active']) {
                    self::$sid = $sid;
                    self::$user = $u;
                }
            }
        }
    }

    public static function isHttps(): bool
    {
        return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (Config::get('trust_proxy') && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    }

    private static function setCookie(string $value, int $expires): void
    {
        setcookie(self::COOKIE, $value, [
            'expires' => $expires, 'path' => self::base() . '/', 'secure' => self::isHttps(),
            'httponly' => true, 'samesite' => 'Lax',
        ]);
    }

    public static function login(array $user): void
    {
        if (self::$sid) Repo::sessionDestroy(self::$sid);
        $s = Repo::sessionCreate($user['id']);
        self::setCookie($s['token'], intdiv($s['expiresAtMillis'], 1000));
        self::$sid = $s['token'];
        self::$user = $user;
    }

    public static function logout(): void
    {
        if (self::$sid) Repo::sessionDestroy(self::$sid);
        self::setCookie('', time() - 3600);
        self::$sid = null;
        self::$user = null;
    }

    public static function user(): ?array
    {
        return self::$user;
    }

    /** Ringkas untuk Svc: id, nama, roles. */
    public static function actor(): array
    {
        $u = self::requireLogin();
        return ['id' => $u['id'], 'nama' => $u['nama'], 'roles' => $u['roles']];
    }

    public static function requireLogin(): array
    {
        if (!self::$user) {
            self::redirect(self::url('login'));
        }
        return self::$user;
    }

    // ---- Peran --------------------------------------------------------------------
    public static function has(string $role): bool
    {
        return self::$user && in_array($role, self::$user['roles'], true);
    }

    public static function isAdmin(): bool
    {
        return self::$user && Util::hasAdminRole(self::$user['roles']);
    }

    public static function isKepsek(): bool
    {
        return self::has('kepsek') || self::has('superadmin');
    }

    public static function requireAdmin(): void
    {
        self::requireLogin();
        if (!self::isAdmin()) {
            http_response_code(403);
            self::head('Akses Ditolak', '');
            echo '<div class="card"><h1>Akses ditolak</h1><p>Halaman ini khusus Administrator.</p></div>';
            self::foot();
            exit;
        }
    }

    public static function roleLabel(array $roles): string
    {
        $map = ['superadmin' => 'Superadmin', 'admin' => 'Admin', 'kepsek' => 'Kepala Sekolah', 'bk' => 'Guru BK', 'guru' => 'Guru'];
        return implode(' · ', array_map(fn($r) => $map[$r] ?? $r, $roles));
    }

    // ---- URL & redirect -------------------------------------------------------------
    public static function base(): string
    {
        $dir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php'));
        return $dir === '/' || $dir === '.' ? '' : rtrim($dir, '/');
    }

    public static function url(string $page = 'dashboard', array $params = []): string
    {
        $q = http_build_query(array_merge(['p' => $page], array_filter($params, fn($v) => $v !== null && $v !== '')));
        return self::base() . '/?' . $q;
    }

    public static function asset(string $file): string
    {
        $path = dirname(__DIR__) . '/public/assets/' . $file;
        $v = is_file($path) ? filemtime($path) : 1;
        return self::base() . '/assets/' . $file . '?v=' . $v;
    }

    public static function redirect(string $url): never
    {
        header('Location: ' . $url);
        exit;
    }

    public static function absoluteUrl(string $page, array $params): string
    {
        $scheme = self::isHttps() ? 'https' : 'http';
        return $scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . self::url($page, $params);
    }

    // ---- CSRF & flash -----------------------------------------------------------------
    public static function csrf(): string
    {
        return hash_hmac('sha256', 'csrf', self::$sid ?? 'anon');
    }

    public static function csrfField(): string
    {
        return '<input type="hidden" name="_csrf" value="' . h(self::csrf()) . '">';
    }

    /** Untuk form POST bertingkat login: wajib token CSRF valid. */
    public static function verifyPost(): void
    {
        $tok = $_POST['_csrf'] ?? '';
        if (!is_string($tok) || !hash_equals(self::csrf(), $tok)) {
            http_response_code(419);
            self::head('Sesi Formulir Kedaluwarsa', '');
            echo '<div class="card"><h1>Formulir kedaluwarsa</h1><p>Muat ulang halaman lalu coba lagi.</p></div>';
            self::foot();
            exit;
        }
    }

    public static function isPost(): bool
    {
        return ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
    }

    /** Pesan sekali-tampil disimpan di cookie pendek (tanpa state PHP session). */
    public static function flash(string $type, string $msg): void
    {
        setcookie('absen_flash', base64_encode(json_encode([$type, $msg])), [
            'expires' => time() + 60, 'path' => self::base() . '/', 'httponly' => true, 'samesite' => 'Lax', 'secure' => self::isHttps(),
        ]);
        $_COOKIE['absen_flash'] = base64_encode(json_encode([$type, $msg]));
    }

    private static function takeFlash(): ?array
    {
        $c = $_COOKIE['absen_flash'] ?? null;
        if (!is_string($c)) return null;
        setcookie('absen_flash', '', ['expires' => time() - 3600, 'path' => self::base() . '/']);
        unset($_COOKIE['absen_flash']);
        $d = json_decode((string) base64_decode($c, true), true);
        return is_array($d) && count($d) === 2 ? $d : null;
    }

    /** Alert inline (tidak lewat redirect). */
    public static function alert(string $type, string $msg): string
    {
        return '<div class="alert alert-' . h($type) . '" role="alert">' . h($msg) . '</div>';
    }

    // ---- Layout -----------------------------------------------------------------------
    public static function head(string $title, string $active = '', bool $bare = false): void
    {
        $settings = [];
        try {
            $settings = Repo::settingsGet();
        } catch (Throwable) {
        }
        $school = $settings['school_name'] ?? (string) Config::get('school_name');
        header('Content-Type: text/html; charset=utf-8');
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: same-origin');
        header('Cache-Control: no-store');
        echo '<!doctype html><html lang="id"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . h($title) . ' · ' . h($school) . '</title>'
            . '<link rel="stylesheet" href="' . h(self::asset('app.css')) . '">'
            . '</head><body' . ($bare ? ' class="bare"' : '') . '>';
        $u = self::$user;
        if ($u && !$bare) {
            $tabs = [['dashboard', 'Dashboard'], ['absensi', 'Absensi'], ['nilai', 'Nilai'], ['siswa', 'Riwayat Siswa']];
            if (self::has('bk') || self::isAdmin()) $tabs[] = ['bk', 'Integrasi BK'];
            if (self::isAdmin()) $tabs[] = ['admin', 'Admin Panel'];
            echo '<header class="nav"><div class="nav-in"><a class="brand" href="' . h(self::url('dashboard')) . '"><span class="logo">A</span><span><b>Absensi Siswa</b><small>' . h($school) . '</small></span></a>';
            echo '<nav class="tabs" aria-label="Menu utama">';
            foreach ($tabs as [$key, $label]) {
                echo '<a href="' . h(self::url($key)) . '"' . ($active === $key ? ' class="on" aria-current="page"' : '') . '>' . h($label) . '</a>';
            }
            echo '</nav><div class="who"><span class="who-name">' . h($u['nama']) . '<small>' . h(self::roleLabel($u['roles'])) . '</small></span>'
                . '<a class="btn btn-sm" href="' . h(self::url('password')) . '">Password</a>'
                . '<a class="btn btn-sm" href="' . h(self::url('login', ['switch' => 1])) . '">Ganti Akun</a>'
                . '<form method="post" action="' . h(self::url('logout')) . '" class="inline">' . self::csrfField() . '<button class="btn btn-sm btn-dark">Keluar</button></form>'
                . '</div></div></header>';
        }
        echo '<main class="wrap' . ($bare ? ' wrap-bare' : '') . '">';
        if ($f = self::takeFlash()) {
            echo self::alert($f[0], $f[1]);
        }
    }

    public static function foot(): void
    {
        echo '</main><script src="' . h(self::asset('app.js')) . '"></script></body></html>';
    }

    // ---- Helper tampilan ---------------------------------------------------------------
    public static function select(string $name, array $options, mixed $selected = null, string $attrs = ''): string
    {
        $html = '<select name="' . h($name) . '" ' . $attrs . '>';
        foreach ($options as $val => $label) {
            $html .= '<option value="' . h($val) . '"' . ((string) $val === (string) $selected ? ' selected' : '') . '>' . h($label) . '</option>';
        }
        return $html . '</select>';
    }

    public static function fmtDate(string $ymd): string
    {
        $ts = Util::parseSessionDate($ymd);
        if ($ts === null) return $ymd;
        $days = Analytics::DAY_NAMES;
        $mon = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];
        return $days[(int) gmdate('w', $ts)] . ', ' . (int) gmdate('j', $ts) . ' ' . $mon[(int) gmdate('n', $ts)] . ' ' . gmdate('Y', $ts);
    }

    public static function rateClass(float $rate): string
    {
        return $rate >= 90 ? 'ok' : ($rate >= 80 ? 'warn' : 'bad');
    }

    public static function today(): string
    {
        // Tanggal hari ini menurut WIB agar guru tidak melihat "kemarin" di pagi hari.
        return (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->format('Y-m-d');
    }

    /** Kelas & mapel yang boleh dipilih user pada form absensi/nilai. */
    public static function classesFor(array $user, bool $wali): array
    {
        $all = Repo::classesAll();
        if (Util::hasAdminRole($user['roles'])) return $all;
        if ($wali) {
            return array_values(array_filter($all, fn($c) => $c['id'] === ($user['kelas_wali_id'] ?? null)));
        }
        $ids = array_map('intval', $user['classes'] ?? []);
        foreach (Repo::pairingsAll() as $p) {
            if ($p['user_id'] === $user['id']) $ids[] = $p['class_id'];
        }
        return array_values(array_filter($all, fn($c) => in_array($c['id'], $ids, true)));
    }

    public static function subjectsFor(array $user): array
    {
        $all = Repo::subjectsAll();
        if (Util::hasAdminRole($user['roles'])) return $all;
        $ids = array_map('intval', $user['subjects'] ?? []);
        foreach (Repo::pairingsAll() as $p) {
            if ($p['user_id'] === $user['id']) $ids[] = $p['subject_id'];
        }
        return array_values(array_filter($all, fn($s) => in_array($s['id'], $ids, true)));
    }
}
