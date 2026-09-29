<?php

namespace App\Support;

/**
 * Definisi modul BK terpadu. Satu tabel (bk_records), tiap "jenis" punya label & kolom sendiri.
 * Keputusan desain (mengikuti rencana Aplikasi Manajemen BK):
 * - Pelanggaran: tanpa poin, tingkat Ringan/Sedang/Berat, hanya BK yang mencatat, sanksi ditentukan manual.
 * - Buku Kasus: bisa ditandai "Sangat Rahasia" (Wali Kelas/Kepsek hanya melihat "ada kasus", tanpa detail).
 * - Prestasi: BK dan Wali Kelas (kelasnya sendiri) boleh mencatat.
 * - Superadmin setara BK. Kepsek & Admin: lihat semua (baca saja). Wali Kelas: lihat kelasnya.
 */
class BkModules
{
    public const STATUS = ['Proses', 'Selesai'];

    public static function all(): array
    {
        return [
            'pelanggaran' => [
                'label' => 'Pelanggaran', 'icon' => '⚠️', 'desc' => 'Catatan pelanggaran tata tertib (tanpa poin). Sanksi/tindak lanjut ditentukan BK per kejadian.',
                'kategori' => ['Tingkat', ['Ringan', 'Sedang', 'Berat']],
                'judul' => ['Pelanggaran', 'mis. Membolos jam ke-3 dan ke-4'],
                'uraian' => 'Kronologi / keterangan', 'tindak' => 'Tindak lanjut / sanksi', 'status' => true,
                'extra' => [], 'rahasia' => false, 'berkas' => false, 'wali_tulis' => false,
            ],
            'kasus' => [
                'label' => 'Buku Kasus', 'icon' => '📒', 'desc' => 'Catatan layanan konseling. Tandai "Sangat Rahasia" agar Wali Kelas/Kepsek hanya melihat bahwa ada kasus, tanpa detail.',
                'kategori' => ['Bidang', ['Pribadi', 'Sosial', 'Belajar', 'Karier']],
                'judul' => ['Permasalahan', 'mis. Sering murung dan menarik diri di kelas'],
                'uraian' => 'Uraian / hasil konseling', 'tindak' => 'Rencana tindak lanjut', 'status' => true,
                'extra' => ['layanan' => ['Jenis layanan', 'select', ['Konseling Individu', 'Konseling Kelompok', 'Konsultasi Orang Tua', 'Kolaborasi Wali Kelas', 'Alih Tangan Kasus', 'Lainnya']]],
                'rahasia' => true, 'berkas' => false, 'wali_tulis' => false,
            ],
            'prestasi' => [
                'label' => 'Prestasi', 'icon' => '🏆', 'desc' => 'Prestasi akademik & non-akademik siswa. Wali Kelas dapat mencatat prestasi siswa kelasnya.',
                'kategori' => ['Tingkat', ['Sekolah', 'Kecamatan', 'Kabupaten/Kota', 'Provinsi', 'Nasional', 'Internasional']],
                'judul' => ['Nama prestasi / lomba', 'mis. Lomba Desain Poster HUT RI'],
                'uraian' => 'Keterangan', 'tindak' => null, 'status' => false,
                'extra' => ['peringkat' => ['Peringkat / juara', 'text', 'mis. Juara 2']],
                'rahasia' => false, 'berkas' => false, 'wali_tulis' => true,
            ],
            'home_visit' => [
                'label' => 'Home Visit', 'icon' => '🏠', 'desc' => 'Laporan kunjungan rumah siswa.',
                'kategori' => null,
                'judul' => ['Tujuan kunjungan', 'mis. Menindaklanjuti alpa 5 hari berturut-turut'],
                'uraian' => 'Hasil kunjungan', 'tindak' => 'Tindak lanjut', 'status' => true,
                'extra' => [
                    'petugas' => ['Petugas', 'text', 'mis. Guru BK & Wali Kelas'],
                    'bertemu' => ['Bertemu dengan', 'text', 'mis. Ibu kandung'],
                    'alamat' => ['Alamat', 'text', ''],
                ],
                'rahasia' => false, 'berkas' => false, 'wali_tulis' => false,
            ],
            'surat' => [
                'label' => 'Riwayat Surat', 'icon' => '✉️', 'desc' => 'Catatan surat yang dikeluarkan BK, lengkap dengan status penanganan dan hasil scan/foto surat bertanda tangan.',
                'kategori' => ['Jenis surat', ['Surat Peringatan', 'Surat Panggilan Orang Tua', 'Surat Pernyataan', 'Surat Keterangan', 'Lainnya']],
                'judul' => ['Perihal', 'mis. Panggilan orang tua terkait alpa berulang'],
                'uraian' => 'Keterangan', 'tindak' => null, 'status' => true,
                'extra' => ['nomor' => ['Nomor surat', 'text', 'mis. 421.7/BK-PANGGILAN/2026']],
                'rahasia' => false, 'berkas' => true, 'wali_tulis' => false,
            ],
        ];
    }

    public static function get(string $jenis): ?array
    {
        return self::all()[$jenis] ?? null;
    }
}
