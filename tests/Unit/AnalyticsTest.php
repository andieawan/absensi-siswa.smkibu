<?php

namespace Tests\Unit;

use App\Services\Analytics;
use PHPUnit\Framework\TestCase;

class AnalyticsTest extends TestCase
{
    private function rec(int $sid, string $d, string $st, ?int $subj = null): array
    {
        return ['student_id' => $sid, 'class_id' => 1, 'subject_id' => $subj, 'tanggal' => $d, 'status' => $st];
    }

    public function test_pola_absen_berkala(): void
    {
        $students = [['id' => 1, 'nama' => 'A', 'nis' => '1', 'class_id' => 1], ['id' => 2, 'nama' => 'B', 'nis' => '2', 'class_id' => 1]];
        // Senin 2026-09-07, 2026-09-21 (14 hari), 2026-10-05 (14 hari) → Pola 3x; siswa 2 jarak 7 hari → tidak
        $r = [$this->rec(1, '2026-09-07', 'A'), $this->rec(1, '2026-09-21', 'S'), $this->rec(1, '2026-10-05', 'I'), $this->rec(2, '2026-09-07', 'A'), $this->rec(2, '2026-09-14', 'A')];
        $p = Analytics::patterns($r, $students, 1, null);
        $this->assertCount(1, $p);
        $this->assertSame(['Senin', 'Pola', 3], [$p[0]['day_of_week'], $p[0]['status_type'], $p[0]['count']]);
        $this->assertSame([], Analytics::patterns($r, $students, 1, 2)); // filter mapel
    }

    public function test_perlu_perhatian_dan_urutan(): void
    {
        $students = array_map(fn ($i) => ['id' => $i, 'nama' => "S$i", 'nis' => "$i", 'class_id' => 1], [1, 2, 3, 4, 5]);
        $r = [
            $this->rec(1, '2026-09-01', 'A'), $this->rec(1, '2026-09-02', 'A'),                          // alpa_tinggi sev 2*3+2=8
            $this->rec(2, '2026-09-01', 'S'), $this->rec(2, '2026-09-02', 'S'), $this->rec(2, '2026-09-03', 'S'), // sakit sev 3*2+3=9
            $this->rec(3, '2026-09-01', 'I'), $this->rec(3, '2026-09-02', 'I'),                          // izin 2*1.5+2=5
            $this->rec(4, '2026-09-01', 'A'), $this->rec(4, '2026-09-02', 'S'), $this->rec(4, '2026-09-03', 'I'), // gabungan 3*2=6
            $this->rec(5, '2026-09-01', 'A'),
        ];
        $a = Analytics::attention($r, $students, [['student_id' => 4, 'nilai' => 'D']], 1, null);
        $this->assertSame([2, 1, 4, 3], array_column($a, 'student_id'));
        $this->assertSame(['sakit_tinggi', 'alpa_tinggi', 'jarang_masuk_gabungan', 'izin_tinggi'], array_column($a, 'category'));
        $this->assertTrue($a[2]['grade_drop']);
    }

    public function test_narasi_ambang(): void
    {
        $this->assertStringContainsString('sangat prima', Analytics::narrative(95, 0, 0, 0, 0, 0, 'X')['summary']);
        $this->assertStringContainsString('rentang wajar', Analytics::narrative(85, 1, 1, 1, 0, 0, 'X')['summary']);
        $n = Analytics::narrative(70, 5, 0, 0, 3, 0, 'X');
        $this->assertStringContainsString('PERINGATAN', $n['summary']);
        $this->assertStringContainsString('pemanggilan orang tua', $n['recommendation']);
        $this->assertStringContainsString('Verifikasi alasan', Analytics::narrative(95, 0, 0, 0, 0, 1, 'X')['recommendation']);
        $this->assertStringContainsString('UKS', Analytics::narrative(95, 0, 0, 4, 0, 0, 'X')['recommendation']);
    }
}
