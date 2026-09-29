<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/** Tanggal sesi selalu YYYY-MM-DD; "hari ini" menurut zona sekolah (WIB), data disimpan UTC. */
class Dates
{
    public const DAYS = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    public const MONTHS = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    public static function tz(): string
    {
        return config('absensi.timezone', 'Asia/Jakarta');
    }

    public static function today(): string
    {
        return CarbonImmutable::now(self::tz())->format('Y-m-d');
    }

    /** Epoch detik (UTC 00:00) untuk tanggal valid, atau null. */
    public static function parse(mixed $ymd): ?int
    {
        if (! is_string($ymd) || ! preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $ymd, $m) || ! checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return gmmktime(0, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1]);
    }

    public static function valid(mixed $ymd): bool
    {
        return self::parse($ymd) !== null;
    }

    /** Selisih hari (dibulatkan ke bawah) antara sekarang dan tanggal. Negatif = masa depan. */
    public static function daysSince(string $ymd): int
    {
        return (int) floor((time() - (int) self::parse($ymd)) / 86400);
    }

    public static function weekday(string $ymd): int
    {
        return (int) gmdate('w', (int) self::parse($ymd));
    }

    public static function human(string $ymd): string
    {
        $ts = self::parse($ymd);
        if ($ts === null) {
            return $ymd;
        }
        $short = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'Mei', 'Jun', 'Jul', 'Agu', 'Sep', 'Okt', 'Nov', 'Des'];

        return self::DAYS[(int) gmdate('w', $ts)].', '.(int) gmdate('j', $ts).' '.$short[(int) gmdate('n', $ts)].' '.gmdate('Y', $ts);
    }

    public static function long(?string $ymd = null): string
    {
        $d = $ymd && self::parse($ymd) !== null ? CarbonImmutable::parse($ymd, 'UTC') : CarbonImmutable::now(self::tz());

        return $d->day.' '.self::MONTHS[$d->month].' '.$d->year;
    }

    public static function nowMillis(): int
    {
        return (int) floor(microtime(true) * 1000);
    }

    public static function isoUtc(?int $millis = null): string
    {
        $millis ??= self::nowMillis();

        return gmdate('Y-m-d\TH:i:s', intdiv($millis, 1000)).sprintf('.%03dZ', $millis % 1000);
    }

    public static function local(int $millis): string
    {
        return CarbonImmutable::createFromTimestampMs($millis)->setTimezone(self::tz())->format('j/n/Y H.i');
    }
}
