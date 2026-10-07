<?php

namespace App\Support;

/** Daftar izin (scope) kunci API. Hanya baca; data sensitif (catatan BK, nilai) belum dibuka. */
class ApiScopes
{
    public const ALL = [
        'kelas:baca' => 'Baca daftar kelas',
        'mapel:baca' => 'Baca daftar mata pelajaran',
        'siswa:baca' => 'Baca data siswa (NIS, nama, JK, kelas, status)',
        'siswa:kontak' => 'Baca nama & No. HP orang tua (data pribadi — beri hanya bila perlu)',
        'kehadiran:baca' => 'Baca data kehadiran & rekap per siswa',
    ];

    public static function valid(array $scopes): array
    {
        return array_values(array_intersect(array_keys(self::ALL), $scopes));
    }
}
