<?php
declare(strict_types=1);

// Surat resmi siap cetak (Ctrl+P / Simpan sebagai PDF). Kolom isian bisa diubah sebelum mencetak.
$user = Web::requireLogin();
$type = (string) ($_GET['type'] ?? '');
$settings = Repo::settingsGet();
$place = (string) ($_GET['tempat'] ?? '');

$allowedFor = function (int $classId) use ($user): bool {
    return Web::isAdmin() || Web::has('bk') || Web::has('kepsek') || (($user['kelas_wali_id'] ?? null) === $classId);
};

if ($type === 'laporan') {
    $classId = (int) ($_GET['class'] ?? 0);
    $subjectId = (int) ($_GET['subject'] ?? 0);
    $class = Repo::classById($classId);
    $subject = Db::get('SELECT name FROM subjects WHERE id = ?', [$subjectId]);
    if (!$class || !$subject || !(Rules::teacherAuthorization($user['id'], $subjectId, $classId, $user['roles'])['allowed'] || $allowedFor($classId))) {
        Web::flash('error', 'Laporan tidak dapat dibuat: kelas/mapel tidak valid atau Anda tidak berwenang.');
        Web::redirect(Web::url('nilai'));
    }
    $students = array_values(array_filter(Repo::studentsAll($classId), fn($s) => $s['status'] === 'aktif'));
    usort($students, fn($a, $b) => strcasecmp($a['nama'], $b['nama']));
    $acts = array_values(array_filter(Repo::gradeActivitiesAll(), fn($a) => $a['class_id'] === $classId && $a['subject_id'] === $subjectId));
    $body = Letters::semesterReport($class, $subject['name'], $students, Repo::attendanceQuery($classId, $subjectId), $acts, Repo::gradeValuesAll(), $settings);
    $toolbar = '';
    $title = 'Laporan ' . $class['name'];
} else {
    $student = Repo::studentById((int) ($_GET['student'] ?? 0));
    if (!$student || !$allowedFor($student['class_id'])) {
        Web::flash('error', 'Surat tidak dapat dibuat: siswa tidak ditemukan atau Anda tidak berwenang (wali kelas siswa / BK / Admin).');
        Web::redirect(Web::url('siswa'));
    }
    $class = Repo::classById($student['class_id']);
    $cn = $class['name'] ?? '-';
    $f = fn(string $k) => (string) ($_GET[$k] ?? '');
    if ($type === 'panggilan') {
        $body = Letters::summons($student, $cn, $settings, $place, $f('tgl'), $f('jam'), $f('ruang'), $f('alasan'));
        $title = 'Surat Panggilan Orang Tua - ' . $student['nama'];
        $toolbar = '<label>Hari/Tanggal<input type="text" name="tgl" value="' . h($f('tgl')) . '" placeholder="Senin, 6 Oktober 2026"></label>'
            . '<label>Jam<input type="text" name="jam" value="' . h($f('jam')) . '" placeholder="09.00"></label>'
            . '<label>Ruang<input type="text" name="ruang" value="' . h($f('ruang')) . '" placeholder="BK"></label>'
            . '<label>Alasan<input type="text" name="alasan" value="' . h($f('alasan')) . '" placeholder="ketidakhadiran berulang"></label>';
    } else {
        $type = 'peringatan';
        $body = Letters::warning($student, $cn, Repo::attendanceForStudent($student['id']), $settings, $place);
        $title = 'Surat Peringatan Presensi - ' . $student['nama'];
        $toolbar = '';
    }
    Repo::audit('Cetak Surat', 'Siswa', $user['nama'], "$title ($type)");
}

Web::head($title, 'siswa');
if (isset($student)): ?>
<form method="get" class="card noprint">
  <input type="hidden" name="p" value="surat"><input type="hidden" name="type" value="<?= h($type) ?>"><input type="hidden" name="student" value="<?= $student['id'] ?>">
  <div class="fields">
    <label>Tempat surat<input type="text" name="tempat" value="<?= h($place) ?>" placeholder="mis. Pakusari"></label>
    <?= $toolbar ?>
    <div class="row"><button class="btn">Perbarui</button><button type="button" class="btn btn-pri" onclick="window.print()">Cetak / Simpan PDF</button></div>
  </div>
  <small>Alamat dan NIP tidak diisi otomatis; lengkapi manual setelah dicetak bila diperlukan.</small>
</form>
<?php else: ?>
<div class="row noprint" style="margin-bottom:12px"><button class="btn btn-pri" onclick="window.print()">Cetak / Simpan PDF</button></div>
<?php endif; ?>
<article class="letter"><?= $body ?></article>
<?php Web::foot();
