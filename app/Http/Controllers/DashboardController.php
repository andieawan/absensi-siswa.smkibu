<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\GradeValue;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Services\Analytics;
use App\Services\Assignments;
use Illuminate\Http\Request;

/** Dashboard 3 varian: Wali Kelas (harian 1 kelas), Per Mapel, Sekolah (hanya absen harian → tanpa double-count). */
class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $u = $this->me();
        // Akun TU murni (tanpa kelas/mapel/BK/kepsek/admin) langsung ke halaman kerjanya.
        if ($u->hasRole('tu') && ! $u->kelas_wali_id && ! $u->hasRole('guru', 'bk', 'kepsek', 'admin', 'superadmin')) {
            return redirect()->route('tu.home');
        }
        $classes = SchoolClass::orderBy('name')->get();
        $subjects = Subject::regular()->orderBy('name')->get();
        $isWali = $u->kelas_wali_id !== null;

        $variants = [];
        if ($isWali) {
            $variants['wali'] = 'Wali Kelas';
        }
        if ($u->hasRole('guru', 'admin', 'superadmin')) {
            $variants['mapel'] = 'Per Mapel';
        }
        if ($u->can('lihat-sekolah') || ! $variants) {
            $variants['sekolah'] = 'Sekolah (Kepsek)';
        }
        $default = $isWali ? 'wali' : ($u->hasRole('kepsek') && isset($variants['sekolah']) ? 'sekolah' : array_key_first($variants));
        $variant = isset($variants[$request->query('v')]) ? $request->query('v') : $default;

        $classId = $request->has('class') ? (int) $request->query('class') : ($isWali ? $u->kelas_wali_id : (int) $classes->first()?->id);
        if ($variant === 'wali') {
            $classId = $u->kelas_wali_id;
        } elseif ($variant === 'sekolah' && ! $request->has('class') && $u->can('lihat-sekolah')) {
            $classId = 0; // bawaan dashboard sekolah = agregat semua kelas
        } elseif ($variant === 'mapel' && ! $classId) {
            $classId = (int) $classes->first()?->id;
        }
        $subjectId = (int) ($request->query('subject') ?: ($u->subjects[0] ?? $subjects->first()?->id));
        // Hanya Kepsek/BK/Admin yang boleh melihat kelas mana pun; guru dibatasi ke kelas & mapel yang diajarnya.
        if (! $u->can('lihat-sekolah')) {
            $classes = $variant === 'wali' ? $classes->where('id', $u->kelas_wali_id)->values() : Assignments::classes($u, false);
            if (! $classes->contains('id', $classId)) {
                $classId = (int) ($classes->first()?->id ?? -1); // -1 = tidak ada kelas → data kosong (bukan agregat)
            }
            $subjects = Assignments::subjectsForClass($u, $classId);
            if (! $subjects->contains('id', $subjectId)) {
                $subjectId = (int) ($subjects->first()?->id ?? -1);
            }
        }
        $class = $classes->firstWhere('id', $classId);
        $subject = $subjects->firstWhere('id', $subjectId);

        $scope = $variant === 'mapel' ? $subjectId : null;
        $base = fn () => Attendance::query()->scope($classId ?: null, $scope);

        // Angka & tren dihitung langsung oleh database (hemat memori untuk data satu tahun penuh).
        $byStatus = $base()->selectRaw('status, COUNT(*) AS n')->groupBy('status')->pluck('n', 'status');
        $total = (int) $byStatus->sum();
        $counts = [
            'total' => $total, 'hadir' => (int) ($byStatus['H'] ?? 0), 'izin' => (int) ($byStatus['I'] ?? 0),
            'sakit' => (int) ($byStatus['S'] ?? 0), 'alpa' => (int) ($byStatus['A'] ?? 0),
            'rate' => $total > 0 ? ($byStatus['H'] ?? 0) / $total * 100 : 100.0,
        ];
        $trend = $base()->selectRaw("tanggal, COUNT(*) AS total, SUM(CASE WHEN status = 'H' THEN 1 ELSE 0 END) AS h")
            ->groupBy('tanggal')->orderBy('tanggal')->get()
            ->map(fn ($r) => ['date' => $r->tanggal, 'total' => (int) $r->total, 'h' => (int) $r->h, 'pct' => (int) round($r->h / $r->total * 100)])->all();

        // Pola & "Perlu Perhatian" hanya butuh baris tidak hadir (I/S/A).
        $absences = $base()->where('status', '!=', 'H')->get(['student_id', 'class_id', 'subject_id', 'tanggal', 'status'])->toArray();
        $students = Student::when($classId, fn ($q) => $q->where('class_id', $classId))->get(['id', 'nis', 'nama', 'class_id'])->toArray();
        $patterns = Analytics::patterns($absences, $students, $classId ?: null, $scope);
        $candidates = array_column(Analytics::attention($absences, $students, [], $classId ?: null, $scope), 'student_id');
        $grades = $candidates ? GradeValue::whereIn('student_id', $candidates)->get(['student_id', 'nilai'])->toArray() : [];
        $attention = Analytics::attention($absences, $students, $grades, $classId ?: null, $scope);
        $dual = count(array_filter($attention, fn ($a) => $a['grade_drop']));
        $scopeLabel = match ($variant) {
            'wali' => 'Kelas Wali '.($class->name ?? ''),
            'sekolah' => $classId ? 'Kelas '.($class->name ?? '').' (Tingkat Sekolah)' : 'Seluruh Kelas (Agregat Sekolah)',
            default => 'Mata Pelajaran '.($subject->name ?? '').' - '.($class->name ?? ''),
        };
        $cat = array_key_exists($request->query('cat'), Analytics::CATEGORIES) ? $request->query('cat') : 'all';

        return view('dashboard', [
            'variants' => $variants, 'variant' => $variant, 'classes' => $classes, 'subjects' => $subjects,
            'classId' => $classId, 'subjectId' => $subjectId, 'class' => $class, 'subject' => $subject,
            'counts' => $counts, 'trend' => $trend, 'patterns' => $patterns, 'attention' => $attention, 'dual' => $dual, 'cat' => $cat,
            'shown' => $cat === 'all' ? $attention : array_values(array_filter($attention, fn ($a) => $a['category'] === $cat)),
            'narrative' => Analytics::narrative($counts['rate'], $counts['alpa'], $counts['izin'], $counts['sakit'], count($attention), count($patterns), $scopeLabel, $dual),
        ]);
    }
}
