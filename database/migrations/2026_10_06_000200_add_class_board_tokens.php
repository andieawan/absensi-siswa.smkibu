<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Tautan "Info Kehadiran Kelas" (baca-saja) yang dibagikan wali kelas ke grup wali murid. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('class_board_tokens')) {
            Schema::create('class_board_tokens', function (Blueprint $t) {
                $t->string('token', 128)->primary();
                $t->integer('class_id')->index();
                $t->string('status', 16)->default('aktif');
                $t->string('created_at', 32);
                $t->integer('created_by')->nullable();
                $t->string('revoked_at', 32)->nullable();
            });
        }
    }

    public function down(): void
    {
        // Sengaja kosong.
    }
};
