<?php
declare(strict_types=1);

// ============================================================================
// Letters: surat resmi yang dicetak dari browser (pengganti Google Docs).
// Kata-kata mengikuti versi lama; alamat/NIP tidak dikarang — diisi lewat
// kolom yang bisa diedit sebelum mencetak.
// ============================================================================

final class Letters
{
    private const MONTHS = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    public static function longDate(?string $ymd = null): string
    {
        $ts = $ymd ? Util::parseSessionDate($ymd) : null;
        $d = $ts !== null
            ? (new DateTimeImmutable('@' . $ts))->setTimezone(new DateTimeZone('UTC'))
            : new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta'));
        return (int) $d->format('j') . ' ' . self::MONTHS[(int) $d->format('n')] . ' ' . $d->format('Y');
    }

    private static function year(): string
    {
        return (new DateTimeImmutable('now', new DateTimeZone('Asia/Jakarta')))->format('Y');
    }

    private static function blank(string $v, string $fallback = '........................'): string
    {
        return trim($v) === '' ? $fallback : h($v);
    }

    private static function kop(array $settings, string $sub = ''): string
    {
        return '<div class="kop"><span>PEMERINTAH DAERAH PROVINSI / DINAS PENDIDIKAN</span><b>' . h(mb_strtoupper($settings['school_name'])) . '</b>'
            . ($sub !== '' ? '<span>' . h($sub) . '</span>' : '') . '</div>';
    }

    private static function ttd(array $settings, string $place, string $bkLabel): string
    {
        return '<p style="text-align:right;margin-top:28px">' . self::blank($place) . ', ' . h(self::longDate()) . '</p>'
            . '<div class="ttd"><div>Mengetahui,<br>Kepala Sekolah,<div class="sp"></div><b><u>' . self::blank((string) ($settings['kepsek_nama'] ?? ''), '(........................)') . '</u></b><br>NIP. ....................</div>'
            . '<div><br>' . h($bkLabel) . ',<div class="sp"></div><b><u>' . self::blank((string) ($settings['bk_nama'] ?? ''), '(........................)') . '</u></b><br>NIP. ....................</div></div>';
    }

    public static function warning(array $student, string $className, array $records, array $settings, string $place): string
    {
        $by = fn(string $s) => array_values(array_filter($records, fn($r) => $r['status'] === $s));
        $alpa = $by('A');
        $izin = count($by('I'));
        $sakit = count($by('S'));
        $h = self::kop($settings);
        $h .= '<table><tr><td>Nomor</td><td>:</td><td>421.5 / SP-BK / ' . self::year() . '</td></tr>'
            . '<tr><td>Lampiran</td><td>:</td><td>1 (Satu) Berkas Riwayat Kehadiran</td></tr>'
            . '<tr><td>Perihal</td><td>:</td><td><b>Peringatan Ketidakhadiran &amp; Bimbingan Konseling Siswa</b></td></tr></table>';
        $h .= '<p>Kepada Yth.<br>Bapak/Ibu Orang Tua / Wali dari Ananda:</p>'
            . '<table><tr><td>Nama Siswa</td><td>:</td><td>' . h($student['nama']) . '</td></tr>'
            . '<tr><td>Nomor Induk (NIS)</td><td>:</td><td>' . h($student['nis']) . '</td></tr>'
            . '<tr><td>Kelas / Program</td><td>:</td><td>' . h($className) . '</td></tr>'
            . '<tr><td>Tahun Pelajaran</td><td>:</td><td>' . h(($settings['tahun_ajaran'] ?? '') . ' (' . ($settings['semester'] ?? '') . ')') . '</td></tr></table>';
        $h .= '<p>Dengan hormat,<br>Berdasarkan rekapitulasi data presensi elektronik pada sistem informasi sekolah, dengan ini kami memberitahukan bahwa putra/putri Bapak/Ibu tercatat mengalami ketidakhadiran dengan rincian sebagai berikut:</p>'
            . '<table style="margin-left:24px"><tr><td>1. Alpa (Tanpa Keterangan)</td><td>:</td><td>' . count($alpa) . ' kali pertemuan</td></tr>'
            . '<tr><td>2. Izin Resmi</td><td>:</td><td>' . $izin . ' kali pertemuan</td></tr>'
            . '<tr><td>3. Sakit</td><td>:</td><td>' . $sakit . ' kali pertemuan</td></tr>'
            . '<tr><td><b>Total Ketidakhadiran</b></td><td>:</td><td><b>' . (count($alpa) + $izin + $sakit) . ' kali pertemuan</b></td></tr></table>';
        if ($alpa) {
            $h .= '<p>Rincian Tanggal Alpa:</p><ul>';
            foreach ($alpa as $a) $h .= '<li>Tanggal: ' . h($a['tanggal']) . ' | Catatan: ' . h($a['notes'] ?? 'Tanpa keterangan resmi') . '</li>';
            $h .= '</ul>';
        }
        $h .= '<p>Mengingat pentingnya pemenuhan syarat minimal kehadiran (85%) untuk evaluasi kenaikan kelas dan kelulusan, kami mengharapkan perhatian dan kerja sama Bapak/Ibu dalam mengawasi serta membimbing Ananda.</p>'
            . '<p>Demikian surat pemberitahuan ini kami sampaikan, atas perhatian dan kerja sama yang baik kami ucapkan terima kasih.</p>';
        return $h . self::ttd($settings, $place, 'Guru Bimbingan Konseling (BK)');
    }

    public static function summons(array $student, string $className, array $settings, string $place, string $tanggal, string $jam, string $ruang, string $alasan): string
    {
        $h = self::kop($settings, 'LAYANAN BIMBINGAN DAN KONSELING (BK)');
        $h .= '<p style="text-align:center"><b><u>SURAT PANGGILAN ORANG TUA / WALI SISWA</u></b><br>Nomor: 421.7 / BK-PANGGILAN / ' . self::year() . '</p>';
        $h .= '<p>Kepada Yth.<br>Bapak / Ibu Orang Tua / Wali dari:</p>'
            . '<table><tr><td>Nama Siswa</td><td>:</td><td>' . h($student['nama']) . '</td></tr>'
            . '<tr><td>NIS</td><td>:</td><td>' . h($student['nis']) . '</td></tr>'
            . '<tr><td>Kelas</td><td>:</td><td>' . h($className) . '</td></tr></table>';
        $h .= '<p>Dengan hormat,<br>Sehubungan dengan adanya hal penting terkait evaluasi kedisiplinan dan capaian belajar siswa (' . self::blank($alasan, 'ketidakhadiran') . '), maka melalui surat ini kami mengharapkan kehadiran Bapak/Ibu pada:</p>'
            . '<table style="margin-left:24px"><tr><td>Hari / Tanggal</td><td>:</td><td>' . self::blank($tanggal) . '</td></tr>'
            . '<tr><td>Waktu</td><td>:</td><td>Pukul ' . self::blank($jam, '....') . ' WIB</td></tr>'
            . '<tr><td>Tempat</td><td>:</td><td>Ruang ' . self::blank($ruang, '..........') . '</td></tr>'
            . '<tr><td>Bertemu Dengan</td><td>:</td><td>Guru Bimbingan Konseling &amp; Wali Kelas</td></tr></table>';
        $h .= '<p>Mengingat sangat pentingnya koordinasi ini demi masa depan belajar putra/putri Bapak/Ibu, kami sangat mengharapkan kehadiran Bapak/Ibu tepat pada waktu yang telah ditentukan.</p><p>Atas perhatian dan kerja samanya, kami ucapkan terima kasih.</p>';
        return $h . self::ttd($settings, $place, 'Guru Bimbingan Konseling');
    }

    public static function semesterReport(array $class, string $subjectName, array $students, array $records, array $activities, array $gradeValues, array $settings): string
    {
        $tot = count($records);
        $hadir = count(array_filter($records, fn($r) => $r['status'] === 'H'));
        $pct = $tot > 0 ? number_format($hadir / $tot * 100, 1) : '100';
        $vals = [];
        foreach ($gradeValues as $g) $vals[$g['activity_id']][$g['student_id']] = $g['nilai'];

        $h = '<div class="kop"><b>LAPORAN EVALUASI PRESENSI &amp; CAPAIAN PEMBELAJARAN SISWA</b><b>' . h(mb_strtoupper($settings['school_name'])) . '</b>'
            . '<span>Tahun Ajaran ' . h($settings['tahun_ajaran'] ?? '') . ' - Semester ' . h($settings['semester'] ?? '') . '</span></div>';
        $h .= '<table><tr><td>Mata Pelajaran</td><td>:</td><td>' . h($subjectName) . '</td></tr><tr><td>Kelas</td><td>:</td><td>' . h($class['name']) . '</td></tr>'
            . '<tr><td>Waktu Cetak</td><td>:</td><td>' . h(self::longDate()) . '</td></tr></table>';
        $h .= '<h3 style="margin-top:14px">I. RINGKASAN KEHADIRAN KELAS</h3><ul><li>Rata-rata Kehadiran Kelas: <b>' . $pct . '%</b></li><li>Total Siswa Aktif: ' . count($students) . ' orang</li><li>Jumlah Sesi Pertemuan: ' . count(array_unique(array_column($records, 'tanggal'))) . ' sesi</li></ul>';
        $h .= '<h3>II. DAFTAR CAPAIAN SISWA</h3><table border="1" cellpadding="4" style="width:100%;font-size:11pt"><tr><th>No</th><th>NIS</th><th>Nama Siswa</th><th>Kehadiran</th><th>Nilai Rata-rata</th></tr>';
        foreach ($students as $i => $s) {
            $sr = array_filter($records, fn($r) => $r['student_id'] === $s['id']);
            $hc = count(array_filter($sr, fn($r) => $r['status'] === 'H'));
            $rate = $sr ? (int) round($hc / count($sr) * 100) : 100;
            $sum = 0.0; $n = 0;
            foreach ($activities as $a) {
                $v = $vals[$a['id']][$s['id']] ?? null;
                if ($a['tipe_skala'] === 'angka' && $v !== null && is_numeric($v)) { $sum += (float) $v; $n++; }
            }
            $h .= '<tr><td>' . ($i + 1) . '</td><td>' . h($s['nis']) . '</td><td>' . h($s['nama']) . '</td><td style="text-align:center">' . $rate . '%</td><td style="text-align:center">' . ($n ? number_format($sum / $n, 1) : '-') . '</td></tr>';
        }
        $h .= '</table><h3 style="margin-top:14px">III. CATATAN &amp; REKOMENDASI PEMBELAJARAN</h3><p>Peserta didik dengan tingkat kehadiran di bawah 85% atau capaian nilai belum tuntas dijadwalkan mengikuti program remedial serta sesi pendampingan konseling.</p>'
            . '<div class="ttd"><div></div><div>Guru Pengampu Mata Pelajaran,<div class="sp"></div>(......................................)<br>NIP. ....................</div></div>';
        return $h;
    }
}
