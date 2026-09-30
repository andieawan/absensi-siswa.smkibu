<?php

namespace App\Services;

use App\Exceptions\UserError;
use App\Models\SchoolSetting;
use App\Models\StaffAttendance;
use App\Models\User;
use App\Support\Dates;
use App\Support\DbUpdate;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
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
                $upd = ['status' => $st, 'notes' => $note, 'dicatat_oleh' => $actor->id];
                if (DbUpdate::selfReady() && $row->status !== $st) {
                    $upd['sumber'] = 'tu';      // dikoreksi TU; jam & penanda terlambat tetap tersimpan
                }
                $row->update($upd);
                $updated++;
            } else {
                StaffAttendance::create(['user_id' => $u->id, 'tanggal' => $tanggal, 'status' => $st, 'notes' => $note, 'dicatat_oleh' => $actor->id]);
                $created++;
            }
        }
        Audit::log('Absensi Guru & Staf', 'TU', $actor->nama, "$tanggal: $created baru, $updated diperbarui");

        return ['created' => $created, 'updated' => $updated];
    }

    /** Rekap satu bulan ('YYYY-MM'). @return array<int, array{user:User, H:int, I:int, S:int, A:int, D:int, T:int, total:int, rate:?float}> */
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
        $late = DbUpdate::selfReady()
            ? StaffAttendance::whereBetween('tanggal', ["$bulan-01", $to])->where('terlambat', true)->selectRaw('user_id, COUNT(*) AS n')->groupBy('user_id')->pluck('n', 'user_id')->all()
            : [];
        $out = [];
        foreach (self::staff() as $u) {
            $c = $by[$u->id] ?? [];
            $total = array_sum($c);
            $out[] = ['user' => $u, 'H' => $c['H'] ?? 0, 'I' => $c['I'] ?? 0, 'S' => $c['S'] ?? 0, 'A' => $c['A'] ?? 0, 'D' => $c['D'] ?? 0,
                'T' => (int) ($late[$u->id] ?? 0), 'total' => $total, 'rate' => $total ? round((($c['H'] ?? 0) + ($c['D'] ?? 0)) / $total * 100, 1) : null];
        }

        return $out;
    }

    // ---------------------------------------------------------------- Absen mandiri

    public static function selfEligible(User $u): bool
    {
        return $u->is_active && $u->hasRole(...self::ROLES);
    }

    /** Pengaturan absen mandiri (dengan nilai bawaan bila kolom belum ada). */
    public static function config(): array
    {
        $s = SchoolSetting::current();
        $radius = (int) ($s->staff_radius ?? 0);
        $lat = $s->staff_lat ?? null;
        $lng = $s->staff_lng ?? null;

        return [
            'aktif' => DbUpdate::selfReady() && (bool) ($s->staff_mandiri ?? true),
            'batas' => $s->staff_jam_masuk ?: '07:15',
            'lat' => $lat, 'lng' => $lng,
            'radius' => ($lat !== null && $lng !== null) ? max(0, $radius) : 0,
        ];
    }

    public static function selfActive(): bool
    {
        return self::config()['aktif'];
    }

    public static function nowHm(): string
    {
        return CarbonImmutable::now(Dates::tz())->format('H:i');
    }

    /** Jarak (meter) dua titik koordinat, rumus haversine. */
    public static function distance(float $lat1, float $lng1, float $lat2, float $lng2): int
    {
        $r = 6371000;
        $a = deg2rad($lat2 - $lat1);
        $b = deg2rad($lng2 - $lng1);
        $h = sin($a / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($b / 2) ** 2;

        return (int) round(2 * $r * asin(min(1, sqrt($h))));
    }

    public static function todayRow(User $u): ?StaffAttendance
    {
        return StaffAttendance::where('user_id', $u->id)->where('tanggal', Dates::today())->first();
    }

    /** Status kartu "Absen Hari Ini", atau null bila fitur tidak berlaku untuk pengguna ini. */
    public static function state(?User $u): ?array
    {
        if (! $u || ! self::selfEligible($u) || ! self::selfActive()) {
            return null;
        }

        return ['row' => self::todayRow($u), 'cfg' => self::config(), 'today' => Dates::today(), 'now' => self::nowHm()];
    }

    /** Memeriksa lokasi bila sekolah mengaktifkan batas radius. @return ?int jarak dalam meter */
    private static function checkLocation(array $cfg, mixed $lat, mixed $lng): ?int
    {
        if ($cfg['radius'] <= 0) {
            return is_numeric($lat) && is_numeric($lng) && $cfg['lat'] !== null ? self::distance((float) $lat, (float) $lng, (float) $cfg['lat'], (float) $cfg['lng']) : null;
        }
        if (! is_numeric($lat) || ! is_numeric($lng) || abs((float) $lat) > 90 || abs((float) $lng) > 180) {
            throw new UserError('Lokasi tidak terbaca. Izinkan akses lokasi (GPS) pada browser, lalu coba lagi. Absen mandiri memakai lokasi untuk memastikan Anda berada di sekolah.');
        }
        $d = self::distance((float) $lat, (float) $lng, (float) $cfg['lat'], (float) $cfg['lng']);
        if ($d > $cfg['radius']) {
            throw new UserError("Anda berada sekitar {$d} m dari sekolah (batas {$cfg['radius']} m). Absen mandiri hanya bisa dilakukan di sekolah; jika sedang bertugas di luar, gunakan Lapor Dinas Luar atau hubungi TU.");
        }

        return $d;
    }

    private static function ensureOpen(User $u): array
    {
        if (! self::selfEligible($u)) {
            throw new UserError('Akun ini tidak termasuk guru/staf yang diabsen.');
        }
        $cfg = self::config();
        if (! $cfg['aktif']) {
            throw new UserError('Absen mandiri sedang dimatikan oleh sekolah. Absensi dicatat oleh TU.');
        }

        return $cfg;
    }

    public static function checkIn(User $u, mixed $lat = null, mixed $lng = null): StaffAttendance
    {
        $cfg = self::ensureOpen($u);
        $row = self::todayRow($u);
        if ($row && $row->jam_masuk) {
            throw new UserError("Anda sudah absen masuk pukul {$row->jam_masuk}.");
        }
        if ($row && $row->status !== 'H') {
            throw new UserError('Kehadiran hari ini sudah tercatat sebagai '.self::STATUS[$row->status].'. Jika keliru, hubungi TU.');
        }
        $jarak = self::checkLocation($cfg, $lat, $lng);
        $jam = self::nowHm();
        $vals = ['jam_masuk' => $jam, 'terlambat' => $jam > $cfg['batas'], 'jarak' => $jarak, 'sumber' => 'mandiri'];
        if ($row) {
            $row->update($vals);

            return $row;
        }
        try {
            return StaffAttendance::create($vals + ['user_id' => $u->id, 'tanggal' => Dates::today(), 'status' => 'H', 'dicatat_oleh' => $u->id]);
        } catch (QueryException) {
            throw new UserError('Absensi hari ini sudah tercatat. Muat ulang halaman.');
        }
    }

    public static function checkOut(User $u, mixed $lat = null, mixed $lng = null): StaffAttendance
    {
        $cfg = self::ensureOpen($u);
        $row = self::todayRow($u);
        if (! $row || $row->status !== 'H' || ! $row->jam_masuk) {
            throw new UserError('Absen masuk dulu sebelum absen pulang.');
        }
        if ($row->jam_pulang) {
            throw new UserError("Anda sudah absen pulang pukul {$row->jam_pulang}.");
        }
        self::checkLocation($cfg, $lat, $lng);
        $row->update(['jam_pulang' => self::nowHm()]);

        return $row;
    }

    /** Lapor tidak masuk hari ini: Izin, Sakit, atau Dinas Luar (catatan wajib). */
    public static function report(User $u, string $status, string $note): StaffAttendance
    {
        self::ensureOpen($u);
        if (! in_array($status, ['I', 'S', 'D'], true)) {
            throw new UserError('Pilih Izin, Sakit, atau Dinas Luar.');
        }
        $note = mb_substr(trim($note), 0, 200);
        if (mb_strlen($note) < 3) {
            throw new UserError('Isi keterangan singkat (mis. alasan izin atau tujuan dinas).');
        }
        $row = self::todayRow($u);
        if ($row && ! ($row->sumber === 'mandiri' && ! $row->jam_masuk)) {
            throw new UserError('Kehadiran hari ini sudah tercatat. Jika keliru, hubungi TU.');
        }
        $vals = ['status' => $status, 'notes' => $note, 'sumber' => 'mandiri', 'terlambat' => false, 'dicatat_oleh' => $u->id];
        if ($row) {
            $row->update($vals);

            return $row;
        }

        return StaffAttendance::create($vals + ['user_id' => $u->id, 'tanggal' => Dates::today()]);
    }

    /** Riwayat absen milik sendiri. */
    public static function history(User $u, int $days = 30): Collection
    {
        return StaffAttendance::where('user_id', $u->id)->where('tanggal', '>=', gmdate('Y-m-d', strtotime(Dates::today().' UTC') - $days * 86400))->orderByDesc('tanggal')->get();
    }
}
