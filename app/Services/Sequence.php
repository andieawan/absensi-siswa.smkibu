<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/** Alokasi ID atomik dari tabel `sequences` (baris dikunci selama transaksi). */
class Sequence
{
    public static function next(string $entity, int $count = 1): int
    {
        return self::allocate($entity, $count)[0];
    }

    /** @return int[] */
    public static function allocate(string $entity, int $count = 1): array
    {
        return DB::transaction(function () use ($entity, $count) {
            $row = DB::table('sequences')->where('entity', $entity)->lockForUpdate()->first();
            $current = $row ? (int) $row->value : max(1000, (int) self::maxExisting($entity));
            $next = $current + $count;
            DB::table('sequences')->updateOrInsert(['entity' => $entity], ['value' => $next]);

            return range($current + 1, $next);
        });
    }

    /** Untuk DB yang belum punya baris sequence: mulai setelah ID terbesar yang sudah ada. */
    private static function maxExisting(string $entity): int
    {
        try {
            return (int) DB::table($entity)->max('id');
        } catch (\Throwable) {
            return 0;
        }
    }

    /** Pastikan sequence tidak di bawah ID yang sudah terpakai (dipakai seeder/impor). */
    public static function atLeast(string $entity, int $value): void
    {
        $row = DB::table('sequences')->where('entity', $entity)->first();
        if (! $row || (int) $row->value < $value) {
            DB::table('sequences')->updateOrInsert(['entity' => $entity], ['value' => max($value, 1000)]);
        }
    }
}
