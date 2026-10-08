<?php

namespace App\Support;

use App\Models\SchoolSetting;

/**
 * Ambang kategori "Perlu Perhatian" (Admin → Pengaturan):
 * alpa/sakit/izin tinggi bila jumlah jenis itu ≥ ambangnya; "jarang masuk (gabungan)" bila total (alpa+izin+sakit) ≥ ambang gabungan.
 * Sebelum pembaruan database dipasang berlaku bawaan 2 / 2 / 2 / 3.
 */
class AttentionPolicy
{
    public const DEFAULTS = ['alpa' => 2, 'sakit' => 2, 'izin' => 2, 'total' => 3];
    public const MIN = 1;
    public const MAX = 100;

    /** @return array{alpa:int, sakit:int, izin:int, total:int} */
    public static function current(): array
    {
        try {
            if (! DbUpdate::attReady()) {
                return self::DEFAULTS;
            }
            $s = SchoolSetting::current();
        } catch (\Throwable) { // mis. dipakai tanpa aplikasi/DB (tes unit murni)
            return self::DEFAULTS;
        }
        $out = [];
        foreach (self::DEFAULTS as $k => $def) {
            $v = (int) ($s->{'att_'.$k} ?? 0);
            $out[$k] = $v >= self::MIN ? min(self::MAX, $v) : $def;
        }

        return $out;
    }

    /** Kategori untuk jumlah alpa/izin/sakit tertentu (null = tidak perlu perhatian). */
    public static function category(int $alpa, int $izin, int $sakit, ?array $p = null): ?string
    {
        $p ??= self::current();

        return match (true) {
            $alpa >= $p['alpa'] => 'alpa_tinggi',
            $sakit >= $p['sakit'] => 'sakit_tinggi',
            $izin >= $p['izin'] => 'izin_tinggi',
            $alpa + $izin + $sakit >= $p['total'] => 'jarang_masuk_gabungan',
            default => null,
        };
    }
}
