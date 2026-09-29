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
    /**
     * Simpan kegiatan baru ($activityId null) atau ubah kegiatan lama.
     * Nilai kosong = siswa belum mengumpulkan (tidak disimpan, tidak dihitung rata-rata).
     * Kegiatan yang sudah terkunci (> 7 hari sejak diinput) masuk "mode susulan":
     * hanya siswa yang BELUM punya nilai yang boleh diisi; nilai lama tidak bisa diubah.
     * @param array<int,string> $scores student_id => nilai
     */
    public static function save(User $user, ?string $activityId, int $classId, int $subjectId, string $nama, string $tanggal, string $tipe, array $scores): GradeActivity
    {
        Rules::requireTeacher($user, $subjectId, $classId);
        $act = $activityId ? GradeActivity::find($activityId) : null;
        if ($act && ($act->class_id !== $classId || $act->subject_id !== $subjectId)) {
            throw new UserError('Kegiatan tidak cocok dengan kelas/mapel terpilih.');
        }
        $susulan = $act && ! Rules::gradeEditable($user, $act);
        if ($susulan) {
            // Identitas kegiatan terkunci: pakai data lama, abaikan isian form.
            [$nama, $tanggal, $tipe] = [$act->nama_kegiatan, (string) $act->tanggal_kegiatan, $act->tipe_skala];
        }
        $nama = trim($nama);
        if ($nama === '') {
            throw new UserError('Nama kegiatan penilaian wajib diisi.');
        }
        if (! in_array($tipe, ['angka', 'huruf'], true)) {
            throw new UserError("Tipe skala harus 'angka' atau 'huruf'.");
        }
        if (! $susulan && ! Dates::valid($tanggal)) {
            throw new UserError('Tanggal kegiatan tidak valid.');
        }
        if (! $susulan && Dates::isFuture($tanggal)) {
            throw new UserError('Tanggal kegiatan tidak boleh berada di masa depan.');
        }

        $students = Student::whereIn('id', array_keys($scores))->get()->keyBy('id');
        $filled = [];     // student_id => nilai (sudah dinormalisasi)
        $submitted = [];  // semua siswa yang ada di form (termasuk yang dikosongkan)
        foreach ($scores as $sid => $val) {
            $s = $students[(int) $sid] ?? null;
            if (! $s || $s->class_id !== $classId) {
                throw new UserError("Siswa #$sid bukan anggota kelas ini.");
            }
            $submitted[] = (int) $sid;
            $val = trim((string) $val);
            if ($val === '') {
                continue; // belum mengumpulkan
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
            $filled[(int) $sid] = $val;
        }

        if ($susulan) {
            $existing = GradeValue::where('activity_id', $act->id)->pluck('nilai', 'student_id');
            $new = [];
            foreach ($filled as $sid => $val) {
                if (! isset($existing[$sid])) {
                    $new[$sid] = $val;
                } elseif ((string) $existing[$sid] !== $val && ! (is_numeric($val) && is_numeric($existing[$sid]) && (float) $val === (float) $existing[$sid])) {
                    throw new UserError("Nilai {$students[$sid]->nama} sudah terkunci (> 7 hari sejak diinput) dan tidak bisa diubah. Hanya siswa yang belum punya nilai yang bisa diisi susulan.");
                }
            }
            if (! $new) {
                throw new UserError('Tidak ada nilai susulan baru. Isi nilai pada siswa yang masih kosong.');
            }
            GradeValue::insert(array_map(fn ($sid, $v) => ['activity_id' => $act->id, 'student_id' => $sid, 'nilai' => $v], array_keys($new), $new));
            Audit::log('Nilai Susulan', 'Nilai', $user->nama, "{$act->nama_kegiatan} — ".count($new).' siswa: '.$students->only(array_keys($new))->pluck('nama')->implode(', '));

            return $act;
        }

        if (! $act && ! $filled) {
            throw new UserError('Belum ada nilai yang diisi.');
        }
        $act = DB::transaction(function () use ($user, $act, $classId, $subjectId, $nama, $tanggal, $tipe, $filled, $submitted) {
            if ($act) {
                $act->update(['nama_kegiatan' => $nama, 'tanggal_kegiatan' => $tanggal, 'tipe_skala' => $tipe]);
            } else {
                $act = GradeActivity::create([
                    'id' => 'act-'.Str::uuid(), 'teacher_id' => $user->id, 'subject_id' => $subjectId, 'class_id' => $classId,
                    'nama_kegiatan' => $nama, 'tanggal_kegiatan' => $tanggal, 'tipe_skala' => $tipe, 'created_at' => now('UTC')->format('Y-m-d H:i:s'),
                ]);
            }
            // Hanya siswa yang ada di form yang diganti (nilai siswa pindah/nonaktif tetap aman).
            GradeValue::where('activity_id', $act->id)->whereIn('student_id', $submitted)->delete();
            if ($filled) {
                GradeValue::insert(array_map(fn ($sid, $v) => ['activity_id' => $act->id, 'student_id' => $sid, 'nilai' => $v], array_keys($filled), $filled));
            }

            return $act;
        });
        Audit::log('Simpan Kegiatan Nilai', 'Nilai', $user->nama, "$nama — tipe $tipe, kelas #$classId, mapel #$subjectId, ".count($filled).' siswa dinilai, '.(count($submitted) - count($filled)).' belum mengumpulkan');

        return $act;
    }

    public static function delete(User $user, GradeActivity $act): void
    {
        Rules::requireTeacher($user, $act->subject_id, $act->class_id);
        if (! Rules::gradeEditable($user, $act)) {
            throw new UserError("Kegiatan \"{$act->nama_kegiatan}\" diinput lebih dari 7 hari lalu dan sudah dikunci. Hanya Administrator yang dapat menghapusnya.");
        }
        DB::transaction(function () use ($act) {
            GradeValue::where('activity_id', $act->id)->delete();
            $act->delete();
        });
        Audit::log('Hapus Kegiatan Nilai', 'Nilai', $user->nama, $act->nama_kegiatan);
    }
}
