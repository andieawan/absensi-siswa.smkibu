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
        (new DatabaseSeeder)->setCommand($this->command ?? new \Illuminate\Console\Command)->run();
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
        $this->command?->info("Data contoh dibuat: 4 kelas, $sid siswa, absensi 4 minggu terakhir.");
    }
}
