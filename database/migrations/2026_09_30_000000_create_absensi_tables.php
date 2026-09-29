<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Skema aplikasi absensi. Nama tabel & kolom SAMA dengan versi sebelumnya (Node/PHP murni),
 * jadi database lama bisa langsung dipakai: tabel yang sudah ada dilewati.
 * ID diisi dari tabel `sequences` (bukan auto-increment) agar kompatibel dengan data lama.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->create('users', function (Blueprint $t) {
            $t->integer('id')->primary();
            $t->string('username', 191)->unique();
            $t->string('password_hash', 255)->nullable();
            $t->string('nama', 191);
            $t->integer('kelas_wali_id')->nullable();
            $t->string('foto_profil_url', 500)->nullable();
            $t->boolean('is_active')->default(true);
            $t->string('created_at', 32);
            $t->text('roles');
            $t->text('subjects');
            $t->text('classes');
        });
        $this->create('classes', function (Blueprint $t) {
            $t->integer('id')->primary();
            $t->string('name', 191);
            $t->string('jurusan', 191)->nullable();
            $t->string('angkatan', 32)->nullable();
            $t->string('tahun_ajaran', 32)->nullable();
            $t->string('semester', 32)->nullable();
        });
        $this->create('subjects', function (Blueprint $t) {
            $t->integer('id')->primary();
            $t->string('name', 191);
        });
        $this->create('students', function (Blueprint $t) {
            $t->integer('id')->primary();
            $t->string('nis', 64);
            $t->string('nama', 191);
            $t->string('jk', 4);
            $t->integer('class_id')->index();
            $t->string('status', 32);
            $t->string('created_at', 32);
        });
        $this->create('attendance', function (Blueprint $t) {
            $t->integer('id')->primary();
            $t->integer('student_id');
            $t->integer('class_id');
            $t->integer('subject_id')->nullable();
            $t->string('tanggal', 10);
            $t->string('status', 4);
            $t->integer('recorded_by')->nullable();
            $t->string('recorded_via', 64)->nullable();
            $t->text('notes')->nullable();
            $t->string('created_at', 32);
            $t->string('updated_at', 32);
            $t->bigInteger('created_at_millis')->nullable();
            $t->unique(['student_id', 'subject_id', 'tanggal'], 'uniq_attendance');
            $t->index(['class_id', 'tanggal']);
        });
        $this->create('grade_activities', function (Blueprint $t) {
            $t->string('id', 64)->primary();
            $t->integer('teacher_id');
            $t->integer('subject_id');
            $t->integer('class_id');
            $t->string('nama_kegiatan', 191);
            $t->string('tanggal_kegiatan', 10)->nullable();
            $t->string('tipe_skala', 16);
            $t->string('created_at', 32);
        });
        $this->create('grade_values', function (Blueprint $t) {
            $t->string('activity_id', 64);
            $t->integer('student_id');
            $t->string('nilai', 16);
            $t->primary(['activity_id', 'student_id']);
        });
        $this->create('teacher_subject_class_pairing', function (Blueprint $t) {
            $t->integer('user_id');
            $t->integer('subject_id');
            $t->integer('class_id');
            $t->primary(['user_id', 'subject_id', 'class_id']);
        });
        $this->create('ketua_kelas_tokens', function (Blueprint $t) {
            $t->string('token', 128)->primary();
            $t->integer('class_id');
            $t->string('status', 16);
            $t->string('created_at', 32);
            $t->integer('created_by')->nullable();
            $t->string('expires_at', 32)->nullable();
            $t->bigInteger('expires_at_millis')->nullable();
        });
        $this->create('parent_access_tokens', function (Blueprint $t) {
            $t->string('token', 128)->primary();
            $t->integer('student_id')->index();
            $t->string('status', 16)->default('aktif');
            $t->string('created_at', 32);
            $t->integer('created_by')->nullable();
            $t->string('revoked_at', 32)->nullable();
        });
        $this->create('audit_log', function (Blueprint $t) {
            $t->increments('id');
            $t->string('timestamp', 32);
            $t->string('action', 191);
            $t->string('module', 64);
            $t->string('actor', 191)->nullable();
            $t->text('details')->nullable();
        });
        $this->create('sequences', function (Blueprint $t) {
            $t->string('entity', 64)->primary();
            $t->integer('value');
        });
        $this->create('school_settings', function (Blueprint $t) {
            $t->integer('id')->primary();
            $t->string('school_name', 191)->nullable();
            $t->string('logo_url', 500)->nullable();
            $t->string('tahun_ajaran', 32)->nullable();
            $t->string('semester', 32)->nullable();
            $t->string('kepsek_nama', 191)->nullable();
            $t->string('bk_nama', 191)->nullable();
            $t->integer('backup_retention_weeks')->nullable();
            $t->string('last_backup_date', 32)->nullable();
            $t->string('last_backup_status', 16)->nullable();
        });
    }

    private function create(string $table, Closure $cb): void
    {
        if (! Schema::hasTable($table)) {
            Schema::create($table, $cb);
        }
    }

    public function down(): void
    {
        // Sengaja kosong: tidak menghapus data sekolah secara tidak sengaja.
    }
};
