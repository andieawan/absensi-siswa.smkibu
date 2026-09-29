<?php
declare(strict_types=1);

$user = Web::requireLogin();
$actor = Web::actor();
$classes = Web::classesFor($user, false);
$subjects = array_values(array_filter(Web::subjectsFor($user), fn($s) => $s['id'] !== 5));
$classId = (int) ($_REQUEST['class'] ?? ($classes[0]['id'] ?? 0));
$subjectId = (int) ($_REQUEST['subject'] ?? ($subjects[0]['id'] ?? 0));
$tab = in_array($_GET['tab'] ?? 'input', ['input', 'aktivitas', 'rekap'], true) ? ($_GET['tab'] ?? 'input') : 'input';
$actId = (string) ($_REQUEST['act'] ?? '');

$self = fn(array $o = []) => Web::url('nilai', array_merge(['class' => $classId, 'subject' => $subjectId, 'tab' => $tab === 'input' ? null : $tab], $o));

if (Web::isPost()) {
    $do = (string) ($_POST['do'] ?? '');
    try {
        if ($do === 'save') {
            $scores = (array) ($_POST['nilai'] ?? []);
            $id = Svc::saveGrades($actor, $actId !== '' ? $actId : null, $classId, $subjectId, (string) ($_POST['nama'] ?? ''), (string) ($_POST['tanggal'] ?? ''), (string) ($_POST['tipe'] ?? 'angka'), $scores);
            Web::flash('success', 'Penilaian "' . trim((string) $_POST['nama']) . '" berhasil disimpan untuk ' . count($scores) . ' siswa.');
            Web::redirect($self(['tab' => 'aktivitas']));
        } elseif ($do === 'delete') {
            Svc::deleteGradeActivity($actor, (string) ($_POST['id'] ?? ''));
            Web::flash('success', 'Kegiatan penilaian dihapus.');
            Web::redirect($self(['tab' => 'aktivitas']));
        }
    } catch (UserError $e) {
        Web::flash('error', $e->getMessage());
    }
    Web::redirect($self(['act' => $actId ?: null]));
}

$students = array_values(array_filter(Repo::studentsAll($classId), fn($s) => $s['status'] === 'aktif'));
usort($students, fn($a, $b) => strcasecmp($a['nama'], $b['nama']));
$acts = array_values(array_filter(Repo::gradeActivitiesAll(), fn($a) => $a['class_id'] === $classId && $a['subject_id'] === $subjectId));
usort($acts, fn($a, $b) => strcmp($b['tanggal_kegiatan'], $a['tanggal_kegiatan']));
$vals = [];
foreach (Repo::gradeValuesAll() as $v) $vals[$v['activity_id']][$v['student_id']] = $v['nilai'];
$auth = ($classId && $subjectId) ? Rules::teacherAuthorization($user['id'], $subjectId, $classId, $user['roles']) : ['allowed' => false, 'error' => 'Pilih kelas dan mata pelajaran.'];

$editing = null;
foreach ($acts as $a) if ($a['id'] === $actId) $editing = $a;
$tipe = $editing['tipe_skala'] ?? 'angka';

Web::head('Nilai', 'nilai');
?>
<div class="head">
  <div><h1>Penilaian Siswa</h1><small>Nilai angka 0–100 atau huruf A–E. Kegiatan dapat dihapus dalam batas 7 hari.</small></div>
  <div class="seg">
    <a class="<?= $tab === 'input' ? 'on' : '' ?>" href="<?= h($self(['tab' => null, 'act' => null])) ?>">Input Nilai</a>
    <a class="<?= $tab === 'aktivitas' ? 'on' : '' ?>" href="<?= h($self(['tab' => 'aktivitas'])) ?>">Kegiatan (<?= count($acts) ?>)</a>
    <a class="<?= $tab === 'rekap' ? 'on' : '' ?>" href="<?= h($self(['tab' => 'rekap'])) ?>">Rekap</a>
  </div>
</div>

<form method="get" class="card">
  <input type="hidden" name="p" value="nilai"><?php if ($tab !== 'input'): ?><input type="hidden" name="tab" value="<?= h($tab) ?>"><?php endif; ?>
  <div class="fields">
    <div><label for="class">Kelas</label><select id="class" name="class" data-auto><?php foreach ($classes as $c): ?><option value="<?= $c['id'] ?>" <?= $c['id'] === $classId ? 'selected' : '' ?>><?= h($c['name']) ?></option><?php endforeach; ?></select></div>
    <div><label for="subject">Mata Pelajaran</label><select id="subject" name="subject" data-auto><?php foreach ($subjects as $s): ?><option value="<?= $s['id'] ?>" <?= $s['id'] === $subjectId ? 'selected' : '' ?>><?= h($s['name']) ?></option><?php endforeach; ?></select></div>
  </div>
</form>
<?php if (!$auth['allowed']): ?><?= Web::alert('warn', $auth['error'] ?? 'Tidak berwenang.') ?><?php endif; ?>

<?php if ($tab === 'input'): ?>
<form method="post" class="card card-tight" action="<?= h($self(['act' => $actId ?: null])) ?>">
  <?= Web::csrfField() ?><input type="hidden" name="do" value="save"><input type="hidden" name="act" value="<?= h($editing['id'] ?? '') ?>">
  <div style="padding:16px;border-bottom:1px solid var(--line)">
    <?php if ($editing): ?><?= Web::alert('info', 'Mengedit kegiatan: ' . $editing['nama_kegiatan'] . ' — menyimpan akan menimpa nilai lama.') ?><?php endif; ?>
    <div class="fields">
      <div><label for="nama">Nama Kegiatan</label><input id="nama" type="text" name="nama" value="<?= h($editing['nama_kegiatan'] ?? '') ?>" placeholder="mis. Ulangan Harian 1" maxlength="120" required></div>
      <div><label for="tanggal">Tanggal</label><input id="tanggal" type="date" name="tanggal" value="<?= h($editing['tanggal_kegiatan'] ?? Web::today()) ?>" required></div>
      <div><label for="tipe_skala">Skala</label><select id="tipe_skala" name="tipe"><option value="angka" <?= $tipe === 'angka' ? 'selected' : '' ?>>Angka (0–100)</option><option value="huruf" <?= $tipe === 'huruf' ? 'selected' : '' ?>>Huruf (A–E)</option></select></div>
    </div>
    <div class="row" style="margin-top:10px"><small>Isi cepat:</small>
      <?php foreach (['100', '85', '75'] as $q): ?><button type="button" class="btn btn-sm" data-quickfill="<?= $q ?>"><?= $q ?></button><?php endforeach; ?>
      <?php foreach (['A', 'B', 'C'] as $q): ?><button type="button" class="btn btn-sm" data-quickfill="<?= $q ?>"><?= $q ?></button><?php endforeach; ?>
    </div>
  </div>
  <?php if (!$students): ?><div class="empty">Tidak ada siswa aktif di kelas ini.</div><?php endif; ?>
  <?php foreach ($students as $i => $s): $cur = $editing ? ($vals[$editing['id']][$s['id']] ?? '') : ''; ?>
  <div class="stu"><span class="no mono"><?= $i + 1 ?></span>
    <div class="nm"><b><?= h($s['nama']) ?></b><small><?= h($s['nis']) ?></small></div>
    <input class="score mono" style="width:100px;text-align:center" type="text" name="nilai[<?= $s['id'] ?>]" value="<?= h($cur) ?>" aria-label="Nilai <?= h($s['nama']) ?>" autocomplete="off">
  </div>
  <?php endforeach; ?>
  <div class="sticky-save"><small>Nilai kosong disimpan sebagai 0 (angka) atau C (huruf).</small>
    <div class="row"><?php if ($editing): ?><a class="btn" href="<?= h($self(['tab' => null, 'act' => null])) ?>">Batal Edit</a><?php endif; ?>
    <button class="btn btn-pri" <?= $auth['allowed'] && $students ? '' : 'disabled' ?>>Simpan Nilai</button></div></div>
</form>

<?php elseif ($tab === 'aktivitas'): ?>
<div class="card card-tight">
  <?php if (!$acts): ?><div class="empty">Belum ada kegiatan penilaian untuk kombinasi ini.</div><?php else: ?>
  <div class="scroll"><table class="tbl"><thead><tr><th>Tanggal</th><th>Kegiatan</th><th>Skala</th><th class="c">Siswa</th><th class="c">Rata-rata</th><th class="r">Aksi</th></tr></thead><tbody>
  <?php foreach ($acts as $a):
      $v = $vals[$a['id']] ?? [];
      $nums = array_filter($v, 'is_numeric');
      $avg = $a['tipe_skala'] === 'angka' && $nums ? number_format(array_sum($nums) / count($nums), 1) : '-';
      $diff = Util::daysSince((int) Util::parseSessionDate($a['tanggal_kegiatan']));
      $canDel = $diff <= 7 || Web::isAdmin(); ?>
    <tr><td class="mono"><?= h($a['tanggal_kegiatan']) ?></td><td><b><?= h($a['nama_kegiatan']) ?></b></td><td><?= h($a['tipe_skala']) ?></td>
      <td class="c mono"><?= count($v) ?></td><td class="c mono"><b><?= $avg ?></b></td>
      <td class="r"><div class="row row-end">
        <a class="btn btn-sm" href="<?= h($self(['tab' => null, 'act' => $a['id']])) ?>">Edit</a>
        <?php if ($canDel): ?><form method="post" class="inline" action="<?= h($self()) ?>" data-confirm="Hapus kegiatan &quot;<?= h($a['nama_kegiatan']) ?>&quot; beserta seluruh nilainya?"><?= Web::csrfField() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="id" value="<?= h($a['id']) ?>"><button class="btn btn-sm btn-danger">Hapus</button></form>
        <?php else: ?><span class="badge">🔒 &gt; 7 hari</span><?php endif; ?></div></td></tr>
  <?php endforeach; ?></tbody></table></div><?php endif; ?>
</div>

<?php else:
    $ordered = array_reverse($acts); ?>
<div class="card card-tight">
  <div class="row" style="padding:14px 16px;justify-content:space-between;border-bottom:1px solid var(--line)"><h2>Rekap Nilai</h2>
    <a class="btn btn-sm" href="<?= h(Web::url('export', ['type' => 'nilai', 'class' => $classId, 'subject' => $subjectId])) ?>">Unduh Excel</a></div>
  <?php if (!$acts): ?><div class="empty">Belum ada data nilai.</div><?php else: ?>
  <div class="scroll"><table class="tbl mx"><thead><tr><th>Nama</th><?php foreach ($ordered as $a): ?><th class="c" title="<?= h($a['tanggal_kegiatan']) ?>"><?= h($a['nama_kegiatan']) ?></th><?php endforeach; ?><th class="c">Rata-rata (angka)</th></tr></thead><tbody>
  <?php foreach ($students as $s): $sum = 0.0; $n = 0; ?>
    <tr><td><b><?= h($s['nama']) ?></b><br><small class="mono"><?= h($s['nis']) ?></small></td>
    <?php foreach ($ordered as $a): $v = $vals[$a['id']][$s['id']] ?? '-';
        if ($a['tipe_skala'] === 'angka' && is_numeric($v)) { $sum += (float) $v; $n++; }
        $low = (is_numeric($v) && (float) $v < 70) || in_array($v, ['D', 'E'], true); ?>
      <td class="g <?= $low ? 'low' : '' ?>"><?= h($v) ?></td>
    <?php endforeach; ?>
    <td class="g"><b><?= $n ? number_format($sum / $n, 1) : '-' ?></b></td></tr>
  <?php endforeach; ?></tbody></table></div><?php endif; ?>
</div>
<?php endif; ?>
<?php Web::foot();
