<?php
declare(strict_types=1);

// ============================================================================
// Svc: aksi bisnis yang dipakai halaman web. Aturan sama dengan Api.php
// (7 hari, 85%, otorisasi guru, token delegasi), tetapi melempar UserError
// alih-alih langsung merespons JSON.
// ============================================================================

final class UserError extends RuntimeException
{
}

final class Svc
{
    /** @return array{created:int, updated:int, alerts:array} */
    public static function submitAttendance(array $actor, int $classId, ?int $subjectId, string $tanggal, array $entries, string $via = 'guru'): array
    {
        $ts = Util::parseSessionDate($tanggal);
        if ($ts === null) throw new UserError('Format tanggal harus YYYY-MM-DD yang valid.');
        if ($ts - time() > 86400) throw new UserError('Tanggal sesi absensi tidak boleh berada di masa depan.');
        if (!$entries) throw new UserError('Tidak ada siswa untuk disimpan.');

        $roles = $actor['roles'];
        $isAdmin = Util::hasAdminRole($roles);
        if ($via === 'guru' || $via === 'wali') {
            self::teacher($actor, $subjectId, $classId);
        } elseif ($via === 'bk_manual') {
            if (!$isAdmin && !in_array('bk', $roles, true)) throw new UserError('Input manual BK khusus Guru BK / Administrator.');
        } elseif ($via === 'upload_hardcopy') {
            if (!$isAdmin) throw new UserError('Unggah hardcopy khusus Administrator.');
        } else {
            throw new UserError("Jalur pencatatan '$via' tidak dikenal.");
        }
        $diff = Util::daysSince($ts);
        if ($diff > 7 && !$isAdmin && $via !== 'bk_manual') {
            throw new UserError("Sesi tanggal $tanggal ($diff hari lalu) melebihi batas toleransi input 7 hari. Data historis > 7 hari hanya dapat dimasukkan Administrator.");
        }
        $err = Rules::validateAttendanceEntries($entries, $classId);
        if ($err) throw new UserError($err[1]);

        $created = $updated = 0;
        Db::tx(function () use ($entries, $classId, $subjectId, $tanggal, $actor, $via, &$created, &$updated) {
            foreach ($entries as $e) {
                $notes = isset($e['notes']) && trim((string) $e['notes']) !== '' ? trim((string) $e['notes']) : null;
                $r = Repo::attendanceUpsert((int) $e['student_id'], $classId, $subjectId, $tanggal, $e['status'], $notes, $actor['id'], $via);
                $r['created'] ? $created++ : $updated++;
            }
        });

        $alerts = [];
        foreach ($entries as $e) {
            $st = Rules::attendanceStats((int) $e['student_id']);
            if (!$st['meets85Percent']) {
                $s = Repo::studentById((int) $e['student_id']);
                $alerts[] = "{$s['nama']}: kehadiran {$st['rate']}% (di bawah 85%, defisit {$st['deficitSessions']} kehadiran)";
            }
        }
        Repo::audit('Submit Absensi', 'Absensi', $actor['nama'], "Kelas #$classId, Mapel #" . ($subjectId ?? 'Harian') . ", Tanggal: $tanggal, Total: " . count($entries) . " ($created baru, $updated update), via $via");
        return ['created' => $created, 'updated' => $updated, 'alerts' => $alerts];
    }

    public static function deleteSession(array $actor, int $classId, ?int $subjectId, string $tanggal): int
    {
        $ts = Util::parseSessionDate($tanggal);
        if ($ts === null) throw new UserError('Format tanggal tidak valid.');
        $diff = Util::daysSince($ts);
        if ($diff > 7 && !Util::hasAdminRole($actor['roles'])) {
            throw new UserError("Data absensi tanggal $tanggal berusia $diff hari (> 7 hari) dan dikunci permanen. Hanya Administrator yang dapat menghapusnya.");
        }
        self::teacher($actor, $subjectId, $classId);
        $n = Repo::attendanceDeleteSession($classId, $subjectId, $tanggal);
        Repo::audit('Hapus Sesi Absensi', 'Absensi', $actor['nama'], "Kelas #$classId, Mapel #" . ($subjectId ?? 'Harian') . ", Tanggal: $tanggal, Terhapus: $n baris");
        return $n;
    }

    public static function teacher(array $actor, ?int $subjectId, int $classId): void
    {
        $a = Rules::teacherAuthorization($actor['id'], $subjectId, $classId, $actor['roles']);
        if (!$a['allowed']) throw new UserError($a['error']);
    }

    // ---- Nilai ----------------------------------------------------------------------
    /** Simpan kegiatan baru ($activityId null) atau timpa yang lama. */
    public static function saveGrades(array $actor, ?string $activityId, int $classId, int $subjectId, string $nama, string $tanggal, string $tipe, array $scores): string
    {
        self::teacher($actor, $subjectId, $classId);
        $nama = trim($nama);
        if ($nama === '') throw new UserError('Nama kegiatan penilaian wajib diisi.');
        if ($tipe !== 'angka' && $tipe !== 'huruf') throw new UserError("Tipe skala harus 'angka' atau 'huruf'.");
        if (Util::parseSessionDate($tanggal) === null) throw new UserError('Tanggal kegiatan tidak valid.');

        $valid = [];
        foreach ($scores as $sid => $val) {
            $val = trim((string) $val);
            $student = Repo::studentById((int) $sid);
            if (!$student || $student['class_id'] !== $classId) throw new UserError("Siswa #$sid bukan anggota kelas ini.");
            if ($val === '') $val = $tipe === 'angka' ? '0' : 'C';
            if ($tipe === 'angka') {
                if (!is_numeric($val) || (float) $val < 0 || (float) $val > 100) throw new UserError("Nilai {$student['nama']} ($val) tidak valid. Angka harus 0–100.");
            } else {
                $val = strtoupper($val);
                if (!in_array($val, ['A', 'B', 'C', 'D', 'E'], true)) throw new UserError("Nilai {$student['nama']} ($val) tidak valid. Huruf harus A–E.");
            }
            $valid[] = ['student_id' => (int) $sid, 'nilai' => $val];
        }
        if (!$valid) throw new UserError('Tidak ada nilai untuk disimpan.');

        Db::tx(function () use (&$activityId, $actor, $classId, $subjectId, $nama, $tanggal, $tipe, $valid) {
            $existing = $activityId ? Repo::gradeActivityById($activityId) : null;
            if ($existing) {
                if ($existing['class_id'] !== $classId || $existing['subject_id'] !== $subjectId) throw new UserError('Kegiatan tidak cocok dengan kelas/mapel terpilih.');
                Repo::gradeActivityUpdate($activityId, $nama, $tanggal, $tipe);
                Repo::gradeValuesReplace($activityId, $valid);
            } else {
                $activityId = 'act-' . Util::uuid4();
                Repo::gradeActivityInsert([
                    'id' => $activityId, 'teacher_id' => $actor['id'], 'subject_id' => $subjectId, 'class_id' => $classId,
                    'nama_kegiatan' => $nama, 'tanggal_kegiatan' => $tanggal, 'tipe_skala' => $tipe, 'created_at' => Util::sqlNow(),
                ]);
                Repo::gradeValuesInsertMany($activityId, $valid);
            }
        });
        Repo::audit('Simpan Kegiatan Nilai', 'Nilai', $actor['nama'], "$nama — tipe $tipe, kelas #$classId, mapel #$subjectId, " . count($valid) . ' siswa');
        return $activityId;
    }

    public static function deleteGradeActivity(array $actor, string $id): void
    {
        $act = Repo::gradeActivityById($id);
        if (!$act) throw new UserError('Kegiatan nilai tidak ditemukan.');
        self::teacher($actor, $act['subject_id'], $act['class_id']);
        $ts = Util::parseSessionDate($act['tanggal_kegiatan']);
        if ($ts !== null && Util::daysSince($ts) > 7 && !Util::hasAdminRole($actor['roles'])) {
            throw new UserError("Kegiatan penilaian tanggal {$act['tanggal_kegiatan']} melebihi batas penghapusan 7 hari.");
        }
        Repo::gradeActivityDelete($id);
        Repo::audit('Hapus Kegiatan Nilai', 'Nilai', $actor['nama'], $act['nama_kegiatan']);
    }

    // ---- Delegasi ketua kelas & akses wali murid -------------------------------------
    public static function createDelegation(array $actor, int $classId, int $hours = 24): array
    {
        if (!Repo::classById($classId)) throw new UserError('Kelas tidak valid.');
        self::teacher($actor, null, $classId);
        $hours = min(max(1, $hours), 24);
        $now = Util::nowMillis();
        $exp = $now + $hours * 3600000;
        $t = [
            'token' => 'kk_' . Util::base64url(random_bytes(24)), 'class_id' => $classId, 'status' => 'aktif',
            'created_at' => Util::iso($now), 'created_by' => $actor['id'], 'expires_at' => Util::iso($exp), 'expires_at_millis' => $exp,
        ];
        Repo::tokenUpsert($t);
        Repo::audit('Buat Delegasi', 'Absensi', $actor['nama'], "Kelas #$classId, berlaku $hours jam");
        return $t;
    }

    /** Token delegasi yang aktif & belum kedaluwarsa, atau UserError. */
    public static function activeDelegation(string $token): array
    {
        $t = $token !== '' ? Repo::tokenByToken($token) : null;
        if (!$t) throw new UserError('Tautan presensi tidak ditemukan di database sekolah.');
        if ($t['status'] !== 'aktif') throw new UserError('Tautan presensi ini sudah tidak aktif atau telah dicabut.');
        $exp = $t['expires_at_millis'] ?? (isset($t['expires_at']) ? strtotime($t['expires_at']) * 1000 : null);
        if ($exp && Util::nowMillis() > $exp) {
            throw new UserError('Tautan presensi telah kedaluwarsa (berakhir ' . Util::idLocale((int) $exp) . '). Minta Wali Kelas membuatkan tautan baru.');
        }
        return $t;
    }

    public static function submitDelegation(string $token, string $tanggal, array $entries): int
    {
        $t = self::activeDelegation($token);
        $ts = Util::parseSessionDate($tanggal);
        if ($ts === null) throw new UserError('Format tanggal tidak valid.');
        if ($ts - time() > 86400 || Util::daysSince($ts) > 7) throw new UserError('Tanggal presensi harus hari ini atau maksimal 7 hari ke belakang.');
        if (!$entries) throw new UserError('Tidak ada siswa untuk disimpan.');
        $err = Rules::validateAttendanceEntries($entries, $t['class_id']);
        if ($err) throw new UserError($err[1]);
        Db::tx(function () use ($entries, $t, $tanggal) {
            foreach ($entries as $e) {
                $notes = isset($e['notes']) && trim((string) $e['notes']) !== '' ? trim((string) $e['notes']) : null;
                Repo::attendanceUpsert((int) $e['student_id'], $t['class_id'], null, $tanggal, $e['status'], $notes, (int) ($t['created_by'] ?? 0), 'ketua_kelas_delegasi');
            }
        });
        Repo::audit('Submit Absensi Delegasi', 'Absensi', "Ketua Kelas (token kelas #{$t['class_id']})", "Tanggal: $tanggal, Total: " . count($entries) . ' siswa');
        return count($entries);
    }

    // ---- Pengesahan akademik (aturan 85%) ---------------------------------------------
    public static function clearance(array $actor, int $studentId, bool $override, string $reason): array
    {
        $roles = $actor['roles'];
        if ($override && !Util::hasAdminRole($roles) && !in_array('kepsek', $roles, true)) {
            throw new UserError('Dispensasi pengesahan akademik khusus Administrator / Kepala Sekolah.');
        }
        if ($override && trim($reason) === '') throw new UserError('Alasan dispensasi wajib diisi.');
        $s = Repo::studentById($studentId);
        if (!$s) throw new UserError('Siswa tidak ditemukan.');
        $st = Rules::attendanceStats($studentId);
        if (!$st['meets85Percent'] && !$override) {
            throw new UserError("Pengesahan ditolak: kehadiran {$s['nama']} hanya {$st['rate']}% (minimal 85%). Selesaikan pembinaan BK atau minta dispensasi Kepala Sekolah.");
        }
        $code = sprintf('CLR-%s-%s-%s', gmdate('Y'), $s['nis'], strtoupper(substr(bin2hex(random_bytes(4)), 0, 5)));
        Repo::audit('Pengesahan Akademik', 'Akademik', $actor['nama'], "Siswa NIS {$s['nis']} ({$s['nama']}) disahkan dengan kehadiran {$st['rate']}% (Override: " . ($override ? 'true' : 'false') . ')');
        return ['code' => $code, 'rate' => $st['rate'], 'override' => $override];
    }

    // ---- Administrasi ------------------------------------------------------------------
    public static function requireAdmin(array $actor): void
    {
        if (!Util::hasAdminRole($actor['roles'])) throw new UserError('Aksi ini khusus Administrator.');
    }

    public static function addTeacher(array $actor, array $in): int
    {
        self::requireAdmin($actor);
        $username = strtolower(trim((string) ($in['username'] ?? '')));
        $nama = trim((string) ($in['nama'] ?? ''));
        $pw = (string) ($in['password'] ?? '');
        if ($username === '' || $nama === '') throw new UserError('Nama dan username wajib diisi.');
        if (!preg_match('/^[a-z0-9._-]{3,}$/', $username)) throw new UserError('Username minimal 3 karakter (huruf kecil, angka, titik, strip).');
        if (mb_strlen($pw) < 8) throw new UserError('Password awal minimal 8 karakter.');
        if (Repo::userByUsername($username)) throw new UserError("Username '$username' sudah dipakai.");
        $roles = array_values(array_intersect((array) ($in['roles'] ?? ['guru']), ['guru', 'admin', 'superadmin', 'kepsek', 'bk']));
        if (!$roles) $roles = ['guru'];
        if (array_intersect($roles, ['admin', 'superadmin']) && !in_array('superadmin', $actor['roles'], true)) {
            throw new UserError('Hanya Superadmin yang dapat memberi peran Administrator.');
        }
        [$id] = Repo::allocateSequence('users', 1);
        $wali = isset($in['kelas_wali_id']) && $in['kelas_wali_id'] !== '' ? (int) $in['kelas_wali_id'] : null;
        Repo::userUpsert([
            'id' => $id, 'username' => $username, 'password_hash' => Util::hashPassword($pw), 'nama' => $nama,
            'kelas_wali_id' => $wali, 'foto_profil_url' => null, 'is_active' => true, 'roles' => $roles,
            'subjects' => array_map('intval', (array) ($in['subjects'] ?? [])), 'classes' => array_map('intval', (array) ($in['classes'] ?? [])),
        ]);
        Repo::audit('Tambah Akun Guru', 'Akun Guru', $actor['nama'], "$nama ($username), peran: " . implode(',', $roles));
        return $id;
    }

    public static function resetPassword(array $actor, int $userId, string $pw): void
    {
        self::requireAdmin($actor);
        if (mb_strlen($pw) < 8) throw new UserError('Password baru minimal 8 karakter.');
        $u = Repo::userById($userId);
        if (!$u) throw new UserError('Akun tidak ditemukan.');
        Repo::userUpsert(array_merge($u, ['password_hash' => Util::hashPassword($pw)]));
        Repo::sessionDestroyAllForUser($userId);
        Repo::audit('Reset Password', 'Akun Guru', $actor['nama'], "Akun {$u['username']}");
    }

    public static function toggleUser(array $actor, int $userId): bool
    {
        self::requireAdmin($actor);
        $u = Repo::userById($userId);
        if (!$u) throw new UserError('Akun tidak ditemukan.');
        if ($u['id'] === $actor['id']) throw new UserError('Anda tidak dapat menonaktifkan akun Anda sendiri.');
        $u['is_active'] = !$u['is_active'];
        Repo::userUpsert($u);
        if (!$u['is_active']) Repo::sessionDestroyAllForUser($userId);
        Repo::audit($u['is_active'] ? 'Aktifkan Akun' : 'Nonaktifkan Akun', 'Akun Guru', $actor['nama'], $u['username']);
        return $u['is_active'];
    }

    public static function saveStudent(array $actor, ?int $id, array $in): int
    {
        self::requireAdmin($actor);
        $nis = trim((string) ($in['nis'] ?? ''));
        $nama = trim((string) ($in['nama'] ?? ''));
        $jk = (string) ($in['jk'] ?? '');
        $classId = (int) ($in['class_id'] ?? 0);
        $status = (string) ($in['status'] ?? 'aktif');
        if ($nama === '' || !in_array($jk, ['L', 'P'], true)) throw new UserError('Nama dan jenis kelamin (L/P) wajib diisi.');
        if (!Repo::classById($classId)) throw new UserError('Kelas tidak valid.');
        if (!in_array($status, ['aktif', 'pindah', 'berhenti', 'nonaktif', 'keluar'], true)) throw new UserError('Status siswa tidak valid.');
        if ($id) {
            $old = Repo::studentById($id);
            if (!$old) throw new UserError('Siswa tidak ditemukan.');
            Repo::studentUpsert(['id' => $id, 'nis' => $old['nis'], 'nama' => $nama, 'jk' => $jk, 'class_id' => $classId, 'status' => $status, 'created_at' => $old['created_at']]);
            Repo::audit('Ubah Data Siswa', 'Siswa', $actor['nama'], "$nama (NIS {$old['nis']})");
            return $id;
        }
        if ($nis === '') throw new UserError('NIS wajib diisi.');
        if (Repo::studentByNis($nis)) throw new UserError("NIS $nis sudah terdaftar.");
        [$id] = Repo::allocateSequence('students', 1);
        Repo::studentUpsert(['id' => $id, 'nis' => $nis, 'nama' => $nama, 'jk' => $jk, 'class_id' => $classId, 'status' => $status]);
        Repo::audit('Tambah Siswa', 'Siswa', $actor['nama'], "$nama (NIS $nis)");
        return $id;
    }

    public static function saveClass(array $actor, ?int $id, array $in): int
    {
        self::requireAdmin($actor);
        $name = trim((string) ($in['name'] ?? ''));
        if ($name === '') throw new UserError('Nama kelas wajib diisi.');
        if (!$id) [$id] = Repo::allocateSequence('classes', 1);
        Repo::classUpsert([
            'id' => $id, 'name' => $name, 'jurusan' => trim((string) ($in['jurusan'] ?? '')), 'angkatan' => trim((string) ($in['angkatan'] ?? '')),
            'tahun_ajaran' => trim((string) ($in['tahun_ajaran'] ?? '')), 'semester' => trim((string) ($in['semester'] ?? '')),
        ]);
        Repo::audit('Simpan Kelas', 'Data Master', $actor['nama'], $name);
        return $id;
    }

    public static function saveSubject(array $actor, ?int $id, string $name): int
    {
        self::requireAdmin($actor);
        $name = trim($name);
        if ($name === '') throw new UserError('Nama mata pelajaran wajib diisi.');
        if (!$id) [$id] = Repo::allocateSequence('subjects', 1);
        Repo::subjectUpsert(['id' => $id, 'name' => $name]);
        Repo::audit('Simpan Mapel', 'Data Master', $actor['nama'], $name);
        return $id;
    }

    public static function saveSettings(array $actor, array $in): void
    {
        self::requireAdmin($actor);
        $cur = Repo::settingsGet();
        $ret = (int) ($in['backup_retention_weeks'] ?? $cur['backup_retention_weeks'] ?? 8);
        Repo::settingsUpdate(array_merge($cur, [
            'school_name' => trim((string) ($in['school_name'] ?? '')) ?: $cur['school_name'],
            'tahun_ajaran' => trim((string) ($in['tahun_ajaran'] ?? '')),
            'semester' => in_array($in['semester'] ?? '', ['Ganjil', 'Genap'], true) ? $in['semester'] : ($cur['semester'] ?? 'Ganjil'),
            'kepsek_nama' => trim((string) ($in['kepsek_nama'] ?? '')),
            'bk_nama' => trim((string) ($in['bk_nama'] ?? '')),
            'backup_retention_weeks' => min(max($ret, 1), 104),
        ]));
        Repo::audit('Ubah Pengaturan Sekolah', 'Sistem', $actor['nama'], 'Pengaturan sekolah diperbarui');
    }
}
