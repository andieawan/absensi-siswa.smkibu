<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Modul Tata Usaha: mutasi siswa, register surat masuk/keluar (+ surat keterangan), absensi guru & staf. */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('mutasi_siswa')) {
            Schema::create('mutasi_siswa', function (Blueprint $t) {
                $t->id();
                $t->string('jenis', 10);                    // masuk | keluar
                $t->integer('student_id');
                $t->integer('class_id')->nullable();        // kelas saat mutasi
                $t->string('tanggal', 10);
                $t->string('sekolah', 191)->nullable();     // sekolah asal (masuk) / tujuan (keluar)
                $t->string('alasan', 191)->nullable();
                $t->string('status_baru', 16)->nullable();  // status siswa setelah mutasi keluar
                $t->string('nomor_surat', 80)->nullable();
                $t->integer('dicatat_oleh')->nullable();
                $t->timestamps();
                $t->index(['jenis', 'tanggal']);
                $t->index('student_id');
            });
        }
        if (! Schema::hasTable('tu_surat')) {
            Schema::create('tu_surat', function (Blueprint $t) {
                $t->id();
                $t->string('arah', 10);                     // masuk | keluar
                $t->string('jenis', 16)->default('biasa');  // biasa | aktif | pindah | dispensasi | izin
                $t->integer('urut');                        // nomor agenda per arah per tahun
                $t->integer('tahun');
                $t->string('nomor', 80)->nullable();
                $t->string('tanggal', 10);
                $t->string('pihak', 191)->nullable();       // pengirim (masuk) / tujuan (keluar)
                $t->string('perihal', 191);
                $t->text('isi')->nullable();
                $t->string('status', 16)->nullable();       // surat masuk: Baru | Diproses | Selesai
                $t->string('disposisi', 191)->nullable();
                $t->integer('student_id')->nullable();
                $t->text('extra')->nullable();              // JSON: keperluan, mulai, sampai, sekolah_tujuan, alasan
                $t->string('berkas_path', 255)->nullable();
                $t->integer('dicatat_oleh')->nullable();
                $t->timestamps();
                $t->unique(['arah', 'tahun', 'urut']);
                $t->index(['arah', 'tanggal']);
            });
        }
        if (! Schema::hasTable('staff_attendance')) {
            Schema::create('staff_attendance', function (Blueprint $t) {
                $t->id();
                $t->integer('user_id');
                $t->string('tanggal', 10);
                $t->string('status', 1);                    // H I S A D
                $t->string('notes', 200)->nullable();
                $t->integer('dicatat_oleh')->nullable();
                $t->timestamps();
                $t->unique(['user_id', 'tanggal']);
                $t->index('tanggal');
            });
        }
    }

    public function down(): void
    {
        // Sengaja kosong: tidak menghapus data sekolah.
    }
};
