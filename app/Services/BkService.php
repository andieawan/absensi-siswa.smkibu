<?php

namespace App\Services;

use App\Exceptions\UserError;
use App\Models\BkRecord;
use App\Models\Student;
use App\Models\User;
use App\Support\BkModules;
use App\Support\Dates;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/** Hak akses & penyimpanan catatan BK. */
class BkService
{
    /** Petugas BK penuh (BK & Superadmin). */
    public static function isCounselor(User $u): bool
    {
        return $u->hasRole('bk', 'superadmin');
    }

    /** Boleh melihat semua kelas (baca). */
    public static function seesAll(User $u): bool
    {
        return self::isCounselor($u) || $u->hasRole('admin', 'kepsek');
    }

    /** Boleh membuka modul BK sama sekali. */
    public static function canOpen(User $u): bool
    {
        return self::seesAll($u) || $u->kelas_wali_id !== null;
    }

    public static function canWrite(User $u, string $jenis, ?int $classId = null): bool
    {
        if (self::isCounselor($u)) {
            return true;
        }
        $m = BkModules::get($jenis);

        return $m && $m['wali_tulis'] && $u->kelas_wali_id !== null && ($classId === null || $u->kelas_wali_id === $classId);
    }

    /** Boleh mengubah/menghapus satu catatan. */
    public static function canEdit(User $u, BkRecord $r): bool
    {
        return self::isCounselor($u) || (self::canWrite($u, $r->jenis, $r->class_id) && $r->dicatat_oleh === $u->id);
    }

    /** Kueri catatan yang boleh dilihat pengguna. */
    public static function visible(User $u, ?string $jenis = null): Builder
    {
        return BkRecord::query()
            ->when($jenis, fn ($q) => $q->where('jenis', $jenis))
            ->when(! self::seesAll($u), fn ($q) => $q->where('class_id', $u->kelas_wali_id ?? -1));
    }

    /** Detail kasus "Sangat Rahasia" disembunyikan dari selain BK. */
    public static function isMasked(User $u, BkRecord $r): bool
    {
        return $r->rahasia && ! self::isCounselor($u);
    }

    public static function save(User $u, string $jenis, ?BkRecord $r, array $in, ?UploadedFile $file = null): BkRecord
    {
        $m = BkModules::get($jenis) ?? throw new UserError('Jenis catatan tidak dikenal.');
        $student = $r ? Student::find($r->student_id) : Student::find((int) ($in['student_id'] ?? 0)); // siswa tidak diganti saat edit
        if (! $student) {
            throw new UserError('Pilih siswa terlebih dahulu.');
        }
        $classId = $r ? $r->class_id : $student->class_id;
        if ($r ? ! self::canEdit($u, $r) : ! self::canWrite($u, $jenis, $classId)) {
            throw new UserError($jenis === 'prestasi' ? 'Prestasi hanya dapat dicatat oleh Guru BK atau Wali Kelas siswa tersebut.' : 'Catatan ini hanya dapat diisi oleh Guru BK.');
        }
        $tanggal = (string) ($in['tanggal'] ?? '');
        if (! Dates::valid($tanggal) || Dates::isFuture($tanggal)) {
            throw new UserError('Tanggal tidak valid atau berada di masa depan.');
        }
        $judul = trim((string) ($in['judul'] ?? ''));
        if ($judul === '') {
            throw new UserError($m['judul'][0].' wajib diisi.');
        }
        $kategori = null;
        if ($m['kategori']) {
            $kategori = (string) ($in['kategori'] ?? '');
            if (! in_array($kategori, $m['kategori'][1], true)) {
                throw new UserError('Pilih '.strtolower($m['kategori'][0]).' yang tersedia.');
            }
        }
        $extra = [];
        foreach ($m['extra'] as $key => [$label, $type, $opt]) {
            $v = trim((string) ($in['extra'][$key] ?? ''));
            if ($type === 'select' && $v !== '' && ! in_array($v, $opt, true)) {
                throw new UserError("Pilihan $label tidak valid.");
            }
            $extra[$key] = mb_substr($v, 0, 191);
        }
        $data = [
            'jenis' => $jenis, 'student_id' => $student->id, 'class_id' => $classId, 'tanggal' => $tanggal, 'kategori' => $kategori,
            'judul' => mb_substr($judul, 0, 191), 'uraian' => trim((string) ($in['uraian'] ?? '')) ?: null,
            'tindak_lanjut' => $m['tindak'] ? (trim((string) ($in['tindak_lanjut'] ?? '')) ?: null) : null,
            'status' => $m['status'] ? (in_array($in['status'] ?? '', BkModules::STATUS, true) ? $in['status'] : 'Proses') : null,
            'rahasia' => $m['rahasia'] && ! empty($in['rahasia']),
            'extra' => $extra,
        ];
        if ($file && $m['berkas']) {
            $ext = strtolower($file->getClientOriginalExtension());
            if (! in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'heic', 'pdf'], true)) {
                throw new UserError('Berkas scan harus berupa foto (JPG/PNG/WEBP/HEIC) atau PDF.');
            }
            if ($r?->berkas_path) {
                Storage::disk('local')->delete($r->berkas_path);
            }
            $data['berkas_path'] = $file->storeAs('bk-surat', 'surat_'.$student->id.'_'.bin2hex(random_bytes(8)).'.'.$ext, 'local');
        }
        if ($r) {
            $r->update($data);
            Audit::log('Ubah Catatan BK', 'BK', $u->nama, BkModules::get($jenis)['label'].": {$student->nama} — ".($data['rahasia'] ? '(rahasia)' : $data['judul']));
        } else {
            $r = BkRecord::create($data + ['dicatat_oleh' => $u->id]);
            Audit::log('Tambah Catatan BK', 'BK', $u->nama, BkModules::get($jenis)['label'].": {$student->nama} — ".($data['rahasia'] ? '(rahasia)' : $data['judul']));
        }

        return $r;
    }

    public static function delete(User $u, BkRecord $r): void
    {
        if (! self::canEdit($u, $r)) {
            throw new UserError('Anda tidak berwenang menghapus catatan ini.');
        }
        if ($r->berkas_path) {
            Storage::disk('local')->delete($r->berkas_path);
        }
        $r->delete();
        Audit::log('Hapus Catatan BK', 'BK', $u->nama, BkModules::get($r->jenis)['label']." #{$r->id}");
    }
}
