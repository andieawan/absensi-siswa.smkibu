<?php

namespace App\Services;

use App\Exceptions\UserError;
use App\Models\Attendance;
use App\Models\SchoolClass;
use App\Models\SchoolSetting;
use App\Models\Student;
use App\Models\User;
use App\Support\Dates;
use Illuminate\Support\Facades\DB;

/** Pantau absen harian, rekap semester untuk rapor, dan kenaikan kelas. */
class SchoolReports
{
    /**
     * Status absen HARIAN semua kelas pada satu tanggal.
     * @return array<int, array{class:SchoolClass, wali:?string, students:int, filled:int, H:int, I:int, S:int, A:int, last:?string, via:?string}>
     */
    public static function dailyStatus(string $tanggal): array
    {
        $agg = Attendance::query()->whereNull('subject_id')->where('tanggal', $tanggal)
            ->selectRaw("class_id, COUNT(*) AS n, MAX(updated_at) AS last,
                SUM(CASE WHEN status='H' THEN 1 ELSE 0 END) AS h, SUM(CASE WHEN status='I' THEN 1 ELSE 0 END) AS i,
                SUM(CASE WHEN status='S' THEN 1 ELSE 0 END) AS s, SUM(CASE WHEN status='A' THEN 1 ELSE 0 END) AS a")
            ->groupBy('class_id')->get()->keyBy('class_id');
        $vias = Attendance::query()->whereNull('subject_id')->where('tanggal', $tanggal)
            ->select('class_id', 'recorded_via')->distinct()->get()->groupBy('class_id');
        $counts = Student::active()->selectRaw('class_id, COUNT(*) AS n')->groupBy('class_id')->pluck('n', 'class_id');
        $walis = User::whereNotNull('kelas_wali_id')->where('is_active', true)->pluck('nama', 'kelas_wali_id');

        $out = [];
        foreach (SchoolClass::orderBy('name')->get() as $c) {
            $a = $agg[$c->id] ?? null;
            $out[] = [
                'class' => $c, 'wali' => $walis[$c->id] ?? null, 'students' => (int) ($counts[$c->id] ?? 0),
                'filled' => (int) ($a->n ?? 0), 'H' => (int) ($a->h ?? 0), 'I' => (int) ($a->i ?? 0), 'S' => (int) ($a->s ?? 0), 'A' => (int) ($a->a ?? 0),
                'last' => $a->last ?? null,
                'via' => isset($vias[$c->id]) ? $vias[$c->id]->pluck('recorded_via')->map(fn ($v) => Attendance::VIA[$v] ?? $v)->implode(', ') : null,
            ];
        }

        return $out;
    }

    public static function waliFilledToday(User $u): ?bool
    {
        if (! $u->kelas_wali_id) {
            return null;
        }

        return Attendance::whereNull('subject_id')->where('class_id', $u->kelas_wali_id)->where('tanggal', Dates::today())->exists();
    }

    /**
     * Rekap ketidakhadiran per siswa (absen HARIAN) untuk rapor.
     * @return array<int, array{student:Student, S:int, I:int, A:int, H:int, total:int, rate:?float}>
     */
    public static function semesterRecap(int $classId, string $from, string $to): array
    {
        if (! Dates::valid($from) || ! Dates::valid($to) || $from > $to) {
            throw new UserError('Rentang tanggal tidak valid.');
        }
        $rows = Attendance::query()->whereNull('subject_id')->where('class_id', $classId)->whereBetween('tanggal', [$from, $to])
            ->selectRaw('student_id, status, COUNT(*) AS n')->groupBy('student_id', 'status')->get();
        $by = [];
        foreach ($rows as $r) {
            $by[$r->student_id][$r->status] = (int) $r->n;
        }
        // Siswa aktif di kelas ini + siswa lain yang punya catatan di kelas ini pada rentang tsb (mis. sudah pindah).
        $students = Student::where(fn ($q) => $q->where('class_id', $classId)->where('status', 'aktif'))
            ->orWhereIn('id', array_keys($by))->orderBy('nama')->get();
        $out = [];
        foreach ($students as $s) {
            $c = $by[$s->id] ?? [];
            $total = array_sum($c);
            $out[] = ['student' => $s, 'S' => $c['S'] ?? 0, 'I' => $c['I'] ?? 0, 'A' => $c['A'] ?? 0, 'H' => $c['H'] ?? 0,
                'total' => $total, 'rate' => $total ? round(($c['H'] ?? 0) / $total * 100, 1) : null];
        }

        return $out;
    }

    /**
     * Kenaikan kelas / kelulusan. $target = id kelas tujuan, atau 'lulus'.
     * @param int[] $studentIds siswa yang dinaikkan/diluluskan (yang tidak dipilih tetap di kelas asal)
     */
    public static function promote(User $actor, int $fromClass, string $target, array $studentIds): int
    {
        if (! $actor->isAdmin()) {
            throw new UserError('Kenaikan kelas khusus Administrator.');
        }
        $from = SchoolClass::find($fromClass) ?? throw new UserError('Kelas asal tidak valid.');
        $ids = Student::where('class_id', $from->id)->where('status', 'aktif')->whereIn('id', array_map('intval', $studentIds))->pluck('id')->all();
        if (! $ids) {
            throw new UserError('Pilih minimal satu siswa.');
        }
        if ($target === 'lulus') {
            $label = 'LULUS';
            $n = DB::transaction(fn () => Student::whereIn('id', $ids)->update(['status' => 'lulus']));
        } else {
            $to = SchoolClass::find((int) $target) ?? throw new UserError('Kelas tujuan tidak valid.');
            if ($to->id === $from->id) {
                throw new UserError('Kelas tujuan harus berbeda dari kelas asal.');
            }
            $label = $to->name;
            $n = DB::transaction(fn () => Student::whereIn('id', $ids)->update(['class_id' => $to->id]));
        }
        Audit::log('Kenaikan Kelas', 'Siswa', $actor->nama, "$n siswa dari {$from->name} → $label");

        return $n;
    }

    /** Mulai tahun ajaran baru: ubah Pengaturan + label tahun ajaran di semua kelas. */
    public static function startNewYear(User $actor, string $tahunAjaran): void
    {
        if (! $actor->isAdmin()) {
            throw new UserError('Khusus Administrator.');
        }
        if (! preg_match('/^(\d{4})\/(\d{4})$/', $tahunAjaran, $m) || (int) $m[2] !== (int) $m[1] + 1) {
            throw new UserError('Format tahun ajaran harus seperti 2027/2028.');
        }
        DB::transaction(function () use ($tahunAjaran) {
            SchoolSetting::put(['tahun_ajaran' => $tahunAjaran, 'semester' => 'Ganjil']);
            SchoolClass::query()->update(['tahun_ajaran' => $tahunAjaran, 'semester' => 'Ganjil']);
        });
        Audit::log('Tahun Ajaran Baru', 'Sistem', $actor->nama, "Tahun ajaran $tahunAjaran, semester Ganjil");
    }
}
