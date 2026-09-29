<?php

namespace App\Support;

use App\Models\SchoolSetting;
use App\Models\Student;

/**
 * Pesan WhatsApp gratis lewat tautan wa.me (tanpa gateway/API berbayar):
 * aplikasi menyiapkan teks, guru menekan tombol, WhatsApp di HP/laptop terbuka
 * dengan pesan siap kirim ke nomor orang tua.
 */
class WhatsApp
{
    /** "0812-3456-789" / "+62 812..." / "812..." → "62812..."; null bila kosong/tidak valid. */
    public static function normalize(?string $raw): ?string
    {
        $d = preg_replace('/\D+/', '', (string) $raw) ?? '';
        if ($d === '') {
            return null;
        }
        if (str_starts_with($d, '0')) {
            $d = '62'.substr($d, 1);
        } elseif (str_starts_with($d, '8')) {
            $d = '62'.$d;
        }

        return preg_match('/^[1-9]\d{8,14}$/', $d) ? $d : null;
    }

    public static function link(?string $phone, string $text): ?string
    {
        $p = self::normalize($phone);

        return $p ? 'https://wa.me/'.$p.'?text='.rawurlencode($text) : null;
    }

    /** Tautan "bagikan" tanpa nomor (pengguna memilih kontak sendiri di WhatsApp). */
    public static function share(string $text): string
    {
        return 'https://wa.me/?text='.rawurlencode($text);
    }

    public static function display(?string $phone): string
    {
        $p = self::normalize($phone);

        return $p ? '0'.substr($p, 2) : '-';
    }

    private static function sapaan(Student $s): string
    {
        return 'Assalamu\'alaikum Bapak/Ibu'.($s->nama_ortu ? ' '.$s->nama_ortu : '').', orang tua/wali dari *'.$s->nama.'*'
            .($s->schoolClass ? ' (kelas '.$s->schoolClass->name.')' : '').'.';
    }

    private static function penutup(?string $sender): string
    {
        return "\n\nTerima kasih.\n".($sender ? $sender."\n" : '').SchoolSetting::current()->school_name;
    }

    /** Pemberitahuan ketidakhadiran pada satu tanggal. */
    public static function attendanceMessage(Student $s, string $tanggal, string $status, ?string $notes, ?string $sender): string
    {
        $isi = match ($status) {
            'A' => 'tercatat *tidak hadir tanpa keterangan (Alpa)*. Mohon konfirmasi kepada wali kelas mengenai alasan ketidakhadiran ananda.',
            'S' => 'tercatat *tidak hadir karena sakit*. Semoga ananda lekas sembuh. Mohon sertakan surat keterangan bila ada.',
            'I' => 'tercatat *tidak hadir dengan izin*.',
            default => 'tercatat *hadir*.',
        };

        return self::sapaan($s)."\n\nKami informasikan bahwa pada hari *".Dates::human($tanggal).'* ananda '.$isi
            .($notes ? "\nCatatan: ".$notes : '').self::penutup($sender);
    }

    /** Pesan umum dari halaman riwayat siswa: ringkasan kehadiran + tautan portal (bila ada). */
    public static function summaryMessage(Student $s, array $stats, ?string $portalUrl, ?string $sender): string
    {
        return self::sapaan($s)."\n\nBerikut ringkasan kehadiran ananda sejauh ini: Hadir {$stats['hadir']}, Izin {$stats['izin']}, Sakit {$stats['sakit']}, Alpa {$stats['alpa']} (kehadiran {$stats['rate']}%)."
            .(! $stats['meets'] ? "\nKehadiran ananda masih di bawah batas minimal 85%. Mohon bantuan Bapak/Ibu untuk mendampingi ananda." : '')
            .($portalUrl ? "\n\nRiwayat kehadiran lengkap dapat dilihat di: ".$portalUrl : '')
            .self::penutup($sender);
    }

    /** Pesan panggilan/tindak lanjut BK. */
    public static function bkMessage(Student $s, string $perihal, ?string $sender): string
    {
        return self::sapaan($s)."\n\nKami dari Bimbingan Konseling ingin menyampaikan hal berikut terkait ananda:\n*".$perihal."*\n\nMohon Bapak/Ibu dapat menghubungi kami atau hadir ke sekolah untuk berkoordinasi."
            .self::penutup($sender);
    }

    /** Pesan dari satu catatan BK (detail kasus rahasia tidak ikut dikirim). */
    public static function recordMessage(\App\Models\BkRecord $r, ?string $sender): string
    {
        $s = $r->student;
        if ($r->jenis === 'prestasi') {
            return self::sapaan($s)."\n\nAlhamdulillah, kami turut bangga menyampaikan bahwa ananda meraih prestasi:\n*".$r->judul.'*'
                .($r->x('peringkat') ? ' — '.$r->x('peringkat') : '').($r->kategori ? ' (tingkat '.$r->kategori.')' : '')
                ."\n\nSemoga menjadi motivasi untuk terus berprestasi.".self::penutup($sender);
        }

        return self::bkMessage($s, $r->rahasia ? 'Tindak lanjut layanan bimbingan konseling' : $r->judul, $sender);
    }
}
