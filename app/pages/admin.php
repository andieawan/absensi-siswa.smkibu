<?php
declare(strict_types=1);

Web::requireAdmin();
$user = Web::user();
$actor = Web::actor();
$tabs = ['guru' => 'Akun Guru', 'siswa' => 'Data Siswa', 'kelas' => 'Kelas & Mapel', 'pairing' => 'Pasangan Mapel', 'hardcopy' => 'Upload Hardcopy', 'log' => 'Log Aktivitas', 'setelan' => 'Pengaturan & Backup'];
$tab = (string) ($_GET['tab'] ?? 'guru');
if (!isset($tabs[$tab])) $tab = 'guru';
$self = fn(array $o = []) => Web::url('admin', array_merge(['tab' => $tab], $o));

$classes = Repo::classesAll();
$subjects = Repo::subjectsAll();
$classMap = array_column($classes, 'name', 'id');
$subjMap = array_column($subjects, 'name', 'id');
$preview = null; // hasil pratinjau upload hardcopy

if (Web::isPost()) {
    $do = (string) ($_POST['do'] ?? '');
    try {
        switch ($do) {
            case 'teacher_add':
                Svc::addTeacher($actor, [
                    'nama' => $_POST['nama'] ?? '', 'username' => $_POST['username'] ?? '', 'password' => $_POST['password'] ?? '',
                    'roles' => $_POST['roles'] ?? ['guru'], 'kelas_wali_id' => $_POST['kelas_wali_id'] ?? '',
                    'subjects' => $_POST['subjects'] ?? [], 'classes' => $_POST['classes'] ?? [],
                ]);
                Web::flash('success', 'Akun guru berhasil ditambahkan.');
                break;
            case 'teacher_reset':
                Svc::resetPassword($actor, (int) ($_POST['id'] ?? 0), (string) ($_POST['password'] ?? ''));
                Web::flash('success', 'Password direset. Sesi login akun tersebut otomatis keluar.');
                break;
            case 'teacher_toggle':
                $on = Svc::toggleUser($actor, (int) ($_POST['id'] ?? 0));
                Web::flash('success', $on ? 'Akun diaktifkan.' : 'Akun dinonaktifkan.');
                break;
            case 'teacher_edit':
                Svc::requireAdmin($actor);
                $u = Repo::userById((int) ($_POST['id'] ?? 0));
                if (!$u) throw new UserError('Akun tidak ditemukan.');
                $wali = ($_POST['kelas_wali_id'] ?? '') !== '' ? (int) $_POST['kelas_wali_id'] : null;
                $roles = array_values(array_intersect((array) ($_POST['roles'] ?? []), ['guru', 'admin', 'superadmin', 'kepsek', 'bk']));
                if (!$roles) throw new UserError('Pilih minimal satu peran.');
                $privileged = fn(array $r) => (bool) array_intersect($r, ['admin', 'superadmin']);
                if ($privileged($roles) !== $privileged($u['roles']) || in_array('superadmin', $roles, true) !== in_array('superadmin', $u['roles'], true)) {
                    if (!in_array('superadmin', $actor['roles'], true)) throw new UserError('Hanya Superadmin yang dapat mengubah peran Administrator.');
                }
                if ($u['id'] === $actor['id'] && !Util::hasAdminRole($roles)) throw new UserError('Anda tidak dapat mencabut peran Administrator dari akun Anda sendiri.');
                Repo::userUpsert(array_merge($u, [
                    'nama' => trim((string) ($_POST['nama'] ?? '')) ?: $u['nama'], 'roles' => $roles, 'kelas_wali_id' => $wali,
                    'subjects' => array_map('intval', (array) ($_POST['subjects'] ?? [])), 'classes' => array_map('intval', (array) ($_POST['classes'] ?? [])),
                ]));
                Repo::audit('Ubah Akun Guru', 'Akun Guru', $actor['nama'], $u['username']);
                Web::flash('success', 'Akun diperbarui.');
                break;
            case 'student_save':
                $id = (int) ($_POST['id'] ?? 0);
                Svc::saveStudent($actor, $id ?: null, $_POST);
                Web::flash('success', $id ? 'Data siswa diperbarui.' : 'Siswa ditambahkan.');
                break;
            case 'student_import':
                Svc::requireAdmin($actor);
                if (empty($_FILES['file']['tmp_name']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) throw new UserError('Pilih berkas .xlsx atau .csv terlebih dahulu.');
                $rows = Xlsx::readFile($_FILES['file']['tmp_name'], (string) $_FILES['file']['name']);
                $hdr = array_map(fn($v) => strtolower(trim($v)), array_shift($rows) ?? []);
                $idx = fn(array $names) => (function () use ($hdr, $names) { foreach ($names as $n) { $i = array_search($n, $hdr, true); if ($i !== false) return $i; } return null; })();
                $iNis = $idx(['nis']); $iNama = $idx(['nama siswa', 'nama']); $iJk = $idx(['jk', 'jenis kelamin']); $iKelas = $idx(['kelas']);
                if ($iNis === null || $iNama === null || $iKelas === null) throw new UserError('Kolom wajib: NIS, Nama Siswa, JK, Kelas (nama kelas sama persis dengan data kelas).');
                $byName = [];
                foreach ($classes as $c) $byName[strtolower($c['name'])] = $c['id'];
                $ok = 0; $skip = [];
                Db::tx(function () use ($rows, $iNis, $iNama, $iJk, $iKelas, $byName, $actor, &$ok, &$skip) {
                    foreach ($rows as $n => $r) {
                        $nis = trim($r[$iNis] ?? ''); $nama = trim($r[$iNama] ?? '');
                        if ($nis === '' && $nama === '') continue;
                        $kelas = $byName[strtolower(trim($r[$iKelas] ?? ''))] ?? null;
                        $jk = strtoupper(substr(trim((string) ($iJk !== null ? ($r[$iJk] ?? 'L') : 'L')), 0, 1));
                        try {
                            if (!$kelas) throw new UserError('kelas tidak dikenal');
                            Svc::saveStudent($actor, null, ['nis' => $nis, 'nama' => $nama, 'jk' => $jk, 'class_id' => $kelas, 'status' => 'aktif']);
                            $ok++;
                        } catch (UserError $e) {
                            $skip[] = 'Baris ' . ($n + 2) . " ($nis): " . $e->getMessage();
                        }
                    }
                });
                Web::flash($skip ? 'warn' : 'success', "Impor selesai: $ok siswa ditambahkan." . ($skip ? ' Dilewati: ' . implode('; ', array_slice($skip, 0, 8)) . (count($skip) > 8 ? '; …' : '') : ''));
                break;
            case 'class_save':
                Svc::saveClass($actor, ((int) ($_POST['id'] ?? 0)) ?: null, $_POST);
                Web::flash('success', 'Kelas disimpan.');
                break;
            case 'subject_save':
                Svc::saveSubject($actor, ((int) ($_POST['id'] ?? 0)) ?: null, (string) ($_POST['name'] ?? ''));
                Web::flash('success', 'Mata pelajaran disimpan.');
                break;
            case 'pair_add':
                $u = Repo::userById((int) ($_POST['user_id'] ?? 0));
                $sid = (int) ($_POST['subject_id'] ?? 0); $cid = (int) ($_POST['class_id'] ?? 0);
                if (!$u || !isset($subjMap[$sid]) || !isset($classMap[$cid])) throw new UserError('Pilih guru, mata pelajaran, dan kelas yang valid.');
                Repo::pairingAdd($u['id'], $sid, $cid);
                Repo::audit('Tambah Pasangan Mapel', 'Pasangan', $actor['nama'], "{$u['nama']} — {$subjMap[$sid]} — {$classMap[$cid]}");
                Web::flash('success', 'Pasangan ditambahkan.');
                break;
            case 'pair_remove':
                Repo::pairingRemove((int) $_POST['user_id'], (int) $_POST['subject_id'], (int) $_POST['class_id']);
                Repo::audit('Hapus Pasangan Mapel', 'Pasangan', $actor['nama'], "Guru #{$_POST['user_id']}, mapel #{$_POST['subject_id']}, kelas #{$_POST['class_id']}");
                Web::flash('success', 'Pasangan dihapus.');
                break;
            case 'hc_preview':
                $cid = (int) ($_POST['class_id'] ?? 0);
                $tgl = (string) ($_POST['date'] ?? '');
                if (!isset($classMap[$cid])) throw new UserError('Pilih kelas.');
                if (Util::parseSessionDate($tgl) === null) throw new UserError('Tanggal tidak valid.');
                if (empty($_FILES['file']['tmp_name']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) throw new UserError('Pilih berkas .xlsx atau .csv.');
                $rows = Xlsx::readFile($_FILES['file']['tmp_name'], (string) $_FILES['file']['name']);
                $hdr = array_map(fn($v) => strtolower(trim($v)), array_shift($rows) ?? []);
                $find = function (array $names) use ($hdr) { foreach ($names as $n) { $i = array_search($n, $hdr, true); if ($i !== false) return $i; } return null; };
                $iNis = $find(['nis', 'nomor induk']); $iSt = $find(['status (h/i/s/a)', 'status']); $iNote = $find(['catatan (opsional)', 'catatan', 'keterangan']);
                if ($iNis === null) throw new UserError('Kolom NIS tidak ditemukan. Gunakan template yang diunduh.');
                $byNis = [];
                foreach (Repo::studentsAll($cid) as $s) $byNis[strtolower(trim($s['nis']))] = $s;
                $entries = []; $warn = []; $valid = 0; $invalid = 0;
                foreach ($rows as $n => $r) {
                    $nis = trim((string) ($r[$iNis] ?? ''));
                    if ($nis === '') continue;
                    $st = strtoupper(trim((string) ($iSt !== null ? ($r[$iSt] ?? 'H') : 'H'))) ?: 'H';
                    $s = $byNis[strtolower($nis)] ?? null;
                    $okSt = in_array($st, ['H', 'I', 'S', 'A'], true);
                    if (!$s) $warn[] = 'Baris ' . ($n + 2) . ": NIS '$nis' tidak terdaftar di kelas ini.";
                    if (!$okSt) $warn[] = 'Baris ' . ($n + 2) . ": status '$st' tidak dikenal (H/I/S/A).";
                    ($s && $okSt) ? $valid++ : $invalid++;
                    $entries[] = ['nis' => $nis, 'student' => $s, 'status' => $st, 'ok' => $s && $okSt, 'notes' => trim((string) ($iNote !== null ? ($r[$iNote] ?? '') : ''))];
                }
                $preview = ['class_id' => $cid, 'date' => $tgl, 'entries' => $entries, 'warn' => $warn, 'valid' => $valid, 'invalid' => $invalid];
                break;
            case 'hc_commit':
                $cid = (int) ($_POST['class_id'] ?? 0);
                $entries = [];
                foreach ((array) ($_POST['e'] ?? []) as $sid => $e) {
                    $entries[] = ['student_id' => (int) $sid, 'status' => (string) ($e['status'] ?? ''), 'notes' => (string) ($e['notes'] ?? '')];
                }
                $r = Svc::submitAttendance($actor, $cid, null, (string) ($_POST['date'] ?? ''), $entries, 'upload_hardcopy');
                Web::flash('success', "Upload hardcopy tersimpan: {$r['created']} baru, {$r['updated']} diperbarui.");
                break;
            case 'settings':
                Svc::saveSettings($actor, $_POST);
                Web::flash('success', 'Pengaturan sekolah disimpan.');
                break;
            case 'backup_now':
                $r = Backup::run();
                if (!$r['success']) throw new UserError($r['error'] ?? 'Backup gagal.');
                Repo::audit('Backup Manual Database', 'Sistem', $actor['nama'], 'Snapshot: ' . basename($r['file']));
                Web::flash('success', 'Backup dibuat: ' . basename($r['file']));
                break;
            default:
                throw new UserError('Aksi tidak dikenal.');
        }
    } catch (UserError $e) {
        Web::flash('error', $e->getMessage());
        if ($do === 'hc_preview') { /* tampilkan form lagi */ }
    }
    if (!$preview) Web::redirect($self());
}

Web::head('Admin Panel', 'admin');
?>
<div class="head"><div><h1>Admin Panel</h1><small>Kelola akun, data master, otorisasi, impor, dan backup.</small></div>
  <div class="seg"><?php foreach ($tabs as $k => $lbl): ?><a class="<?= $tab === $k ? 'on' : '' ?>" href="<?= h(Web::url('admin', ['tab' => $k])) ?>"><?= h($lbl) ?></a><?php endforeach; ?></div></div>

<?php
$roleBoxes = function (array $sel) use ($user): string {
    $o = ['guru' => 'Guru', 'kepsek' => 'Kepala Sekolah', 'bk' => 'Guru BK', 'admin' => 'Admin'];
    if (in_array('superadmin', $user['roles'], true)) $o['superadmin'] = 'Superadmin';
    $h = '';
    foreach ($o as $k => $l) $h .= '<label class="chk"><input type="checkbox" name="roles[]" value="' . $k . '" ' . (in_array($k, $sel, true) ? 'checked' : '') . '> ' . h($l) . '</label>';
    return $h;
};
$checks = function (string $name, array $items, array $sel): string {
    $h = '<div class="row" style="gap:4px 14px">';
    foreach ($items as $it) $h .= '<label class="chk" style="font-size:12px"><input type="checkbox" name="' . $name . '[]" value="' . $it['id'] . '" ' . (in_array($it['id'], $sel, true) ? 'checked' : '') . '> ' . h($it['name']) . '</label>';
    return $h . '</div>';
};
?>

<?php if ($tab === 'guru'): $users = Repo::usersAll(); ?>
<details class="card"><summary style="cursor:pointer;font-weight:700">+ Tambah Akun Guru</summary>
  <form method="post" action="<?= h($self()) ?>" style="margin-top:12px"><?= Web::csrfField() ?><input type="hidden" name="do" value="teacher_add">
    <div class="fields"><div><label>Nama lengkap</label><input type="text" name="nama" required></div><div><label>Username</label><input type="text" name="username" autocapitalize="none" required></div>
      <div><label>Password awal (min. 8)</label><input type="text" name="password" minlength="8" required autocomplete="off"></div>
      <div><label>Wali kelas (opsional)</label><select name="kelas_wali_id"><option value="">Bukan wali kelas</option><?php foreach ($classes as $c): ?><option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?></select></div></div>
    <div class="field" style="margin-top:12px"><label>Peran</label><div class="row"><?= $roleBoxes(['guru']) ?></div></div>
    <div class="field"><label>Mata pelajaran diampu</label><?= $checks('subjects', $subjects, []) ?></div>
    <div class="field"><label>Kelas diajar</label><?= $checks('classes', $classes, []) ?></div>
    <button class="btn btn-pri">Tambah Akun</button>
  </form></details>
<div class="card card-tight"><div class="scroll"><table class="tbl"><thead><tr><th>Nama</th><th>Username</th><th>Peran</th><th>Wali</th><th>Mapel</th><th>Status</th><th class="r">Aksi</th></tr></thead><tbody>
<?php foreach ($users as $u): ?>
  <tr><td><b><?= h($u['nama']) ?></b></td><td class="mono"><?= h($u['username']) ?></td><td><?= h(Web::roleLabel($u['roles'])) ?></td>
    <td><?= h($classMap[$u['kelas_wali_id'] ?? 0] ?? '-') ?></td><td style="font-size:12px"><?= h(implode(', ', array_map(fn($i) => $subjMap[$i] ?? $i, $u['subjects'])) ?: '-') ?></td>
    <td><span class="badge <?= $u['is_active'] ? 'b-ok' : 'b-bad' ?>"><?= $u['is_active'] ? 'Aktif' : 'Nonaktif' ?></span></td>
    <td class="r"><div class="row row-end">
      <form method="post" class="inline" action="<?= h($self()) ?>" data-confirm="<?= $u['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?> akun <?= h($u['username']) ?>?"><?= Web::csrfField() ?><input type="hidden" name="do" value="teacher_toggle"><input type="hidden" name="id" value="<?= $u['id'] ?>"><button class="btn btn-sm <?= $u['is_active'] ? 'btn-danger' : '' ?>"><?= $u['is_active'] ? 'Nonaktifkan' : 'Aktifkan' ?></button></form></div></td></tr>
  <tr><td colspan="7" style="padding:0;border-bottom:1px solid var(--line)"><details style="padding:6px 12px"><summary style="cursor:pointer;font-size:12px;color:var(--pri)">Ubah / reset password — <?= h($u['username']) ?></summary>
    <div class="grid g2" style="margin:10px 0">
      <form method="post" action="<?= h($self()) ?>"><?= Web::csrfField() ?><input type="hidden" name="do" value="teacher_reset"><input type="hidden" name="id" value="<?= $u['id'] ?>">
        <label>Password baru (min. 8)</label><div class="row"><input type="text" name="password" minlength="8" required autocomplete="off" style="flex:1"><button class="btn btn-sm">Reset</button></div></form>
      <form method="post" action="<?= h($self()) ?>"><?= Web::csrfField() ?><input type="hidden" name="do" value="teacher_edit"><input type="hidden" name="id" value="<?= $u['id'] ?>">
        <div class="field"><label>Nama</label><input type="text" name="nama" value="<?= h($u['nama']) ?>"></div>
        <div class="field"><label>Wali kelas</label><select name="kelas_wali_id"><option value="">Bukan wali kelas</option><?php foreach ($classes as $c): ?><option value="<?= $c['id'] ?>" <?= ($u['kelas_wali_id'] ?? null) === $c['id'] ? 'selected' : '' ?>><?= h($c['name']) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>Peran</label><div class="row"><?= $roleBoxes($u['roles']) ?></div></div>
        <div class="field"><label>Mapel</label><?= $checks('subjects', $subjects, array_map('intval', $u['subjects'])) ?></div>
        <div class="field"><label>Kelas</label><?= $checks('classes', $classes, array_map('intval', $u['classes'])) ?></div>
        <button class="btn btn-sm btn-pri">Simpan Perubahan</button></form>
    </div></details></td></tr>
<?php endforeach; ?></tbody></table></div></div>

<?php elseif ($tab === 'siswa'):
    $q = trim((string) ($_GET['q'] ?? '')); $fc = (int) ($_GET['class'] ?? 0);
    $list = array_values(array_filter(Repo::studentsAll($fc ?: null), fn($s) => $q === '' || mb_stripos($s['nama'], $q) !== false || mb_stripos($s['nis'], $q) !== false));
    usort($list, fn($a, $b) => [$a['class_id'], strtolower($a['nama'])] <=> [$b['class_id'], strtolower($b['nama'])]);
    $total = count($list); $list = array_slice($list, 0, 100);
    $stOpts = ['aktif' => 'Aktif', 'pindah' => 'Pindah', 'berhenti' => 'Berhenti', 'nonaktif' => 'Nonaktif', 'keluar' => 'Keluar']; ?>
<div class="grid g2">
<details class="card"><summary style="cursor:pointer;font-weight:700">+ Tambah Siswa</summary>
  <form method="post" action="<?= h($self()) ?>" style="margin-top:12px"><?= Web::csrfField() ?><input type="hidden" name="do" value="student_save">
    <div class="fields"><div><label>NIS</label><input type="text" name="nis" required></div><div><label>Nama</label><input type="text" name="nama" required></div>
      <div><label>JK</label><select name="jk"><option value="L">Laki-laki</option><option value="P">Perempuan</option></select></div>
      <div><label>Kelas</label><select name="class_id"><?php foreach ($classes as $c): ?><option value="<?= $c['id'] ?>" <?= $c['id'] === $fc ? 'selected' : '' ?>><?= h($c['name']) ?></option><?php endforeach; ?></select></div>
      <div><label>Status</label><?= Web::select('status', $stOpts, 'aktif') ?></div></div>
    <button class="btn btn-pri" style="margin-top:12px">Tambah</button></form></details>
<details class="card"><summary style="cursor:pointer;font-weight:700">⇪ Impor Siswa (xlsx / csv)</summary>
  <form method="post" enctype="multipart/form-data" action="<?= h($self()) ?>" style="margin-top:12px"><?= Web::csrfField() ?><input type="hidden" name="do" value="student_import">
    <p class="mut" style="font-size:12px">Kolom: <b>NIS, Nama Siswa, JK, Kelas</b> (nama kelas persis seperti di data kelas). NIS yang sudah ada dilewati.</p>
    <input type="file" name="file" accept=".xlsx,.csv" required><button class="btn btn-pri" style="margin-top:10px">Impor</button></form></details>
</div>
<form method="get" class="card"><input type="hidden" name="p" value="admin"><input type="hidden" name="tab" value="siswa">
  <div class="fields"><div><label>Cari</label><input type="search" name="q" value="<?= h($q) ?>" placeholder="Nama / NIS"></div>
    <div><label>Kelas</label><select name="class" data-auto><option value="0">Semua</option><?php foreach ($classes as $c): ?><option value="<?= $c['id'] ?>" <?= $c['id'] === $fc ? 'selected' : '' ?>><?= h($c['name']) ?></option><?php endforeach; ?></select></div>
    <div><button class="btn">Cari</button></div></div></form>
<div class="card card-tight"><div class="scroll"><table class="tbl"><thead><tr><th>NIS</th><th>Nama</th><th>JK</th><th>Kelas</th><th>Status</th><th class="r">Edit</th></tr></thead><tbody>
<?php foreach ($list as $s): ?>
  <tr><td class="mono"><?= h($s['nis']) ?></td><td><b><?= h($s['nama']) ?></b></td><td><?= h($s['jk']) ?></td><td><?= h($classMap[$s['class_id']] ?? '-') ?></td><td><span class="badge <?= $s['status'] === 'aktif' ? 'b-ok' : 'b-warn' ?>"><?= h($s['status']) ?></span></td>
    <td class="r"><details><summary style="cursor:pointer;color:var(--pri);font-size:12px">Ubah</summary>
      <form method="post" action="<?= h($self(['q' => $q ?: null, 'class' => $fc ?: null])) ?>" style="text-align:left;min-width:240px;margin:8px 0"><?= Web::csrfField() ?><input type="hidden" name="do" value="student_save"><input type="hidden" name="id" value="<?= $s['id'] ?>">
        <div class="field"><label>Nama</label><input type="text" name="nama" value="<?= h($s['nama']) ?>" required></div>
        <div class="field"><label>JK</label><?= Web::select('jk', ['L' => 'Laki-laki', 'P' => 'Perempuan'], $s['jk']) ?></div>
        <div class="field"><label>Kelas</label><select name="class_id"><?php foreach ($classes as $c): ?><option value="<?= $c['id'] ?>" <?= $c['id'] === $s['class_id'] ? 'selected' : '' ?>><?= h($c['name']) ?></option><?php endforeach; ?></select></div>
        <div class="field"><label>Status</label><?= Web::select('status', $stOpts, $s['status']) ?></div>
        <small>NIS tidak dapat diubah.</small><br><button class="btn btn-sm btn-pri" style="margin-top:6px">Simpan</button></form></details></td></tr>
<?php endforeach; ?>
<?php if (!$list): ?><tr><td colspan="6" class="empty">Belum ada siswa.</td></tr><?php endif; ?></tbody></table></div>
<?php if ($total > 100): ?><div class="empty">Menampilkan 100 dari <?= $total ?> siswa. Persempit pencarian.</div><?php endif; ?></div>

<?php elseif ($tab === 'kelas'): ?>
<div class="grid g2" style="align-items:start">
  <div class="card"><h2>Kelas</h2>
    <?php foreach ($classes as $c): ?>
      <details style="border-top:1px solid var(--line2);padding:8px 0"><summary style="cursor:pointer"><b><?= h($c['name']) ?></b> <small><?= h($c['jurusan']) ?> · <?= h($c['tahun_ajaran']) ?> <?= h($c['semester']) ?></small></summary>
        <form method="post" action="<?= h($self()) ?>" style="margin-top:8px"><?= Web::csrfField() ?><input type="hidden" name="do" value="class_save"><input type="hidden" name="id" value="<?= $c['id'] ?>">
          <div class="fields"><div><label>Nama</label><input type="text" name="name" value="<?= h($c['name']) ?>" required></div><div><label>Jurusan</label><input type="text" name="jurusan" value="<?= h($c['jurusan']) ?>"></div>
            <div><label>Angkatan</label><input type="text" name="angkatan" value="<?= h($c['angkatan']) ?>"></div><div><label>Tahun ajaran</label><input type="text" name="tahun_ajaran" value="<?= h($c['tahun_ajaran']) ?>" placeholder="2026/2027"></div>
            <div><label>Semester</label><?= Web::select('semester', ['Ganjil' => 'Ganjil', 'Genap' => 'Genap'], $c['semester']) ?></div></div><button class="btn btn-sm btn-pri" style="margin-top:8px">Simpan</button></form></details>
    <?php endforeach; ?>
    <details open style="border-top:1px solid var(--line);padding-top:10px;margin-top:8px"><summary style="cursor:pointer;font-weight:700">+ Tambah kelas</summary>
      <form method="post" action="<?= h($self()) ?>" style="margin-top:8px"><?= Web::csrfField() ?><input type="hidden" name="do" value="class_save">
        <div class="fields"><div><label>Nama</label><input type="text" name="name" placeholder="X RPL 1" required></div><div><label>Jurusan</label><input type="text" name="jurusan"></div>
          <div><label>Angkatan</label><input type="text" name="angkatan"></div><div><label>Tahun ajaran</label><input type="text" name="tahun_ajaran" placeholder="2026/2027"></div>
          <div><label>Semester</label><?= Web::select('semester', ['Ganjil' => 'Ganjil', 'Genap' => 'Genap'], 'Ganjil') ?></div></div><button class="btn btn-pri" style="margin-top:8px">Tambah Kelas</button></form></details>
  </div>
  <div class="card"><h2>Mata Pelajaran</h2>
    <?php foreach ($subjects as $s): ?>
      <form method="post" class="row" action="<?= h($self()) ?>" style="margin-bottom:6px"><?= Web::csrfField() ?><input type="hidden" name="do" value="subject_save"><input type="hidden" name="id" value="<?= $s['id'] ?>">
        <input type="text" name="name" value="<?= h($s['name']) ?>" style="flex:1" required><button class="btn btn-sm">Simpan</button></form>
    <?php endforeach; ?>
    <form method="post" class="row" action="<?= h($self()) ?>" style="margin-top:12px;border-top:1px solid var(--line);padding-top:12px"><?= Web::csrfField() ?><input type="hidden" name="do" value="subject_save">
      <input type="text" name="name" placeholder="Nama mata pelajaran baru" style="flex:1" required><button class="btn btn-pri btn-sm">Tambah</button></form>
    <small class="mut">Mapel ber-ID 5 dianggap “Bimbingan Konseling” dan disembunyikan dari daftar mapel reguler.</small>
  </div>
</div>

<?php elseif ($tab === 'pairing'): $pairs = Repo::pairingsAll(); $users = array_values(array_filter(Repo::usersAll(), fn($u) => $u['is_active'])); $uMap = array_column($users, 'nama', 'id'); ?>
<form method="post" class="card" action="<?= h($self()) ?>"><?= Web::csrfField() ?><input type="hidden" name="do" value="pair_add">
  <h2>Otorisasi Pasangan Guru – Mapel – Kelas</h2><p class="mut" style="font-size:12px">Guru hanya bisa mengisi absen mapel/nilai pada kombinasi yang terdaftar di sini.</p>
  <div class="fields"><div><label>Guru</label><select name="user_id"><?php foreach ($users as $u): ?><option value="<?= $u['id'] ?>"><?= h($u['nama']) ?></option><?php endforeach; ?></select></div>
    <div><label>Mata pelajaran</label><select name="subject_id"><?php foreach ($subjects as $s): ?><option value="<?= $s['id'] ?>"><?= h($s['name']) ?></option><?php endforeach; ?></select></div>
    <div><label>Kelas</label><select name="class_id"><?php foreach ($classes as $c): ?><option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?></select></div>
    <div><button class="btn btn-pri">Tambah Pasangan</button></div></div></form>
<div class="card card-tight"><table class="tbl"><thead><tr><th>Guru</th><th>Mata pelajaran</th><th>Kelas</th><th class="r">Aksi</th></tr></thead><tbody>
<?php foreach ($pairs as $p): ?><tr><td><?= h($uMap[$p['user_id']] ?? ('#' . $p['user_id'])) ?></td><td><?= h($subjMap[$p['subject_id']] ?? '-') ?></td><td><?= h($classMap[$p['class_id']] ?? '-') ?></td>
  <td class="r"><form method="post" class="inline" action="<?= h($self()) ?>" data-confirm="Hapus pasangan ini?"><?= Web::csrfField() ?><input type="hidden" name="do" value="pair_remove"><input type="hidden" name="user_id" value="<?= $p['user_id'] ?>"><input type="hidden" name="subject_id" value="<?= $p['subject_id'] ?>"><input type="hidden" name="class_id" value="<?= $p['class_id'] ?>"><button class="btn btn-sm btn-danger">Hapus</button></form></td></tr>
<?php endforeach; ?><?php if (!$pairs): ?><tr><td colspan="4" class="empty">Belum ada pasangan.</td></tr><?php endif; ?></tbody></table></div>

<?php elseif ($tab === 'hardcopy'): ?>
<?php if ($preview): ?>
  <form method="post" class="card card-tight" action="<?= h($self()) ?>"><?= Web::csrfField() ?><input type="hidden" name="do" value="hc_commit"><input type="hidden" name="class_id" value="<?= $preview['class_id'] ?>"><input type="hidden" name="date" value="<?= h($preview['date']) ?>">
    <div style="padding:16px"><h2>Pratinjau — <?= h($classMap[$preview['class_id']] ?? '') ?>, <?= h($preview['date']) ?></h2>
      <p><span class="badge b-ok"><?= $preview['valid'] ?> valid</span> <span class="badge <?= $preview['invalid'] ? 'b-bad' : '' ?>"><?= $preview['invalid'] ?> bermasalah (tidak disimpan)</span></p>
      <?php if ($preview['warn']): ?><div class="alert alert-warn"><b>Peringatan:</b><ul><?php foreach (array_slice($preview['warn'], 0, 15) as $w): ?><li><?= h($w) ?></li><?php endforeach; ?></ul></div><?php endif; ?></div>
    <div class="scroll"><table class="tbl"><thead><tr><th>NIS</th><th>Nama</th><th class="c">Status</th><th>Catatan</th><th>Hasil</th></tr></thead><tbody>
    <?php foreach ($preview['entries'] as $e): ?><tr><td class="mono"><?= h($e['nis']) ?></td><td><?= h($e['student']['nama'] ?? 'Tidak dikenal') ?></td><td class="c"><b><?= h($e['status']) ?></b></td><td><?= h($e['notes']) ?></td>
      <td><?php if ($e['ok']): ?><span class="badge b-ok">OK</span>
        <input type="hidden" name="e[<?= $e['student']['id'] ?>][status]" value="<?= h($e['status']) ?>"><input type="hidden" name="e[<?= $e['student']['id'] ?>][notes]" value="<?= h($e['notes']) ?>">
        <?php else: ?><span class="badge b-bad">Lewati</span><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div>
    <div class="sticky-save"><a class="btn" href="<?= h($self()) ?>">Batal</a><button class="btn btn-pri" <?= $preview['valid'] ? '' : 'disabled' ?>>Simpan <?= $preview['valid'] ?> Data</button></div>
  </form>
<?php else: ?>
  <div class="grid g2" style="align-items:start">
    <div class="card"><h2>1. Unduh template</h2>
      <form method="get" action="<?= h(Web::base() . '/') ?>" class="fields"><input type="hidden" name="p" value="export"><input type="hidden" name="type" value="template">
        <div><label>Kelas</label><select name="class"><?php foreach ($classes as $c): ?><option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?></select></div>
        <div><label>Tanggal</label><input type="date" name="date" value="<?= h(Web::today()) ?>"></div><div><button class="btn" data-multi>Unduh Template Excel</button></div></form></div>
    <div class="card"><h2>2. Unggah &amp; pratinjau</h2>
      <form method="post" enctype="multipart/form-data" action="<?= h($self()) ?>"><?= Web::csrfField() ?><input type="hidden" name="do" value="hc_preview">
        <div class="fields"><div><label>Kelas</label><select name="class_id"><?php foreach ($classes as $c): ?><option value="<?= $c['id'] ?>"><?= h($c['name']) ?></option><?php endforeach; ?></select></div>
          <div><label>Tanggal presensi</label><input type="date" name="date" value="<?= h(Web::today()) ?>" required></div></div>
        <div class="field" style="margin-top:12px"><input type="file" name="file" accept=".xlsx,.csv" required></div><button class="btn btn-pri">Pratinjau</button></form></div>
  </div>
  <?= Web::alert('info', 'Data tersimpan sebagai absensi harian dengan jalur “upload_hardcopy”. Hanya Administrator yang dapat memasukkan data lebih dari 7 hari ke belakang.') ?>
<?php endif; ?>

<?php elseif ($tab === 'log'):
    $mod = (string) ($_GET['modul'] ?? ''); $q = trim((string) ($_GET['q'] ?? ''));
    $all = Repo::auditAll();
    $mods = array_values(array_unique(array_column($all, 'module'))); sort($mods);
    $rows = array_values(array_filter($all, fn($r) => ($mod === '' || $r['module'] === $mod) && ($q === '' || mb_stripos($r['action'] . ' ' . $r['actor'] . ' ' . $r['details'], $q) !== false))); ?>
<form method="get" class="card"><input type="hidden" name="p" value="admin"><input type="hidden" name="tab" value="log">
  <div class="fields"><div><label>Modul</label><select name="modul" data-auto><option value="">Semua</option><?php foreach ($mods as $m): ?><option <?= $m === $mod ? 'selected' : '' ?>><?= h($m) ?></option><?php endforeach; ?></select></div>
    <div><label>Cari</label><input type="search" name="q" value="<?= h($q) ?>" placeholder="aksi, pelaku, detail"></div><div><button class="btn">Cari</button></div></div></form>
<div class="card card-tight"><div class="scroll"><table class="tbl"><thead><tr><th>Waktu (UTC)</th><th>Aksi</th><th>Modul</th><th>Pelaku</th><th>Detail</th></tr></thead><tbody>
<?php foreach ($rows as $r): ?><tr><td class="mono" style="white-space:nowrap"><?= h(str_replace('T', ' ', substr($r['timestamp'], 0, 19))) ?></td><td><b><?= h($r['action']) ?></b></td><td><?= h($r['module']) ?></td><td><?= h($r['actor']) ?></td><td style="font-size:12px"><?= h($r['details']) ?></td></tr><?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="5" class="empty">Tidak ada log.</td></tr><?php endif; ?></tbody></table></div></div>
<small class="mut">Menyimpan maksimal 500 log terbaru.</small>

<?php else: $s = Repo::settingsGet(); $dir = Backup::dir(); $files = array_reverse(glob($dir . '/absensi_*') ?: []); ?>
<div class="grid g2" style="align-items:start">
  <form method="post" class="card" action="<?= h($self()) ?>"><?= Web::csrfField() ?><input type="hidden" name="do" value="settings"><h2>Pengaturan Sekolah</h2>
    <div class="field"><label>Nama sekolah</label><input type="text" name="school_name" value="<?= h($s['school_name'] ?? '') ?>" required></div>
    <div class="fields"><div><label>Tahun ajaran</label><input type="text" name="tahun_ajaran" value="<?= h($s['tahun_ajaran'] ?? '') ?>" placeholder="2026/2027"></div>
      <div><label>Semester</label><?= Web::select('semester', ['Ganjil' => 'Ganjil', 'Genap' => 'Genap'], $s['semester'] ?? 'Ganjil') ?></div></div>
    <div class="fields" style="margin-top:12px"><div><label>Nama Kepala Sekolah</label><input type="text" name="kepsek_nama" value="<?= h($s['kepsek_nama'] ?? '') ?>"></div>
      <div><label>Nama Guru BK</label><input type="text" name="bk_nama" value="<?= h($s['bk_nama'] ?? '') ?>"></div></div>
    <div class="field" style="margin-top:12px"><label>Simpan backup (minggu)</label><input type="number" name="backup_retention_weeks" min="1" max="104" value="<?= (int) ($s['backup_retention_weeks'] ?? 8) ?>"></div>
    <button class="btn btn-pri">Simpan Pengaturan</button></form>
  <div class="card"><h2>Backup Database</h2>
    <p>Status terakhir: <span class="badge <?= ($s['last_backup_status'] ?? '') === 'success' ? 'b-ok' : (($s['last_backup_status'] ?? '') === 'failed' ? 'b-bad' : '') ?>"><?= h($s['last_backup_status'] ?? 'belum ada') ?></span>
      <small><?= !empty($s['last_backup_date']) ? h($s['last_backup_date']) . ' UTC' : '' ?></small></p>
    <p class="mut" style="font-size:12px">Backup otomatis berjalan tiap 24 jam saat ada yang login (atau jadwalkan <code>php cli/backup.php</code> lewat cron).</p>
    <div class="row"><form method="post" class="inline" action="<?= h($self()) ?>"><?= Web::csrfField() ?><input type="hidden" name="do" value="backup_now"><button class="btn btn-pri">Backup Sekarang</button></form>
      <a class="btn" href="<?= h(Web::url('export', ['type' => 'json'])) ?>">Unduh Data (JSON)</a></div>
    <div style="margin-top:14px"><?php foreach (array_slice($files, 0, 10) as $f): $b = basename($f); ?>
      <div class="row" style="justify-content:space-between;border-top:1px solid var(--line2);padding:6px 0;font-size:12px"><span class="mono"><?= h($b) ?> <small>(<?= number_format(filesize($f) / 1024, 0) ?> KB)</small></span><a href="<?= h(Web::url('export', ['type' => 'backup', 'file' => $b])) ?>">Unduh</a></div>
    <?php endforeach; ?><?php if (!$files): ?><small class="mut">Belum ada berkas backup.</small><?php endif; ?></div>
  </div>
</div>
<?php endif; ?>
<?php Web::foot();
