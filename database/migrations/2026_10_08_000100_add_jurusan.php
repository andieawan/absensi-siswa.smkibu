<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Daftar jurusan (kode + nama). Kelas menyimpan KODE jurusan pada kolom classes.jurusan (tetap kompatibel dengan data lama). */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('jurusan')) {
            return;
        }
        Schema::create('jurusan', function (Blueprint $t) {
            $t->id();
            $t->string('kode', 32)->unique();
            $t->string('nama', 191);
        });
        // Isi awal dari nilai jurusan yang sudah dipakai kelas.
        $seen = [];
        foreach (DB::table('classes')->whereNotNull('jurusan')->pluck('jurusan') as $j) {
            $k = trim((string) $j);
            if ($k !== '' && ! isset($seen[mb_strtoupper($k)])) {
                $seen[mb_strtoupper($k)] = true;
                DB::table('jurusan')->insert(['kode' => $k, 'nama' => $k]);
            }
        }
    }

    public function down(): void
    {
        // Sengaja kosong.
    }
};
