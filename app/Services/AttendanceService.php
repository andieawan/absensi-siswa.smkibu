<?php

namespace App\Services;

use App\Exceptions\UserError;
use App\Models\Attendance;
use App\Models\Student;
use App\Models\User;
use App\Support\Dates;
use Illuminate\Support\Facades\DB;

class AttendanceService
{
    /**
     * Simpan presensi (UPSERT per siswa/mapel/tanggal) dalam satu transaksi.
     * $via: guru | wali | bk_manual | upload_hardcopy | ketua_kelas_delegasi (khusus delegate()).
     * @return array{created:int, updated:int, alerts:string[]}
     */
    public static function submit(User $user, int $classId, ?int $subjectId, string $tanggal, array $entries, string $via): array
    {
        if (! Dates::valid($tanggal)) {
            throw new UserError('Format tanggal harus YYYY-MM-DD yang valid.');
        }
        if (Dates::isFuture($tanggal)) {
            throw new UserError('Tanggal sesi absensi tidak boleh berada di masa depan.');
        }
        match ($via) {
            'guru', 'wali' => Rules::requireTeacher($user, $subjectId, $classId),
            'bk_manual' => $user->hasRole('bk', 'admin', 'superadmin') ?: throw new UserError('Input manual BK khusus Guru BK / Administrator.'),
            'upload_hardcopy' => $user->isAdmin() ?: throw new UserError('Unggah hardcopy khusus Administrator.'),
            default => throw new UserError("Jalur pencatatan '$via' tidak dikenal."),
        };
        if (! Rules::withinEditWindow($tanggal) && ! $user->isAdmin() && $via !== 'bk_manual') {
            $d = Dates::daysSince($tanggal);
            throw new UserError("Sesi tanggal $tanggal ($d hari lalu) melebihi batas toleransi input 7 hari. Data historis hanya dapat dimasukkan Administrator.");
        }
        Rules::validateEntries($entries, $classId);
        $r = self::write($entries, $classId, $subjectId, $tanggal, $user->id, $via);
        Audit::log('Submit Absensi', 'Absensi', $user->nama, "Kelas #$classId, Mapel #".($subjectId ?? 'Harian').", Tanggal: $tanggal, Total: ".count($entries)." ({$r['created']} baru, {$r['updated']} update), via $via");
        $r['alerts'] = self::alerts(array_column($entries, 'student_id'));

        return $r;
    }

    /** @return array{created:int, updated:int} */
    public static function write(array $entries, int $classId, ?int $subjectId, string $tanggal, int $recordedBy, string $via): array
    {
        $created = $updated = 0;
        DB::transaction(function () use ($entries, $classId, $subjectId, $tanggal, $recordedBy, $via, &$created, &$updated) {
            $now = now('UTC')->format('Y-m-d H:i:s');
            foreach ($entries as $e) {
                $notes = trim((string) ($e['notes'] ?? '')) ?: null;
                $row = Attendance::where('student_id', $e['student_id'])->where('tanggal', $tanggal)
                    ->when($subjectId === null, fn ($q) => $q->whereNull('subject_id'), fn ($q) => $q->where('subject_id', $subjectId))
                    ->lockForUpdate()->first();
                if ($row) {
                    $row->update(['status' => $e['status'], 'notes' => $notes, 'recorded_by' => $recordedBy, 'recorded_via' => $via, 'updated_at' => $now]);
                    $updated++;
                } else {
                    Attendance::create([
                        'student_id' => $e['student_id'], 'class_id' => $classId, 'subject_id' => $subjectId, 'tanggal' => $tanggal,
                        'status' => $e['status'], 'recorded_by' => $recordedBy, 'recorded_via' => $via, 'notes' => $notes,
                        'created_at' => $now, 'updated_at' => $now, 'created_at_millis' => Dates::parse($tanggal) * 1000,
                    ]);
                    $created++;
                }
            }
        });

        return ['created' => $created, 'updated' => $updated];
    }

    /** Peringatan otomatis untuk siswa yang kehadirannya di bawah 85%. */
    public static function alerts(array $studentIds): array
    {
        $out = [];
        foreach (Student::whereIn('id', $studentIds)->get() as $s) {
            $st = Rules::attendanceStats($s->id);
            if (! $st['meets']) {
                $out[] = "{$s->nama}: kehadiran {$st['rate']}% (defisit {$st['deficit']} kehadiran)";
            }
        }

        return $out;
    }

    public static function deleteSession(User $user, int $classId, ?int $subjectId, string $tanggal): int
    {
        if (! Dates::valid($tanggal)) {
            throw new UserError('Format tanggal tidak valid.');
        }
        if (! Rules::withinEditWindow($tanggal) && ! $user->isAdmin()) {
            throw new UserError('Data absensi tanggal '.$tanggal.' berusia '.Dates::daysSince($tanggal).' hari (> 7 hari) dan dikunci permanen. Hanya Administrator yang dapat menghapusnya.');
        }
        Rules::requireTeacher($user, $subjectId, $classId);
        $n = Attendance::query()->scope($classId, $subjectId)->where('tanggal', $tanggal)->delete();
        Audit::log('Hapus Sesi Absensi', 'Absensi', $user->nama, "Kelas #$classId, Mapel #".($subjectId ?? 'Harian').", Tanggal: $tanggal, Terhapus: $n baris");

        return $n;
    }
}
