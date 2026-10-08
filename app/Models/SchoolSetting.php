<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/** Pengaturan sekolah (satu baris, id = 1). */
class SchoolSetting extends Model
{
    public $timestamps = false;
    public $incrementing = false;
    protected $guarded = [];
    protected $casts = ['id' => 'integer', 'backup_retention_weeks' => 'integer', 'staff_mandiri' => 'boolean', 'staff_lat' => 'float', 'staff_lng' => 'float', 'staff_radius' => 'integer', 'pw_min' => 'integer', 'pw_huruf' => 'boolean', 'pw_angka' => 'boolean', 'pw_simbol' => 'boolean', 'att_alpa' => 'integer', 'att_sakit' => 'integer', 'att_izin' => 'integer', 'att_total' => 'integer'];

    private static ?self $cached = null;

    public static function current(): self
    {
        if (self::$cached) {
            return self::$cached;
        }
        $row = null;
        try {
            $row = static::find(1);
        } catch (\Throwable) {
            // tabel belum dibuat (sebelum instalasi)
        }

        return self::$cached = $row ?? new static([
            'id' => 1, 'school_name' => config('absensi.school_name'), 'tahun_ajaran' => '', 'semester' => 'Ganjil',
            'kepsek_nama' => '', 'bk_nama' => '', 'backup_retention_weeks' => 8,
        ]);
    }

    public static function forget(): void
    {
        self::$cached = null;
    }

    public static function put(array $values): self
    {
        $s = static::find(1) ?? new static(['id' => 1, 'school_name' => config('absensi.school_name'), 'semester' => 'Ganjil', 'backup_retention_weeks' => 8]);
        $s->fill($values)->save();
        self::$cached = null;

        return $s;
    }
}
