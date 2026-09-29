<?php
declare(strict_types=1);

// Data contoh untuk mencoba aplikasi: php cli/seed_demo.php
// Hanya berjalan bila belum ada data kelas. Password akun contoh dicetak di layar.
require dirname(__DIR__) . '/app/bootstrap.php';
if (PHP_SAPI !== 'cli') exit('Jalankan lewat CLI.');

Db::pdo();
if (Repo::classesAll()) {
    fwrite(STDERR, "Sudah ada data kelas — seed dibatalkan.\n");
    exit(1);
}
mt_srand(42);
$classes = [
    [1, 'XI DKV 1', 'Desain Komunikasi Visual', '2025', 'DKV'], [2, 'X RPL 1', 'Rekayasa Perangkat Lunak', '2026', 'RPL'],
    [3, 'XII TKJ 2', 'Teknik Komputer Jaringan', '2024', 'TKJ'], [4, 'X AKL 1', 'Akuntansi & Keuangan Lembaga', '2026', 'AKL'],
];
foreach ($classes as [$id, $name, $jur, $ang]) Repo::classUpsert(['id' => $id, 'name' => $name, 'jurusan' => $jur, 'angkatan' => $ang, 'tahun_ajaran' => '2026/2027', 'semester' => 'Ganjil']);
foreach ([[1, 'Bahasa Indonesia'], [2, 'Matematika'], [3, 'Pemrograman Web'], [4, 'Desain Grafis Percetakan'], [5, 'Bimbingan Konseling']] as [$id, $n]) Repo::subjectUpsert(['id' => $id, 'name' => $n]);

$names = ['Achmad Fauzi', 'Adinda Putri', 'Bayu Arya', 'Cantika Dewi', 'Dimas Kurnia', 'Eka Nurul', 'Fajar Maulana', 'Gita Pertiwi', 'Hasan Basri', 'Intan Permata', 'Joko Santoso', 'Kartika Sari'];
$sid = 0;
foreach ($classes as [$cid, , , , $code]) {
    foreach ($names as $i => $n) {
        $sid++;
        Repo::studentUpsert(['id' => $sid, 'nis' => sprintf('26.%02d/%s/%03d', $cid, $code, $i + 1), 'nama' => $n . ' ' . ['Putra', 'Sari', 'Wijaya'][$i % 3], 'jk' => $i % 2 ? 'P' : 'L', 'class_id' => $cid, 'status' => 'aktif']);
    }
}
$setSeq = function (string $entity, int $min): void {
    $row = Db::get('SELECT value FROM sequences WHERE entity = ?', [$entity]);
    if (!$row) Db::run('INSERT INTO sequences (entity, value) VALUES (?, ?)', [$entity, max(1000, $min)]);
    elseif ((int) $row['value'] < $min) Db::run('UPDATE sequences SET value = ? WHERE entity = ?', [$min, $entity]);
};
$setSeq('students', $sid);
$setSeq('classes', 4);
$setSeq('subjects', 5);

$accounts = [
    ['ibu.siti', 'Siti Aminah, S.Pd.', ['guru'], 1, [1], [1, 2]], ['pak.hendra', 'Hendra Pratama, S.Si.', ['guru'], 2, [2], [1, 2, 3]],
    ['bu.ratna', 'Dra. Ratna Kusuma, M.Pd.', ['kepsek'], null, [], []], ['bu.maya', 'Maya Rosita, S.Psi.', ['bk'], null, [5], [1, 2, 3, 4]],
];
$uid = 1;
echo "Akun contoh (password acak):\n";
foreach ($accounts as [$un, $nama, $roles, $wali, $subj, $cls]) {
    $uid = max($uid, 1) + 1;
    while (Repo::userById($uid)) $uid++;
    $pw = 'Demo-' . bin2hex(random_bytes(4));
    Repo::userUpsert(['id' => $uid, 'username' => $un, 'password_hash' => Util::hashPassword($pw), 'nama' => $nama, 'kelas_wali_id' => $wali, 'is_active' => true, 'roles' => $roles, 'subjects' => $subj, 'classes' => $cls]);
    echo sprintf("  %-12s %s\n", $un, $pw);
}
foreach ([[2, 1, 1], [2, 1, 2], [3, 2, 1], [3, 2, 2]] as [$u, $s, $c]) Repo::pairingAdd($u, $s, $c);

// Absensi harian 4 minggu terakhir untuk semua kelas
$today = time();
for ($d = 28; $d >= 1; $d--) {
    $ts = $today - $d * 86400;
    if ((int) gmdate('w', $ts) === 0 || (int) gmdate('w', $ts) === 6) continue;
    $tgl = gmdate('Y-m-d', $ts);
    foreach (Repo::studentsAll() as $s) {
        $r = mt_rand(1, 100);
        $st = $r <= 88 ? 'H' : ($r <= 93 ? 'S' : ($r <= 97 ? 'I' : 'A'));
        Repo::attendanceUpsert($s['id'], $s['class_id'], null, $tgl, $st, null, 1, 'wali');
    }
}
echo "Selesai: 4 kelas, " . $sid . " siswa, absensi 4 minggu terakhir.\n";
