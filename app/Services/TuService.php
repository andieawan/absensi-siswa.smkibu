<?php

namespace App\Services;

use App\Exceptions\UserError;
use App\Models\MutasiSiswa;
use App\Models\Student;
use App\Models\TuSurat;
use App\Models\User;
use App\Support\Dates;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/** Tata Usaha: register surat masuk/keluar, surat keterangan bernomor otomatis, mutasi siswa. */
class TuService
{
    public const KODE = ['biasa' => 'UM', 'aktif' => 'KET', 'pindah' => 'MUT', 'dispensasi' => 'DSP', 'izin' => 'IZN'];
    public const KETERANGAN = [
        'aktif' => 'Surat Keterangan Siswa Aktif', 'pindah' => 'Surat Keterangan Pindah Sekolah',
        'dispensasi' => 'Surat Dispensasi', 'izin' => 'Surat Izin',
    ];
    public const STATUS_MASUK = ['Baru', 'Diproses', 'Selesai'];
    /** Kunci = status siswa setelah keluar. */
    public const ALASAN_KELUAR = ['pindah' => 'Pindah ke sekolah lain', 'berhenti' => 'Mengundurkan diri', 'keluar' => 'Dikeluarkan', 'nonaktif' => 'Lainnya'];
    public const BULAN = ['', 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
    private const ROMAWI = ['', 'I', 'II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII'];

    public static function nomor(string $jenis, int $urut, string $tanggal): string
    {
        [$y, $m] = array_map('intval', explode('-', $tanggal));

        return sprintf('%03d/%s/%s/%s/%d', $urut, self::KODE[$jenis] ?? 'UM', config('absensi.kode_surat', 'SMKIBU'), self::ROMAWI[$m], $y);
    }

    private static function str(array $in, string $k, int $max = 191): string
    {
        return mb_substr(trim((string) ($in[$k] ?? '')), 0, $max);
    }

    private static function date(array $in, string $k, string $label): string
    {
        $d = self::str($in, $k, 10);
        if (! Dates::valid($d)) {
            throw new UserError("$label tidak valid.");
        }

        return $d;
    }

    /** Simpan surat (baru/ubah). Surat keluar mendapat nomor otomatis bila kolom nomor dikosongkan. */
    public static function saveSurat(User $u, string $arah, ?TuSurat $s, array $in, ?UploadedFile $file = null): TuSurat
    {
        $tanggal = self::date($in, 'tanggal', $arah === 'masuk' ? 'Tanggal diterima' : 'Tanggal surat');
        $perihal = self::str($in, 'perihal');
        $pihak = self::str($in, 'pihak');
        if ($perihal === '' || $pihak === '') {
            throw new UserError(($arah === 'masuk' ? 'Pengirim' : 'Tujuan').' dan perihal wajib diisi.');
        }
        $data = ['tanggal' => $tanggal, 'perihal' => $perihal, 'pihak' => $pihak, 'isi' => trim((string) ($in['isi'] ?? '')) ?: null];
        if ($arah === 'masuk') {
            $st = (string) ($in['status'] ?? '');
            $data['status'] = in_array($st, self::STATUS_MASUK, true) ? $st : ($s->status ?? 'Baru');
            $data['disposisi'] = self::str($in, 'disposisi') ?: null;
        }
        if ($file) {
            $data['berkas_path'] = self::storeFile($file, $s?->berkas_path);
        }
        $nomorIn = self::str($in, 'nomor', 80);

        if ($s) {
            if ($nomorIn !== '') {
                $data['nomor'] = $nomorIn;
            }
            $s->update($data);
            Audit::log('Ubah Surat '.ucfirst($arah), 'TU', $u->nama, "{$s->nomor} — {$s->perihal}");

            return $s;
        }

        return self::create($u, ['arah' => $arah, 'jenis' => 'biasa'] + $data, $nomorIn);
    }

    /** Buat surat dengan nomor agenda berikutnya (ulang bila bentrok dengan input bersamaan). */
    private static function create(User $u, array $data, string $nomorManual = ''): TuSurat
    {
        $tahun = (int) substr($data['tanggal'], 0, 4);
        for ($i = 0; ; $i++) {
            try {
                $urut = (int) TuSurat::where('arah', $data['arah'])->where('tahun', $tahun)->max('urut') + 1;
                $nomor = $nomorManual !== '' ? $nomorManual : ($data['arah'] === 'keluar' ? self::nomor($data['jenis'], $urut, $data['tanggal']) : null);
                $row = TuSurat::create($data + ['urut' => $urut, 'tahun' => $tahun, 'nomor' => $nomor, 'dicatat_oleh' => $u->id]);
                break;
            } catch (QueryException $e) {
                if ($i >= 4) {
                    throw $e;
                }
            }
        }
        Audit::log('Tambah Surat '.ucfirst($data['arah']), 'TU', $u->nama, "No. agenda {$row->urut}/{$tahun} — {$row->perihal}");

        return $row;
    }

    /** Surat keterangan/dispensasi/izin untuk seorang siswa; otomatis tercatat di Register Surat Keluar. */
    public static function keterangan(User $u, array $in): TuSurat
    {
        $jenis = (string) ($in['jenis'] ?? '');
        if (! isset(self::KETERANGAN[$jenis])) {
            throw new UserError('Pilih jenis surat.');
        }
        $s = Student::with('schoolClass')->find((int) ($in['student_id'] ?? 0)) ?? throw new UserError('Pilih siswa terlebih dahulu.');
        $tanggal = self::date($in + ['tanggal' => Dates::today()], 'tanggal', 'Tanggal surat');
        $extra = ['keperluan' => self::str($in, 'keperluan'), 'alasan' => self::str($in, 'alasan'), 'sekolah_tujuan' => self::str($in, 'sekolah_tujuan')];
        if (in_array($jenis, ['dispensasi', 'izin'], true)) {
            $extra['mulai'] = self::date($in, 'mulai', 'Tanggal mulai');
            $extra['sampai'] = self::str($in, 'sampai', 10) === '' ? $extra['mulai'] : self::date($in, 'sampai', 'Tanggal selesai');
            if ($extra['sampai'] < $extra['mulai']) {
                throw new UserError('Tanggal selesai tidak boleh sebelum tanggal mulai.');
            }
            if ($extra['alasan'] === '') {
                throw new UserError('Alasan wajib diisi.');
            }
        }
        if ($jenis === 'pindah' && $extra['sekolah_tujuan'] === '') {
            throw new UserError('Sekolah tujuan wajib diisi.');
        }

        return self::create($u, [
            'arah' => 'keluar', 'jenis' => $jenis, 'tanggal' => $tanggal, 'student_id' => $s->id, 'extra' => $extra,
            'perihal' => self::KETERANGAN[$jenis].' — '.$s->nama,
            'pihak' => $jenis === 'pindah' ? $extra['sekolah_tujuan'] : ($extra['keperluan'] ?: 'Yang berkepentingan'),
        ]);
    }

    public static function deleteSurat(User $u, TuSurat $s): void
    {
        if (! $u->isAdmin() && ! \App\Services\Rules::withinEditWindow($s->tanggal)) {
            throw new UserError('Surat lebih dari 7 hari terkunci; hanya Administrator yang dapat menghapus.');
        }
        if ($s->berkas_path) {
            Storage::disk('local')->delete($s->berkas_path);
        }
        $s->delete();
        Audit::log('Hapus Surat '.ucfirst($s->arah), 'TU', $u->nama, "{$s->nomor} — {$s->perihal}");
    }

    private static function storeFile(UploadedFile $file, ?string $old): string
    {
        $ext = strtolower($file->getClientOriginalExtension());
        if (! in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'heic', 'pdf'], true)) {
            throw new UserError('Berkas scan harus berupa foto (JPG/PNG/WEBP/HEIC) atau PDF.');
        }
        if ($old) {
            Storage::disk('local')->delete($old);
        }

        return $file->storeAs('tu-surat', 'surat_'.bin2hex(random_bytes(8)).'.'.$ext, 'local');
    }

    // ---- Mutasi siswa ------------------------------------------------------------------

    /** Siswa pindahan/baru masuk: buat data siswa + catatan mutasi. */
    public static function mutasiMasuk(User $u, array $in): Student
    {
        $tanggal = self::date($in, 'tanggal', 'Tanggal masuk');
        if (Dates::isFuture($tanggal)) {
            throw new UserError('Tanggal masuk tidak boleh di masa depan.');
        }
        $sekolah = self::str($in, 'sekolah');
        if ($sekolah === '') {
            throw new UserError('Sekolah asal wajib diisi (isi "-" bila siswa baru).');
        }

        return DB::transaction(function () use ($u, $in, $tanggal, $sekolah) {
            $s = AdminService::saveStudent($u, null, [
                'nis' => $in['nis'] ?? '', 'nama' => (string) ($in['nama'] ?? ''), 'jk' => $in['jk'] ?? 'L', 'class_id' => (int) ($in['class_id'] ?? 0),
                'status' => 'aktif', 'nama_ortu' => $in['nama_ortu'] ?? '', 'telp_ortu' => $in['telp_ortu'] ?? '',
            ]);
            if (trim($s->nama) === '' || ! in_array($s->jk, ['L', 'P'], true)) {
                throw new UserError('Nama dan jenis kelamin wajib diisi.');
            }
            MutasiSiswa::create(['jenis' => 'masuk', 'student_id' => $s->id, 'class_id' => $s->class_id, 'tanggal' => $tanggal, 'sekolah' => $sekolah,
                'alasan' => self::str($in, 'alasan') ?: null, 'nomor_surat' => self::str($in, 'nomor_surat', 80) ?: null, 'dicatat_oleh' => $u->id]);
            Audit::log('Mutasi Masuk', 'TU', $u->nama, "{$s->nama} (NIS {$s->nis}) dari $sekolah");

            return $s;
        });
    }

    /** Siswa keluar (pindah/berhenti/dikeluarkan): ubah status + catatan mutasi; surat pindah opsional. @return array{0:MutasiSiswa,1:?TuSurat} */
    public static function mutasiKeluar(User $u, array $in, bool $buatSurat = false): array
    {
        $s = Student::active()->find((int) ($in['student_id'] ?? 0)) ?? throw new UserError('Pilih siswa aktif terlebih dahulu.');
        $status = (string) ($in['status'] ?? '');
        if (! isset(self::ALASAN_KELUAR[$status])) {
            throw new UserError('Pilih jenis mutasi keluar.');
        }
        $tanggal = self::date($in, 'tanggal', 'Tanggal keluar');
        if (Dates::isFuture($tanggal)) {
            throw new UserError('Tanggal keluar tidak boleh di masa depan.');
        }
        $sekolah = self::str($in, 'sekolah');
        if ($status === 'pindah' && $sekolah === '') {
            throw new UserError('Sekolah tujuan wajib diisi untuk siswa yang pindah.');
        }

        return DB::transaction(function () use ($u, $in, $s, $status, $tanggal, $sekolah, $buatSurat) {
            $m = MutasiSiswa::create(['jenis' => 'keluar', 'student_id' => $s->id, 'class_id' => $s->class_id, 'tanggal' => $tanggal, 'sekolah' => $sekolah ?: null,
                'alasan' => self::str($in, 'alasan') ?: null, 'status_baru' => $status, 'dicatat_oleh' => $u->id]);
            $s->update(['status' => $status]);
            Audit::log('Mutasi Keluar', 'TU', $u->nama, "{$s->nama} (NIS {$s->nis}) — ".self::ALASAN_KELUAR[$status].($sekolah ? " → $sekolah" : ''));
            $surat = null;
            if ($buatSurat && $status === 'pindah') {
                $surat = self::keterangan($u, ['jenis' => 'pindah', 'student_id' => $s->id, 'tanggal' => $tanggal, 'sekolah_tujuan' => $sekolah, 'alasan' => self::str($in, 'alasan')]);
                $m->update(['nomor_surat' => $surat->nomor]);
            }

            return [$m, $surat];
        });
    }
}
