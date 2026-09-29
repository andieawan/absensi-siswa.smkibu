<?php

namespace App\Http\Controllers;

use App\Models\Attendance;
use App\Models\GradeValue;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Services\Analytics;
use Illuminate\Http\Request;

/** Dashboard 3 varian: Wali Kelas (harian 1 kelas), Per Mapel, Sekolah (hanya absen harian → tanpa double-count). */
class DashboardController extends Controller
{
    public function __invoke(Request $request)
    {
        $u = $this->me();
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
        } elseif ($variant === 'mapel' && ! $classId) {
            $classId = (int) $classes->first()?->id;
        }
        $subjectId = (int) ($request->query('subject') ?: ($u->subjects[0] ?? $subjects->first()?->id));
        $class = $classes->firstWhere('id', $classId);
        $subject = $subjects->firstWhere('id', $subjectId);

        $scope = $variant === 'mapel' ? $subjectId : null;
        $records = Attendance::query()->scope($classId ?: null, $scope)->get(['student_id', 'class_id', 'subject_id', 'tanggal', 'status'])->toArray();
        $students = Student::when($classId, fn ($q) => $q->where('class_id', $classId))->get(['id', 'nis', 'nama', 'class_id'])->toArray();
        $grades = GradeValue::whereIn('student_id', array_column($students, 'id'))->get(['student_id', 'nilai'])->toArray();

        $counts = Analytics::counts($records);
        $trend = Analytics::trend($records);
        $patterns = Analytics::patterns($records, $students, $classId ?: null, $scope);
        $attention = Analytics::attention($records, $students, $grades, $classId ?: null, $scope);
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
