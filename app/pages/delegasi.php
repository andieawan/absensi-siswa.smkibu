<?php
declare(strict_types=1);

// Halaman publik Ketua Kelas (tanpa login): hanya bisa mengisi absen harian kelasnya lewat token.
header('X-Robots-Tag: noindex, nofollow');
$token = (string) ($_REQUEST['token'] ?? '');
$tanggal = (string) ($_REQUEST['date'] ?? Web::today());
if (Util::parseSessionDate($tanggal) === null) $tanggal = Web::today();
$done = null;
$error = '';
$t = null;
try {
    $t = Svc::activeDelegation($token);
} catch (UserError $e) {
    $error = $e->getMessage();
}

if ($t && Web::isPost()) {
    try {
        $entries = [];
        foreach ((array) ($_POST['status'] ?? []) as $sid => $st) {
            $entries[] = ['student_id' => (int) $sid, 'status' => (string) $st, 'notes' => (string) ($_POST['notes'][$sid] ?? '')];
        }
        $n = Svc::submitDelegation($token, $tanggal, $entries);
        $done = "Presensi tanggal $tanggal ($n siswa) berhasil disimpan. Terima kasih!";
    } catch (UserError $e) {
        $error = $e->getMessage();
    }
}

$settings = Repo::settingsGet();
Web::head('Presensi Ketua Kelas', '', true);
echo '<style>.wrap-bare{max-width:760px}</style>';
?>
<div class="head"><div><h1>Presensi Harian — Ketua Kelas</h1><small><?= h($settings['school_name'] ?? '') ?></small></div></div>
<?php if ($done): ?><?= Web::alert('success', $done) ?><?php endif; ?>
<?php if ($error): ?><?= Web::alert('error', $error) ?><?php endif; ?>
<?php if (!$t): ?>
  <div class="card"><p>Tautan tidak dapat dipakai. Minta Wali Kelas membuatkan tautan baru.</p></div>
<?php else:
    $class = Repo::classById($t['class_id']);
    $students = array_values(array_filter(Repo::studentsAll($t['class_id']), fn($s) => $s['status'] === 'aktif'));
    usort($students, fn($a, $b) => strcasecmp($a['nama'], $b['nama']));
    $existing = [];
    foreach (Repo::attendanceQuery($t['class_id'], null, $tanggal) as $r) $existing[$r['student_id']] = $r;
    $exp = $t['expires_at_millis'] ?? null;
    $min = gmdate('Y-m-d', time() - 7 * 86400);
?>
<div class="card">
  <div class="row" style="justify-content:space-between"><div><h2><?= h($class['name'] ?? '-') ?></h2><small><?= count($students) ?> siswa aktif</small></div>
    <?php if ($exp): ?><span class="badge b-warn">Berlaku sampai <?= h(Util::idLocale((int) $exp)) ?> WIB</span><?php endif; ?></div>
  <form method="get" class="row" style="margin-top:12px"><input type="hidden" name="p" value="delegasi"><input type="hidden" name="token" value="<?= h($token) ?>">
    <div><label for="date">Tanggal presensi</label><input id="date" type="date" name="date" value="<?= h($tanggal) ?>" min="<?= h($min) ?>" max="<?= h(Web::today()) ?>" onchange="this.form.submit()"></div></form>
  <?php if ($existing): ?><p><span class="badge b-warn">Tanggal ini sudah pernah diisi — menyimpan lagi akan memperbarui.</span></p><?php endif; ?>
</div>
<form method="post" class="card card-tight" action="<?= h(Web::url('delegasi', ['token' => $token, 'date' => $tanggal])) ?>">
  <?= Web::csrfField() ?>
  <div class="row" style="padding:12px 14px;border-bottom:1px solid var(--line)"><button type="button" class="btn btn-sm" data-setall="H">Set Semua Hadir</button>
    <span id="recount" class="mono" style="font-size:12px">H <b data-c="H">0</b> · I <b data-c="I">0</b> · S <b data-c="S">0</b> · A <b data-c="A">0</b></span></div>
  <?php foreach ($students as $i => $s): $cur = $existing[$s['id']] ?? null; $st = $cur['status'] ?? 'H'; ?>
  <div class="stu"><span class="no mono"><?= $i + 1 ?></span><div class="nm"><b><?= h($s['nama']) ?></b><small><?= h($s['nis']) ?></small></div>
    <div class="hisa" role="radiogroup" aria-label="Status <?= h($s['nama']) ?>">
      <?php foreach (['H' => 'Hadir', 'I' => 'Izin', 'S' => 'Sakit', 'A' => 'Alpa'] as $k => $lbl): ?>
        <label title="<?= $lbl ?>"><input type="radio" name="status[<?= $s['id'] ?>]" value="<?= $k ?>" data-status="<?= $k ?>" <?= $st === $k ? 'checked' : '' ?>><span class="<?= $k ?>"><?= $k ?></span></label>
      <?php endforeach; ?></div>
    <input class="note" type="text" name="notes[<?= $s['id'] ?>]" value="<?= h($cur['notes'] ?? '') ?>" placeholder="Catatan (opsional)" maxlength="200" aria-label="Catatan <?= h($s['nama']) ?>">
  </div>
  <?php endforeach; ?>
  <div class="sticky-save"><small>Data langsung diterima Wali Kelas.</small><button class="btn btn-pri" <?= $students ? '' : 'disabled' ?>>Kirim Presensi</button></div>
</form>
<?php endif; ?>
<?php Web::foot();
