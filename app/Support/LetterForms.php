<?php

namespace App\Support;

use App\Models\Attendance;
use App\Models\Student;
use App\Models\User;
use Illuminate\Http\Request;

/** Daftar surat siswa (format resmi SMK IBU) beserta isian & nilai bawaannya. */
class LetterForms
{
    public const TITLES = [
        'panggilan' => 'Surat Panggilan Wali Murid',
        'peringatan' => 'Surat Peringatan',
        'teguran' => 'Surat Teguran Tertulis',
        'pernyataan-berhenti' => 'Surat Pernyataan Siap Diberhentikan',
        'pernyataan-mundur' => 'Surat Pernyataan Mengundurkan Diri',
        'berita-acara' => 'Berita Acara Pemanggilan Orang Tua',
        'izin' => 'Surat Izin Meninggalkan Sekolah',
    ];

    private const HARI = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
    private const BULAN = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

    /** @return array{hari:string, tgl:int, bulan:string, tahun:int, long:string} untuk tanggal Y-m-d (bawaan: hari ini, WIB). */
    public static function dateParts(?string $ymd = null): array
    {
        $t = Dates::parse($ymd ?: Dates::today());
        $c = \Carbon\CarbonImmutable::createFromTimestampUTC((int) $t)->setTimezone('UTC');
        $d = (int) $c->format('j');
        $m = (int) $c->format('n');

        return ['hari' => self::HARI[(int) $c->format('w')], 'tgl' => $d, 'bulan' => self::BULAN[$m], 'tahun' => (int) $c->format('Y'),
            'long' => $d.' '.self::BULAN[$m].' '.$c->format('Y')];
    }

    /**
     * @return list<array{k:string, label:string, v:string, wide?:bool}>
     */
    public static function fields(string $jenis, Student $s, User $me, Request $r): array
    {
        $s->loadMissing('schoolClass');
        $now = self::dateParts();
        $wali = $s->class_id ? User::where('kelas_wali_id', $s->class_id)->value('nama') : null;
        $alpa = Attendance::where('student_id', $s->id)->where('status', 'A')->count();
        $f = [['k' => 'tempat', 'label' => 'Tempat surat', 'v' => (string) config('absensi.surat.kota')]];
        $tglSurat = ['k' => 'tgl', 'label' => 'Tanggal surat', 'v' => $now['long']];
        $id = [
            ['k' => 'ttl', 'label' => 'Tempat, tgl lahir', 'v' => ''],
            ['k' => 'alamat', 'label' => 'Alamat', 'v' => '', 'wide' => true],
            ['k' => 'hp', 'label' => 'No. HP orang tua', 'v' => (string) ($s->telp_ortu ?? '')],
        ];
        $pelanggaran = ['k' => 'pelanggaran', 'label' => 'Jenis pelanggaran', 'v' => $alpa > 0 ? "Tidak masuk tanpa keterangan (alpa {$alpa} kali)" : '', 'wide' => true];

        $set = match ($jenis) {
            'panggilan' => [
                ['k' => 'no', 'label' => 'No. urut surat', 'v' => ''],
                $tglSurat,
                ['k' => 'jam', 'label' => 'Jam', 'v' => '08.00 WIB'],
                ['k' => 'hari', 'label' => 'Hari, tanggal', 'v' => ''],
                ['k' => 'menemui', 'label' => 'Menemui', 'v' => 'WALI KELAS'.($wali ? ' ('.mb_strtoupper($wali).')' : '').' DAN BK', 'wide' => true],
                ['k' => 'tempat_temu', 'label' => 'Tempat', 'v' => 'KANTOR KESISWAAN DAN BK', 'wide' => true],
                ['k' => 'nb', 'label' => 'Catatan (NB)', 'v' => 'MEMBAWA FC KK DAN KTP ORANGTUA/WALI', 'wide' => true],
            ],
            'peringatan' => [...$id, $pelanggaran, ['k' => 'kesalahan', 'label' => 'Kesalahan siswa (uraian)', 'v' => $pelanggaran['v'], 'wide' => true], $tglSurat],
            'teguran' => [
                ['k' => 'dari', 'label' => 'Dari', 'v' => $wali ? 'Wali Kelas ('.$wali.')' : '', 'wide' => true],
                ['k' => 'pelanggaran', 'label' => 'Pelanggaran tata tertib', 'v' => $pelanggaran['v'], 'wide' => true], $tglSurat,
            ],
            'pernyataan-berhenti' => [...$id, $pelanggaran, ['k' => 'uraian', 'label' => 'Uraian kesalahan', 'v' => '', 'wide' => true], $tglSurat],
            'pernyataan-mundur' => [...$id, ['k' => 'alasan', 'label' => 'Alasan', 'v' => '', 'wide' => true], $tglSurat],
            'berita-acara' => [
                ['k' => 'tglk', 'label' => 'Tanggal pertemuan (Y-m-d)', 'v' => Dates::today()],
                ['k' => 'mulai', 'label' => 'Pukul mulai', 'v' => ''], ['k' => 'selesai', 'label' => 's/d pukul', 'v' => ''],
                ['k' => 'alamat', 'label' => 'Alamat', 'v' => '', 'wide' => true],
                ['k' => 'hasil', 'label' => 'Hasil yang diperoleh', 'v' => '', 'wide' => true], $tglSurat,
            ],
            'izin' => [
                ['k' => 'tglk', 'label' => 'Tanggal izin (Y-m-d)', 'v' => Dates::today()],
                ['k' => 'alasan', 'label' => 'Alasan', 'v' => '', 'wide' => true],
                ['k' => 'guru', 'label' => 'Guru yang menangani', 'v' => $me->nama ?? ''],
                ['k' => 'salinan', 'label' => 'Jumlah salinan', 'v' => '2'],
            ],
            default => abort(404),
        };

        return array_map(function (array $x) use ($r) {
            $x['v'] = $r->has($x['k']) ? trim((string) $r->query($x['k'])) : $x['v'];

            return $x;
        }, array_merge($f, $set));
    }
}
