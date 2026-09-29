<?php

namespace App\Services;

use App\Exceptions\UserError;
use App\Models\SchoolClass;
use App\Models\SchoolSetting;
use App\Models\Student;
use App\Models\Subject;
use App\Models\User;
use App\Support\Passwords;
use Illuminate\Support\Facades\DB;

class AdminService
{
    public const ROLES = ['guru' => 'Guru', 'kepsek' => 'Kepala Sekolah', 'bk' => 'Guru BK', 'admin' => 'Admin', 'superadmin' => 'Superadmin'];

    private static function cleanRoles(User $actor, array $roles, array $old = []): array
    {
        $roles = array_values(array_intersect(array_keys(self::ROLES), $roles));
        if (! $roles) {
            throw new UserError('Pilih minimal satu peran.');
        }
        $priv = fn (array $r) => array_values(array_intersect($r, ['admin', 'superadmin']));
        if ($priv($roles) !== $priv($old) && ! $actor->hasRole('superadmin')) {
            throw new UserError('Hanya Superadmin yang dapat memberi atau mencabut peran Administrator.');
        }

        return $roles;
    }

    /** Satu kelas hanya boleh punya satu wali kelas. */
    private static function ensureSingleWali(?int $classId, ?int $exceptUserId = null): void
    {
        if (! $classId) {
            return;
        }
        $other = User::where('kelas_wali_id', $classId)->when($exceptUserId, fn ($q) => $q->where('id', '!=', $exceptUserId))->first();
        if ($other) {
            throw new UserError("Kelas ini sudah punya wali kelas: {$other->nama}. Lepaskan dulu tugas wali dari akun tersebut.");
        }
    }

    public static function addTeacher(User $actor, array $in): User
    {
        $username = strtolower(trim((string) ($in['username'] ?? '')));
        if (! preg_match('/^[a-z0-9._-]{3,}$/', $username)) {
            throw new UserError('Username minimal 3 karakter (huruf kecil, angka, titik, strip).');
        }
        if (User::whereRaw('LOWER(username) = ?', [$username])->exists()) {
            throw new UserError("Username '$username' sudah dipakai.");
        }
        self::ensureSingleWali(($in['kelas_wali_id'] ?? null) ? (int) $in['kelas_wali_id'] : null);
        $u = User::create([
            'username' => $username, 'nama' => trim($in['nama']), 'password_hash' => Passwords::make($in['password']),
            'roles' => self::cleanRoles($actor, (array) ($in['roles'] ?? ['guru'])), 'kelas_wali_id' => ($in['kelas_wali_id'] ?? null) ? (int) $in['kelas_wali_id'] : null,
            'subjects' => array_map('intval', (array) ($in['subjects'] ?? [])), 'classes' => array_map('intval', (array) ($in['classes'] ?? [])),
            'is_active' => true,
        ]);
        Audit::log('Tambah Akun Guru', 'Akun Guru', $actor->nama, "{$u->nama} ({$u->username}), peran: ".implode(',', $u->roles));

        return $u;
    }

    public static function updateTeacher(User $actor, User $u, array $in): void
    {
        $roles = self::cleanRoles($actor, (array) ($in['roles'] ?? []), $u->roles ?? []);
        if ($u->id === $actor->id && ! array_intersect($roles, ['admin', 'superadmin'])) {
            throw new UserError('Anda tidak dapat mencabut peran Administrator dari akun Anda sendiri.');
        }
        self::ensureSingleWali(($in['kelas_wali_id'] ?? null) ? (int) $in['kelas_wali_id'] : null, $u->id);
        $u->update([
            'nama' => trim($in['nama']) ?: $u->nama, 'roles' => $roles, 'kelas_wali_id' => ($in['kelas_wali_id'] ?? null) ? (int) $in['kelas_wali_id'] : null,
            'subjects' => array_map('intval', (array) ($in['subjects'] ?? [])), 'classes' => array_map('intval', (array) ($in['classes'] ?? [])),
        ]);
        Audit::log('Ubah Akun Guru', 'Akun Guru', $actor->nama, $u->username);
    }

    public static function resetPassword(User $actor, User $u, string $pw): void
    {
        if ($u->hasRole('superadmin') && ! $actor->hasRole('superadmin')) {
            throw new UserError('Password Superadmin hanya dapat direset oleh Superadmin.');
        }
        $u->update(['password_hash' => Passwords::make($pw)]);
        Audit::log('Reset Password', 'Akun Guru', $actor->nama, "Akun {$u->username}");
    }

    public static function toggle(User $actor, User $u): bool
    {
        if ($u->id === $actor->id) {
            throw new UserError('Anda tidak dapat menonaktifkan akun Anda sendiri.');
        }
        if ($u->hasRole('superadmin') && ! $actor->hasRole('superadmin')) {
            throw new UserError('Akun Superadmin hanya dapat diubah oleh Superadmin.');
        }
        $u->update(['is_active' => ! $u->is_active]);
        Audit::log($u->is_active ? 'Aktifkan Akun' : 'Nonaktifkan Akun', 'Akun Guru', $actor->nama, $u->username);

        return $u->is_active;
    }

    public static function saveStudent(User $actor, ?Student $s, array $in, bool $audit = true): Student
    {
        if (! SchoolClass::whereKey($in['class_id'])->exists()) {
            throw new UserError('Kelas tidak valid.');
        }
        $data = ['nama' => trim($in['nama']), 'jk' => $in['jk'], 'class_id' => (int) $in['class_id'], 'status' => $in['status'] ?? 'aktif'];
        if ((array_key_exists('telp_ortu', $in) || array_key_exists('nama_ortu', $in)) && \App\Support\DbUpdate::parentContactReady()) {
            $data['nama_ortu'] = trim((string) ($in['nama_ortu'] ?? '')) ?: null;
            $data['telp_ortu'] = \App\Support\WhatsApp::normalize((string) ($in['telp_ortu'] ?? ''));
            if ($data['telp_ortu'] === null && trim((string) ($in['telp_ortu'] ?? '')) !== '') {
                throw new UserError('Nomor HP orang tua tidak valid. Contoh: 081234567890.');
            }
        }
        if ($s) {
            $s->update($data); // NIS tidak dapat diubah
            Audit::log('Ubah Data Siswa', 'Siswa', $actor->nama, "{$s->nama} (NIS {$s->nis})");

            return $s;
        }
        $nis = trim((string) ($in['nis'] ?? ''));
        if ($nis === '') {
            throw new UserError('NIS wajib diisi.');
        }
        if (Student::where('nis', $nis)->exists()) {
            throw new UserError("NIS $nis sudah terdaftar.");
        }
        $s = Student::create($data + ['nis' => $nis]);
        if ($audit) {
            Audit::log('Tambah Siswa', 'Siswa', $actor->nama, "{$s->nama} (NIS $nis)");
        }

        return $s;
    }

    /** Impor siswa dari baris xlsx/csv. Kolom: NIS, Nama Siswa, JK, Kelas (nama kelas); opsional Nama Ortu, No HP Ortu. @return array{0:int,1:string[]} */
    public static function importStudents(User $actor, array $hdr, array $rows): array
    {
        $iNis = \App\Support\Xlsx::col($hdr, ['nis']);
        $iNama = \App\Support\Xlsx::col($hdr, ['nama siswa', 'nama']);
        $iJk = \App\Support\Xlsx::col($hdr, ['jk', 'jenis kelamin', 'l/p']);
        $iKelas = \App\Support\Xlsx::col($hdr, ['kelas']);
        $iOrtu = \App\Support\Xlsx::col($hdr, ['nama ortu', 'nama orang tua', 'nama wali', 'orang tua']);
        $iTelp = \App\Support\Xlsx::col($hdr, ['no hp ortu', 'hp ortu', 'no hp', 'no. hp', 'telp ortu', 'telepon', 'no wa', 'whatsapp']);
        if ($iNis === null || $iNama === null || $iKelas === null) {
            throw new UserError('Kolom wajib: NIS, Nama Siswa, JK, Kelas (nama kelas sama persis dengan data kelas).');
        }
        $classes = SchoolClass::pluck('id', 'name')->mapWithKeys(fn ($id, $n) => [mb_strtolower($n) => $id]);
        $ok = 0;
        $skip = [];
        DB::transaction(function () use ($actor, $rows, $iNis, $iNama, $iJk, $iKelas, $iOrtu, $iTelp, $classes, &$ok, &$skip) {
            foreach ($rows as $n => $r) {
                $nis = trim((string) ($r[$iNis] ?? ''));
                $nama = trim((string) ($r[$iNama] ?? ''));
                if ($nis === '' && $nama === '') {
                    continue;
                }
                $jk = strtoupper(substr(trim((string) ($iJk !== null ? ($r[$iJk] ?? 'L') : 'L')), 0, 1));
                try {
                    $cid = $classes[mb_strtolower(trim((string) ($r[$iKelas] ?? '')))] ?? throw new UserError('kelas tidak dikenal');
                    if ($nama === '' || ! in_array($jk, ['L', 'P'], true)) {
                        throw new UserError('nama/JK tidak valid');
                    }
                    self::saveStudent($actor, null, ['nis' => $nis, 'nama' => $nama, 'jk' => $jk, 'class_id' => $cid, 'status' => 'aktif',
                        'nama_ortu' => $iOrtu !== null ? trim((string) ($r[$iOrtu] ?? '')) : '', 'telp_ortu' => $iTelp !== null ? trim((string) ($r[$iTelp] ?? '')) : ''], false);
                    $ok++;
                } catch (UserError $e) {
                    $skip[] = 'Baris '.($n + 2)." ($nis): ".$e->getMessage();
                }
            }
        });
        Audit::log('Impor Siswa', 'Siswa', $actor->nama, "$ok siswa ditambahkan, ".count($skip).' baris dilewati');

        return [$ok, $skip];
    }

    public static function saveClass(User $actor, ?SchoolClass $c, array $in): SchoolClass
    {
        $data = array_map(fn ($v) => trim((string) $v), array_intersect_key($in, array_flip(['name', 'jurusan', 'angkatan', 'tahun_ajaran', 'semester'])));
        $c ? $c->update($data) : $c = SchoolClass::create($data);
        Audit::log('Simpan Kelas', 'Data Master', $actor->nama, $c->name);

        return $c;
    }

    public static function saveSubject(User $actor, ?Subject $s, string $name): Subject
    {
        $s ? $s->update(['name' => trim($name)]) : $s = Subject::create(['name' => trim($name)]);
        Audit::log('Simpan Mapel', 'Data Master', $actor->nama, $s->name);

        return $s;
    }

    public static function saveSettings(User $actor, array $in): void
    {
        SchoolSetting::put([
            'school_name' => trim($in['school_name']), 'tahun_ajaran' => trim((string) ($in['tahun_ajaran'] ?? '')),
            'semester' => $in['semester'], 'kepsek_nama' => trim((string) ($in['kepsek_nama'] ?? '')),
            'bk_nama' => trim((string) ($in['bk_nama'] ?? '')), 'backup_retention_weeks' => (int) $in['backup_retention_weeks'],
        ]);
        Audit::log('Ubah Pengaturan Sekolah', 'Sistem', $actor->nama, 'Pengaturan sekolah diperbarui');
    }
}
