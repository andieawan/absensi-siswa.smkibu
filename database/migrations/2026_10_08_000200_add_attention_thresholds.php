<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Ambang "Perlu Perhatian" yang bisa diatur Admin (bawaan: alpa/sakit/izin ≥ 2, gabungan ≥ 3). */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('school_settings')) {
            return;
        }
        Schema::table('school_settings', function (Blueprint $t) {
            foreach (['att_alpa' => 2, 'att_sakit' => 2, 'att_izin' => 2, 'att_total' => 3] as $col => $def) {
                if (! Schema::hasColumn('school_settings', $col)) {
                    $t->integer($col)->default($def);
                }
            }
        });
    }

    public function down(): void
    {
        // Sengaja kosong.
    }
};
