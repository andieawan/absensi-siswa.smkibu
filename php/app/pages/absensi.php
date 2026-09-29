<?php
declare(strict_types=1);

$user = Web::requireLogin();
$actor = Web::actor();
$isWali = ($user['kelas_wali_id'] ?? null) !== null;

$mode = (string) ($_REQUEST['mode'] ?? ($isWali ? 'wali' : 'mapel'));
if (!in_array($mode, ['wali', 'mapel'], true)) $mode = 'wali';
$classes = Web::classesFor($user, $mode === 'wali');
$subjects = Web::subjectsFor($user);
if ($mode === 'mapel') $subjects = array_values(array_filter($subjects, fn($s) => $s['id'] !== 5));

$classId = (int) ($_REQUEST['class'] ?? ($classes[0]['id'] ?? 0));
if ($mode === 'wali' && !Util::hasAdminRole($user['roles']) && $isWali) $classId = (int) $user['kelas_wali_id'];
$subjectId = $mode === 'mapel' ? (int) ($_REQUEST['subject'] ?? ($subjects[0]['id'] ?? 0)) : null;
$tanggal = (string) ($_REQUEST['date'] ?? Web::today());
if (Util::parseSessionDate($tanggal) === null) $tanggal = Web::today();
$tab = ($_GET['tab'] ?? 'input') === 'riwayat' ? 'riwayat' : 'input';

$self = fn(array $o = []) => Web::url('absensi', array_merge(['mode' => $mode, 'class' => $classId, 'subject' => $subjectId, 'date' => $tanggal, 'tab' => $tab === 'input' ? null : $tab], $o));

if (Web::isPost()) {
    $do = (string) ($_POST['do'] ?? '');
    try {
        if ($do === 'save') {
            $entries = [];
            foreach ((array) ($_POST['status'] ?? []) as $sid => $st) {
                $entries[] = ['student_id' => (int) $sid, 'status' => (string) $st, 'notes' => (string) ($_POST['notes'][$sid] ?? '')];
            }
            $r = Svc::submitAttendance($actor, $classId, $subjectId, $tanggal, $entries, $mode === 'wali' ? 'wali' : 'guru');
            $msg = "Presensi tersimpan ({$r['created']} baru, {$r['updated']} diperbarui).";
            if ($r['alerts']) $msg .= ' Peringatan 85%: ' . implode('; ', array_slice($r['alerts'], 0, 5)) . (count($r['alerts']) > 5 ? '; …' : '');
            Web::flash($r['alerts'] ? 'warn' : 'success', $msg);
        } elseif ($do === 'delete') {
            $d = (string) ($_POST['tgl'] ?? '');
            $n = Svc::deleteSession($actor, $classId, $subjectId, $d);
            Web::flash('success', "Sesi $d dihapus ($n data).");
            Web::redirect($self(['tab' => 'riwayat', 'date' => $tanggal]));
        } elseif ($do === 'delegasi') {
            $t = Svc::createDelegation($actor, $classId, (int) ($_POST['hours'] ?? 24));
            Web::redirect($self(['tab' => 'riwayat', 'newtok' => $t['token']]));
        }
    } catch (UserError $e) {
        Web::flash('error', $e->getMessage());
    }
    Web::redirect($self());
}

$students = array_values(array_filter(Repo::studentsAll($classId), fn($s) => $s['status'] === 'aktif'));
usort($students, fn($a, $b) => strcasecmp($a['nama'], $b['nama']));
$existing = [];
foreach (Repo::attendanceQuery($classId, $subjectId, $tanggal) as $r) $existing[$r['student_id']] = $r;
$auth = $classId ? Rules::teacherAuthorization($user['id'], $subjectId, $classId, $user['roles']) : ['allowed' => false, 'error' => 'Belum ada kelas yang bisa dipilih.'];
$classObj = $classId ? Repo::classById($classId) : null;

Web::head('Absensi', 'absensi');
?>
<div class="head">
  <div>
    <h1>Absensi <?= $mode === 'wali' ? 'Harian (Wali Kelas)' : 'Per Mata Pelajaran' ?></h1>
    <small><?= h($classObj['name'] ?? '-') ?> · <?= h(Web::fmtDate($tanggal)) ?></small>
  </div>
  <div class="row">
    <div class="seg">
      <?php if ($isWali || Web::isAdmin()): ?><a class="<?= $mode === 'wali' ? 'on' : '' ?>" href="<?= h(Web::url('absensi', ['mode' => 'wali'])) ?>">Wali Kelas</a><?php endif; ?>
      <a class="<?= $mode === 'mapel' ? 'on' : '' ?>" href="<?= h(Web::url('absensi', ['mode' => 'mapel'])) ?>">Per Mapel</a>
    </div>
    <div class="seg">
      <a class="<?= $tab === 'input' ? 'on' : '' ?>" href="<?= h($self(['tab' => null])) ?>">Input</a>
      <a class="<?= $tab === 'riwayat' ? 'on' : '' ?>" href="<?= h($self(['tab' => 'riwayat'])) ?>">Riwayat &amp; Delegasi</a>
    </div>
  </div>
</div>

<form method="get" class="card">
  <input type="hidden" name="p" value="absensi"><input type="hidden" name="mode" value="<?= h($mode) ?>"><?php if ($tab !== 'input'): ?><input type="hidden" name="tab" value="<?= h($tab) ?>"><?php endif; ?>
  <div class="fields">
    <div><label for="class">Kelas</label>
      <select id="class" name="class" data-auto><?php foreach ($classes as $c): ?><option value="<?= $c['id'] ?>" <?= $c['id'] === $classId ? 'selected' : '' ?>><?= h($c['name']) ?></option><?php endforeach; ?></select></div>
    <?php if ($mode === 'mapel'): ?>
    <div><label for="subject">Mata Pelajaran</label>
      <select id="subject" name="subject" data-auto><?php foreach ($subjects as $s): ?><option value="<?= $s['id'] ?>" <?= $s['id'] === $subjectId ? 'selected' : '' ?>><?= h($s['name']) ?></option><?php endforeach; ?></select></div>
    <?php endif; ?>
    <div><label for="date">Tanggal</label><input id="date" type="date" name="date" value="<?= h($tanggal) ?>" max="<?= h(Web::today()) ?>" data-auto onchange="this.form.submit()"></div>
    <noscript><div><button class="btn">Tampilkan</button></div></noscript>
  </div>
</form>

<?php if (!$auth['allowed']): ?><?= Web::alert('warn', $auth['error'] ?? 'Anda tidak berwenang pada kelas/mapel ini.') ?><?php endif; ?>

<?php if ($tab === 'input'): ?>
  <?php if (!$students): ?>
    <div class="card"><div class="empty">Tidak ada siswa aktif di kelas ini.</div></div>
  <?php else: ?>
  <?php if ($existing): ?><?= Web::alert('info', 'Sesi ini sudah tercatat. Menyimpan lagi akan memperbarui data (UPSERT), bukan menggandakan.') ?><?php endif; ?>
  <form method="post" class="card card-tight" action="<?= h($self()) ?>">
    <?= Web::csrfField() ?><input type="hidden" name="do" value="save">
    <div class="row" style="padding:12px 14px;border-bottom:1px solid var(--line);justify-content:space-between">
      <div class="row"><button type="button" class="btn btn-sm" data-setall="H">Set Semua Hadir</button>
        <span id="recount" class="mono" style="font-size:12px">H <b data-c="H">0</b> · I <b data-c="I">0</b> · S <b data-c="S">0</b> · A <b data-c="A">0</b></span></div>
      <small><?= count($students) ?> siswa aktif</small>
    </div>
    <?php foreach ($students as $i => $s): $cur = $existing[$s['id']] ?? null; $st = $cur['status'] ?? 'H'; ?>
    <div class="stu">
      <span class="no mono"><?= $i + 1 ?></span>
      <div class="nm"><b><?= h($s['nama']) ?></b><small><?= h($s['nis']) ?> · <?= h($s['jk']) ?></small></div>
      <div class="hisa" role="radiogroup" aria-label="Status <?= h($s['nama']) ?>">
        <?php foreach (['H' => 'Hadir', 'I' => 'Izin', 'S' => 'Sakit', 'A' => 'Alpa'] as $k => $lbl): ?>
          <label title="<?= $lbl ?>"><input type="radio" name="status[<?= $s['id'] ?>]" value="<?= $k ?>" data-status="<?= $k ?>" <?= $st === $k ? 'checked' : '' ?>><span class="<?= $k ?>"><?= $k ?></span></label>
        <?php endforeach; ?>
      </div>
      <input class="note" type="text" name="notes[<?= $s['id'] ?>]" value="<?= h($cur['notes'] ?? '') ?>" placeholder="Catatan (opsional)" maxlength="200" aria-label="Catatan <?= h($s['nama']) ?>">
    </div>
    <?php endforeach; ?>
    <div class="sticky-save">
      <small>Aturan: input maksimal 7 hari ke belakang untuk non-admin.</small>
      <button class="btn btn-pri" <?= $auth['allowed'] ? '' : 'disabled' ?>>Simpan Presensi</button>
    </div>
  </form>
  <?php endif; ?>

<?php else:
    // ---- Riwayat sesi & delegasi ----
    $sessions = [];
    foreach (Repo::attendanceQuery($classId, $subjectId ?? null) as $r) {
        $k = $r['tanggal'];
        $sessions[$k] ??= ['tanggal' => $k, 'H' => 0, 'I' => 0, 'S' => 0, 'A' => 0, 'via' => $r['recorded_via']];
        $sessions[$k][$r['status']]++;
    }
    krsort($sessions);
    $newTok = (string) ($_GET['newtok'] ?? '');
    $tokens = array_values(array_filter(Repo::tokensAll(), fn($t) => $t['class_id'] === $classId && $t['status'] === 'aktif' && ($t['expires_at_millis'] ?? 0) > Util::nowMillis()));
    $viaLabel = ['guru' => 'Guru', 'wali' => 'Wali Kelas', 'ketua_kelas_delegasi' => 'Ketua Kelas', 'bk_manual' => 'BK Manual', 'upload_hardcopy' => 'Upload Hardcopy'];
?>
  <?php if ($mode === 'wali'): ?>
  <div class="card">
    <h2>Delegasi Ketua Kelas</h2>
    <p class="mut" style="font-size:12px">Buat tautan sementara (maks. 24 jam) agar ketua kelas dapat mengisi absen harian tanpa login.</p>
    <?php if ($newTok !== ''): $link = Web::absoluteUrl('delegasi', ['token' => $newTok]); ?>
      <?= Web::alert('success', 'Tautan delegasi dibuat. Bagikan ke ketua kelas:') ?>
      <code class="link" id="lnk"><?= h($link) ?></code>
      <p><button type="button" class="btn btn-sm" data-copy="#lnk">Salin Tautan</button></p>
    <?php endif; ?>
    <form method="post" class="row" action="<?= h($self()) ?>"><?= Web::csrfField() ?><input type="hidden" name="do" value="delegasi">
      <select name="hours" style="width:auto"><?php foreach ([1, 3, 6, 12, 24] as $hr): ?><option value="<?= $hr ?>" <?= $hr === 24 ? 'selected' : '' ?>>Berlaku <?= $hr ?> jam</option><?php endforeach; ?></select>
      <button class="btn btn-pri" <?= $auth['allowed'] ? '' : 'disabled' ?>>Buat Tautan Delegasi</button>
    </form>
    <?php if ($tokens): ?><p class="mut" style="font-size:12px;margin-top:12px"><?= count($tokens) ?> tautan masih aktif untuk kelas ini (kedaluwarsa otomatis).</p><?php endif; ?>
  </div>
  <?php endif; ?>

  <div class="card card-tight">
    <div class="row" style="padding:14px 16px;justify-content:space-between;border-bottom:1px solid var(--line)">
      <div><h2>Riwayat Sesi Absensi</h2><small>Sesi lebih dari 7 hari terkunci (hanya Admin yang bisa mengubah/menghapus).</small></div>
      <a class="btn btn-sm" href="<?= h(Web::url('export', ['type' => 'absensi', 'class' => $classId, 'subject' => $subjectId])) ?>">Unduh Excel</a>
    </div>
    <?php if (!$sessions): ?><div class="empty">Belum ada sesi tercatat.</div><?php else: ?>
    <div class="scroll"><table class="tbl"><thead><tr><th>Tanggal</th><th class="c">H</th><th class="c">I</th><th class="c">S</th><th class="c">A</th><th>Dicatat via</th><th class="r">Aksi</th></tr></thead><tbody>
    <?php foreach ($sessions as $s):
        $diff = Util::daysSince((int) Util::parseSessionDate($s['tanggal']));
        $locked = $diff > 7 && !Web::isAdmin(); ?>
      <tr>
        <td><a href="<?= h($self(['tab' => null, 'date' => $s['tanggal']])) ?>"><?= h(Web::fmtDate($s['tanggal'])) ?></a></td>
        <td class="c mono"><?= $s['H'] ?></td><td class="c mono"><?= $s['I'] ?></td><td class="c mono warn"><?= $s['S'] ?></td><td class="c mono bad"><?= $s['A'] ?></td>
        <td><?= h($viaLabel[$s['via']] ?? $s['via']) ?></td>
        <td class="r"><?php if ($locked): ?><span class="badge">🔒 Terkunci</span><?php else: ?>
          <form method="post" class="inline" action="<?= h($self()) ?>" data-confirm="Hapus seluruh presensi tanggal <?= h($s['tanggal']) ?>?"><?= Web::csrfField() ?><input type="hidden" name="do" value="delete"><input type="hidden" name="tgl" value="<?= h($s['tanggal']) ?>"><button class="btn btn-sm btn-danger">Hapus</button></form><?php endif; ?></td>
      </tr>
    <?php endforeach; ?></tbody></table></div>
    <?php endif; ?>
  </div>
<?php endif; ?>
<?php Web::foot();
