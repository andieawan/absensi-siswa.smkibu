<?php
declare(strict_types=1);

$user = Web::requireLogin();
$err = '';
if (Web::isPost()) {
    $old = (string) ($_POST['old'] ?? '');
    $new = (string) ($_POST['new'] ?? '');
    $conf = (string) ($_POST['confirm'] ?? '');
    $fresh = Repo::userById($user['id']);
    if ($old === '') $err = 'Password lama wajib diisi.';
    elseif (mb_strlen($new) < 8) $err = 'Password baru minimal 8 karakter.';
    elseif ($new !== $conf) $err = 'Konfirmasi password tidak sama.';
    elseif ($old === $new) $err = 'Password baru harus berbeda dari password lama.';
    elseif (!$fresh || !Util::verifyPassword($old, $fresh['password_hash'] ?? null)) $err = 'Password lama tidak sesuai.';
    else {
        Repo::userUpsert(array_merge($fresh, ['password_hash' => Util::hashPassword($new)]));
        // Sesi di perangkat lain dicabut; sesi ini tetap berlaku.
        $cur = $_COOKIE['absen_sid'] ?? null;
        Repo::sessionDestroyAllForUser($user['id'], is_string($cur) ? $cur : null);
        Repo::audit('Ganti Password Mandiri', 'Akun Guru', $user['nama'], 'Pengguna mengganti password akunnya sendiri');
        Web::flash('success', 'Password berhasil diganti. Sesi di perangkat lain otomatis keluar.');
        Web::redirect(Web::url('dashboard'));
    }
}
Web::head('Ganti Password', '');
?>
<div class="card" style="max-width:460px;margin:0 auto">
  <h1>Ganti Password</h1><p class="mut">Akun: <b><?= h($user['username']) ?></b></p>
  <?php if ($err) echo Web::alert('error', $err); ?>
  <form method="post" action="<?= h(Web::url('password')) ?>" autocomplete="off"><?= Web::csrfField() ?>
    <div class="field"><label for="old">Password lama</label><input id="old" type="password" name="old" autocomplete="current-password" required></div>
    <div class="field"><label for="new">Password baru (min. 8 karakter)</label><input id="new" type="password" name="new" autocomplete="new-password" minlength="8" required></div>
    <div class="field"><label for="confirm">Ulangi password baru</label><input id="confirm" type="password" name="confirm" autocomplete="new-password" required></div>
    <div class="row"><button class="btn btn-pri">Simpan Password</button><a class="btn" href="<?= h(Web::url('dashboard')) ?>">Batal</a></div>
  </form>
</div>
<?php Web::foot();
