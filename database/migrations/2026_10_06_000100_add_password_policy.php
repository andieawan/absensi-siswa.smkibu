<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Kebijakan password yang bisa diatur Admin: bebas, atau aturan (panjang minimal + huruf/angka/simbol). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('school_settings')) {
            return;
        }
        Schema::table('school_settings', function (Blueprint $t) {
            foreach ([
                'pw_mode' => fn () => $t->string('pw_mode', 10)->default('aturan'),     // aturan | bebas
                'pw_min' => fn () => $t->integer('pw_min')->default(8),
                'pw_huruf' => fn () => $t->boolean('pw_huruf')->default(false),
                'pw_angka' => fn () => $t->boolean('pw_angka')->default(false),
                'pw_simbol' => fn () => $t->boolean('pw_simbol')->default(false),
            ] as $col => $add) {
                if (! Schema::hasColumn('school_settings', $col)) {
                    $add();
                }
            }
        });
    }

    public function down(): void
    {
        // Sengaja kosong.
    }
};
