<?php

namespace App\Services;

use App\Support\Dates;

/**
 * Mesin analitik dashboard (ambang batas, non-generatif). Bekerja pada array baris
 * absensi: ['student_id','class_id','subject_id','tanggal','status'].
 * $subject: false = semua, null = hanya absen harian, int = mapel tertentu.
 */
class Analytics
{
    public const CATEGORIES = [
        'alpa_tinggi' => 'Alpa tinggi', 'sakit_tinggi' => 'Sakit tinggi',
        'izin_tinggi' => 'Izin tinggi', 'jarang_masuk_gabungan' => 'Jarang masuk (gabungan)',
    ];

    public static function filter(array $records, ?int $classId, int|null|false $subject): array
    {
        return array_values(array_filter($records, function ($r) use ($classId, $subject) {
            if ($classId && $r['class_id'] !== $classId) {
                return false;
            }
            if ($subject === null && $r['subject_id'] !== null) {
                return false;
            }

            return ! (is_int($subject) && $r['subject_id'] !== $subject);
        }));
    }

    public static function counts(array $records): array
    {
        $c = ['H' => 0, 'I' => 0, 'S' => 0, 'A' => 0];
        foreach ($records as $r) {
            if (isset($c[$r['status']])) {
                $c[$r['status']]++;
            }
        }
        $total = array_sum($c);

        return ['total' => $total, 'hadir' => $c['H'], 'izin' => $c['I'], 'sakit' => $c['S'], 'alpa' => $c['A'], 'rate' => $total > 0 ? $c['H'] / $total * 100 : 100.0];
    }

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
            $out[] = ['date' => $d, 'total' => $v['total'], 'h' => $v['h'], 'pct' => (int) round($v['h'] / $v['total'] * 100)];
        }

        return $out;
    }

    /** Pola absen berkala: hari yang sama, jarak antarkejadian 10–18 hari. */
    public static function patterns(array $records, array $students, ?int $classId, int|null|false $subject): array
    {
        $byId = array_column($students, null, 'id');
        $group = [];
        foreach (self::filter($records, $classId, $subject) as $r) {
            if ($r['status'] === 'H' || ! Dates::valid($r['tanggal'])) {
                continue;
            }
            $group[$r['student_id'].'-'.Dates::weekday($r['tanggal'])][] = $r['tanggal'];
        }
        $alerts = [];
        foreach ($group as $key => $dates) {
            [$sid, $dow] = array_map('intval', explode('-', $key));
            $dates = array_values(array_unique($dates));
            sort($dates);
            if (count($dates) < 2 || ! isset($byId[$sid])) {
                continue;
            }
            $matched = [];
            for ($i = 0; $i < count($dates) - 1; $i++) {
                $diff = (int) round((Dates::parse($dates[$i + 1]) - Dates::parse($dates[$i])) / 86400);
                if ($diff >= 10 && $diff <= 18) {
                    $matched[$dates[$i]] = true;
                    $matched[$dates[$i + 1]] = true;
                }
            }
            if ($matched) {
                $alerts[] = [
                    'student_id' => $sid, 'student_name' => $byId[$sid]['nama'], 'nis' => $byId[$sid]['nis'],
                    'day_of_week' => Dates::DAYS[$dow], 'count' => count($matched), 'dates' => array_keys($matched),
                    'status_type' => count($matched) >= 3 ? 'Pola' : 'Peringatan',
                ];
            }
        }

        return $alerts;
    }

    /** Siswa "Perlu Perhatian" (4 kategori) + sinyal nilai turun, urut severity menurun. */
    public static function attention(array $records, array $students, array $gradeValues, ?int $classId, int|null|false $subject): array
    {
        $cnt = [];
        foreach (self::filter($records, $classId, $subject) as $a) {
            $cnt[$a['student_id']] ??= ['A' => 0, 'I' => 0, 'S' => 0, 'H' => 0];
            $cnt[$a['student_id']][$a['status']] = ($cnt[$a['student_id']][$a['status']] ?? 0) + 1;
        }
        $drop = [];
        foreach ($gradeValues as $g) {
            $n = is_numeric($g['nilai']) ? (float) $g['nilai'] : null;
            if (($n !== null && $n < 70) || ($n === null && in_array($g['nilai'], ['D', 'E'], true))) {
                $drop[$g['student_id']] = true;
            }
        }
        $out = [];
        $policy = \App\Support\AttentionPolicy::current();
        foreach ($students as $s) {
            if ($classId && $s['class_id'] !== $classId) {
                continue;
            }
            $c = $cnt[$s['id']] ?? ['A' => 0, 'I' => 0, 'S' => 0];
            $total = $c['A'] + $c['I'] + $c['S'];
            $cat = \App\Support\AttentionPolicy::category($c['A'], $c['I'], $c['S'], $policy);
            $sev = match ($cat) {
                'alpa_tinggi' => $c['A'] * 3 + $total,
                'sakit_tinggi' => $c['S'] * 2 + $total,
                'izin_tinggi' => $c['I'] * 1.5 + $total,
                'jarang_masuk_gabungan' => $total * 2,
                default => 0,
            };
            if ($cat) {
                $out[] = [
                    'student_id' => $s['id'], 'student_name' => $s['nama'], 'nis' => $s['nis'],
                    'alpa' => $c['A'], 'izin' => $c['I'], 'sakit' => $c['S'], 'total' => $total,
                    'category' => $cat, 'severity' => $sev, 'grade_drop' => isset($drop[$s['id']]),
                ];
            }
        }
        usort($out, fn ($a, $b) => $b['severity'] <=> $a['severity']);

        return $out;
    }

    public static function narrative(float $rate, int $alpa, int $izin, int $sakit, int $attention, int $patterns, string $scope, int $dual = 0): array
    {
        $r = number_format($rate, 1, '.', '');
        $sum = match (true) {
            $rate >= 92 => "Tingkat kehadiran siswa pada $scope berada pada level sangat prima ($r%). Mayoritas peserta didik hadir konsisten mengikuti kegiatan pembelajaran.",
            $rate >= 80 => "Kehadiran siswa pada $scope berada dalam rentang wajar ($r%), namun terdapat akumulasi $alpa Alpa, $izin Izin, dan $sakit Sakit yang perlu dimonitor secara berkala.",
            default => "PERINGATAN: Tingkat kehadiran siswa pada $scope mengalami penurunan kritis ($r%), di bawah standar minimum sekolah (80%). Tercatat $alpa ketidakhadiran tanpa keterangan (Alpa).",
        };
        if ($patterns > 0) {
            $sum .= " Sistem juga mendeteksi adanya keteraturan pola absensi berkala pada $patterns siswa dengan interval ~14 hari pada hari yang sama.";
        }
        if ($dual > 0) {
            $sum .= " Terdapat sinyal prioritas: $dual siswa mengalami penurunan di kedua aspek secara serempak (kehadiran dan nilai akademik).";
        }
        $rec = match (true) {
            $alpa > 3 || $dual > 0 => "Rekomendasi Tindakan: Lakukan pemanggilan orang tua dan koordinasi dengan Guru BK untuk $attention siswa berstatus perhatian tinggi. Prioritaskan siswa dengan penurunan ganda (absen dan nilai) agar tidak tertinggal materi uji semester.",
            $patterns > 0 => 'Rekomendasi Tindakan: Verifikasi alasan ketidakhadiran berulang pada hari tertentu kepada siswa terkait atau wali kelas, guna memastikan tidak ada faktor kebiasaan membolos berulang.',
            $sakit >= 4 => 'Rekomendasi Tindakan: Koordinasikan dengan tim UKS sekolah untuk memantau kondisi kesehatan siswa yang sering izin sakit berturut-turut.',
            default => 'Rekomendasi Tindakan: Pertahankan ritme pembelajaran aktif dan berikan apresiasi kepada siswa dengan rekor kehadiran 100%.',
        };

        return ['summary' => $sum, 'recommendation' => $rec];
    }
}
