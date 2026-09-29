<?php
declare(strict_types=1);

// ============================================================================
// Repo: lapisan akses data (padanan Repo di server/db.ts). Semua SQL portable
// untuk MySQL maupun SQLite; upsert dilakukan dengan pola cek-lalu-update/insert.
// ============================================================================

final class Repo
{
    // ---- Mapper baris -> objek domain (bentuk JSON sama dengan versi Node) ----
    private static function omitNull(array $a, array $keys): array
    {
        foreach ($keys as $k) {
            if (array_key_exists($k, $a) && $a[$k] === null) {
                unset($a[$k]);
            }
        }
        return $a;
    }

    private static function intOrNull(mixed $v): ?int
    {
        return $v === null ? null : (int) $v;
    }

    private static function user(array $r): array
    {
        return self::omitNull([
            'id' => (int) $r['id'],
            'username' => $r['username'],
            'password_hash' => $r['password_hash'],
            'nama' => $r['nama'],
            'kelas_wali_id' => self::intOrNull($r['kelas_wali_id']),
            'foto_profil_url' => $r['foto_profil_url'],
            'is_active' => (bool) $r['is_active'],
            'created_at' => $r['created_at'],
            'roles' => json_decode($r['roles'] ?: '[]', true) ?: [],
            'subjects' => json_decode($r['subjects'] ?: '[]', true) ?: [],
            'classes' => json_decode($r['classes'] ?: '[]', true) ?: [],
        ], ['password_hash']);
    }

    private static function student(array $r): array
    {
        return [
            'id' => (int) $r['id'], 'nis' => $r['nis'], 'nama' => $r['nama'], 'jk' => $r['jk'],
            'class_id' => (int) $r['class_id'], 'status' => $r['status'], 'created_at' => $r['created_at'],
        ];
    }

    private static function classRow(array $r): array
    {
        return [
            'id' => (int) $r['id'], 'name' => $r['name'], 'jurusan' => $r['jurusan'], 'angkatan' => $r['angkatan'],
            'tahun_ajaran' => $r['tahun_ajaran'], 'semester' => $r['semester'],
        ];
    }

    private static function attendance(array $r): array
    {
        return self::omitNull([
            'id' => (int) $r['id'],
            'student_id' => (int) $r['student_id'],
            'class_id' => (int) $r['class_id'],
            'subject_id' => self::intOrNull($r['subject_id']),
            'tanggal' => $r['tanggal'],
            'status' => $r['status'],
            'recorded_by' => self::intOrNull($r['recorded_by']),
            'recorded_via' => $r['recorded_via'],
            'notes' => $r['notes'],
            'created_at' => $r['created_at'],
            'updated_at' => $r['updated_at'],
            'created_at_millis' => self::intOrNull($r['created_at_millis']),
        ], ['notes', 'created_at_millis']);
    }

    private static function token(array $r): array
    {
        return self::omitNull([
            'token' => $r['token'], 'class_id' => (int) $r['class_id'], 'status' => $r['status'],
            'created_at' => $r['created_at'], 'created_by' => self::intOrNull($r['created_by']),
            'expires_at' => $r['expires_at'], 'expires_at_millis' => self::intOrNull($r['expires_at_millis']),
        ], ['expires_at', 'expires_at_millis']);
    }

    private static function parentToken(array $r): array
    {
        return self::omitNull([
            'token' => $r['token'], 'student_id' => (int) $r['student_id'], 'status' => $r['status'],
            'created_at' => $r['created_at'], 'created_by' => self::intOrNull($r['created_by']),
            'revoked_at' => $r['revoked_at'],
        ], ['revoked_at']);
    }

    private static function settingsRow(array $r): array
    {
        return self::omitNull([
            'school_name' => $r['school_name'], 'logo_url' => $r['logo_url'], 'tahun_ajaran' => $r['tahun_ajaran'],
            'semester' => $r['semester'], 'kepsek_nama' => $r['kepsek_nama'], 'bk_nama' => $r['bk_nama'],
            'backup_retention_weeks' => self::intOrNull($r['backup_retention_weeks']),
            'last_backup_date' => $r['last_backup_date'], 'last_backup_status' => $r['last_backup_status'],
        ], ['last_backup_date', 'last_backup_status']);
    }

    // ---- Sequences & audit ----------------------------------------------------
    /** Alokasi ID atomik (baris dikunci selama transaksi). Mengembalikan daftar ID baru. */
    public static function allocateSequence(string $entity, int $count = 1): array
    {
        return Db::tx(function () use ($entity, $count) {
            $lock = Db::driver() === 'mysql' ? ' FOR UPDATE' : '';
            $row = Db::get('SELECT value FROM sequences WHERE entity = ?' . $lock, [$entity]);
            $current = $row ? (int) $row['value'] : 1000;
            $next = $current + $count;
            if ($row) {
                Db::run('UPDATE sequences SET value = ? WHERE entity = ?', [$next, $entity]);
            } else {
                Db::run('INSERT INTO sequences (entity, value) VALUES (?, ?)', [$entity, $next]);
            }
            return $count > 0 ? range($current + 1, $next) : [];
        });
    }

    public static function sequencesStatus(): array
    {
        $out = [];
        foreach (Db::all('SELECT entity, value FROM sequences') as $r) {
            $out[$r['entity']] = (int) $r['value'];
        }
        return $out;
    }

    public static function audit(string $action, string $module, string $actor, string $details): void
    {
        Db::run(
            'INSERT INTO audit_log (timestamp, action, module, actor, details) VALUES (?, ?, ?, ?, ?)',
            [Util::iso(), $action, $module, $actor, $details]
        );
        Db::run('DELETE FROM audit_log WHERE id NOT IN (SELECT id FROM (SELECT id FROM audit_log ORDER BY id DESC LIMIT 500) AS keep_ids)');
    }

    public static function counts(): array
    {
        $c = fn(string $t) => (int) (Db::get("SELECT COUNT(*) AS c FROM $t")['c'] ?? 0);
        return [
            'attendance' => $c('attendance'), 'students' => $c('students'), 'classes' => $c('classes'),
            'pairings' => $c('teacher_subject_class_pairing'),
        ];
    }

    // ---- Users ------------------------------------------------------------------
    public static function usersAll(): array
    {
        return array_map([self::class, 'user'], Db::all('SELECT * FROM users ORDER BY id'));
    }

    public static function userById(int $id): ?array
    {
        $r = Db::get('SELECT * FROM users WHERE id = ?', [$id]);
        return $r ? self::user($r) : null;
    }

    public static function userByUsername(string $lowerUsername): ?array
    {
        $r = Db::get('SELECT * FROM users WHERE LOWER(username) = ?', [$lowerUsername]);
        return $r ? self::user($r) : null;
    }

    public static function userUpsert(array $u): void
    {
        $id = (int) ($u['id'] ?? 0);
        $vals = [
            (string) ($u['username'] ?? ''),
            $u['password_hash'] ?? null,
            (string) ($u['nama'] ?? ''),
            isset($u['kelas_wali_id']) ? (int) $u['kelas_wali_id'] : null,
            $u['foto_profil_url'] ?? null,
            !empty($u['is_active']) ? 1 : 0,
            json_encode(array_values($u['roles'] ?? [])),
            json_encode(array_values($u['subjects'] ?? [])),
            json_encode(array_values($u['classes'] ?? [])),
        ];
        if (Db::get('SELECT id FROM users WHERE id = ?', [$id])) {
            Db::run(
                'UPDATE users SET username = ?, password_hash = COALESCE(?, password_hash), nama = ?, kelas_wali_id = ?, foto_profil_url = ?, is_active = ?, roles = ?, subjects = ?, classes = ? WHERE id = ?',
                [...$vals, $id]
            );
        } else {
            Db::run(
                'INSERT INTO users (id, username, password_hash, nama, kelas_wali_id, foto_profil_url, is_active, created_at, roles, subjects, classes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$id, $vals[0], $vals[1], $vals[2], $vals[3], $vals[4], $vals[5], $u['created_at'] ?? Util::sqlNow(), $vals[6], $vals[7], $vals[8]]
            );
        }
    }

    // ---- Classes / subjects / students -------------------------------------------
    public static function classesAll(): array
    {
        return array_map([self::class, 'classRow'], Db::all('SELECT * FROM classes ORDER BY id'));
    }

    public static function classById(int $id): ?array
    {
        $r = Db::get('SELECT * FROM classes WHERE id = ?', [$id]);
        return $r ? self::classRow($r) : null;
    }

    public static function classUpsert(array $c): void
    {
        $id = (int) ($c['id'] ?? 0);
        $v = [$c['name'] ?? '', $c['jurusan'] ?? null, $c['angkatan'] ?? null, $c['tahun_ajaran'] ?? null, $c['semester'] ?? null];
        if (Db::get('SELECT id FROM classes WHERE id = ?', [$id])) {
            Db::run('UPDATE classes SET name = ?, jurusan = ?, angkatan = ?, tahun_ajaran = ?, semester = ? WHERE id = ?', [...$v, $id]);
        } else {
            Db::run('INSERT INTO classes (id, name, jurusan, angkatan, tahun_ajaran, semester) VALUES (?, ?, ?, ?, ?, ?)', [$id, ...$v]);
        }
    }

    public static function subjectsAll(): array
    {
        return array_map(fn($r) => ['id' => (int) $r['id'], 'name' => $r['name']], Db::all('SELECT * FROM subjects ORDER BY id'));
    }

    public static function subjectUpsert(array $s): void
    {
        $id = (int) ($s['id'] ?? 0);
        if (Db::get('SELECT id FROM subjects WHERE id = ?', [$id])) {
            Db::run('UPDATE subjects SET name = ? WHERE id = ?', [$s['name'] ?? '', $id]);
        } else {
            Db::run('INSERT INTO subjects (id, name) VALUES (?, ?)', [$id, $s['name'] ?? '']);
        }
    }

    public static function studentsAll(?int $classId = null): array
    {
        $rows = $classId
            ? Db::all('SELECT * FROM students WHERE class_id = ? ORDER BY id', [$classId])
            : Db::all('SELECT * FROM students ORDER BY id');
        return array_map([self::class, 'student'], $rows);
    }

    public static function studentById(int $id): ?array
    {
        $r = Db::get('SELECT * FROM students WHERE id = ?', [$id]);
        return $r ? self::student($r) : null;
    }

    public static function studentUpsert(array $s): void
    {
        $id = (int) ($s['id'] ?? 0);
        $v = [(string) ($s['nis'] ?? ''), (string) ($s['nama'] ?? ''), (string) ($s['jk'] ?? ''), (int) ($s['class_id'] ?? 0), (string) ($s['status'] ?? 'aktif')];
        if (Db::get('SELECT id FROM students WHERE id = ?', [$id])) {
            Db::run('UPDATE students SET nis = ?, nama = ?, jk = ?, class_id = ?, status = ? WHERE id = ?', [...$v, $id]);
        } else {
            Db::run('INSERT INTO students (id, nis, nama, jk, class_id, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)', [$id, ...$v, $s['created_at'] ?? Util::sqlNow()]);
        }
    }

    // ---- Pairings ------------------------------------------------------------------
    public static function pairingsAll(): array
    {
        return array_map(
            fn($r) => ['user_id' => (int) $r['user_id'], 'subject_id' => (int) $r['subject_id'], 'class_id' => (int) $r['class_id']],
            Db::all('SELECT * FROM teacher_subject_class_pairing ORDER BY user_id, subject_id, class_id')
        );
    }

    public static function isPaired(int $userId, int $subjectId, int $classId): bool
    {
        return (bool) Db::get(
            'SELECT 1 AS ok FROM teacher_subject_class_pairing WHERE user_id = ? AND subject_id = ? AND class_id = ?',
            [$userId, $subjectId, $classId]
        );
    }

    /** @return array{applied:bool, reason?:string} */
    public static function pairingsReplaceAll(array $pairings): array
    {
        $current = (int) (Db::get('SELECT COUNT(*) AS c FROM teacher_subject_class_pairing')['c'] ?? 0);
        if (count($pairings) === 0 && $current > 0) {
            return [
                'applied' => false,
                'reason' => "Ditolak: payload pairing kosong tapi server sudah punya $current data. Kemungkinan device belum sinkron penuh — sync ditolak untuk mencegah penghapusan massal tidak sengaja.",
            ];
        }
        Db::run('DELETE FROM teacher_subject_class_pairing');
        $sql = (Db::driver() === 'mysql' ? 'INSERT IGNORE' : 'INSERT OR IGNORE')
            . ' INTO teacher_subject_class_pairing (user_id, subject_id, class_id) VALUES (?, ?, ?)';
        foreach ($pairings as $p) {
            Db::run($sql, [(int) ($p['user_id'] ?? 0), (int) ($p['subject_id'] ?? 0), (int) ($p['class_id'] ?? 0)]);
        }
        return ['applied' => true];
    }

    // ---- Settings ------------------------------------------------------------------
    public static function settingsGet(): array
    {
        $r = Db::get('SELECT * FROM school_settings WHERE id = 1');
        if ($r) {
            return self::settingsRow($r);
        }
        return [
            'school_name' => (string) Config::get('school_name'), 'logo_url' => '', 'tahun_ajaran' => '', 'semester' => 'Ganjil',
            'kepsek_nama' => '', 'bk_nama' => '', 'backup_retention_weeks' => 8,
        ];
    }

    public static function settingsUpdate(array $s): void
    {
        $p = [
            $s['school_name'] ?? null, $s['logo_url'] ?? null, $s['tahun_ajaran'] ?? null, $s['semester'] ?? null,
            $s['kepsek_nama'] ?? null, $s['bk_nama'] ?? null,
            isset($s['backup_retention_weeks']) ? (int) $s['backup_retention_weeks'] : null,
            $s['last_backup_date'] ?? null, $s['last_backup_status'] ?? null,
        ];
        if (Db::get('SELECT id FROM school_settings WHERE id = 1')) {
            Db::run('UPDATE school_settings SET school_name = ?, logo_url = ?, tahun_ajaran = ?, semester = ?, kepsek_nama = ?, bk_nama = ?, backup_retention_weeks = ?, last_backup_date = ?, last_backup_status = ? WHERE id = 1', $p);
        } else {
            Db::run('INSERT INTO school_settings (id, school_name, logo_url, tahun_ajaran, semester, kepsek_nama, bk_nama, backup_retention_weeks, last_backup_date, last_backup_status) VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?)', $p);
        }
    }

    // ---- Attendance ----------------------------------------------------------------
    /** $subjectId: false = tidak difilter, null = harian (IS NULL), int = mapel tertentu. */
    public static function attendanceQuery(?int $classId = null, int|null|false $subjectId = false, ?string $tanggal = null, ?int $studentId = null): array
    {
        $sql = 'SELECT * FROM attendance WHERE 1=1';
        $p = [];
        if ($classId !== null) { $sql .= ' AND class_id = ?'; $p[] = $classId; }
        if ($subjectId === null) { $sql .= ' AND subject_id IS NULL'; }
        elseif ($subjectId !== false) { $sql .= ' AND subject_id = ?'; $p[] = $subjectId; }
        if ($tanggal !== null) { $sql .= ' AND tanggal = ?'; $p[] = $tanggal; }
        if ($studentId !== null) { $sql .= ' AND student_id = ?'; $p[] = $studentId; }
        return array_map([self::class, 'attendance'], Db::all($sql . ' ORDER BY id', $p));
    }

    public static function attendanceForStudent(int $studentId): array
    {
        return array_map([self::class, 'attendance'], Db::all('SELECT * FROM attendance WHERE student_id = ? ORDER BY id', [$studentId]));
    }

    /** @return array{created:bool} */
    public static function attendanceUpsert(int $studentId, int $classId, ?int $subjectId, string $tanggal, string $status, ?string $notes, int $recordedBy, string $recordedVia): array
    {
        return Db::tx(function () use ($studentId, $classId, $subjectId, $tanggal, $status, $notes, $recordedBy, $recordedVia) {
            $existing = $subjectId === null
                ? Db::get('SELECT id, notes FROM attendance WHERE student_id = ? AND subject_id IS NULL AND tanggal = ?', [$studentId, $tanggal])
                : Db::get('SELECT id, notes FROM attendance WHERE student_id = ? AND subject_id = ? AND tanggal = ?', [$studentId, $subjectId, $tanggal]);
            $now = Util::sqlNow();
            if ($existing) {
                Db::run(
                    'UPDATE attendance SET status = ?, notes = ?, recorded_by = ?, recorded_via = ?, updated_at = ? WHERE id = ?',
                    [$status, $notes ?? $existing['notes'], $recordedBy, $recordedVia, $now, (int) $existing['id']]
                );
                return ['created' => false];
            }
            [$nextId] = self::allocateSequence('attendance', 1);
            $millis = (Util::parseSessionDate($tanggal) ?? time()) * 1000;
            Db::run(
                'INSERT INTO attendance (id, student_id, class_id, subject_id, tanggal, status, recorded_by, recorded_via, notes, created_at, updated_at, created_at_millis) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [$nextId, $studentId, $classId, $subjectId, $tanggal, $status, $recordedBy, $recordedVia, $notes, $now, $now, $millis]
            );
            return ['created' => true];
        });
    }

    public static function attendanceDeleteSession(int $classId, ?int $subjectId, string $tanggal): int
    {
        return $subjectId === null
            ? Db::run('DELETE FROM attendance WHERE class_id = ? AND tanggal = ? AND subject_id IS NULL', [$classId, $tanggal])
            : Db::run('DELETE FROM attendance WHERE class_id = ? AND tanggal = ? AND subject_id = ?', [$classId, $tanggal, $subjectId]);
    }

    // ---- Grades --------------------------------------------------------------------
    public static function gradeActivityInsert(array $a): void
    {
        Db::run(
            'INSERT INTO grade_activities (id, teacher_id, subject_id, class_id, nama_kegiatan, tanggal_kegiatan, tipe_skala, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
            [$a['id'], $a['teacher_id'], $a['subject_id'], $a['class_id'], $a['nama_kegiatan'], $a['tanggal_kegiatan'], $a['tipe_skala'], $a['created_at']]
        );
    }

    public static function gradeActivitiesAll(): array
    {
        return array_map(fn($r) => [
            'id' => $r['id'], 'teacher_id' => (int) $r['teacher_id'], 'subject_id' => (int) $r['subject_id'],
            'class_id' => (int) $r['class_id'], 'nama_kegiatan' => $r['nama_kegiatan'],
            'tanggal_kegiatan' => $r['tanggal_kegiatan'], 'tipe_skala' => $r['tipe_skala'], 'created_at' => $r['created_at'],
        ], Db::all('SELECT * FROM grade_activities ORDER BY created_at, id'));
    }

    public static function gradeValuesInsertMany(string $activityId, array $values): void
    {
        foreach ($values as $v) {
            Db::run('INSERT INTO grade_values (activity_id, student_id, nilai) VALUES (?, ?, ?)', [$activityId, $v['student_id'], $v['nilai']]);
        }
    }

    public static function gradeValuesAll(): array
    {
        return array_map(
            fn($r) => ['activity_id' => $r['activity_id'], 'student_id' => (int) $r['student_id'], 'nilai' => $r['nilai']],
            Db::all('SELECT * FROM grade_values ORDER BY activity_id, student_id')
        );
    }

    // ---- Token delegasi ketua kelas ------------------------------------------------
    public static function tokensAll(): array
    {
        return array_map([self::class, 'token'], Db::all('SELECT * FROM ketua_kelas_tokens ORDER BY created_at'));
    }

    public static function tokenByToken(string $token): ?array
    {
        $r = Db::get('SELECT * FROM ketua_kelas_tokens WHERE token = ?', [$token]);
        return $r ? self::token($r) : null;
    }

    public static function tokenUpsert(array $t): void
    {
        $v = [$t['class_id'], $t['status'], $t['created_at'], $t['created_by'] ?? null, $t['expires_at'] ?? null, $t['expires_at_millis'] ?? null];
        if (Db::get('SELECT token FROM ketua_kelas_tokens WHERE token = ?', [$t['token']])) {
            Db::run('UPDATE ketua_kelas_tokens SET class_id = ?, status = ?, created_at = ?, created_by = ?, expires_at = ?, expires_at_millis = ? WHERE token = ?', [...$v, $t['token']]);
        } else {
            Db::run('INSERT INTO ketua_kelas_tokens (token, class_id, status, created_at, created_by, expires_at, expires_at_millis) VALUES (?, ?, ?, ?, ?, ?, ?)', [$t['token'], ...$v]);
        }
    }

    // ---- Token akses wali murid ----------------------------------------------------
    public static function parentTokenByToken(string $token): ?array
    {
        $r = Db::get('SELECT * FROM parent_access_tokens WHERE token = ?', [$token]);
        return $r ? self::parentToken($r) : null;
    }

    public static function parentTokensByStudent(int $studentId): array
    {
        return array_map([self::class, 'parentToken'], Db::all('SELECT * FROM parent_access_tokens WHERE student_id = ? ORDER BY created_at DESC', [$studentId]));
    }

    public static function parentTokenCreate(int $studentId, int $createdBy): array
    {
        $token = 'wm_' . Util::base64url(random_bytes(24));
        $now = Util::iso();
        Db::run("INSERT INTO parent_access_tokens (token, student_id, status, created_at, created_by) VALUES (?, ?, 'aktif', ?, ?)", [$token, $studentId, $now, $createdBy]);
        return ['token' => $token, 'student_id' => $studentId, 'status' => 'aktif', 'created_at' => $now, 'created_by' => $createdBy];
    }

    public static function parentTokenRevoke(string $token): bool
    {
        return Db::run("UPDATE parent_access_tokens SET status = 'nonaktif', revoked_at = ? WHERE token = ? AND status = 'aktif'", [Util::iso(), $token]) > 0;
    }

    // ---- Sesi login ----------------------------------------------------------------
    /** @return array{token:string, expiresAtMillis:int} */
    public static function sessionCreate(int $userId, int $ttlMillis = 12 * 3600 * 1000): array
    {
        $token = bin2hex(random_bytes(32));
        $expires = Util::nowMillis() + $ttlMillis;
        Db::run('INSERT INTO sessions (token, user_id, created_at, expires_at_millis) VALUES (?, ?, ?, ?)', [$token, $userId, Util::iso(), $expires]);
        Db::run('DELETE FROM sessions WHERE expires_at_millis < ?', [Util::nowMillis()]);
        return ['token' => $token, 'expiresAtMillis' => $expires];
    }

    public static function sessionFindValid(string $token): ?int
    {
        if ($token === '') {
            return null;
        }
        $r = Db::get('SELECT user_id, expires_at_millis FROM sessions WHERE token = ?', [$token]);
        if (!$r) {
            return null;
        }
        if (Util::nowMillis() > (int) $r['expires_at_millis']) {
            Db::run('DELETE FROM sessions WHERE token = ?', [$token]);
            return null;
        }
        return (int) $r['user_id'];
    }

    public static function sessionDestroy(string $token): void
    {
        Db::run('DELETE FROM sessions WHERE token = ?', [$token]);
    }

    public static function sessionDestroyAllForUser(int $userId, ?string $exceptToken = null): int
    {
        return $exceptToken
            ? Db::run('DELETE FROM sessions WHERE user_id = ? AND token != ?', [$userId, $exceptToken])
            : Db::run('DELETE FROM sessions WHERE user_id = ?', [$userId]);
    }

    // ---- Rate limit login (persisten di DB karena PHP tidak punya memori bersama) --
    private const LOGIN_WINDOW_S = 15 * 60;
    private const LOGIN_MAX_FAILURES = 10;

    /** Sisa detik penguncian, atau 0 kalau boleh mencoba login. */
    public static function loginLockedFor(string $key): int
    {
        $r = Db::get('SELECT cnt, first_at FROM login_attempts WHERE k = ?', [$key]);
        if (!$r) {
            return 0;
        }
        $elapsed = time() - (int) $r['first_at'];
        if ($elapsed > self::LOGIN_WINDOW_S) {
            Db::run('DELETE FROM login_attempts WHERE k = ?', [$key]);
            return 0;
        }
        return (int) $r['cnt'] >= self::LOGIN_MAX_FAILURES ? self::LOGIN_WINDOW_S - $elapsed : 0;
    }

    public static function loginRecordFailure(string $key): void
    {
        Db::tx(function () use ($key) {
            $r = Db::get('SELECT cnt, first_at FROM login_attempts WHERE k = ?', [$key]);
            if (!$r || time() - (int) $r['first_at'] > self::LOGIN_WINDOW_S) {
                Db::run('DELETE FROM login_attempts WHERE k = ?', [$key]);
                Db::run('INSERT INTO login_attempts (k, cnt, first_at) VALUES (?, 1, ?)', [$key, time()]);
            } else {
                Db::run('UPDATE login_attempts SET cnt = cnt + 1 WHERE k = ?', [$key]);
            }
            Db::run('DELETE FROM login_attempts WHERE first_at < ?', [time() - self::LOGIN_WINDOW_S]);
        });
    }

    public static function loginClear(string $key): void
    {
        Db::run('DELETE FROM login_attempts WHERE k = ?', [$key]);
    }
}
