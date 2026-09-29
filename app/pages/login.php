<?php
declare(strict_types=1);

// Login, ganti akun (verifikasi ulang password), dan logout.
if (($_GET['p'] ?? '') === 'logout') {
    if (Web::isPost()) {
        Web::verifyPost();
        Web::logout();
        Web::flash('success', 'Anda sudah keluar.');
    }
    Web::redirect(Web::url('login'));
}

$switch = isset($_GET['switch']) && Web::user();
if (Web::user() && !$switch) {
    Web::redirect(Web::url('dashboard'));
}

$error = '';
$prefill = '';
if (Web::isPost()) {
    if ($switch) Web::verifyPost();
    $username = strtolower(trim((string) ($_POST['username'] ?? '')));
    $password = (string) ($_POST['password'] ?? '');
    $prefill = $username;
    $key = sha1(Req::ip() . '|' . $username);
    if ($username === '' || $password === '') {
        $error = 'Username dan password wajib diisi.';
    } elseif (($locked = Repo::loginLockedFor($key)) > 0) {
        $error = 'Terlalu banyak percobaan login gagal. Coba lagi dalam ' . (int) ceil($locked / 60) . ' menit.';
    } else {
        $user = Repo::userByUsername($username);
        if ($user && $user['is_active'] && Util::verifyPassword($password, $user['password_hash'] ?? null)) {
            Repo::loginClear($key);
            Web::login($user);
            Repo::audit($switch ? 'Ganti Akun' : 'Login', 'Akun Guru', $user['nama'], $switch ? 'Pindah akun dengan verifikasi ulang password' : 'Login berhasil');
            try {
                Backup::runIfDue(24);
            } catch (Throwable $e) {
                error_log('[auto-backup] ' . $e->getMessage());
            }
            Web::redirect(Web::url('dashboard'));
        }
        Repo::loginRecordFailure($key);
        $error = 'Username atau password salah, atau akun nonaktif.';
    }
}

$settings = Repo::settingsGet();
Web::head($switch ? 'Ganti Akun' : 'Masuk', '', !$switch);
?>
<div class="card login">
  <div class="logo">A</div>
  <h1><?= $switch ? 'Ganti Akun' : 'Absensi Siswa' ?></h1>
  <p class="mut"><?= h($settings['school_name'] ?? '') ?><?= $switch ? '<br>Masukkan username dan password akun tujuan.' : '<br>Masuk dengan akun guru yang diberikan administrator.' ?></p>
  <?php if ($error): ?><?= Web::alert('error', $error) ?><?php endif; ?>
  <form method="post" action="<?= h(Web::url('login', $switch ? ['switch' => 1] : [])) ?>" autocomplete="off">
    <?php if ($switch) echo Web::csrfField(); ?>
    <div class="field"><label for="u">Username</label><input id="u" type="text" name="username" value="<?= h($prefill) ?>" autocapitalize="none" autocomplete="username" required autofocus></div>
    <div class="field"><label for="pw">Password</label><input id="pw" type="password" name="password" autocomplete="current-password" required></div>
    <button class="btn btn-pri" style="width:100%"><?= $switch ? 'Pindah Akun' : 'Masuk' ?></button>
  </form>
  <?php if ($switch): ?><p style="margin-top:14px"><a href="<?= h(Web::url('dashboard')) ?>">&larr; Batal, kembali</a></p><?php endif; ?>
</div>
<?php Web::foot();
