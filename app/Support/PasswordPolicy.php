<?php

namespace App\Support;

use App\Exceptions\UserError;
use App\Models\SchoolSetting;

/**
 * Kebijakan password sekolah (diatur Admin → Pengaturan):
 *  - mode "bebas": password apa saja asal tidak kosong;
 *  - mode "aturan": panjang minimal + (opsional) wajib huruf, angka, dan/atau simbol.
 * Sebelum pembaruan database dipasang, berlaku aturan lama: minimal 8 karakter.
 */
class PasswordPolicy
{
    public const MIN_LIMIT = 1;
    public const MAX_LIMIT = 64;

    /** @return array{mode:string, min:int, huruf:bool, angka:bool, simbol:bool} */
    public static function current(): array
    {
        $d = ['mode' => 'aturan', 'min' => 8, 'huruf' => false, 'angka' => false, 'simbol' => false];
        if (! DbUpdate::pwReady()) {
            return $d;
        }
        $s = SchoolSetting::current();
        if (($s->pw_mode ?? 'aturan') === 'bebas') {
            return ['mode' => 'bebas', 'min' => 1, 'huruf' => false, 'angka' => false, 'simbol' => false];
        }

        return ['mode' => 'aturan', 'min' => max(self::MIN_LIMIT, min(self::MAX_LIMIT, (int) ($s->pw_min ?: 8))),
            'huruf' => (bool) $s->pw_huruf, 'angka' => (bool) $s->pw_angka, 'simbol' => (bool) $s->pw_simbol];
    }

    /** Daftar syarat yang belum terpenuhi (kosong = lolos). */
    public static function problems(string $pw, ?array $p = null): array
    {
        $p ??= self::current();
        $bad = [];
        if ($pw === '') {
            return ['tidak boleh kosong'];
        }
        if (mb_strlen($pw) < $p['min']) {
            $bad[] = "minimal {$p['min']} karakter";
        }
        if ($p['huruf'] && ! preg_match('/\p{L}/u', $pw)) {
            $bad[] = 'harus mengandung huruf';
        }
        if ($p['angka'] && ! preg_match('/\d/', $pw)) {
            $bad[] = 'harus mengandung angka';
        }
        if ($p['simbol'] && ! preg_match('/[^\p{L}\d\s]/u', $pw)) {
            $bad[] = 'harus mengandung simbol (mis. ! @ # $ %)';
        }

        return $bad;
    }

    /** Lempar UserError bila password tidak memenuhi kebijakan. */
    public static function assert(string $pw): void
    {
        if ($bad = self::problems($pw)) {
            throw new UserError('Password '.implode(', ', $bad).'.');
        }
    }

    /** Aturan validasi Laravel: ->validate(['password' => ['required', PasswordPolicy::rule()]]). */
    public static function rule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail): void {
            if ($bad = self::problems((string) $value)) {
                $fail('Password '.implode(', ', $bad).'.');
            }
        };
    }

    /** Kalimat ringkas untuk label/petunjuk, mis. "min. 8 karakter, wajib huruf & angka". */
    public static function hint(?array $p = null): string
    {
        $p ??= self::current();
        if ($p['mode'] === 'bebas') {
            return 'bebas, asal tidak kosong';
        }
        $need = array_values(array_filter([$p['huruf'] ? 'huruf' : null, $p['angka'] ? 'angka' : null, $p['simbol'] ? 'simbol' : null]));

        return "min. {$p['min']} karakter".($need ? ', wajib '.implode(' & ', $need) : '');
    }

    /** Password acak yang pasti memenuhi kebijakan (untuk impor akun tanpa password). */
    public static function generate(): string
    {
        $p = self::current();
        $letters = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ';
        $digits = '23456789';
        $symbols = '!@#$%*?-_';
        $pick = fn (string $set) => $set[random_int(0, strlen($set) - 1)];
        $len = max(10, $p['min']);
        $chars = [$pick($letters), $pick($digits)];          // selalu ada huruf & angka
        if ($p['simbol']) {
            $chars[] = $pick($symbols);
        }
        $pool = $letters.$digits;
        while (count($chars) < $len) {
            $chars[] = $pick($pool);
        }
        for ($i = count($chars) - 1; $i > 0; $i--) {          // acak urutan
            $j = random_int(0, $i);
            [$chars[$i], $chars[$j]] = [$chars[$j], $chars[$i]];
        }

        return implode('', $chars);
    }
}
