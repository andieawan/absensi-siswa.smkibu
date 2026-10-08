<?php

namespace App\Services;

use App\Exceptions\UserError;
use App\Models\Attendance;
use App\Models\Pairing;
use App\Models\Student;
use App\Models\User;
use App\Support\Dates;

/** Aturan bisnis: 85%, kategori perhatian, pola absen, otorisasi guru, validasi entri. */
class Rules
{
    public static function statsFromRecords(iterable $records): array
    {
        $c = ['H' => 0, 'I' => 0, 'S' => 0, 'A' => 0];
        foreach ($records as $r) {
            $s = is_array($r) ? $r['status'] : $r->status;
            if (isset($c[$s])) {
                $c[$s]++;
            }
        }
        $total = array_sum($c);
        $rate = round(($total > 0 ? $c['H'] / $total * 100 : 100) * 10) / 10;
        $min = (float) config('absensi.min_attendance', 85);
        $meets = $rate >= $min;

        return [
            'total' => $total, 'hadir' => $c['H'], 'izin' => $c['I'], 'sakit' => $c['S'], 'alpa' => $c['A'],
            'rate' => $rate, 'threshold' => $min, 'meets' => $meets,
            'deficit' => $meets ? 0 : max(1, (int) ceil(($min / 100 * $total - $c['H']) / (1 - $min / 100))),
        ];
    }

    public static function attendanceStats(int $studentId): array
    {
        return self::statsFromRecords(Attendance::where('student_id', $studentId)->get(['status']));
    }

    public static function attentionCategory(int $studentId): array
    {
        $s = self::attendanceStats($studentId);
        $total = $s['alpa'] + $s['izin'] + $s['sakit'];
        $cat = \App\Support\AttentionPolicy::category($s['alpa'], $s['izin'], $s['sakit']);

        return ['category' => $cat, 'alpa' => $s['alpa'], 'izin' => $s['izin'], 'sakit' => $s['sakit'], 'totalAbsen' => $total];
    }

    public static function periodicPattern(int $studentId): array
    {
        $student = Student::find($studentId);
        if (! $student) {
            return [];
        }
        $records = Attendance::where('student_id', $studentId)->get()->toArray();

        return Analytics::patterns($records, [$student->toArray()], null, false);
    }

    /** Wali kelas (absen harian) atau pasangan mapel-kelas / kelas+mapel yang diampu. */
    public static function teacherAuthorization(User $user, ?int $subjectId, int $classId): array
    {
        if ($user->isAdmin()) {
            return ['allowed' => true];
        }
        if (! $user->is_active) {
            return ['allowed' => false, 'error' => 'Akun dalam status nonaktif.'];
        }
        if ($subjectId === null) {
            return $user->kelas_wali_id === $classId
                ? ['allowed' => true]
                : ['allowed' => false, 'error' => "Otorisasi ditolak: Anda ({$user->nama}) bukan Wali Kelas dari kelas ini."];
        }
        $paired = Pairing::where(['user_id' => $user->id, 'subject_id' => $subjectId, 'class_id' => $classId])->exists();
        if (! $paired) {
            $hasClass = in_array($classId, array_map('intval', $user->classes ?? []), true);
            $hasSubject = in_array($subjectId, array_map('intval', $user->subjects ?? []), true);
            if (! $hasClass || ! $hasSubject) {
                return ['allowed' => false, 'error' => "Otorisasi ditolak: Anda ({$user->nama}) tidak memiliki penugasan untuk mapel ini di kelas ini."];
            }
        }

        return ['allowed' => true];
    }

    public static function requireTeacher(User $user, ?int $subjectId, int $classId): void
    {
        $a = self::teacherAuthorization($user, $subjectId, $classId);
        if (! $a['allowed']) {
            throw new UserError($a['error']);
        }
    }

    /** @param array<int, array{student_id:int, status:string, notes?:string}> $entries */
    public static function validateEntries(array $entries, int $classId): void
    {
        if (! $entries) {
            throw new UserError('Tidak ada siswa untuk disimpan.');
        }
        $students = Student::whereIn('id', array_column($entries, 'student_id'))->get()->keyBy('id');
        foreach ($entries as $e) {
            if (! in_array($e['status'] ?? null, ['H', 'I', 'S', 'A'], true)) {
                throw new UserError("Status '".($e['status'] ?? '')."' tidak valid. Status harus H, I, S, atau A.");
            }
            if (mb_strlen((string) ($e['notes'] ?? '')) > 200) {
                throw new UserError('Catatan absensi maksimal 200 karakter.');
            }
            $s = $students[$e['student_id']] ?? null;
            if (! $s) {
                throw new UserError("Siswa ID #{$e['student_id']} tidak terdaftar.");
            }
            if ($s->class_id !== $classId) {
                throw new UserError("Integritas data gagal: {$s->nama} bukan anggota kelas ini.");
            }
        }
    }

    /** Kegiatan nilai boleh diubah/dihapus non-admin hanya ≤ 7 hari sejak diinput. */
    public static function gradeEditable(User $user, \App\Models\GradeActivity $act): bool
    {
        if ($user->isAdmin()) {
            return true;
        }
        $created = strtotime(((string) $act->created_at).' UTC');

        return $created !== false && (time() - $created) <= (int) config('absensi.edit_window_days', 7) * 86400;
    }

    public static function withinEditWindow(string $tanggal): bool
    {
        return Dates::daysSince($tanggal) <= (int) config('absensi.edit_window_days', 7);
    }
}
