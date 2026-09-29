<?php
declare(strict_types=1);

$user = Web::requireLogin();
$classes = Repo::classesAll();
$subjects = array_values(array_filter(Repo::subjectsAll(), fn($s) => $s['id'] !== 5)); // BK bukan mapel reguler
$settings = Repo::settingsGet();

$isWali = ($user['kelas_wali_id'] ?? null) !== null;
$canSekolah = Web::isKepsek() || Web::isAdmin() || Web::has('bk');
$canMapel = Web::has('guru') || Web::isAdmin();
$variants = [];
if ($isWali) $variants['wali'] = 'Wali Kelas';
if ($canMapel) $variants['mapel'] = 'Per Mapel';
if ($canSekolah) $variants['sekolah'] = 'Sekolah (Kepsek)';
if (!$variants) $variants['sekolah'] = 'Sekolah';

$default = $isWali ? 'wali' : (Web::isKepsek() ? 'sekolah' : (isset($variants['mapel']) ? 'mapel' : 'sekolah'));
$variant = (string) ($_GET['v'] ?? $default);
if (!isset($variants[$variant])) $variant = array_key_first($variants);

$classId = isset($_GET['class']) ? (int) $_GET['class'] : ($isWali ? (int) $user['kelas_wali_id'] : ($classes[0]['id'] ?? 0));
if ($variant === 'wali' && $isWali) $classId = (int) $user['kelas_wali_id'];
if ($variant !== 'sekolah' && $classId === 0) $classId = $classes[0]['id'] ?? 0;
$subjectId = isset($_GET['subject']) ? (int) $_GET['subject'] : (int) (($user['subjects'][0] ?? null) ?: ($subjects[0]['id'] ?? 0));

$activeClass = $classId ? Repo::classById($classId) : null;
$activeSubject = null;
foreach ($subjects as $s) if ($s['id'] === $subjectId) $activeSubject = $s;

$subjectScope = $variant === 'mapel' ? $subjectId : null;
$classScope = $classId ?: null;
$records = Repo::attendanceQuery($classScope, $subjectScope);
$students = Repo::studentsAll($classScope);
$grades = Repo::gradeValuesAll();
$all = Repo::attendanceQuery($classScope, $subjectScope); // sudah terfilter

$c = Analytics::counts($records);
$trend = Analytics::trend($records);
$patterns = Analytics::patterns($records, $students, $classScope, $subjectScope);
$attention = Analytics::attention($records, $students, $grades, $classScope, $subjectScope);
$dual = count(array_filter($attention, fn($a) => $a['hasGradeDrop']));

$scopeLabel = $variant === 'wali'
    ? 'Kelas Wali ' . ($activeClass['name'] ?? '')
    : ($variant === 'sekolah'
        ? ($classId ? 'Kelas ' . ($activeClass['name'] ?? '') . ' (Tingkat Sekolah)' : 'Seluruh Kelas (Agregat Sekolah)')
        : 'Mata Pelajaran ' . ($activeSubject['name'] ?? '') . ' - ' . ($activeClass['name'] ?? ''));
$narr = Analytics::narrative($c['rate'], $c['alpa'], $c['izin'], $c['sakit'], count($attention), count($patterns), $scopeLabel, $dual);

$cat = (string) ($_GET['cat'] ?? 'all');
$shown = $cat === 'all' ? $attention : array_values(array_filter($attention, fn($a) => $a['category'] === $cat));
$q = fn(array $over = []) => Web::url('dashboard', array_merge(['v' => $variant, 'class' => $classId ?: null, 'subject' => $variant === 'mapel' ? $subjectId : null, 'cat' => $cat === 'all' ? null : $cat], $over));

$title = $variant === 'wali' ? 'Dashboard Wali Kelas — ' . ($activeClass['name'] ?? '')
    : ($variant === 'sekolah' ? 'Dashboard Sekolah (Kepsek) — Agregat Harian' : 'Dashboard Guru Mapel — ' . ($activeSubject['name'] ?? ''));

Web::head('Dashboard', 'dashboard');
?>
<div class="head">
  <div>
    <h1><?= h($title) ?></h1>
    <small>Tahun Ajaran <?= h($settings['tahun_ajaran'] ?: ($activeClass['tahun_ajaran'] ?? '-')) ?> · Semester <?= h($settings['semester'] ?? '-') ?> · <span class="mono"><?= count($trend) ?></span> pertemuan tercatat</small>
  </div>
  <form method="get" class="row" action="<?= h(Web::base() . '/') ?>">
    <input type="hidden" name="p" value="dashboard">
    <?php if (count($variants) > 1): ?>
    <div class="seg" role="group" aria-label="Jenis dashboard">
      <?php foreach ($variants as $k => $label): ?>
        <a href="<?= h(Web::url('dashboard', ['v' => $k])) ?>" class="<?= $variant === $k ? 'on' : '' ?>"><?= h($label) ?></a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <input type="hidden" name="v" value="<?= h($variant) ?>">
    <?php if ($variant !== 'wali'): ?>
    <select name="class" data-auto aria-label="Pilih kelas" style="width:auto">
      <?php if ($variant === 'sekolah'): ?><option value="0">Semua Kelas (Agregat)</option><?php endif; ?>
      <?php foreach ($classes as $cl): ?><option value="<?= $cl['id'] ?>" <?= $cl['id'] === $classId ? 'selected' : '' ?>><?= h($cl['name']) ?> (<?= h($cl['jurusan']) ?>)</option><?php endforeach; ?>
    </select>
    <?php endif; ?>
    <?php if ($variant === 'mapel'): ?>
    <select name="subject" data-auto aria-label="Pilih mata pelajaran" style="width:auto">
      <?php foreach ($subjects as $s): ?><option value="<?= $s['id'] ?>" <?= $s['id'] === $subjectId ? 'selected' : '' ?>><?= h($s['name']) ?></option><?php endforeach; ?>
    </select>
    <?php endif; ?>
    <noscript><button class="btn btn-sm">Terapkan</button></noscript>
  </form>
</div>

<?php if (!$classes): ?>
  <?= Web::alert('info', 'Belum ada data kelas. ' . (Web::isAdmin() ? 'Buka Admin Panel → Kelas & Mapel untuk menambahkannya.' : 'Hubungi administrator.')) ?>
<?php endif; ?>

<?php if ($variant === 'sekolah'): ?>
  <?= Web::alert('info', 'Aturan agregasi sekolah: data dihitung murni dari absensi harian wali kelas (bukan gabungan mapel) agar tidak terjadi double-counting.') ?>
<?php endif; ?>

<div class="grid g5" style="margin-bottom:16px">
  <div class="kpi first"><small>Tingkat Kehadiran</small><div class="n mono <?= Web::rateClass($c['rate']) ?>"><?= number_format($c['rate'], 1) ?>%</div><small>Target sekolah: ≥ 85%</small></div>
  <div class="kpi"><small>Hadir (H)</small><div class="n mono"><?= $c['hadir'] ?></div></div>
  <div class="kpi"><small>Izin (I)</small><div class="n mono"><?= $c['izin'] ?></div></div>
  <div class="kpi"><small>Sakit (S)</small><div class="n mono warn"><?= $c['sakit'] ?></div></div>
  <div class="kpi"><small>Alpa (A)</small><div class="n mono bad"><?= $c['alpa'] ?></div></div>
</div>

<div class="narr">
  <h2>Ringkasan &amp; Saran Otomatis Berdasarkan Kondisi Data</h2>
  <div><?= h($narr['summary']) ?></div>
  <div class="rec">→ <?= h($narr['recommendation']) ?></div>
</div>

<div class="split">
  <div class="card">
    <h2>Tren Kehadiran dari Waktu ke Waktu</h2>
    <small>Persentase siswa hadir per tanggal sesi</small>
    <?php if (!$trend): ?><div class="empty">Belum ada data rekaman absensi untuk kombinasi ini.</div><?php endif; ?>
    <?php foreach ($trend as $t): $f = $t['pct'] >= 90 ? 'f-ok' : ($t['pct'] >= 80 ? 'f-warn' : 'f-bad'); ?>
      <div class="bar"><span class="d mono"><?= h($t['date']) ?></span><span class="t"><i class="<?= $f ?>" style="width:<?= $t['pct'] ?>%"></i></span><span class="p mono"><?= $t['pct'] ?>%</span><span class="m mono">(<?= $t['h'] ?>/<?= $t['total'] ?>)</span></div>
    <?php endforeach; ?>
  </div>
  <div class="card">
    <h2>Deteksi Pola Absen Berkala</h2>
    <p class="mut" style="font-size:12px">Siswa yang berulang kali absen pada <b>hari yang sama</b> dengan jarak ~14 hari (toleransi 10–18 hari).</p>
    <?php if (!$patterns): ?><div class="empty">Tidak terdeteksi pola berkala pada rentang ini.</div><?php endif; ?>
    <?php foreach ($patterns as $a): ?>
      <div class="pola">
        <div class="row" style="justify-content:space-between"><a href="<?= h(Web::url('siswa', ['id' => $a['student_id']])) ?>"><b><?= h($a['student_name']) ?></b></a>
          <span class="badge <?= $a['status_type'] === 'Pola' ? 'b-bad' : 'b-warn' ?>"><?= h($a['status_type']) ?> (<?= $a['count'] ?>x)</span></div>
        <div>Selalu absen di hari <b><?= h($a['day_of_week']) ?></b></div>
        <div class="mono mut" style="font-size:11px">Tanggal: <?= h(implode(', ', $a['dates'])) ?></div>
      </div>
    <?php endforeach; ?>
  </div>
</div>

<div class="card">
  <div class="head" style="border:0;padding:0;margin-bottom:10px">
    <div>
      <h2>Daftar Siswa Perlu Perhatian <?php if ($dual): ?><span class="badge b-bad"><?= $dual ?> sinyal prioritas (absen + nilai turun)</span><?php endif; ?></h2>
      <small>Diurutkan berdasarkan tingkat signifikansi ketidakhadiran &amp; dampak akademik</small>
    </div>
    <div class="seg">
      <?php foreach (['all' => 'Semua (' . count($attention) . ')', 'alpa_tinggi' => 'Alpa Tinggi', 'sakit_tinggi' => 'Sakit Tinggi', 'izin_tinggi' => 'Izin Tinggi', 'jarang_masuk_gabungan' => 'Jarang Masuk'] as $k => $label): ?>
        <a href="<?= h($q(['cat' => $k === 'all' ? null : $k])) ?>" class="<?= $cat === $k ? 'on' : '' ?>"><?= h($label) ?></a>
      <?php endforeach; ?>
    </div>
  </div>
  <?php if (!$shown): ?><div class="empty">Tidak ada siswa dalam kategori ini. Seluruh siswa terpantau tertib.</div><?php else: ?>
  <div class="scroll"><table class="tbl">
    <thead><tr><th>Nama Siswa</th><th>NIS</th><th>Kategori</th><th class="c">Alpa</th><th class="c">Izin</th><th class="c">Sakit</th><th class="c">Total</th><th>Sinyal</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($shown as $s): ?>
      <tr>
        <td><b><?= h($s['student_name']) ?></b></td><td class="mono mut"><?= h($s['nis']) ?></td><td><?= h(Analytics::categoryLabel($s['category'])) ?></td>
        <td class="c mono bad"><b><?= $s['alpa_count'] ?></b></td><td class="c mono"><?= $s['izin_count'] ?></td><td class="c mono warn"><?= $s['sakit_count'] ?></td><td class="c mono"><b><?= $s['total_absen'] ?></b></td>
        <td><?= $s['hasGradeDrop'] ? '<span class="bad"><b>Nilai &amp; kehadiran anjlok</b></span>' : '<span class="mut">Normal</span>' ?></td>
        <td class="r"><a href="<?= h(Web::url('siswa', ['id' => $s['student_id']])) ?>">Lihat riwayat →</a></td>
      </tr>
    <?php endforeach; ?>
    </tbody></table></div>
  <?php endif; ?>
</div>
<?php Web::foot();
