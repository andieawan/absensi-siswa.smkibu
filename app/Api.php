<?php
declare(strict_types=1);

// ============================================================================
// Api: router + seluruh endpoint /api/* (padanan server.ts versi Node.js).
// Kontrak request/response sengaja identik, sehingga frontend React tidak
// perlu diubah sama sekali.
// ============================================================================

final class Api
{
    /** Endpoint yang boleh diakses TANPA token sesi (divalidasi token khusus di handler-nya). */
    private const PUBLIC_PATHS = [
        '/health', '/auth/login', '/auth/logout',
        '/delegation/verify', '/delegation/session', '/delegation/submit',
        '/parent-access/summary',
    ];

    private const ALLOWED_SEQUENCE_ENTITIES = ['users', 'students', 'attendance', 'audit_logs', 'classes', 'subjects'];

    /** @var array{id:int, nama:string, roles:string[]}|null */
    private static ?array $actor = null;

    public static function dispatch(): never
    {
        $path = Req::$path;
        $method = Req::$method;

        if (!in_array($path, self::PUBLIC_PATHS, true)) {
            self::$actor = self::requireAuth();
        }

        // Rute statis
        $routes = [
            'GET /health' => 'health',
            'POST /sequence/allocate' => 'sequenceAllocate',
            'GET /sequence/status' => 'sequenceStatus',
            'GET /business-rules/summary' => 'businessRules',
            'POST /attendance/delete' => 'attendanceDelete',
            'POST /attendance/validate-delete' => 'attendanceValidateDelete',
            'POST /attendance/submit' => 'attendanceSubmit',
            'POST /students/evaluate-eligibility' => 'evaluateEligibility',
            'POST /students/academic-clearance' => 'academicClearance',
            'POST /grades/submit' => 'gradesSubmit',
            'POST /delegation/create' => 'Access::delegationCreate',
            'GET /delegation/verify' => 'Access::delegationVerify',
            'GET /delegation/session' => 'Access::delegationSession',
            'POST /delegation/submit' => 'Access::delegationSubmit',
            'POST /parent-access/create' => 'Access::parentAccessCreate',
            'GET /parent-access/list' => 'Access::parentAccessList',
            'POST /parent-access/revoke' => 'Access::parentAccessRevoke',
            'GET /parent-access/summary' => 'Access::parentAccessSummary',
            'GET /attendance' => 'attendanceList',
            'GET /students' => 'Access::studentsList',
            'GET /classes' => 'Access::classesList',
            'GET /subjects' => 'Access::subjectsList',
            'GET /users' => 'Access::usersList',
            'GET /pairings' => 'Access::pairingsList',
            'GET /settings' => 'Access::settingsGet',
            'POST /settings' => 'Access::settingsSave',
            'POST /admin/backup-now' => 'Access::backupNow',
            'POST /sync/push' => 'Access::syncPush',
            'GET /sync/pull' => 'Access::syncPull',
            'GET /grades' => 'gradesList',
            'POST /auth/login' => 'Access::authLogin',
            'POST /auth/logout' => 'Access::authLogout',
            'POST /auth/change-password' => 'Access::changePassword',
            'GET /schema' => 'schema',
        ];

        if (isset($routes["$method $path"])) {
            $target = $routes["$method $path"];
            str_starts_with($target, 'Access::') ? Access::{substr($target, 8)}() : self::{$target}();
        }
        if ($method === 'GET' && preg_match('#^/students/([^/]+)/attendance-eligibility$#', $path, $m)) {
            self::attendanceEligibility((int) $m[1]);
        }
        Res::error('Endpoint tidak ditemukan.', 404);
    }

    // ------------------------------------------------------------------------
    // Auth helpers
    // ------------------------------------------------------------------------
    private static function requireAuth(): array
    {
        $token = Req::bearer();
        $userId = $token ? Repo::sessionFindValid($token) : null;
        if ($userId === null) {
            Res::error('Sesi tidak sah atau sudah kedaluwarsa. Silakan login ulang.', 401);
        }
        $user = Repo::userById($userId);
        if (!$user || !$user['is_active']) {
            Res::error('Akun tidak ditemukan atau nonaktif.', 401);
        }
        return ['id' => $user['id'], 'nama' => $user['nama'], 'roles' => $user['roles']];
    }

    public static function actor(): array
    {
        return self::$actor;
    }

    public static function requireAdmin(string $msg = 'Otorisasi Ditolak: aksi ini khusus Administrator.'): void
    {
        if (!Util::hasAdminRole(self::actor()['roles'])) {
            Res::error($msg, 403);
        }
    }

    public static function requireTeacher(?int $subjectId, int $classId): void
    {
        $a = self::actor();
        $auth = Rules::teacherAuthorization($a['id'], $subjectId, $classId, $a['roles']);
        if (!$auth['allowed']) {
            Res::error($auth['error'], 403);
        }
    }

    public static function stripHash(array $u): array
    {
        unset($u['password_hash']);
        return $u;
    }

    public static function subjectParam(mixed $v): ?int
    {
        return $v === null || $v === '' ? null : (int) $v;
    }

    // ------------------------------------------------------------------------
    // Info
    // ------------------------------------------------------------------------
    private static function health(): never
    {
        Res::json([
            'status' => 'ok',
            'service' => 'go_absen_siswa_api',
            'engine' => 'PHP ' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION . ' + ' . (Db::driver() === 'mysql' ? 'MySQL/MariaDB' : 'SQLite') . ' + Server Business Rules',
            'timestamp' => Util::iso(),
            'records' => Repo::counts(),
        ]);
    }

    private static function sequenceAllocate(): never
    {
        $entity = Req::body('entity');
        if (!$entity || !is_string($entity) || !in_array($entity, self::ALLOWED_SEQUENCE_ENTITIES, true)) {
            Res::error('Parameter entity tidak valid.', 400);
        }
        $safeCount = min(max(1, (int) Req::body('count', 1)), 1000);
        $ids = Repo::allocateSequence($entity, $safeCount);
        Res::json(['success' => true, 'entity' => $entity, 'count' => count($ids), 'allocatedIds' => $ids, 'nextId' => $ids[0]]);
    }

    private static function sequenceStatus(): never
    {
        Res::json(['success' => true, 'sequences' => (object) Repo::sequencesStatus(), 'timestamp' => Util::iso()]);
    }

    private static function businessRules(): never
    {
        Res::json([
            'service' => 'Server-Side Business Rules Enforcement Engine',
            'rules' => [
                ['id' => 'RULE-01', 'name' => 'Batas Hapus & Ubah Absensi 7 Hari',
                    'description' => 'Data absensi yang berusia lebih dari 7 hari dikunci secara permanen. Hanya Administrator yang dapat memodifikasi data melebihi 7 hari.',
                    'enforcement_points' => ['POST /api/attendance/delete', 'POST /api/attendance/submit']],
                ['id' => 'RULE-02', 'name' => 'Syarat Kehadiran Minimal 85%',
                    'description' => 'Siswa wajib memiliki tingkat kehadiran ≥ 85% untuk berhak mengikuti ujian akhir, kenaikan kelas, dan pengesahan kelulusan.',
                    'enforcement_points' => ['GET /api/students/:id/attendance-eligibility', 'POST /api/students/evaluate-eligibility', 'POST /api/students/academic-clearance', 'POST /api/attendance/submit (Auto-alert)']],
                ['id' => 'RULE-03', 'name' => 'Otorisasi Guru Mengajar (isTeacherAllowed)',
                    'description' => 'Guru hanya berhak menginput/mengubah absensi jika merupakan Wali Kelas (untuk absen harian) atau terdaftar di pairing mapel-kelas (untuk absen mapel).',
                    'enforcement_points' => ['POST /api/attendance/submit', 'POST /api/attendance/delete', 'POST /api/grades/submit']],
                ['id' => 'RULE-04', 'name' => 'Integritas Input & Skala Penilaian',
                    'description' => 'Status absensi hanya boleh H, I, S, A. Skala angka harus berada di antara 0-100, skala huruf harus A-E.',
                    'enforcement_points' => ['POST /api/attendance/submit', 'POST /api/grades/submit']],
                ['id' => 'RULE-05', 'name' => 'Token Delegasi Presensi Ketua Kelas (24 Jam)',
                    'description' => 'Token delegasi wajib berstatus aktif dan belum kedaluwarsa saat submit absensi berlangsung.',
                    'enforcement_points' => ['POST /api/delegation/submit', 'GET /api/delegation/verify']],
            ],
        ]);
    }

    private static function schema(): never
    {
        Res::json([
            'message' => 'Skema Relasional Terverifikasi dengan Server Business Rules',
            'engine' => Db::driver() === 'mysql' ? 'MySQL/MariaDB (PDO)' : 'SQLite (PDO)',
            'tables' => ['users', 'classes', 'subjects', 'students', 'attendance', 'grade_activities', 'grade_values',
                'teacher_subject_class_pairing', 'ketua_kelas_tokens', 'audit_log', 'sequences', 'school_settings',
                'sessions', 'parent_access_tokens', 'login_attempts'],
            'constraints' => [
                'attendance_unique' => '(student_id, subject_id, tanggal)',
                'grade_values_primary' => '(activity_id, student_id)',
                'pairing_primary' => '(user_id, subject_id, class_id)',
                'business_rules' => [
                    'retention_7_days' => 'Enforced via /api/attendance/delete',
                    'minimum_attendance_85_percent' => 'Enforced via /api/students/academic-clearance & /api/students/evaluate-eligibility',
                    'teacher_pairing_authorization' => 'Enforced via /api/attendance/submit & /api/grades/submit',
                ],
            ],
        ]);
    }

    // ------------------------------------------------------------------------
    // Absensi
    // ------------------------------------------------------------------------
    private static function attendanceDelete(): never
    {
        $a = self::actor();
        $classId = Req::body('class_id');
        $tanggal = Req::body('tanggal');
        if (!$classId || !$tanggal) {
            Res::error('Parameter class_id dan tanggal wajib disertakan.', 400);
        }
        $ts = Util::parseSessionDate($tanggal);
        if ($ts === null) {
            Res::error('Format tanggal harus YYYY-MM-DD yang valid.', 400);
        }
        $diffDays = Util::daysSince($ts);
        if ($diffDays > 7 && !Util::hasAdminRole($a['roles'])) {
            Res::error(
                "Otorisasi Ditolak Server: Data absensi tanggal $tanggal sudah berusia $diffDays hari (> 7 hari). Berdasarkan kebijakan integritas data sekolah, entri yang melebihi batas 7 hari dikunci secara permanen dan tidak dapat dihapus oleh non-admin.",
                403, ['diffDays' => $diffDays]
            );
        }
        $subjectId = self::subjectParam(Req::body('subject_id'));
        self::requireTeacher($subjectId, (int) $classId);

        $deleted = Repo::attendanceDeleteSession((int) $classId, $subjectId, $tanggal);
        $subjTxt = Req::body('subject_id') ?? 'Harian';
        Repo::audit('Hapus Sesi Absensi', 'Absensi', $a['nama'], "Kelas #$classId, Mapel #$subjTxt, Tanggal: $tanggal, Terhapus: $deleted baris");
        Res::json([
            'success' => true, 'deleted' => $deleted, 'diffDays' => $diffDays,
            'message' => "Sesi absensi tanggal $tanggal berhasil dihapus di sisi server ($deleted data terhapus).",
        ]);
    }

    private static function attendanceValidateDelete(): never
    {
        $tanggal = Req::body('tanggal');
        $ts = Util::parseSessionDate($tanggal);
        if ($ts === null) {
            Res::json(['allowed' => false, 'error' => 'Tanggal sesi absensi (YYYY-MM-DD) wajib disertakan.'], 400);
        }
        $diffDays = Util::daysSince($ts);
        if ($diffDays > 7 && !Util::hasAdminRole(self::actor()['roles'])) {
            Res::json([
                'allowed' => false, 'diffDays' => $diffDays,
                'error' => "Otorisasi Ditolak Server: Data absensi tanggal $tanggal sudah berusia $diffDays hari (> 7 hari). Sesuai kebijakan integritas data sekolah, entri yang melebihi batas 7 hari dikunci dan tidak boleh dihapus.",
            ], 403);
        }
        Res::json(['allowed' => true, 'diffDays' => $diffDays, 'message' => 'Validasi penghapusan sesi absensi berhasil (dalam rentang aman 7 hari).']);
    }

    private static function attendanceSubmit(): never
    {
        $a = self::actor();
        $classId = Req::body('class_id');
        $tanggal = Req::body('tanggal');
        $entries = Req::body('entries');
        $via = (string) (Req::body('recorded_via') ?: 'guru');

        if (!$classId || !$tanggal || !is_array($entries) || !array_is_list($entries)) {
            Res::error('Parameter class_id, tanggal, dan entries[] wajib diisi.', 400);
        }
        $ts = Util::parseSessionDate($tanggal);
        if ($ts === null) {
            Res::error('Format tanggal harus YYYY-MM-DD yang valid.', 400);
        }
        if ($ts - time() > 86400) {
            Res::error('Validasi Server Gagal: Tanggal sesi absensi tidak boleh berada di masa depan.', 400);
        }

        $roles = $a['roles'];
        $isAdmin = Util::hasAdminRole($roles);
        $subjectId = self::subjectParam(Req::body('subject_id'));

        // Jalur pencatatan hanya dari daftar resmi, masing-masing dengan syarat peran sendiri.
        // Delegasi ketua kelas WAJIB lewat /api/delegation/submit.
        if ($via === 'guru' || $via === 'wali') {
            self::requireTeacher($subjectId, (int) $classId);
        } elseif ($via === 'bk_manual') {
            if (!$isAdmin && !in_array('bk', $roles, true)) {
                Res::error('Otorisasi Ditolak Server: input manual BK khusus Guru BK / Administrator.', 403);
            }
        } elseif ($via === 'upload_hardcopy') {
            if (!$isAdmin) {
                Res::error('Otorisasi Ditolak Server: unggah hardcopy khusus Administrator.', 403);
            }
        } else {
            Res::error("Jalur pencatatan '$via' tidak dikenal.", 400);
        }

        $pastDiff = Util::daysSince($ts);
        if ($pastDiff > 7 && !$isAdmin && $via !== 'bk_manual') {
            Res::error("Otorisasi Ditolak Server: Sesi tanggal $tanggal ($pastDiff hari lalu) melebihi batas toleransi penginputan 7 hari. Data historis > 7 hari hanya dapat dimasukkan oleh Administrator.", 403);
        }

        $err = Rules::validateAttendanceEntries($entries, (int) $classId);
        if ($err) {
            Res::error($err[1], $err[0]);
        }

        // Idempotent UPSERT dalam satu transaksi (semua entri masuk, atau tidak sama sekali)
        $created = 0;
        $updated = 0;
        Db::tx(function () use ($entries, $classId, $subjectId, $tanggal, $a, $via, &$created, &$updated) {
            foreach ($entries as $item) {
                $notes = isset($item['notes']) ? (string) $item['notes'] : null;
                $r = Repo::attendanceUpsert((int) $item['student_id'], (int) $classId, $subjectId, $tanggal, $item['status'], $notes, $a['id'], $via);
                $r['created'] ? $created++ : $updated++;
            }
        });

        // ATURAN 85%: peringatan otomatis untuk siswa yang kehadirannya di bawah ambang
        $alerts = [];
        foreach ($entries as $item) {
            $stats = Rules::attendanceStats((int) $item['student_id']);
            if (!$stats['meets85Percent']) {
                $st = Repo::studentById((int) $item['student_id']);
                $alerts[] = [
                    'student_id' => $item['student_id'],
                    'nama' => $st['nama'] ?? null,
                    'nis' => $st['nis'] ?? null,
                    'rate' => $stats['rate'],
                    'deficit' => $stats['deficitSessions'],
                    'warning' => "Kehadiran siswa {$st['nama']} berada pada {$stats['rate']}% (di bawah batas kelulusan 85%). Defisit {$stats['deficitSessions']} kehadiran.",
                ];
            }
        }

        $total = count($entries);
        Repo::audit('Submit Absensi', 'Absensi', $a['nama'], "Kelas #$classId, Tanggal: $tanggal, Total: $total ($created baru, $updated update)");
        Res::json([
            'success' => true, 'count' => $total, 'created' => $created, 'updated' => $updated,
            'attendanceAlerts' => $alerts,
            'message' => "Presensi berhasil diverifikasi & disimpan oleh server ($created baru, $updated update).",
        ]);
    }

    private static function attendanceList(): never
    {
        $classId = Req::query('class_id');
        $subject = Req::query('subject_id');
        $tanggal = Req::query('tanggal');
        $studentId = Req::query('student_id');
        $rows = Repo::attendanceQuery(
            $classId !== null && $classId !== '' ? (int) $classId : null,
            $subject === null ? false : ($subject === 'null' ? null : (int) $subject),
            $tanggal !== null && $tanggal !== '' ? $tanggal : null,
            $studentId !== null && $studentId !== '' ? (int) $studentId : null
        );
        Res::json(['success' => true, 'count' => count($rows), 'data' => $rows]);
    }

    // ------------------------------------------------------------------------
    // Aturan 85% & pengesahan akademik
    // ------------------------------------------------------------------------
    private static function attendanceEligibility(int $studentId): never
    {
        $student = Repo::studentById($studentId);
        if (!$student) {
            Res::error('Siswa tidak ditemukan.', 404);
        }
        $s = Rules::attendanceStats($studentId);
        $class = Repo::classById($student['class_id']);
        Res::json([
            'success' => true,
            'student' => ['id' => $student['id'], 'nis' => $student['nis'], 'nama' => $student['nama'], 'kelas' => $class['name'] ?? null],
            'evaluation' => [
                'total_sessions' => $s['total'], 'hadir' => $s['hadir'], 'izin' => $s['izin'], 'sakit' => $s['sakit'], 'alpa' => $s['alpa'],
                'rate' => $s['rate'], 'minimum_threshold' => $s['threshold'], 'meets_minimum_85' => $s['meets85Percent'],
                'status' => $s['meets85Percent'] ? 'MEMENUHI_SYARAT_85' : 'TIDAK_MEMENUHI_SYARAT_MINIMAL_85',
                'badge' => $s['meets85Percent'] ? 'KOMPETEN_MEMENUHI_SYARAT' : 'PERINGATAN_DEFISIT_BK',
                'deficit_sessions' => $s['deficitSessions'],
                'sanctions' => $s['meets85Percent'] ? [] : [
                    'Penangguhan pengesahan nilai akhir semester / cetak rapor resmi',
                    'Wajib mengikuti program kompensasi kehadiran akademik BK',
                    'Penerbitan Surat Peringatan / Panggilan Orang Tua ke sekolah',
                ],
                'academic_clearance_allowed' => $s['meets85Percent'],
            ],
        ]);
    }

    private static function evaluateEligibility(): never
    {
        $classId = Req::body('class_id');
        $students = Repo::studentsAll($classId ? (int) $classId : null);
        $evals = array_map(function ($st) {
            $s = Rules::attendanceStats($st['id']);
            return [
                'student_id' => $st['id'], 'nis' => $st['nis'], 'nama' => $st['nama'], 'class_id' => $st['class_id'],
                'rate' => $s['rate'], 'total' => $s['total'], 'hadir' => $s['hadir'], 'alpa' => $s['alpa'],
                'meets_85' => $s['meets85Percent'], 'deficit' => $s['deficitSessions'],
            ];
        }, $students);
        $atRisk = count(array_filter($evals, fn($e) => !$e['meets_85']));
        Res::json([
            'success' => true, 'total_students' => count($evals), 'compliant_count' => count($evals) - $atRisk,
            'at_risk_count' => $atRisk, 'minimum_threshold' => 85.0, 'evaluations' => $evals,
        ]);
    }

    private static function academicClearance(): never
    {
        $a = self::actor();
        $override = (bool) Req::body('admin_override');
        $reason = Req::body('override_reason');

        if ($override && !Util::hasAdminRole($a['roles']) && !in_array('kepsek', $a['roles'], true)) {
            Res::error('Otorisasi Ditolak Server: dispensasi pengesahan akademik khusus Administrator / Kepala Sekolah.', 403, ['clearance_granted' => false]);
        }
        if ($override && trim((string) $reason) === '') {
            Res::error('Alasan dispensasi (override_reason) wajib diisi.', 400, ['clearance_granted' => false]);
        }
        $student = Repo::studentById((int) Req::body('student_id'));
        if (!$student) {
            Res::error('Siswa tidak ditemukan.', 404);
        }
        $s = Rules::attendanceStats($student['id']);

        if (!$s['meets85Percent'] && !$override) {
            Res::json([
                'success' => false, 'clearance_granted' => false,
                'error' => "Pengesahan Akademik Ditolak Server: Siswa NIS {$student['nis']} ({$student['nama']}) hanya memiliki persentase kehadiran {$s['rate']}% (di bawah standar kelulusan minimal 85.0%). Siswa wajib menuntaskan program pembinaan BK atau memperoleh dispensasi khusus Kepala Sekolah.",
                'details' => [
                    'current_rate' => $s['rate'], 'required_threshold' => 85.0, 'deficit_sessions' => $s['deficitSessions'],
                    'required_actions' => [
                        'Hubungi Guru Bimbingan Konseling (BK)',
                        'Selesaikan penugasan kompensasi jam kehadiran',
                        'Surat dispensasi tertulis dari Kepala Sekolah (jika ada alasan medis/kedaruratan khusus)',
                    ],
                ],
            ], 422);
        }

        $code = sprintf('CLR-%s-%s-%s', gmdate('Y'), $student['nis'], strtoupper(substr(bin2hex(random_bytes(4)), 0, 5)));
        Repo::audit('Pengesahan Akademik', 'Akademik', $a['nama'], "Siswa NIS {$student['nis']} ({$student['nama']}) disahkan dengan kehadiran {$s['rate']}% (Override: " . ($override ? 'true' : 'false') . ')');
        Res::json([
            'success' => true, 'clearance_granted' => true, 'clearance_code' => $code,
            'student_id' => $student['id'], 'nama' => $student['nama'], 'attendance_rate' => $s['rate'],
            'is_override' => $override, 'override_reason' => $reason ?: null, 'certified_at' => Util::iso(),
            'message' => $override
                ? "Pengesahan akademik disetujui melalui dispensasi khusus administrator (Alasan: $reason)."
                : 'Pengesahan akademik disetujui server: Siswa memenuhi standar kehadiran sekolah (≥ 85%).',
        ]);
    }

    // ------------------------------------------------------------------------
    // Nilai
    // ------------------------------------------------------------------------
    private static function gradesSubmit(): never
    {
        $a = self::actor();
        $subjectId = Req::body('subject_id');
        $classId = Req::body('class_id');
        $nama = Req::body('nama_kegiatan');
        $tipe = Req::body('tipe_skala');
        $scores = Req::body('scores');

        if (!$subjectId || !$classId || !$nama || !is_array($scores)) {
            Res::error('Data penilaian tidak lengkap.', 400);
        }
        self::requireTeacher((int) $subjectId, (int) $classId);
        if ($tipe !== 'angka' && $tipe !== 'huruf') {
            Res::error("Tipe skala harus 'angka' atau 'huruf'.", 400);
        }

        $valid = [];
        foreach ($scores as $sc) {
            $sid = is_array($sc) ? ($sc['student_id'] ?? null) : null;
            $val = trim(is_array($sc) && isset($sc['nilai']) && is_scalar($sc['nilai']) ? (string) $sc['nilai'] : '');
            if ($tipe === 'angka') {
                if ($val === '' || !is_numeric($val) || (float) $val < 0 || (float) $val > 100) {
                    Res::error("Nilai angka untuk siswa #$sid tidak valid ($val). Nilai harus berada dalam rentang 0 sampai 100.", 400);
                }
            } elseif (!in_array(strtoupper($val), ['A', 'B', 'C', 'D', 'E'], true)) {
                Res::error("Nilai huruf untuk siswa #$sid tidak valid ($val). Nilai harus salah satu dari A, B, C, D, E.", 400);
            }
            $valid[] = ['student_id' => (int) $sid, 'nilai' => $val];
        }

        $activityId = 'act-' . Util::uuid4();
        $now = Util::sqlNow();
        Db::tx(function () use ($activityId, $a, $subjectId, $classId, $nama, $tipe, $now, $valid) {
            Repo::gradeActivityInsert([
                'id' => $activityId, 'teacher_id' => $a['id'], 'subject_id' => (int) $subjectId, 'class_id' => (int) $classId,
                'nama_kegiatan' => (string) $nama, 'tanggal_kegiatan' => Req::body('tanggal_kegiatan') ?: substr($now, 0, 10),
                'tipe_skala' => $tipe, 'created_at' => $now,
            ]);
            Repo::gradeValuesInsertMany($activityId, $valid);
        });
        Res::json([
            'success' => true, 'activity_id' => $activityId, 'records_saved' => count($valid),
            'message' => 'Penilaian siswa berhasil divalidasi dan disimpan di server.',
        ]);
    }

    private static function gradesList(): never
    {
        $classId = Req::query('class_id');
        $subjectId = Req::query('subject_id');
        $acts = Repo::gradeActivitiesAll();
        if ($classId) $acts = array_values(array_filter($acts, fn($x) => $x['class_id'] === (int) $classId));
        if ($subjectId) $acts = array_values(array_filter($acts, fn($x) => $x['subject_id'] === (int) $subjectId));
        $ids = array_flip(array_column($acts, 'id'));
        $vals = array_values(array_filter(Repo::gradeValuesAll(), fn($v) => isset($ids[$v['activity_id']])));
        Res::json(['success' => true, 'activities' => $acts, 'values' => $vals]);
    }
}
