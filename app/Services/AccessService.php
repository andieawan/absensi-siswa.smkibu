<?php

namespace App\Services;

use App\Exceptions\UserError;
use App\Models\DelegationToken;
use App\Models\ParentToken;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\User;
use App\Support\Dates;

/** Delegasi Ketua Kelas, Portal Wali Murid, dan pengesahan akademik (aturan 85%). */
class AccessService
{
    private static function randomToken(string $prefix): string
    {
        return $prefix.rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    }

    public static function createDelegation(User $user, int $classId, int $hours = 24): DelegationToken
    {
        if (! SchoolClass::whereKey($classId)->exists()) {
            throw new UserError('Kelas tidak valid.');
        }
        if (! $user->hasRole('bk')) {
            Rules::requireTeacher($user, null, $classId); // wali kelas (atau admin); Guru BK boleh untuk kelas mana pun
        }
        $hours = min(max(1, $hours), 24);
        $now = Dates::nowMillis();
        $exp = $now + $hours * 3600000;
        $t = DelegationToken::create([
            'token' => self::randomToken('kk_'), 'class_id' => $classId, 'status' => 'aktif', 'created_at' => Dates::isoUtc($now),
            'created_by' => $user->id, 'expires_at' => Dates::isoUtc($exp), 'expires_at_millis' => $exp,
        ]);
        Audit::log('Buat Delegasi', 'Absensi', $user->nama, "Kelas #$classId, berlaku $hours jam");

        return $t;
    }

    public static function revokeDelegation(User $user, DelegationToken $t): void
    {
        if (! $user->hasRole('bk')) {
            Rules::requireTeacher($user, null, $t->class_id);
        }
        if ($t->status === 'aktif') {
            $t->update(['status' => 'dicabut']);
            Audit::log('Cabut Delegasi', 'Absensi', $user->nama, "Kelas #{$t->class_id}");
        }
    }

    public static function activeDelegation(string $token): DelegationToken
    {
        $t = $token !== '' ? DelegationToken::find($token) : null;
        if (! $t) {
            throw new UserError('Tautan presensi tidak ditemukan di database sekolah.');
        }
        if ($t->status !== 'aktif') {
            throw new UserError('Tautan presensi ini sudah tidak aktif atau telah dicabut.');
        }
        if (! $t->isUsable()) {
            throw new UserError('Tautan presensi telah kedaluwarsa (berakhir '.Dates::local($t->expiryMillis()).' WIB). Minta Wali Kelas membuatkan tautan baru.');
        }

        return $t;
    }

    public static function submitDelegation(string $token, string $tanggal, array $entries): int
    {
        $t = self::activeDelegation($token);
        if (! Dates::valid($tanggal)) {
            throw new UserError('Format tanggal tidak valid.');
        }
        if (Dates::isFuture($tanggal) || ! Rules::withinEditWindow($tanggal)) {
            throw new UserError('Tanggal presensi harus hari ini atau maksimal 7 hari ke belakang.');
        }
        Rules::validateEntries($entries, $t->class_id);
        AttendanceService::write($entries, $t->class_id, null, $tanggal, (int) $t->created_by, 'ketua_kelas_delegasi');
        Audit::log('Submit Absensi Delegasi', 'Absensi', "Ketua Kelas (token kelas #{$t->class_id})", "Tanggal: $tanggal, Total: ".count($entries).' siswa');

        return count($entries);
    }

    public static function canManageParentAccess(User $user, Student $s): bool
    {
        return $user->isAdmin() || $user->isWaliOf($s->class_id);
    }

    public static function createParentToken(User $user, Student $s): ParentToken
    {
        if (! self::canManageParentAccess($user, $s)) {
            throw new UserError('Akses wali murid hanya dapat dikelola Wali Kelas siswa ini atau Administrator.');
        }
        $t = ParentToken::create(['token' => self::randomToken('wm_'), 'student_id' => $s->id, 'status' => 'aktif', 'created_at' => Dates::isoUtc(), 'created_by' => $user->id]);
        Audit::log('Buat Akses Wali Murid', 'Siswa', $user->nama, "Siswa #{$s->id} ({$s->nama})");

        return $t;
    }

    public static function revokeParentToken(User $user, ParentToken $t): void
    {
        $s = Student::findOrFail($t->student_id);
        if (! self::canManageParentAccess($user, $s)) {
            throw new UserError('Tidak berwenang mencabut akses ini.');
        }
        if ($t->status === 'aktif') {
            $t->update(['status' => 'nonaktif', 'revoked_at' => Dates::isoUtc()]);
            Audit::log('Cabut Akses Wali Murid', 'Siswa', $user->nama, "Siswa #{$s->id} ({$s->nama})");
        }
    }

    /** @return array{code:string, rate:float, override:bool} */
    public static function clearance(User $user, Student $s, bool $override, string $reason): array
    {
        if (! ($user->hasRole('admin', 'superadmin', 'kepsek', 'bk') || $user->isWaliOf($s->class_id))) {
            throw new UserError('Pengesahan akademik hanya oleh Wali Kelas siswa, Guru BK, Kepala Sekolah, atau Administrator.');
        }
        if ($override && ! $user->hasRole('admin', 'superadmin', 'kepsek')) {
            throw new UserError('Dispensasi pengesahan akademik khusus Administrator / Kepala Sekolah.');
        }
        if ($override && trim($reason) === '') {
            throw new UserError('Alasan dispensasi wajib diisi.');
        }
        $st = Rules::attendanceStats($s->id);
        if (! $st['meets'] && ! $override) {
            throw new UserError("Pengesahan ditolak: kehadiran {$s->nama} hanya {$st['rate']}% (minimal 85%). Selesaikan pembinaan BK atau minta dispensasi Kepala Sekolah.");
        }
        $code = sprintf('CLR-%s-%s-%s', gmdate('Y'), $s->nis, strtoupper(substr(bin2hex(random_bytes(4)), 0, 5)));
        Audit::log('Pengesahan Akademik', 'Akademik', $user->nama, "Siswa NIS {$s->nis} ({$s->nama}) disahkan dengan kehadiran {$st['rate']}% (Override: ".($override ? "ya — $reason" : 'tidak').')');

        return ['code' => $code, 'rate' => $st['rate'], 'override' => $override];
    }
}
