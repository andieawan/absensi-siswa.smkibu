<?php

namespace App\Services;

use App\Exceptions\UserError;
use App\Models\GradeActivity;
use App\Models\GradeValue;
use App\Models\Student;
use App\Models\User;
use App\Support\Dates;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class GradeService
{
    /** Simpan kegiatan baru ($activityId null) atau timpa kegiatan lama. @param array<int,string> $scores student_id => nilai */
    public static function save(User $user, ?string $activityId, int $classId, int $subjectId, string $nama, string $tanggal, string $tipe, array $scores): GradeActivity
    {
        Rules::requireTeacher($user, $subjectId, $classId);
        $nama = trim($nama);
        if ($nama === '') {
            throw new UserError('Nama kegiatan penilaian wajib diisi.');
        }
        if (! in_array($tipe, ['angka', 'huruf'], true)) {
            throw new UserError("Tipe skala harus 'angka' atau 'huruf'.");
        }
        if (! Dates::valid($tanggal)) {
            throw new UserError('Tanggal kegiatan tidak valid.');
        }
        $students = Student::whereIn('id', array_keys($scores))->get()->keyBy('id');
        $rows = [];
        foreach ($scores as $sid => $val) {
            $s = $students[(int) $sid] ?? null;
            if (! $s || $s->class_id !== $classId) {
                throw new UserError("Siswa #$sid bukan anggota kelas ini.");
            }
            $val = trim((string) $val);
            if ($val === '') {
                $val = $tipe === 'angka' ? '0' : 'C';
            }
            if ($tipe === 'angka') {
                if (! is_numeric($val) || (float) $val < 0 || (float) $val > 100) {
                    throw new UserError("Nilai {$s->nama} ($val) tidak valid. Angka harus 0–100.");
                }
            } else {
                $val = strtoupper($val);
                if (! in_array($val, ['A', 'B', 'C', 'D', 'E'], true)) {
                    throw new UserError("Nilai {$s->nama} ($val) tidak valid. Huruf harus A–E.");
                }
            }
            $rows[] = ['student_id' => (int) $sid, 'nilai' => $val];
        }
        if (! $rows) {
            throw new UserError('Tidak ada nilai untuk disimpan.');
        }

        $act = DB::transaction(function () use ($user, $activityId, $classId, $subjectId, $nama, $tanggal, $tipe, $rows) {
            $act = $activityId ? GradeActivity::find($activityId) : null;
            if ($act && ($act->class_id !== $classId || $act->subject_id !== $subjectId)) {
                throw new UserError('Kegiatan tidak cocok dengan kelas/mapel terpilih.');
            }
            if ($act) {
                $act->update(['nama_kegiatan' => $nama, 'tanggal_kegiatan' => $tanggal, 'tipe_skala' => $tipe]);
            } else {
                $act = GradeActivity::create([
                    'id' => 'act-'.Str::uuid(), 'teacher_id' => $user->id, 'subject_id' => $subjectId, 'class_id' => $classId,
                    'nama_kegiatan' => $nama, 'tanggal_kegiatan' => $tanggal, 'tipe_skala' => $tipe, 'created_at' => now('UTC')->format('Y-m-d H:i:s'),
                ]);
            }
            GradeValue::where('activity_id', $act->id)->delete();
            GradeValue::insert(array_map(fn ($r) => $r + ['activity_id' => $act->id], $rows));

            return $act;
        });
        Audit::log('Simpan Kegiatan Nilai', 'Nilai', $user->nama, "$nama — tipe $tipe, kelas #$classId, mapel #$subjectId, ".count($rows).' siswa');

        return $act;
    }

    public static function delete(User $user, GradeActivity $act): void
    {
        Rules::requireTeacher($user, $act->subject_id, $act->class_id);
        if ($act->tanggal_kegiatan && ! Rules::withinEditWindow($act->tanggal_kegiatan) && ! $user->isAdmin()) {
            throw new UserError("Kegiatan penilaian tanggal {$act->tanggal_kegiatan} melebihi batas penghapusan 7 hari.");
        }
        DB::transaction(function () use ($act) {
            GradeValue::where('activity_id', $act->id)->delete();
            $act->delete();
        });
        Audit::log('Hapus Kegiatan Nilai', 'Nilai', $user->nama, $act->nama_kegiatan);
    }
}
