<?php

namespace App\Support;

use Illuminate\Support\Facades\Hash;

/**
 * Verifikasi password: mendukung hash lama "sha256:<salt>:<hash>" (versi React/Node/PHP murni)
 * dan hash Laravel (bcrypt). Hash lama otomatis diganti bcrypt saat login berhasil.
 */
class Passwords
{
    public static function check(string $plain, ?string $stored): bool
    {
        if (! $stored) {
            return false;
        }
        if (str_starts_with($stored, 'sha256:')) {
            $p = explode(':', $stored);

            return count($p) === 3 && hash_equals($stored, 'sha256:'.$p[1].':'.hash('sha256', $p[1].':'.$plain));
        }

        return Hash::check($plain, $stored);
    }

    public static function needsRehash(?string $stored): bool
    {
        return ! $stored || str_starts_with($stored, 'sha256:') || Hash::needsRehash($stored);
    }

    public static function make(string $plain): string
    {
        return Hash::make($plain);
    }
}
