<?php
declare(strict_types=1);

// ============================================================================
// Access: endpoint delegasi ketua kelas, portal wali murid, auth/login, data
// master, sinkronisasi, dan backup.
// ============================================================================

final class Access
{
    // ------------------------------------------------------------------------
    // Data master (baca)
    // ------------------------------------------------------------------------
    public static function studentsList(): never
    {
        $c = Req::query('class_id');
        $rows = Repo::studentsAll($c !== null && $c !== '' ? (int) $c : null);
        Res::json(['success' => true, 'count' => count($rows), 'data' => $rows]);
    }

    public static function classesList(): never
    {
        Res::json(['success' => true, 'data' => Repo::classesAll()]);
    }

    public static function subjectsList(): never
    {
        Res::json(['success' => true, 'data' => Repo::subjectsAll()]);
    }

    /** Pengguna — TIDAK PERNAH mengekspos password_hash. */
    public static function usersList(): never
    {
        Res::json(['success' => true, 'data' => array_map([Api::class, 'stripHash'], Repo::usersAll())]);
    }

    public static function pairingsList(): never
    {
        Res::json(['success' => true, 'data' => Repo::pairingsAll()]);
    }

    public static function settingsGet(): never
    {
        Res::json(['success' => true, 'data' => Repo::settingsGet()]);
    }

    public static function settingsSave(): never
    {
        Api::requireAdmin();
        $settings = Req::$body;
        if (!$settings) {
            Res::error('Data pengaturan sekolah tidak valid.', 400);
        }
        // last_backup_* hanya boleh diisi proses backup server sendiri
        $current = Repo::settingsGet();
        Repo::settingsUpdate(array_merge($settings, [
            'last_backup_date' => $current['last_backup_date'] ?? null,
            'last_backup_status' => $current['last_backup_status'] ?? null,
        ]));
        Res::json(['success' => true, 'message' => 'Pengaturan sekolah berhasil disimpan di server.']);
    }

    public static function backupNow(): never
    {
        Api::requireAdmin('Otorisasi Ditolak: backup manual khusus Administrator.');
        $r = Backup::run();
        if (!$r['success']) {
            Res::error($r['error'] ?? 'Gagal membuat backup database.', 500);
        }
        Repo::audit('Backup Manual Database', 'Sistem', Api::actor()['nama'], 'Snapshot: ' . basename($r['file']));
        Res::json(['success' => true, 'file' => basename($r['file']), 'settings' => Repo::settingsGet()]);
    }

    // ------------------------------------------------------------------------
    // Sinkronisasi massal data master
    // ------------------------------------------------------------------------
    public static function syncPush(): never
    {
        Api::requireAdmin();
        $b = Req::$body;
        $warning = null;
        try {
            Db::tx(function () use ($b, &$warning) {
                foreach (($b['classes'] ?? []) as $c) if (is_array($c)) Repo::classUpsert($c);
                foreach (($b['subjects'] ?? []) as $s) if (is_array($s)) Repo::subjectUpsert($s);
                foreach (($b['students'] ?? []) as $s) if (is_array($s)) Repo::studentUpsert($s);
                foreach (($b['users'] ?? []) as $u) if (is_array($u)) Repo::userUpsert($u);
                if (isset($b['pairings']) && is_array($b['pairings'])) {
                    $r = Repo::pairingsReplaceAll($b['pairings']);
                    if (!$r['applied']) {
                        $warning = $r['reason'];
                    }
                }
                if (!empty($b['settings']) && is_array($b['settings'])) {
                    Repo::settingsUpdate($b['settings']);
                }
            });
        } catch (Throwable $e) {
            error_log('[sync/push] ' . $e->getMessage());
            Res::error(Config::get('debug') ? $e->getMessage() : 'Gagal menyimpan data ke server.', 500);
        }
        Repo::audit('Sinkronisasi Data Master', 'Sistem', Api::actor()['nama'], 'Push data master dari device ke server');
        $out = ['success' => true, 'message' => 'Sinkronisasi data master ke server berhasil.'];
        if ($warning) {
            $out['warnings'] = [$warning];
        }
        Res::json($out);
    }

    public static function syncPull(): never
    {
        Res::json([
            'success' => true,
            'data' => [
                'classes' => Repo::classesAll(),
                'subjects' => Repo::subjectsAll(),
                'students' => Repo::studentsAll(),
                'users' => array_map([Api::class, 'stripHash'], Repo::usersAll()),
                'pairings' => Repo::pairingsAll(),
                'settings' => Repo::settingsGet(),
                'attendance' => Repo::attendanceQuery(),
                'tokens' => Repo::tokensAll(),
                'gradeActivities' => Repo::gradeActivitiesAll(),
                'gradeValues' => Repo::gradeValuesAll(),
            ],
        ]);
    }

    // ------------------------------------------------------------------------
    // Delegasi Ketua Kelas (token 24 jam)
    // ------------------------------------------------------------------------
    /** Validasi token delegasi aktif & belum kedaluwarsa. Mengembalikan token atau langsung merespons error. */
    private static function activeDelegationToken(string $tokenStr, bool $forSubmit): array
    {
        $key = $forSubmit ? 'success' : 'valid';
        $found = Repo::tokenByToken($tokenStr);
        if (!$found) {
            Res::json([$key => false, 'error' => $forSubmit ? 'Token delegasi tidak sah.' : 'Token delegasi tidak ditemukan di database sekolah.'], 404);
        }
        if ($found['status'] !== 'aktif') {
            Res::json([$key => false, 'error' => $forSubmit ? 'Token delegasi sudah tidak aktif.' : 'Token presensi ini sudah tidak aktif atau telah dicabut.'], 403);
        }
        $expiry = $found['expires_at_millis'] ?? (isset($found['expires_at']) ? (int) (strtotime($found['expires_at']) * 1000) : null);
        if ($expiry && Util::nowMillis() > $expiry) {
            $when = Util::idLocale((int) $expiry);
            Res::json([$key => false, 'error' => $forSubmit
                ? 'Token delegasi telah kedaluwarsa.'
                : "Tautan presensi telah kedaluwarsa (berakhir pada $when). Silakan minta Wali Kelas untuk membuatkan tautan baru."], 410);
        }
        return $found;
    }

    public static function delegationCreate(): never
    {
        $a = Api::actor();
        $classId = (int) Req::body('class_id');
        if (!$classId || !Repo::classById($classId)) {
            Res::error('Kelas tujuan delegasi tidak valid.', 400);
        }
        Api::requireTeacher(null, $classId);

        $hours = min(max(1, (int) Req::body('expiry_hours', 24) ?: 24), 24);
        $now = Util::nowMillis();
        $expires = $now + $hours * 3600 * 1000;
        $token = [
            'token' => 'kk_' . Util::base64url(random_bytes(24)),
            'class_id' => $classId,
            'status' => 'aktif',
            'created_at' => Util::iso($now),
            'created_by' => $a['id'],
            'expires_at' => Util::iso($expires),
            'expires_at_millis' => $expires,
        ];
        Repo::tokenUpsert($token);
        Repo::audit('Buat Delegasi', 'Absensi', $a['nama'], "Kelas #$classId, berlaku $hours jam");
        Res::json(['success' => true, 'token' => $token]);
    }

    public static function delegationVerify(): never
    {
        $t = (string) Req::query('token');
        if ($t === '') {
            Res::json(['valid' => false, 'error' => 'Parameter token wajib diisi.'], 400);
        }
        Res::json(['valid' => true, 'token' => self::activeDelegationToken($t, false)]);
    }

    public static function delegationSession(): never
    {
        $t = (string) Req::query('token');
        if ($t === '') {
            Res::json(['valid' => false, 'error' => 'Parameter token diperlukan.'], 400);
        }
        $found = self::activeDelegationToken($t, false);
        $students = array_values(array_filter(Repo::studentsAll($found['class_id']), fn($s) => $s['status'] === 'aktif'));
        Res::json(['valid' => true, 'token' => $found, 'targetClass' => Repo::classById($found['class_id']), 'students' => $students]);
    }

    public static function delegationSubmit(): never
    {
        $token = Req::body('token');
        if (!is_string($token) || $token === '') {
            Res::error('Parameter token wajib diisi.', 400);
        }
        $found = self::activeDelegationToken($token, true);
        $classId = Req::body('class_id');
        if ($found['class_id'] !== (int) $classId) {
            Res::error('Token ini tidak berlaku untuk kelas yang diajukan.', 403);
        }

        $tanggal = Req::body('tanggal');
        $ts = Util::parseSessionDate($tanggal);
        if ($ts === null) {
            Res::error('Format tanggal harus YYYY-MM-DD yang valid.', 400);
        }
        if ($ts - time() > 86400 || Util::daysSince($ts) > 7) {
            Res::error('Tanggal presensi delegasi harus hari ini atau maksimal 7 hari ke belakang.', 400);
        }
        $entries = Req::body('entries');
        if (!is_array($entries) || !array_is_list($entries)) {
            Res::error('Parameter entries[] wajib diisi.', 400);
        }
        $err = Rules::validateAttendanceEntries($entries, $found['class_id']);
        if ($err) {
            Res::error($err[1], $err[0]);
        }
        Db::tx(function () use ($entries, $found, $tanggal) {
            foreach ($entries as $item) {
                Repo::attendanceUpsert(
                    (int) $item['student_id'], $found['class_id'], null, $tanggal, $item['status'],
                    isset($item['notes']) ? (string) $item['notes'] : null, (int) ($found['created_by'] ?? 0), 'ketua_kelas_delegasi'
                );
            }
        });
        $n = count($entries);
        Repo::audit('Submit Absensi Delegasi', 'Absensi', "Ketua Kelas (token kelas #{$found['class_id']})", "Tanggal: $tanggal, Total: $n siswa");
        Res::json(['success' => true, 'message' => "Presensi kelas #$classId tanggal $tanggal ($n siswa) berhasil diverifikasi dan disimpan via delegasi Ketua Kelas."]);
    }

    // ------------------------------------------------------------------------
    // Portal Orang Tua / Wali Murid (baca-saja, per siswa)
    // ------------------------------------------------------------------------
    public static function parentAccessCreate(): never
    {
        $a = Api::actor();
        $studentId = (int) Req::body('student_id');
        $student = $studentId ? Repo::studentById($studentId) : null;
        if (!$student) {
            Res::error('Siswa tujuan akses wali murid tidak valid.', 400);
        }
        Api::requireTeacher(null, $student['class_id']);
        $token = Repo::parentTokenCreate($studentId, $a['id']);
        Repo::audit('Buat Akses Wali Murid', 'Siswa', $a['nama'], "Siswa #$studentId ({$student['nama']})");
        Res::json(['success' => true, 'token' => $token]);
    }

    public static function parentAccessList(): never
    {
        $studentId = (int) Req::query('student_id');
        $student = $studentId ? Repo::studentById($studentId) : null;
        if (!$student) {
            Res::error('Parameter student_id tidak valid.', 400);
        }
        Api::requireTeacher(null, $student['class_id']);
        Res::json(['success' => true, 'data' => Repo::parentTokensByStudent($studentId)]);
    }

    public static function parentAccessRevoke(): never
    {
        $a = Api::actor();
        $token = (string) Req::body('token', '');
        $found = $token !== '' ? Repo::parentTokenByToken($token) : null;
        if (!$found) {
            Res::error('Token akses wali murid tidak ditemukan.', 404);
        }
        $student = Repo::studentById($found['student_id']);
        if (!$student) {
            Res::error('Data siswa untuk token ini tidak ditemukan.', 404);
        }
        Api::requireTeacher(null, $student['class_id']);
        $revoked = Repo::parentTokenRevoke($token);
        if ($revoked) {
            Repo::audit('Cabut Akses Wali Murid', 'Siswa', $a['nama'], "Siswa #{$found['student_id']} ({$student['nama']})");
        }
        Res::json(['success' => true, 'revoked' => $revoked]);
    }

    /** Publik — divalidasi token wali murid. Tidak pernah mengembalikan nilai akademik / data siswa lain. */
    public static function parentAccessSummary(): never
    {
        $t = (string) Req::query('token');
        if ($t === '') {
            Res::json(['valid' => false, 'error' => 'Parameter token wajib diisi.'], 400);
        }
        $found = Repo::parentTokenByToken($t);
        if (!$found) {
            Res::json(['valid' => false, 'error' => 'Tautan akses wali murid tidak ditemukan di sistem sekolah.'], 404);
        }
        if ($found['status'] !== 'aktif') {
            Res::json(['valid' => false, 'error' => 'Tautan akses ini sudah dicabut oleh wali kelas/Administrator.'], 403);
        }
        $student = Repo::studentById($found['student_id']);
        if (!$student) {
            Res::json(['valid' => false, 'error' => 'Data siswa untuk tautan ini tidak ditemukan.'], 404);
        }
        $class = Repo::classById($student['class_id']);
        $records = Repo::attendanceForStudent($student['id']);
        usort($records, fn($x, $y) => strcmp($y['tanggal'], $x['tanggal']));
        $recent = array_map(function ($r) {
            return array_filter(['tanggal' => $r['tanggal'], 'status' => $r['status'], 'notes' => $r['notes'] ?? null], fn($v) => $v !== null);
        }, array_slice($records, 0, 30));

        Res::json([
            'valid' => true,
            'student' => ['nama' => $student['nama'], 'nis' => $student['nis'], 'class_name' => $class['name'] ?? '-'],
            'stats' => Rules::attendanceStats($student['id']),
            'attention' => Rules::attentionCategory($student['id']),
            'patternAlerts' => Rules::periodicPattern($student['id']),
            'recentAttendance' => $recent,
        ]);
    }

    // ------------------------------------------------------------------------
    // Auth
    // ------------------------------------------------------------------------
    public static function authLogin(): never
    {
        $username = Req::body('username');
        $password = Req::body('password');
        if (!is_string($username) || trim($username) === '' || !$password) {
            Res::error('Username dan password wajib diisi.', 400);
        }
        $clean = strtolower(trim($username));
        $rateKey = sha1(Req::ip() . '|' . $clean);
        $locked = Repo::loginLockedFor($rateKey);
        if ($locked > 0) {
            Res::json(
                ['success' => false, 'error' => 'Terlalu banyak percobaan login gagal. Coba lagi dalam ' . (int) ceil($locked / 60) . ' menit.'],
                429, ['Retry-After' => (string) $locked]
            );
        }

        // Pesan gagal sengaja seragam agar tidak bisa dipakai menebak username terdaftar.
        $user = Repo::userByUsername($clean);
        $ok = $user && $user['is_active'] && Util::verifyPassword((string) $password, $user['password_hash'] ?? null);
        if (!$ok) {
            Repo::loginRecordFailure($rateKey);
            Res::error('Username atau password/PIN salah, atau akun nonaktif.', 401);
        }
        Repo::loginClear($rateKey);
        $session = Repo::sessionCreate($user['id']);

        try {
            Backup::runIfDue(24); // PHP tidak punya scheduler bawaan — dipicu oportunistik saat login
        } catch (Throwable $e) {
            error_log('[auto-backup] ' . $e->getMessage());
        }

        Res::json([
            'success' => true, 'token' => $session['token'], 'expires_at_millis' => $session['expiresAtMillis'],
            'user' => Api::stripHash($user),
        ]);
    }

    public static function authLogout(): never
    {
        $token = Req::bearer();
        if ($token) {
            Repo::sessionDestroy($token);
        }
        Res::json(['success' => true]);
    }

    /** Ganti password mandiri; identitas dari token sesi, bukan dari body. */
    public static function changePassword(): never
    {
        $a = Api::actor();
        $old = Req::body('old_password');
        $new = Req::body('new_password');
        if (!is_string($old) || $old === '') {
            Res::error('Password lama wajib diisi.', 400);
        }
        if (!is_string($new) || mb_strlen($new) < 8) {
            Res::error('Password baru minimal 8 karakter.', 400);
        }
        if ($old === $new) {
            Res::error('Password baru harus berbeda dari password lama.', 400);
        }
        $user = Repo::userById($a['id']);
        if (!$user || !$user['is_active']) {
            Res::error('Akun tidak ditemukan atau nonaktif.', 404);
        }
        if (!Util::verifyPassword($old, $user['password_hash'] ?? null)) {
            Res::error('Password lama tidak sesuai.', 401);
        }
        Repo::userUpsert(array_merge($user, ['password_hash' => Util::hashPassword($new)]));
        // Sesi di perangkat lain dicabut; sesi yang dipakai sekarang tetap berlaku.
        Repo::sessionDestroyAllForUser($a['id'], Req::bearer());
        Repo::audit('Ganti Password Mandiri', 'Akun Guru', $a['nama'], 'Pengguna mengganti password akunnya sendiri');
        Res::json(['success' => true, 'message' => 'Password berhasil diganti. Sesi login di perangkat lain (jika ada) sudah otomatis keluar demi keamanan.']);
    }
}
