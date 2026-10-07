<?php

namespace App\Services;

use App\Exceptions\UserError;
use App\Models\Pairing;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Support\DbUpdate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Hapus data master yang salah input (siswa, kelas, mapel, akun guru).
 * Aman: hanya data yang BELUM punya riwayat (presensi, nilai, catatan BK, mutasi, surat) yang boleh dihapus;
 * yang sudah punya riwayat ditolak dengan alasan jelas (gunakan status Nonaktif/Pindah/Keluar).
 */
class DeleteService
{
    private static function has(string $table): bool
    {
        return Schema::hasTable($table);
    }

    /** @param int[] $ids @return array<int,string[]> student_id => alasan penolakan */
    public static function studentBlockers(array $ids): array
    {
        $out = [];
        $add = function (string $label, $rows) use (&$out) {
            foreach ($rows as $sid => $n) {
                $out[(int) $sid][] = "$n $label";
            }
        };
        if ($ids === []) {
            return $out;
        }
        $count = fn (string $table) => DB::table($table)->whereIn('student_id', $ids)->select('student_id', DB::raw('count(*) as n'))->groupBy('student_id')->pluck('n', 'student_id');
        $add('data presensi', $count('attendance'));
        $add('nilai', $count('grade_values'));
        if (DbUpdate::bkReady()) {
            $add('catatan BK', $count('bk_records'));
        }
        if (self::has('mutasi_siswa')) {
            $add('data mutasi', $count('mutasi_siswa'));
        }
        if (self::has('tu_surat')) {
            $add('surat', DB::table('tu_surat')->whereIn('student_id', $ids)->select('student_id', DB::raw('count(*) as n'))->groupBy('student_id')->pluck('n', 'student_id'));
        }

        return $out;
    }

    /** @param int[] $ids @return array{deleted:int, skipped:string[]} */
    public static function deleteStudents(User $actor, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $students = Student::whereIn('id', $ids)->get()->keyBy('id');
        if ($students->isEmpty()) {
            throw new UserError('Tidak ada siswa yang dipilih.');
        }
        $block = self::studentBlockers($students->keys()->all());
        $ok = $students->keys()->reject(fn ($id) => isset($block[$id]))->values();
        $skipped = [];
        foreach ($students as $id => $s) {
            if (isset($block[$id])) {
                $skipped[] = "{$s->nama} (NIS {$s->nis}): punya ".implode(', ', $block[$id]);
            }
        }
        if ($ok->isNotEmpty()) {
            if ($students->count() > 1) {
                BackupService::run(); // cadangan otomatis sebelum hapus massal
            }
            DB::transaction(function () use ($ok) {
                foreach ($ok->chunk(500) as $chunk) {
                    if (self::has('parent_access_tokens')) {
                        DB::table('parent_access_tokens')->whereIn('student_id', $chunk->all())->delete();
                    }
                    Student::whereIn('id', $chunk->all())->delete();
                }
            });
            $names = $ok->take(5)->map(fn ($id) => $students[$id]->nama)->implode(', ');
            Audit::log('Hapus Siswa', 'Siswa', $actor->nama, $ok->count().' siswa dihapus: '.$names.($ok->count() > 5 ? ', …' : ''));
        }

        return ['deleted' => $ok->count(), 'skipped' => $skipped];
    }

    public static function deleteStudent(User $actor, Student $s): void
    {
        $r = self::deleteStudents($actor, [$s->id]);
        if ($r['deleted'] === 0) {
            throw new UserError("{$r['skipped'][0]}. Siswa yang sudah punya riwayat tidak dihapus; ubah statusnya menjadi Nonaktif/Pindah/Keluar.");
        }
    }

    public static function deleteClass(User $actor, SchoolClass $c): void
    {
        $why = [];
        if (($n = Student::where('class_id', $c->id)->count()) > 0) {
            $why[] = "$n siswa (pindahkan atau hapus dulu)";
        }
        foreach (['attendance' => 'data presensi', 'grade_activities' => 'penilaian'] as $t => $label) {
            if (($n = DB::table($t)->where('class_id', $c->id)->count()) > 0) {
                $why[] = "$n $label";
            }
        }
        if (DbUpdate::bkReady() && ($n = DB::table('bk_records')->where('class_id', $c->id)->count()) > 0) {
            $why[] = "$n catatan BK";
        }
        if ($why) {
            throw new UserError("Kelas {$c->name} tidak dapat dihapus: masih punya ".implode(', ', $why).'.');
        }
        DB::transaction(function () use ($c) {
            Pairing::where('class_id', $c->id)->delete();
            DB::table('ketua_kelas_tokens')->where('class_id', $c->id)->delete();
            if (DbUpdate::boardReady()) {
                DB::table('class_board_tokens')->where('class_id', $c->id)->delete();
            }
            foreach (User::all() as $u) {
                $upd = [];
                if ((int) $u->kelas_wali_id === (int) $c->id) {
                    $upd['kelas_wali_id'] = null;
                }
                if (in_array($c->id, array_map('intval', $u->classes ?? []), true)) {
                    $upd['classes'] = array_values(array_filter(array_map('intval', $u->classes), fn ($x) => $x !== (int) $c->id));
                }
                if ($upd) {
                    $u->update($upd);
                }
            }
            $c->delete();
        });
        Audit::log('Hapus Kelas', 'Data Master', $actor->nama, $c->name);
    }

    public static function deleteSubject(User $actor, Subject $s): void
    {
        if ((int) $s->id === (int) config('absensi.bk_subject_id')) {
            throw new UserError('Mapel Bimbingan Konseling adalah bagian sistem dan tidak dapat dihapus.');
        }
        $why = [];
        foreach (['attendance' => 'data presensi', 'grade_activities' => 'penilaian'] as $t => $label) {
            if (($n = DB::table($t)->where('subject_id', $s->id)->count()) > 0) {
                $why[] = "$n $label";
            }
        }
        if ($why) {
            throw new UserError("Mapel {$s->name} tidak dapat dihapus: sudah dipakai di ".implode(', ', $why).'.');
        }
        DB::transaction(function () use ($s) {
            Pairing::where('subject_id', $s->id)->delete();
            foreach (User::all() as $u) {
                if (in_array($s->id, array_map('intval', $u->subjects ?? []), true)) {
                    $u->update(['subjects' => array_values(array_filter(array_map('intval', $u->subjects), fn ($x) => $x !== (int) $s->id))]);
                }
            }
            $s->delete();
        });
        Audit::log('Hapus Mapel', 'Data Master', $actor->nama, $s->name);
    }

    public static function deleteUser(User $actor, User $u): void
    {
        if ($u->id === $actor->id) {
            throw new UserError('Anda tidak dapat menghapus akun Anda sendiri.');
        }
        if ($u->hasRole('superadmin') && ! $actor->hasRole('superadmin')) {
            throw new UserError('Akun Superadmin hanya dapat dihapus oleh Superadmin.');
        }
        if ($u->hasRole('admin', 'superadmin') && User::where('id', '!=', $u->id)->where('is_active', true)->get()->filter(fn ($x) => $x->hasRole('admin', 'superadmin'))->isEmpty()) {
            throw new UserError('Tidak dapat menghapus satu-satunya Administrator aktif.');
        }
        $why = [];
        $chk = [['attendance', 'recorded_by', 'data presensi'], ['grade_activities', 'teacher_id', 'penilaian']];
        if (DbUpdate::bkReady()) {
            $chk[] = ['bk_records', 'dicatat_oleh', 'catatan BK'];
        }
        if (self::has('mutasi_siswa')) {
            $chk[] = ['mutasi_siswa', 'dicatat_oleh', 'data mutasi'];
        }
        if (self::has('tu_surat')) {
            $chk[] = ['tu_surat', 'dicatat_oleh', 'surat'];
        }
        if (self::has('staff_attendance')) {
            $chk[] = ['staff_attendance', 'user_id', 'absensi guru/staf'];
        }
        foreach ($chk as [$t, $col, $label]) {
            if (($n = DB::table($t)->where($col, $u->id)->count()) > 0) {
                $why[] = "$n $label";
            }
        }
        if ($why) {
            throw new UserError("Akun {$u->username} tidak dapat dihapus: sudah punya riwayat ".implode(', ', $why).'. Nonaktifkan saja akunnya.');
        }
        DB::transaction(function () use ($u) {
            Pairing::where('user_id', $u->id)->delete();
            $u->delete();
        });
        Audit::log('Hapus Akun', 'Akun Guru', $actor->nama, $u->username);
    }
}
