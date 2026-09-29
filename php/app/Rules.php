<?php
declare(strict_types=1);

// ============================================================================
// Rules: aturan bisnis sisi server (padanan fungsi-fungsi di atas server.ts).
// ============================================================================

final class Rules
{
    /** Aturan 85%: statistik kehadiran satu siswa + defisit sesi untuk mencapai ambang. */
    public static function attendanceStats(int $studentId): array
    {
        $records = Repo::attendanceForStudent($studentId);
        $count = fn(string $s) => count(array_filter($records, fn($a) => $a['status'] === $s));
        $total = count($records);
        $hadir = $count('H');
        $rate = $total > 0 ? ($hadir / $total) * 100 : 100;
        $rateRounded = round($rate * 10) / 10;
        $meets = $rateRounded >= 85.0;
        return [
            'studentId' => $studentId,
            'total' => $total,
            'hadir' => $hadir,
            'izin' => $count('I'),
            'sakit' => $count('S'),
            'alpa' => $count('A'),
            'rate' => $rateRounded,
            'threshold' => 85.0,
            'meets85Percent' => $meets,
            'deficitSessions' => $meets ? 0 : max(1, (int) ceil((0.85 * $total - $hadir) / 0.15)),
        ];
    }

    /** Kategori "Perlu Perhatian" untuk satu siswa (ambang sama dengan client). */
    public static function attentionCategory(int $studentId): array
    {
        $records = Repo::attendanceForStudent($studentId);
        $count = fn(string $s) => count(array_filter($records, fn($a) => $a['status'] === $s));
        $alpa = $count('A');
        $izin = $count('I');
        $sakit = $count('S');
        $totalAbsen = $alpa + $izin + $sakit;
        $category = null;
        if ($alpa >= 2) $category = 'alpa_tinggi';
        elseif ($sakit >= 2) $category = 'sakit_tinggi';
        elseif ($izin >= 2) $category = 'izin_tinggi';
        elseif ($totalAbsen >= 3) $category = 'jarang_masuk_gabungan';
        return ['category' => $category, 'alpa' => $alpa, 'izin' => $izin, 'sakit' => $sakit, 'totalAbsen' => $totalAbsen];
    }

    /** Pola absen berkala: hari yang sama, interval 10–18 hari. */
    public static function periodicPattern(int $studentId): array
    {
        $dayNames = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
        $byWeekday = [];
        foreach (Repo::attendanceForStudent($studentId) as $r) {
            if ($r['status'] === 'H') {
                continue;
            }
            $ts = Util::parseSessionDate($r['tanggal']);
            if ($ts === null) {
                continue;
            }
            $byWeekday[(int) gmdate('w', $ts)][] = $r['tanggal'];
        }

        $alerts = [];
        foreach ($byWeekday as $dayIdx => $dates) {
            sort($dates);
            if (count($dates) < 2) {
                continue;
            }
            $matched = [];
            for ($i = 0; $i < count($dates) - 1; $i++) {
                $diff = (int) round((Util::parseSessionDate($dates[$i + 1]) - Util::parseSessionDate($dates[$i])) / 86400);
                if ($diff >= 10 && $diff <= 18) {
                    if (!in_array($dates[$i], $matched, true)) $matched[] = $dates[$i];
                    if (!in_array($dates[$i + 1], $matched, true)) $matched[] = $dates[$i + 1];
                }
            }
            if ($matched) {
                $alerts[] = [
                    'day_of_week' => $dayNames[$dayIdx],
                    'day_index' => $dayIdx,
                    'count' => count($matched),
                    'dates' => $matched,
                    'status_type' => count($matched) >= 3 ? 'Pola' : 'Peringatan',
                ];
            }
        }
        return $alerts;
    }

    /** Otorisasi guru: wali kelas (absen harian) atau pairing mapel-kelas. @return array{allowed:bool, error?:string} */
    public static function teacherAuthorization(int $userId, ?int $subjectId, int $classId, array $roles): array
    {
        if (Util::hasAdminRole($roles)) {
            return ['allowed' => true];
        }
        $user = Repo::userById($userId);
        if (!$user || !$user['is_active']) {
            return ['allowed' => false, 'error' => 'Pengguna tidak ditemukan atau akun dalam status nonaktif.'];
        }
        if ($subjectId === null) {
            if (($user['kelas_wali_id'] ?? null) !== $classId) {
                return ['allowed' => false, 'error' => "Otorisasi Ditolak Server: Anda ({$user['nama']}) bukan Wali Kelas dari kelas ID #$classId."];
            }
            return ['allowed' => true];
        }
        if (!Repo::isPaired($userId, $subjectId, $classId)) {
            $hasClass = in_array($classId, array_map('intval', $user['classes'] ?? []), true);
            $hasSubject = in_array($subjectId, array_map('intval', $user['subjects'] ?? []), true);
            if (!$hasClass || !$hasSubject) {
                return ['allowed' => false, 'error' => "Otorisasi Ditolak Server: Anda ({$user['nama']}) tidak memiliki penugasan untuk Mapel ID #$subjectId di Kelas ID #$classId."];
            }
        }
        return ['allowed' => true];
    }

    /** Integritas entri absensi. Mengembalikan null bila valid, atau [status, pesan]. */
    public static function validateAttendanceEntries(array $entries, int $classId): ?array
    {
        foreach ($entries as $item) {
            $sid = is_array($item) ? ($item['student_id'] ?? null) : null;
            $st = is_array($item) ? ($item['status'] ?? null) : null;
            if (!$sid || !in_array($st, ['H', 'I', 'S', 'A'], true)) {
                $sidTxt = is_scalar($sid) ? (string) $sid : 'undefined';
                $stTxt = is_scalar($st) ? (string) $st : 'undefined';
                return [400, "Entri absensi tidak valid: ID siswa #$sidTxt dengan status '$stTxt'. Status harus salah satu dari: H, I, S, A."];
            }
            $student = Repo::studentById((int) $sid);
            if (!$student) {
                return [404, "Siswa ID #$sid tidak terdaftar di database sekolah."];
            }
            if ($student['class_id'] !== $classId) {
                return [400, "Integritas Data Gagal: Siswa {$student['nama']} bukan anggota kelas ID #$classId."];
            }
        }
        return null;
    }
}
