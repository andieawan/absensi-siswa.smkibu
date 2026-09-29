<?php
declare(strict_types=1);

// Portal Wali Murid (publik, baca-saja, per siswa) — hanya kehadiran, tanpa nilai/data siswa lain.
header('X-Robots-Tag: noindex, nofollow');
$token = (string) ($_GET['token'] ?? $_GET['wali'] ?? '');
$found = $token !== '' ? Repo::parentTokenByToken($token) : null;
$error = '';
$student = null;
if (!$found) $error = 'Tautan akses wali murid tidak ditemukan di sistem sekolah.';
elseif ($found['status'] !== 'aktif') $error = 'Tautan akses ini sudah dicabut oleh wali kelas/Administrator.';
else {
    $student = Repo::studentById($found['student_id']);
    if (!$student) $error = 'Data siswa untuk tautan ini tidak ditemukan.';
}
$settings = Repo::settingsGet();
Web::head('Portal Wali Murid', '', true);
echo '<style>.wrap-bare{max-width:720px}</style>';
?>
<div class="head"><div><h1>Portal Wali Murid</h1><small><?= h($settings['school_name'] ?? '') ?></small></div></div>
<?php if ($error || !$student): ?>
  <?= Web::alert('error', $error ?: 'Tautan tidak valid.') ?>
<?php else:
    $class = Repo::classById($student['class_id']);
    $st = Rules::attendanceStats($student['id']);
    $att = Rules::attentionCategory($student['id']);
    $pat = Rules::periodicPattern($student['id']);
    $recs = Repo::attendanceForStudent($student['id']);
    usort($recs, fn($a, $b) => strcmp($b['tanggal'], $a['tanggal']));
    $recs = array_slice($recs, 0, 30);
    $lbl = ['H' => ['Hadir', 'b-ok'], 'I' => ['Izin', ''], 'S' => ['Sakit', 'b-warn'], 'A' => ['Alpa', 'b-bad']];
?>
<div class="card"><h2><?= h($student['nama']) ?></h2><small class="mono"><?= h($student['nis']) ?></small> · <small><?= h($class['name'] ?? '-') ?></small></div>
<div class="grid g5" style="margin-bottom:16px">
  <div class="kpi"><small>Kehadiran</small><div class="n mono <?= Web::rateClass($st['rate']) ?>"><?= number_format($st['rate'], 1) ?>%</div></div>
  <div class="kpi"><small>Hadir</small><div class="n mono"><?= $st['hadir'] ?></div></div>
  <div class="kpi"><small>Izin</small><div class="n mono"><?= $st['izin'] ?></div></div>
  <div class="kpi"><small>Sakit</small><div class="n mono warn"><?= $st['sakit'] ?></div></div>
  <div class="kpi"><small>Alpa</small><div class="n mono bad"><?= $st['alpa'] ?></div></div>
</div>
<?php if (!$st['meets85Percent']): ?><?= Web::alert('error', "Kehadiran ananda {$st['rate']}% — di bawah batas minimal 85% untuk kenaikan kelas/kelulusan. Mohon segera berkoordinasi dengan wali kelas atau guru BK.") ?><?php endif; ?>
<?php if ($att['category']): ?><?= Web::alert('warn', 'Catatan perhatian: ' . Analytics::categoryLabel($att['category']) . " (Alpa {$att['alpa']}, Izin {$att['izin']}, Sakit {$att['sakit']}).") ?><?php endif; ?>
<?php foreach ($pat as $a): ?><?= Web::alert('warn', "Terdeteksi pola absen berkala pada hari {$a['day_of_week']} ({$a['count']}x): " . implode(', ', $a['dates'])) ?><?php endforeach; ?>
<div class="card card-tight"><div style="padding:14px 16px"><h2>30 Catatan Terakhir</h2></div>
  <?php if (!$recs): ?><div class="empty">Belum ada catatan kehadiran.</div><?php else: ?>
  <table class="tbl"><thead><tr><th>Tanggal</th><th>Status</th><th>Catatan</th></tr></thead><tbody>
  <?php foreach ($recs as $r): ?><tr><td><?= h(Web::fmtDate($r['tanggal'])) ?></td><td><span class="badge <?= $lbl[$r['status']][1] ?>"><?= $lbl[$r['status']][0] ?></span></td><td><?= h($r['notes'] ?? '-') ?></td></tr><?php endforeach; ?>
  </tbody></table><?php endif; ?>
</div>
<?php endif; ?>
<?php Web::foot();
