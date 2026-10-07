<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Kunci API per aplikasi (integrasi dengan aplikasi web lain). Kunci disimpan sebagai hash. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('api_keys')) {
            Schema::create('api_keys', function (Blueprint $t) {
                $t->id();
                $t->string('name', 120);
                $t->string('key_prefix', 16);                 // tampilan saja, mis. "ak_1a2b3c4d"
                $t->string('key_hash', 64)->unique();         // sha256 dari kunci lengkap
                $t->text('scopes');                           // JSON daftar izin
                $t->boolean('is_active')->default(true);
                $t->integer('rate_limit')->default(60);       // permintaan per menit
                $t->string('last_used_at', 32)->nullable();
                $t->string('last_ip', 45)->nullable();
                $t->integer('created_by')->nullable();
                $t->string('created_at', 32);
                $t->string('revoked_at', 32)->nullable();
            });
        }
    }

    public function down(): void
    {
        // Sengaja kosong.
    }
};
