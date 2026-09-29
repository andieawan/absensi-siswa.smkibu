<?php
declare(strict_types=1);

// Riwayat Siswa (Student 360): profil, kehadiran, pola, uji 85%, nilai, akses wali murid, surat.
$user = Web::requireLogin();
$actor = Web::actor();
$classes = Repo::classesAll();
$classMap = array_column($classes, null, 'id');
$q = trim((string) ($_GET['q'] ?? ''));
$fc = (int) ($_GET['class'] ?? 0);
$sid = (int) ($_REQUEST['id'] ?? 0);

$self = fn(array $o = []) => Web::url('siswa', array_merge(['id' => $sid ?: null, 'q' => $q ?: null, 'class' => $fc ?: null], $o));

$student = $sid ? Repo::studentById($sid) : null;
$canParent = $student && (Web::isAdmin() || ($user['kelas_wali_id'] ?? null) === $student['class_id']);
$canLetter = $student && (Web::isAdmin() || Web::has('bk') || Web::has('kepsek') || ($user['kelas_wali_id'] ?? null) === $student['class_id']);

if (Web::isPost() && $student) {
    $do = (string) ($_POST['do'] ?? '');
    try {
        if ($do === 'clearance') {
            $r = Svc::clearance($actor, $student['id'], !empty($_POST['override']), (string) ($_POST['reason'] ?? ''));
            Web::flash('success', "Pengesahan akademik DISETUJUI. Kode: {$r['code']} · kehadiran {$r['rate']}%" . ($r['override'] ? ' (dengan dispensasi)' : '') . '.');
        } elseif ($do === 'parent_create') {
            if (!$canParent) throw new UserError('Akses wali murid hanya dapat dikelola Wali Kelas siswa ini atau Administrator.');
            $t = Repo::parentTokenCreate($student['id'], $user['id']);
            Repo::audit('Buat Akses Wali Murid', 'Siswa', $user['nama'], "Siswa #{$student['id']} ({$student['nama']})");
            Web::redirect($self(['newwm' => $t['token']]));
        } elseif ($do === 'parent_revoke') {
            if (!$canParent) throw new UserError('Tidak berwenang mencabut akses ini.');
            $tk = Repo::parentTokenByToken((string) ($_POST['token'] ?? ''));
            if (!$tk || $tk['student_id'] !== $student['id']) throw new UserError('Token tidak ditemukan.');
            if (Repo::parentTokenRevoke($tk['token'])) Repo::audit('Cabut Akses Wali Murid', 'Siswa', $user['nama'], "Siswa #{$student['id']} ({$student['nama']})");
            Web::flash('success', 'Akses wali murid dicabut.');
        }
    } catch (UserError $e) {
        Web::flash('error', $e->getMessage());
    }
    Web::redirect($self());
}

// Daftar pencarian
$list = array_values(array_filter(Repo::studentsAll($fc ?: null), function ($s) use ($q) {
    return $q === '' || mb_stripos($s['nama'], $q) !== false || mb_stripos($s['nis'], $q) !== false;
}));
usort($list, fn($a, $b) => strcasecmp($a['nama'], $b['nama']));
$total = count($list);
$list = array_slice($list, 0, 80);

Web::head('Riwayat Siswa', 'siswa');
?>
<div class="head"><div><h1>Riwayat Siswa (Student 360)</h1><small>Profil, kehadiran, pola absen, nilai, dan akses wali murid dalam satu halaman.</small></div></div>
<div class="grid" style="grid-template-columns:minmax(230px,300px) 1fr;align-items:start">
  <aside class="card card-tight">
    <form method="get" style="padding:12px;border-bottom:1px solid var(--line)"><input type="hidden" name="p" value="siswa"><?php if ($sid): ?><input type="hidden" name="id" value="<?= $sid ?>"><?php endif; ?>
      <div class="field"><input type="search" name="q" value="<?= h($q) ?>" placeholder="Cari nama / NIS…" aria-label="Cari siswa"></div>
      <select name="class" data-auto aria-label="Filter kelas"><option value="0">Semua kelas</option><?php foreach ($classes as $c): ?><option value="<?= $c['id'] ?>" <?= $c['id'] === $fc ? 'selected' : '' ?>><?= h($c['name']) ?></option><?php endforeach; ?></select>
      <noscript><button class="btn btn-sm" style="margin-top:8px">Cari</button></noscript>
    </form>
    <div style="max-height:62vh;overflow:auto">
      <?php foreach ($list as $s): ?>
        <a href="<?= h($self(['id' => $s['id']])) ?>" class="stu" style="color:inherit;text-decoration:none;<?= $s['id'] === $sid ? 'background:#eef2ff' : '' ?>">
          <div class="nm"><b><?= h($s['nama']) ?></b><small><?= h($s['nis']) ?> · <?= h($classMap[$s['class_id']]['name'] ?? '-') ?></small></div>
        </a>
      <?php endforeach; ?>
      <?php if (!$list): ?><div class="empty">Tidak ada siswa cocok.</div><?php endif; ?>
      <?php if ($total > 80): ?><div class="empty">Menampilkan 80 dari <?= $total ?>. Persempit pencarian.</div><?php endif; ?>
    </div>
  </aside>

  <section>
  <?php if (!$student): ?>
    <div class="card"><div class="empty">Pilih siswa dari daftar di kiri.</div></div>
  <?php else:
    $recs = Repo::attendanceForStudent($student['id']);
    usort($recs, fn($a, $b) => strcmp($b['tanggal'], $a['tanggal']));
    $st = Rules::attendanceStats($student['id']);
    $pat = Rules::periodicPattern($student['id']);
    $att = Rules::attentionCategory($student['id']);
    $absen = array_values(array_filter($recs, fn($r) => $r['status'] !== 'H'));
    $subjMap = array_column(Repo::subjectsAll(), 'name', 'id');
    $acts = array_column(Repo::gradeActivitiesAll(), null, 'id');
    $grades = array_values(array_filter(Repo::gradeValuesAll(), fn($g) => $g['student_id'] === $student['id'] && isset($acts[$g['activity_id']])));
    usort($grades, fn($a, $b) => strcmp($acts[$b['activity_id']]['tanggal_kegiatan'], $acts[$a['activity_id']]['tanggal_kegiatan']));
    $newWm = (string) ($_GET['newwm'] ?? '');
    $wms = $canParent ? Repo::parentTokensByStudent($student['id']) : [];
    $canOverride = Web::isAdmin() || Web::has('kepsek');
  ?>
    <div class="card">
      <div class="head" style="margin-bottom:12px">
        <div><h1><?= h($student['nama']) ?></h1><small class="mono"><?= h($student['nis']) ?></small> · <small><?= h($classMap[$student['class_id']]['name'] ?? '-') ?> · <?= $student['jk'] === 'L' ? 'Laki-laki' : 'Perempuan' ?></small>
          <span class="badge <?= $student['status'] === 'aktif' ? 'b-ok' : 'b-warn' ?>"><?= h($student['status']) ?></span></div>
        <?php if ($canLetter): ?><div class="row">
          <a class="btn btn-sm" target="_blank" href="<?= h(Web::url('surat', ['type' => 'peringatan', 'student' => $student['id']])) ?>">Surat Peringatan</a>
          <a class="btn btn-sm" target="_blank" href="<?= h(Web::url('surat', ['type' => 'panggilan', 'student' => $student['id']])) ?>">Surat Panggilan Ortu</a></div><?php endif; ?>
      </div>
      <div class="grid g5">
        <div class="kpi"><small>Kehadiran</small><div class="n mono <?= Web::rateClass($st['rate']) ?>"><?= number_format($st['rate'], 1) ?>%</div></div>
        <div class="kpi"><small>Hadir</small><div class="n mono"><?= $st['hadir'] ?></div></div>
        <div class="kpi"><small>Izin</small><div class="n mono"><?= $st['izin'] ?></div></div>
        <div class="kpi"><small>Sakit</small><div class="n mono warn"><?= $st['sakit'] ?></div></div>
        <div class="kpi"><small>Alpa</small><div class="n mono bad"><?= $st['alpa'] ?></div></div>
      </div>
      <?php if ($att['category']): ?><p style="margin-top:12px"><span class="badge b-warn">Perlu perhatian: <?= h(Analytics::categoryLabel($att['category'])) ?></span></p><?php endif; ?>
    </div>

    <?php foreach ($pat as $a): ?><?= Web::alert('warn', "{$a['status_type']} pola absen berkala: selalu absen pada hari {$a['day_of_week']} ({$a['count']}x) — " . implode(', ', $a['dates'])) ?><?php endforeach; ?>

    <div class="card">
      <h2>Syarat Kehadiran Minimal 85%</h2>
      <?php if ($st['meets85Percent']): ?><?= Web::alert('success', "Memenuhi syarat: kehadiran {$st['rate']}% (≥ 85%). Berhak mengikuti ujian akhir dan pengesahan akademik.") ?>
      <?php else: ?><?= Web::alert('error', "Belum memenuhi syarat: kehadiran {$st['rate']}% (< 85%). Defisit {$st['deficitSessions']} sesi kehadiran. Pengesahan ditangguhkan; wajib pembinaan BK atau dispensasi Kepala Sekolah.") ?><?php endif; ?>
      <form method="post" action="<?= h($self()) ?>" class="row" style="align-items:flex-end">
        <?= Web::csrfField() ?><input type="hidden" name="do" value="clearance">
        <?php if ($canOverride): ?>
          <label class="chk" style="margin:0"><input type="checkbox" name="override" value="1"> Dispensasi khusus (Admin/Kepsek)</label>
          <div style="flex:1;min-width:220px"><label for="reason">Alasan dispensasi</label><input id="reason" type="text" name="reason" placeholder="mis. sakit rawat inap dengan surat dokter"></div>
        <?php endif; ?>
        <button class="btn btn-pri">Uji Pengesahan Akademik</button>
      </form>
    </div>

    <div class="card card-tight"><div style="padding:14px 16px"><h2>Log Ketidakhadiran (<?= count($absen) ?>)</h2></div>
      <?php if (!$absen): ?><div class="empty">Tidak ada catatan ketidakhadiran. 🎉</div><?php else: ?>
      <div class="scroll"><table class="tbl"><thead><tr><th>Tanggal</th><th>Status</th><th>Sesi</th><th>Catatan</th></tr></thead><tbody>
      <?php foreach ($absen as $r): $b = ['I' => ['Izin', 'b-ok'], 'S' => ['Sakit', 'b-warn'], 'A' => ['Alpa', 'b-bad']][$r['status']]; ?>
        <tr><td><?= h(Web::fmtDate($r['tanggal'])) ?></td><td><span class="badge <?= $b[1] ?>"><?= $b[0] ?></span></td>
          <td><?= $r['subject_id'] === null ? 'Harian' : h($subjMap[$r['subject_id']] ?? 'Mapel #' . $r['subject_id']) ?></td><td><?= h($r['notes'] ?? '-') ?></td></tr>
      <?php endforeach; ?></tbody></table></div><?php endif; ?>
    </div>

    <div class="card card-tight"><div style="padding:14px 16px"><h2>Dossier Nilai</h2></div>
      <?php if (!$grades): ?><div class="empty">Belum ada nilai.</div><?php else: ?>
      <div class="scroll"><table class="tbl"><thead><tr><th>Tanggal</th><th>Mapel</th><th>Kegiatan</th><th class="c">Nilai</th></tr></thead><tbody>
      <?php foreach ($grades as $g): $a = $acts[$g['activity_id']]; $low = (is_numeric($g['nilai']) && (float) $g['nilai'] < 70) || in_array($g['nilai'], ['D', 'E'], true); ?>
        <tr><td class="mono"><?= h($a['tanggal_kegiatan']) ?></td><td><?= h($subjMap[$a['subject_id']] ?? '-') ?></td><td><?= h($a['nama_kegiatan']) ?></td><td class="c mono <?= $low ? 'low' : '' ?>"><b><?= h($g['nilai']) ?></b></td></tr>
      <?php endforeach; ?></tbody></table></div><?php endif; ?>
    </div>

    <?php if ($canParent): ?>
    <div class="card"><h2>Akses Portal Wali Murid</h2>
      <p class="mut" style="font-size:12px">Tautan baca-saja untuk orang tua: hanya menampilkan kehadiran siswa ini (tanpa nilai atau data siswa lain). Dapat dicabut kapan saja.</p>
      <?php if ($newWm !== ''): $lnk = Web::absoluteUrl('wali', ['token' => $newWm]); ?><?= Web::alert('success', 'Tautan dibuat. Kirim ke orang tua/wali:') ?><code class="link" id="wm"><?= h($lnk) ?></code><p><button type="button" class="btn btn-sm" data-copy="#wm">Salin Tautan</button></p><?php endif; ?>
      <form method="post" action="<?= h($self()) ?>" style="margin-bottom:10px"><?= Web::csrfField() ?><input type="hidden" name="do" value="parent_create"><button class="btn">Buat Tautan Baru</button></form>
      <?php foreach ($wms as $t): ?>
        <div class="row" style="justify-content:space-between;padding:8px 0;border-top:1px solid var(--line2)">
          <div><code class="mono" style="font-size:11px"><?= h(substr($t['token'], 0, 12)) ?>…</code> <span class="badge <?= $t['status'] === 'aktif' ? 'b-ok' : '' ?>"><?= h($t['status']) ?></span> <small>dibuat <?= h(substr($t['created_at'], 0, 10)) ?></small></div>
          <?php if ($t['status'] === 'aktif'): ?><form method="post" class="inline" action="<?= h($self()) ?>" data-confirm="Cabut tautan ini? Orang tua tidak bisa membukanya lagi."><?= Web::csrfField() ?><input type="hidden" name="do" value="parent_revoke"><input type="hidden" name="token" value="<?= h($t['token']) ?>"><button class="btn btn-sm btn-danger">Cabut</button></form><?php endif; ?></div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  <?php endif; ?>
  </section>
</div>
<style>@media(max-width:760px){.grid[style*="minmax(230px"]{grid-template-columns:1fr!important}}</style>
<?php Web::foot();
