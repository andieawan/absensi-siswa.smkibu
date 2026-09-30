<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Absen mandiri guru & staf: jam masuk/pulang, terlambat, sumber, jarak; pengaturan jam & lokasi sekolah. */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('staff_attendance')) {
            Schema::table('staff_attendance', function (Blueprint $t) {
                foreach ([
                    'jam_masuk' => fn () => $t->string('jam_masuk', 5)->nullable(),
                    'jam_pulang' => fn () => $t->string('jam_pulang', 5)->nullable(),
                    'terlambat' => fn () => $t->boolean('terlambat')->default(false),
                    'sumber' => fn () => $t->string('sumber', 10)->default('tu'),   // tu | mandiri
                    'jarak' => fn () => $t->integer('jarak')->nullable(),            // meter dari sekolah saat absen masuk
                ] as $col => $add) {
                    if (! Schema::hasColumn('staff_attendance', $col)) {
                        $add();
                    }
                }
            });
        }
        if (Schema::hasTable('school_settings')) {
            Schema::table('school_settings', function (Blueprint $t) {
                foreach ([
                    'staff_mandiri' => fn () => $t->boolean('staff_mandiri')->default(true),
                    'staff_jam_masuk' => fn () => $t->string('staff_jam_masuk', 5)->default('07:15'),
                    'staff_lat' => fn () => $t->double('staff_lat')->nullable(),
                    'staff_lng' => fn () => $t->double('staff_lng')->nullable(),
                    'staff_radius' => fn () => $t->integer('staff_radius')->nullable(),   // meter; kosong/0 = tanpa cek lokasi
                ] as $col => $add) {
                    if (! Schema::hasColumn('school_settings', $col)) {
                        $add();
                    }
                }
            });
        }
    }

    public function down(): void
    {
        // Sengaja kosong.
    }
};
