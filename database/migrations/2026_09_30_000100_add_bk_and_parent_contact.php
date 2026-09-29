<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tambahan fitur:
 * - Kontak orang tua di data siswa (untuk tombol WhatsApp gratis via wa.me).
 * - Catatan BK terpadu (Pelanggaran, Buku Kasus, Prestasi, Home Visit, Riwayat Surat) dalam satu tabel.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $t) {
            if (! Schema::hasColumn('students', 'nama_ortu')) {
                $t->string('nama_ortu', 191)->nullable();
            }
            if (! Schema::hasColumn('students', 'telp_ortu')) {
                $t->string('telp_ortu', 32)->nullable();
            }
        });

        if (! Schema::hasTable('bk_records')) {
            Schema::create('bk_records', function (Blueprint $t) {
                $t->id();
                $t->string('jenis', 20);                 // pelanggaran | kasus | prestasi | home_visit | surat
                $t->integer('student_id');
                $t->integer('class_id');                 // kelas saat kejadian (tetap walau siswa naik kelas)
                $t->string('tanggal', 10);
                $t->string('kategori', 60)->nullable();  // tingkat / bidang / jenis surat
                $t->string('judul', 191);
                $t->text('uraian')->nullable();
                $t->text('tindak_lanjut')->nullable();
                $t->string('status', 16)->nullable();    // Proses | Selesai
                $t->boolean('rahasia')->default(false);  // Buku Kasus "Sangat Rahasia"
                $t->text('extra')->nullable();           // JSON: layanan, peringkat, alamat, nomor surat, dst
                $t->string('berkas_path', 255)->nullable();
                $t->integer('dicatat_oleh')->nullable();
                $t->timestamps();
                $t->index(['jenis', 'student_id']);
                $t->index(['jenis', 'class_id', 'tanggal']);
            });
        }
    }

    public function down(): void
    {
        // Sengaja kosong: tidak menghapus data sekolah.
    }
};
