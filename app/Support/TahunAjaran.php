<?php

namespace App\Support;

use App\Models\SchoolSetting;

/** Rentang tanggal tahun ajaran & semester (Ganjil: Jul–Des, Genap: Jan–Jun). */
class TahunAjaran
{
    /** @return array{0:int,1:int} tahun awal & akhir, dari Pengaturan ("2026/2027") atau dari tanggal hari ini. */
    public static function years(?string $label = null): array
    {
        $label ??= (string) SchoolSetting::current()->tahun_ajaran;
        if (preg_match('/(\d{4})\s*[\/\-]\s*(\d{4})/', $label, $m)) {
            return [(int) $m[1], (int) $m[2]];
        }
        [$y, $mo] = array_map('intval', explode('-', substr(Dates::today(), 0, 7)));
        $start = $mo >= 7 ? $y : $y - 1;

        return [$start, $start + 1];
    }

    public static function label(): string
    {
        [$a, $b] = self::years();

        return "$a/$b";
    }

    public static function semester(): string
    {
        return SchoolSetting::current()->semester === 'Genap' ? 'Genap' : 'Ganjil';
    }

    /** @return array{0:string,1:string} */
    public static function semesterRange(?string $semester = null, ?string $label = null): array
    {
        [$a, $b] = self::years($label);

        return ($semester ?? self::semester()) === 'Genap' ? ["$b-01-01", "$b-06-30"] : ["$a-07-01", "$a-12-31"];
    }

    /** @return array{0:string,1:string} */
    public static function yearRange(?string $label = null): array
    {
        [$a, $b] = self::years($label);

        return ["$a-07-01", "$b-06-30"];
    }
}
