<?php
declare(strict_types=1);

$user = Web::requireLogin();
if (!Web::has('bk') && !Web::isAdmin()) {
    http_response_code(403);
    Web::head('Akses Ditolak', 'bk');
    echo '<div class="card"><h1>Akses ditolak</h1><p>Halaman Integrasi BK khusus Guru BK dan Administrator.</p></div>';
    Web::foot();
    exit;
}
$actor = Web::actor();
$classes = Repo::classesAll();
$classId = (int) ($_REQUEST['class'] ?? ($classes[0]['id'] ?? 0));
$self = fn(array $o = []) => Web::url('bk', array_merge(['class' => $classId], $o));

if (Web::isPost()) {
    try {
        $sid = (int) ($_POST['student'] ?? 0);
        $stt = (string) ($_POST['status'] ?? '');
        $st = Repo::studentById($sid);
        if (!$st) throw new UserError('Pilih siswa terlebih dahulu.');
        $r = Svc::submitAttendance($actor, $st['class_id'], null, (string) ($_POST['date'] ?? ''), [['student_id' => $sid, 'status' => $stt, 'notes' => (string) ($_POST['notes'] ?? '')]], 'bk_manual');
        Web::flash('success', "Absensi manual BK untuk {$st['nama']} tersimpan (" . ($r['created'] ? 'baru' : 'diperbarui') . ').');
    } catch (UserError $e) {
        Web::flash('error', $e->getMessage());
    }
    Web::redirect($self());
}

$students = array_values(array_filter(Repo::studentsAll($classId), fn($s) => $s['status'] === 'aktif'));
usort($students, fn($a, $b) => strcasecmp($a['nama'], $b['nama']));
$recs = Repo::attendanceQuery($classId);
$cnt = [];
foreach ($recs as $r) {
    $cnt[$r['student_id']][$r['status']] = ($cnt[$r['student_id']][$r['status']] ?? 0) + 1;
}
$recap = array_map(function ($s) use ($cnt) {
    $c = $cnt[$s['id']] ?? [];
    $i = $c['I'] ?? 0; $sk = $c['S'] ?? 0; $a = $c['A'] ?? 0;
    return ['s' => $s, 'h' => $c['H'] ?? 0, 'i' => $i, 'sk' => $sk, 'a' => $a, 'abs' => $i + $sk + $a];
}, $students);
usort($recap, fn($x, $y) => $y['abs'] <=> $x['abs']);

Web::head('Integrasi BK', 'bk');
?>
<div class="head"><div><h1>Integrasi Bimbingan Konseling</h1><small>Input absensi manual oleh BK dan rekap ketidakhadiran per kelas.</small></div>
  <a class="btn btn-sm" href="<?= h(Web::url('export', ['type' => 'bk', 'class' => $classId])) ?>">Unduh Rekap Excel</a></div>

<form method="get" class="card"><input type="hidden" name="p" value="bk">
  <div class="fields"><div><label for="class">Kelas</label><select id="class" name="class" data-auto><?php foreach ($classes as $c): ?><option value="<?= $c['id'] ?>" <?= $c['id'] === $classId ? 'selected' : '' ?>><?= h($c['name']) ?></option><?php endforeach; ?></select></div></div>
</form>

<form method="post" class="card" action="<?= h($self()) ?>">
  <?= Web::csrfField() ?>
  <h2>Input Absensi Manual (BK)</h2>
  <div class="fields">
    <div><label for="student">Siswa</label><select id="student" name="student"><?php foreach ($students as $s): ?><option value="<?= $s['id'] ?>"><?= h($s['nama']) ?> (<?= h($s['nis']) ?>)</option><?php endforeach; ?></select></div>
    <div><label for="status">Status</label><select id="status" name="status"><option value="I">Izin (I)</option><option value="S">Sakit (S)</option><option value="A">Alpa (A)</option><option value="H">Hadir (H)</option></select></div>
    <div><label for="date">Tanggal</label><input id="date" type="date" name="date" value="<?= h(Web::today()) ?>" max="<?= h(Web::today()) ?>" required></div>
    <div style="grid-column:span 2"><label for="notes">Catatan</label><input id="notes" type="text" name="notes" maxlength="200" placeholder="mis. Konseling BK: izin dispensasi pendampingan keluarga"></div>
    <div><button class="btn btn-pri" <?= $students ? '' : 'disabled' ?>>Simpan Absensi BK</button></div>
  </div>
</form>

<div class="card card-tight"><div style="padding:14px 16px"><h2>Rekap Ketidakhadiran Kelas</h2><small>Diurutkan dari ketidakhadiran terbanyak</small></div>
  <?php if (!$recap): ?><div class="empty">Tidak ada siswa aktif.</div><?php else: ?>
  <div class="scroll"><table class="tbl"><thead><tr><th>Nama</th><th>NIS</th><th class="c">H</th><th class="c">I</th><th class="c">S</th><th class="c">A</th><th class="c">Total Absen</th><th class="r">Aksi</th></tr></thead><tbody>
  <?php foreach ($recap as $r): ?>
    <tr><td><b><?= h($r['s']['nama']) ?></b></td><td class="mono mut"><?= h($r['s']['nis']) ?></td><td class="c mono"><?= $r['h'] ?></td><td class="c mono"><?= $r['i'] ?></td><td class="c mono warn"><?= $r['sk'] ?></td><td class="c mono bad"><?= $r['a'] ?></td><td class="c mono"><b><?= $r['abs'] ?></b></td>
      <td class="r"><a href="<?= h(Web::url('siswa', ['id' => $r['s']['id']])) ?>">Riwayat</a> · <a target="_blank" href="<?= h(Web::url('surat', ['type' => 'panggilan', 'student' => $r['s']['id']])) ?>">Surat panggilan</a></td></tr>
  <?php endforeach; ?></tbody></table></div><?php endif; ?>
</div>
<?php Web::foot();
