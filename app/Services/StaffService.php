<?php

namespace App\Services;

use App\Exceptions\UserError;
use App\Models\StaffAttendance;
use App\Models\User;
use App\Support\Dates;
use Illuminate\Support\Collection;

/** Absensi guru & staf (dicatat TU). Yang diabsen: akun aktif berperan guru, BK, kepsek, atau TU. */
class StaffService
{
    public const STATUS = ['H' => 'Hadir', 'I' => 'Izin', 'S' => 'Sakit', 'A' => 'Alpa', 'D' => 'Dinas Luar'];
    public const ROLES = ['guru', 'bk', 'kepsek', 'tu'];

    /** @return Collection<int, User> */
    public static function staff(): Collection
    {
        return User::where('is_active', true)->orderBy('nama')->get()->filter(fn (User $u) => $u->hasRole(...self::ROLES))->values();
    }

    /** @return array{created:int, updated:int} */
    public static function save(User $actor, string $tanggal, array $status, array $notes): array
    {
        if (! Dates::valid($tanggal) || Dates::isFuture($tanggal)) {
            throw new UserError('Tanggal tidak valid atau berada di masa depan.');
        }
        if (! $actor->isAdmin() && ! Rules::withinEditWindow($tanggal)) {
            throw new UserError('Absensi lebih dari 7 hari terkunci; hanya Administrator yang dapat mengubah.');
        }
        $created = $updated = 0;
        foreach (self::staff() as $u) {
            $st = isset(self::STATUS[$status[$u->id] ?? '']) ? $status[$u->id] : 'H';
            $note = mb_substr(trim((string) ($notes[$u->id] ?? '')), 0, 200) ?: null;
            $row = StaffAttendance::where('user_id', $u->id)->where('tanggal', $tanggal)->first();
            if ($row) {
                $row->update(['status' => $st, 'notes' => $note, 'dicatat_oleh' => $actor->id]);
                $updated++;
            } else {
                StaffAttendance::create(['user_id' => $u->id, 'tanggal' => $tanggal, 'status' => $st, 'notes' => $note, 'dicatat_oleh' => $actor->id]);
                $created++;
            }
        }
        Audit::log('Absensi Guru & Staf', 'TU', $actor->nama, "$tanggal: $created baru, $updated diperbarui");

        return ['created' => $created, 'updated' => $updated];
    }

    /** Rekap satu bulan ('YYYY-MM'). @return array<int, array{user:User, H:int, I:int, S:int, A:int, D:int, total:int, rate:?float}> */
    public static function recap(string $bulan): array
    {
        if (! preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $bulan)) {
            throw new UserError('Bulan tidak valid.');
        }
        $to = gmdate('Y-m-t', strtotime("$bulan-01 UTC"));
        $by = [];
        foreach (StaffAttendance::whereBetween('tanggal', ["$bulan-01", $to])->selectRaw('user_id, status, COUNT(*) AS n')->groupBy('user_id', 'status')->get() as $r) {
            $by[$r->user_id][$r->status] = (int) $r->n;
        }
        $out = [];
        foreach (self::staff() as $u) {
            $c = $by[$u->id] ?? [];
            $total = array_sum($c);
            $out[] = ['user' => $u, 'H' => $c['H'] ?? 0, 'I' => $c['I'] ?? 0, 'S' => $c['S'] ?? 0, 'A' => $c['A'] ?? 0, 'D' => $c['D'] ?? 0,
                'total' => $total, 'rate' => $total ? round((($c['H'] ?? 0) + ($c['D'] ?? 0)) / $total * 100, 1) : null];
        }

        return $out;
    }
}
