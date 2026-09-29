<?php
declare(strict_types=1);

// ============================================================================
// Analytics: mesin analitik dashboard (padanan storage.ts: detectPeriodicPatterns,
// getAttentionStudents, generateAutomatedNarrative). Murni berbasis ambang batas.
// $subjectScope: false = semua, null = hanya absen harian, int = mapel tertentu.
// ============================================================================

final class Analytics
{
    public const DAY_NAMES = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];

    public static function filter(array $records, ?int $classId, int|null|false $subjectScope): array
    {
        return array_values(array_filter($records, function ($r) use ($classId, $subjectScope) {
            if ($classId && $r['class_id'] !== $classId) return false;
            if ($subjectScope === null && $r['subject_id'] !== null) return false;
            if (is_int($subjectScope) && $r['subject_id'] !== $subjectScope) return false;
            return true;
        }));
    }

    public static function counts(array $records): array
    {
        $c = ['H' => 0, 'I' => 0, 'S' => 0, 'A' => 0];
        foreach ($records as $r) {
            if (isset($c[$r['status']])) $c[$r['status']]++;
        }
        $total = array_sum($c);
        return [
            'total' => $total, 'hadir' => $c['H'], 'izin' => $c['I'], 'sakit' => $c['S'], 'alpa' => $c['A'],
            'rate' => $total > 0 ? ($c['H'] / $total) * 100 : 100.0,
        ];
    }

    /** Tren per tanggal: [{date, pct, total, h}] terurut naik. */
    public static function trend(array $records): array
    {
        $by = [];
        foreach ($records as $r) {
            $by[$r['tanggal']]['total'] = ($by[$r['tanggal']]['total'] ?? 0) + 1;
            $by[$r['tanggal']]['h'] = ($by[$r['tanggal']]['h'] ?? 0) + ($r['status'] === 'H' ? 1 : 0);
        }
        ksort($by);
        $out = [];
        foreach ($by as $d => $v) {
            $out[] = ['date' => $d, 'total' => $v['total'], 'h' => $v['h'], 'pct' => $v['total'] > 0 ? (int) round($v['h'] / $v['total'] * 100) : 0];
        }
        return $out;
    }

    /** Pola absen berkala: hari yang sama, jarak 10–18 hari. */
    public static function patterns(array $attendance, array $students, ?int $classId, int|null|false $subjectScope): array
    {
        $byId = array_column($students, null, 'id');
        $group = [];
        foreach (self::filter($attendance, $classId, $subjectScope) as $r) {
            if ($r['status'] === 'H') continue;
            $ts = Util::parseSessionDate($r['tanggal']);
            if ($ts === null) continue;
            $group[$r['student_id'] . '-' . (int) gmdate('w', $ts)][] = $r['tanggal'];
        }
        $alerts = [];
        foreach ($group as $key => $dates) {
            [$sid, $dow] = array_map('intval', explode('-', $key));
            sort($dates);
            if (count($dates) < 2 || !isset($byId[$sid])) continue;
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
                    'student_id' => $sid, 'student_name' => $byId[$sid]['nama'], 'nis' => $byId[$sid]['nis'],
                    'day_of_week' => self::DAY_NAMES[$dow], 'day_index' => $dow, 'count' => count($matched),
                    'dates' => $matched, 'status_type' => count($matched) >= 3 ? 'Pola' : 'Peringatan',
                ];
            }
        }
        return $alerts;
    }

    /** Siswa "Perlu Perhatian", terurut severity menurun. */
    public static function attention(array $attendance, array $students, array $gradeValues, ?int $classId, int|null|false $subjectScope): array
    {
        $cnt = [];
        foreach (self::filter($attendance, $classId, $subjectScope) as $a) {
            $cnt[$a['student_id']] ??= ['A' => 0, 'I' => 0, 'S' => 0, 'H' => 0];
            if (isset($cnt[$a['student_id']][$a['status']])) $cnt[$a['student_id']][$a['status']]++;
        }
        $drop = [];
        foreach ($gradeValues as $g) {
            $n = is_numeric($g['nilai']) ? (float) $g['nilai'] : null;
            if (($n !== null && $n < 70) || ($n === null && in_array($g['nilai'], ['D', 'E'], true))) {
                $drop[$g['student_id']] = true;
            }
        }
        $out = [];
        foreach ($students as $s) {
            if ($classId && $s['class_id'] !== $classId) continue;
            $c = $cnt[$s['id']] ?? ['A' => 0, 'I' => 0, 'S' => 0, 'H' => 0];
            $total = $c['A'] + $c['I'] + $c['S'];
            $cat = null;
            $sev = 0;
            if ($c['A'] >= 2) { $cat = 'alpa_tinggi'; $sev = $c['A'] * 3 + $total; }
            elseif ($c['S'] >= 2) { $cat = 'sakit_tinggi'; $sev = $c['S'] * 2 + $total; }
            elseif ($c['I'] >= 2) { $cat = 'izin_tinggi'; $sev = $c['I'] * 1.5 + $total; }
            elseif ($total >= 3) { $cat = 'jarang_masuk_gabungan'; $sev = $total * 2; }
            if ($cat) {
                $out[] = [
                    'student_id' => $s['id'], 'student_name' => $s['nama'], 'nis' => $s['nis'],
                    'alpa_count' => $c['A'], 'izin_count' => $c['I'], 'sakit_count' => $c['S'], 'total_absen' => $total,
                    'category' => $cat, 'severity' => $sev, 'hasGradeDrop' => isset($drop[$s['id']]),
                ];
            }
        }
        usort($out, fn($a, $b) => $b['severity'] <=> $a['severity']);
        return $out;
    }

    /** Ringkasan & rekomendasi otomatis (aturan ambang batas, bukan generatif). */
    public static function narrative(float $rate, int $alpa, int $izin, int $sakit, int $attentionCount, int $patternCount, string $scope, int $dualDrop = 0): array
    {
        $r = number_format($rate, 1, '.', '');
        if ($rate >= 92) {
            $sum = "Tingkat kehadiran siswa pada $scope berada pada level sangat prima ($r%). Mayoritas peserta didik hadir konsisten mengikuti kegiatan pembelajaran.";
        } elseif ($rate >= 80) {
            $sum = "Kehadiran siswa pada $scope berada dalam rentang wajar ($r%), namun terdapat akumulasi $alpa Alpa, $izin Izin, dan $sakit Sakit yang perlu dimonitor secara berkala.";
        } else {
            $sum = "PERINGATAN: Tingkat kehadiran siswa pada $scope mengalami penurunan kritis ($r%), di bawah standar minimum sekolah (80%). Tercatat $alpa ketidakhadiran tanpa keterangan (Alpa).";
        }
        if ($patternCount > 0) {
            $sum .= " Sistem juga mendeteksi adanya keteraturan pola absensi berkala pada $patternCount siswa dengan interval ~14 hari pada hari yang sama.";
        }
        if ($dualDrop > 0) {
            $sum .= " Terdapat sinyal prioritas: $dualDrop siswa mengalami penurunan di kedua aspek secara serempak (kehadiran dan nilai akademik).";
        }
        if ($alpa > 3 || $dualDrop > 0) {
            $rec = "Rekomendasi Tindakan: Lakukan pemanggilan orang tua dan koordinasi dengan Guru BK untuk $attentionCount siswa berstatus perhatian tinggi. Prioritaskan siswa dengan penurunan ganda (absen dan nilai) agar tidak tertinggal materi uji semester.";
        } elseif ($patternCount > 0) {
            $rec = 'Rekomendasi Tindakan: Verifikasi alasan ketidakhadiran berulang pada hari tertentu kepada siswa terkait atau wali kelas, guna memastikan tidak ada faktor kebiasaan membolos berulang.';
        } elseif ($sakit >= 4) {
            $rec = 'Rekomendasi Tindakan: Koordinasikan dengan tim UKS sekolah untuk memantau kondisi kesehatan siswa yang sering izin sakit berturut-turut.';
        } else {
            $rec = 'Rekomendasi Tindakan: Pertahankan ritme pembelajaran aktif dan berikan apresiasi kepada siswa dengan rekor kehadiran 100%.';
        }
        return ['summary' => $sum, 'recommendation' => $rec];
    }

    public static function categoryLabel(string $cat): string
    {
        return ['alpa_tinggi' => 'Alpa tinggi', 'sakit_tinggi' => 'Sakit tinggi', 'izin_tinggi' => 'Izin tinggi', 'jarang_masuk_gabungan' => 'Jarang masuk (gabungan)'][$cat] ?? $cat;
    }

    /** Rata-rata nilai satu siswa untuk satu set nilai (angka -> rata2; huruf -> modus tidak dipakai). */
    public static function gradeNumber(string $nilai): ?float
    {
        return is_numeric($nilai) ? (float) $nilai : null;
    }
}
