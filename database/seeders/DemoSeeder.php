<?php

namespace Database\Seeders;

use App\Models\Pairing;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Services\AttendanceService;
use App\Services\Sequence;
use App\Support\Passwords;
use Illuminate\Database\Seeder;

/**
 * Data contoh untuk mencoba aplikasi: php artisan db:seed --class=DemoSeeder
 * Hanya berjalan bila belum ada kelas. Password akun contoh dicetak di layar.
 */
class DemoSeeder extends Seeder
{
    public static array $passwords = [];

    public function run(): void
    {
        if (SchoolClass::query()->exists()) {
            $this->command?->warn('Sudah ada data kelas — seed contoh dibatalkan.');

            return;
        }
        if (! User::query()->exists()) {
            (new DatabaseSeeder)->run();
        }
        $classes = [[1, 'XI DKV 1', 'Desain Komunikasi Visual', 'DKV'], [2, 'X RPL 1', 'Rekayasa Perangkat Lunak', 'RPL'], [3, 'XII TKJ 2', 'Teknik Komputer Jaringan', 'TKJ'], [4, 'X AKL 1', 'Akuntansi & Keuangan Lembaga', 'AKL']];
        foreach ($classes as [$id, $name, $jur]) {
            SchoolClass::create(['id' => $id, 'name' => $name, 'jurusan' => $jur, 'angkatan' => '2026', 'tahun_ajaran' => '2026/2027', 'semester' => 'Ganjil']);
        }
        foreach ([1 => 'Bahasa Indonesia', 2 => 'Matematika', 3 => 'Pemrograman Web', 4 => 'Desain Grafis Percetakan', 5 => 'Bimbingan Konseling'] as $id => $n) {
            Subject::create(['id' => $id, 'name' => $n]);
        }
        $names = ['Achmad Fauzi', 'Adinda Putri', 'Bayu Arya', 'Cantika Dewi', 'Dimas Kurnia', 'Eka Nurul', 'Fajar Maulana', 'Gita Pertiwi', 'Hasan Basri', 'Intan Permata', 'Joko Santoso', 'Kartika Sari'];
        $sid = 0;
        foreach ($classes as [$cid, , , $code]) {
            foreach ($names as $i => $n) {
                Student::create(['id' => ++$sid, 'nis' => sprintf('26.%02d/%s/%03d', $cid, $code, $i + 1), 'nama' => $n.' '.['Putra', 'Sari', 'Wijaya'][$i % 3], 'jk' => $i % 2 ? 'P' : 'L', 'class_id' => $cid, 'status' => 'aktif']);
            }
        }
        Sequence::atLeast('students', $sid);
        Sequence::atLeast('classes', 4);
        Sequence::atLeast('subjects', 5);

        $accounts = [
            ['ibu.siti', 'Siti Aminah, S.Pd.', ['guru'], 1, [1], [1, 2]], ['pak.hendra', 'Hendra Pratama, S.Si.', ['guru'], 2, [2], [1, 2, 3]],
            ['bu.ratna', 'Dra. Ratna Kusuma, M.Pd.', ['kepsek'], null, [], []], ['bu.maya', 'Maya Rosita, S.Psi.', ['bk'], null, [5], [1, 2, 3, 4]],
            ['bu.tata', 'Tata Lestari, S.Pd.', ['tu'], null, [], []],
        ];
        $ids = [];
        foreach ($accounts as [$un, $nama, $roles, $wali, $subj, $cls]) {
            $pw = 'Demo-'.bin2hex(random_bytes(4));
            self::$passwords[$un] = $pw;
            $ids[$un] = User::create(['username' => $un, 'password_hash' => Passwords::make($pw), 'nama' => $nama, 'kelas_wali_id' => $wali, 'is_active' => true, 'roles' => $roles, 'subjects' => $subj, 'classes' => $cls])->id;
            $this->command?->line(sprintf('  %-12s %s', $un, $pw));
        }
        foreach ([['pak.hendra', 2, 1], ['pak.hendra', 2, 2]] as [$un, $s, $c]) {
            Pairing::create(['user_id' => $ids[$un], 'subject_id' => $s, 'class_id' => $c]);
        }
        mt_srand(42);
        for ($d = 28; $d >= 1; $d--) {
            $ts = time() - $d * 86400;
            if (in_array((int) gmdate('w', $ts), [0, 6], true)) {
                continue;
            }
            foreach (SchoolClass::all() as $c) {
                $entries = Student::where('class_id', $c->id)->get()->map(function ($s) {
                    $r = mt_rand(1, 100);

                    return ['student_id' => $s->id, 'status' => $r <= 88 ? 'H' : ($r <= 93 ? 'S' : ($r <= 97 ? 'I' : 'A'))];
                })->all();
                AttendanceService::write($entries, $c->id, null, gmdate('Y-m-d', $ts), 1, 'wali');
            }
        }
        self::extras($ids);
        $this->command?->info("Data contoh dibuat: 4 kelas, $sid siswa, absensi 4 minggu terakhir.");
    }

    /** Nilai, kontak orang tua, catatan BK, register surat, dan absensi guru/staf (bagian yang tabelnya sudah ada). */
    private static function extras(array $ids): void
    {
        \App\Models\SchoolSetting::put(['tahun_ajaran' => '2026/2027', 'semester' => 'Ganjil', 'kepsek_nama' => 'Dra. Ratna Kusuma, M.Pd.', 'bk_nama' => 'Maya Rosita, S.Psi.']);
        $today = \App\Support\Dates::today();
        $ago = fn (int $d) => gmdate('Y-m-d', strtotime($today.' UTC') - $d * 86400);

        if (\App\Support\DbUpdate::parentContactReady()) {
            foreach (Student::orderBy('id')->get() as $s) {
                $s->update(['nama_ortu' => 'Orang tua '.explode(' ', $s->nama)[0], 'telp_ortu' => '62812000'.sprintf('%05d', $s->id)]);
            }
        }

        mt_srand(7);
        foreach ([['pak.hendra', 2, 2, ['Ulangan Harian 1', 'Tugas Projek', 'UTS']], ['ibu.siti', 1, 1, ['Tugas 1', 'Ulangan Harian 1']]] as [$un, $subj, $cls, $acts]) {
            foreach ($acts as $i => $nama) {
                $a = \App\Models\GradeActivity::create(['id' => 'demo-'.$un.'-'.$i, 'teacher_id' => $ids[$un], 'subject_id' => $subj, 'class_id' => $cls, 'nama_kegiatan' => $nama,
                    'tanggal_kegiatan' => $ago(20 - $i * 6), 'tipe_skala' => 'angka', 'created_at' => now('UTC')->format('Y-m-d H:i:s')]);
                foreach (Student::where('class_id', $cls)->pluck('id') as $sid) {
                    \App\Models\GradeValue::create(['activity_id' => $a->id, 'student_id' => $sid, 'nilai' => (string) mt_rand(55, 98)]);
                }
            }
        }

        if (\App\Support\DbUpdate::bkReady()) {
            $bk = fn (string $j, int $sid, int $d, ?string $kat, string $judul, ?string $ur, ?string $st = null, bool $r = false, array $x = []) => \App\Models\BkRecord::create([
                'jenis' => $j, 'student_id' => $sid, 'class_id' => Student::find($sid)->class_id, 'tanggal' => $ago($d), 'kategori' => $kat, 'judul' => $judul, 'uraian' => $ur,
                'status' => $st, 'rahasia' => $r, 'extra' => $x ?: null, 'dicatat_oleh' => $ids['bu.maya']]);
            $bk('pelanggaran', 1, 3, 'Ringan', 'Terlambat masuk kelas', 'Terlambat 20 menit tanpa keterangan.', 'Selesai');
            $bk('pelanggaran', 14, 6, 'Sedang', 'Membolos jam ke-3 dan ke-4', 'Ditemukan di kantin saat jam pelajaran.', 'Proses');
            $bk('kasus', 15, 9, 'Pribadi', 'Konseling masalah belajar', 'Siswa merasa kesulitan mengikuti pelajaran produktif.', 'Proses', true, ['layanan' => 'Konseling individu']);
            $bk('prestasi', 3, 12, 'Kabupaten/Kota', 'Lomba Poster Digital', null, null, false, ['peringkat' => 'Juara 2']);
            $bk('home_visit', 14, 5, 'Kunjungan rumah', 'Kunjungan ke rumah siswa', 'Orang tua bersedia memantau kehadiran anak.', 'Selesai', false, ['alamat' => 'Dusun Krajan, Pakusari']);
        }

        if (\App\Support\DbUpdate::tuReady()) {
            $y = (int) substr($today, 0, 4);
            $surat = [
                ['masuk', 'biasa', 1, 18, 'Dinas Pendidikan Kab. Jember', 'Undangan rapat koordinasi kepala sekolah', 'Diproses', 'Kepala Sekolah'],
                ['masuk', 'biasa', 2, 9, 'Kemenag Kab. Jember', 'Pemberitahuan jadwal asesmen', 'Baru', null],
                ['keluar', 'aktif', 1, 14, 'Orang tua/wali', 'Surat Keterangan Siswa Aktif', null, null],
                ['keluar', 'biasa', 2, 7, 'Dunia Usaha/Industri', 'Permohonan tempat PKL', null, null],
            ];
            foreach ($surat as [$arah, $jenis, $urut, $d, $pihak, $perihal, $st, $disp]) {
                \App\Models\TuSurat::create(['arah' => $arah, 'jenis' => $jenis, 'urut' => $urut, 'tahun' => $y,
                    'nomor' => $arah === 'keluar' ? \App\Services\TuService::nomor($jenis, $urut, $ago($d)) : null, 'tanggal' => $ago($d),
                    'pihak' => $pihak, 'perihal' => $perihal, 'status' => $st, 'disposisi' => $disp, 'dicatat_oleh' => $ids['bu.tata']]);
            }
            $self = \App\Support\DbUpdate::selfReady();
            foreach ([10, 9, 8, 7, 6, 5, 4, 3, 2, 1] as $d) {
                if (in_array((int) gmdate('w', strtotime($ago($d).' UTC')), [0, 6], true)) {
                    continue;
                }
                foreach (['ibu.siti', 'pak.hendra', 'bu.ratna', 'bu.maya', 'bu.tata'] as $un) {
                    $r = mt_rand(1, 100);
                    $st = $r <= 86 ? 'H' : ($r <= 92 ? 'S' : ($r <= 96 ? 'I' : 'D'));
                    $row = ['user_id' => $ids[$un], 'tanggal' => $ago($d), 'status' => $st, 'notes' => $st === 'S' ? 'Sakit' : ($st === 'D' ? 'Tugas dinas' : null), 'dicatat_oleh' => $ids['bu.tata']];
                    if ($self && $st === 'H') {
                        $late = mt_rand(1, 100) <= 20;
                        $row += ['jam_masuk' => $late ? '07:'.mt_rand(20, 50) : '06:'.mt_rand(30, 59), 'jam_pulang' => '15:'.sprintf('%02d', mt_rand(0, 30)), 'terlambat' => $late, 'sumber' => 'mandiri'];
                    }
                    \App\Models\StaffAttendance::create($row);
                }
            }
        }
    }
}
