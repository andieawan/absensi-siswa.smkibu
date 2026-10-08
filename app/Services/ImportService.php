<?php

namespace App\Services;

use App\Exceptions\UserError;
use App\Models\Pairing;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\User;
use App\Support\PasswordPolicy;
use App\Support\TahunAjaran;
use Illuminate\Support\Facades\DB;

/**
 * Impor massal dari Excel/CSV untuk Admin: kelas, mapel, siswa, guru & staf, pasangan mapel.
 * Tiap baris diproses sendiri-sendiri: baris bermasalah dilewati dan dilaporkan, sisanya tetap masuk.
 * Mode "cek dulu" menjalankan semuanya lalu membatalkan (rollback) sehingga tidak ada yang tersimpan.
 */
class ImportService
{
    /** Definisi tiap jenis data: judul, kolom, contoh isi, dan petunjuk (dipakai untuk template & halaman). */
    public static function types(): array
    {
        $cls = SchoolClass::orderBy('name')->pluck('name')->all();
        $sub = Subject::regular()->orderBy('name')->pluck('name')->all();
        $k1 = $cls[0] ?? 'X RPL 1';
        $k2 = $cls[1] ?? 'X RPL 2';
        $m1 = $sub[0] ?? 'Bahasa Indonesia';
        $m2 = $sub[1] ?? 'Matematika';

        return [
            'kelas' => [
                'title' => 'Kelas', 'icon' => '🏫', 'file' => 'Template_Impor_Kelas.xlsx',
                'cols' => ['Nama Kelas', 'Jurusan', 'Angkatan', 'Tahun Ajaran', 'Semester'],
                'example' => [['X RPL 1', 'Rekayasa Perangkat Lunak', 'X', TahunAjaran::label(), TahunAjaran::semester()], ['X DKV 1', 'Desain Komunikasi Visual', 'X', TahunAjaran::label(), TahunAjaran::semester()]],
                'notes' => ['Wajib: Nama Kelas. Kolom lain boleh kosong (Tahun Ajaran & Semester otomatis memakai pengaturan sekolah).', 'Kelas yang namanya sudah ada dilewati.'],
            ],
            'mapel' => [
                'title' => 'Mata Pelajaran', 'icon' => '📚', 'file' => 'Template_Impor_Mapel.xlsx',
                'cols' => ['Nama Mapel'],
                'example' => [['Bahasa Indonesia'], ['Matematika'], ['Bahasa Daerah']],
                'notes' => ['Satu mapel per baris. Mapel yang namanya sudah ada dilewati.'],
            ],
            'siswa' => [
                'title' => 'Siswa', 'icon' => '🎓', 'file' => 'Template_Impor_Siswa.xlsx',
                'cols' => ['NIS', 'Nama Siswa', 'JK', 'Kelas', 'Nama Ortu', 'No HP Ortu'],
                'example' => [['2025001', 'Ahmad Fauzi', 'L', $k1, 'Bpk. Slamet', '081234567890'], ['2025002', 'Siti Aisyah', 'P', $k1, '', '']],
                'notes' => ['Wajib: NIS, Nama Siswa, JK (L/P), Kelas.', 'Kelas harus sudah ada & ditulis persis seperti di data kelas (impor Kelas dulu bila perlu).', 'NIS yang sudah terdaftar dilewati. Nama Ortu & No HP Ortu opsional (untuk tombol WhatsApp).', 'Kolom Status opsional (aktif, nonaktif, pindah, lulus, berhenti, keluar); kosong = aktif.'],
            ],
            'guru' => [
                'title' => 'Guru & Staf', 'icon' => '👩‍🏫', 'file' => 'Template_Impor_Guru_Staf.xlsx',
                'cols' => ['Username', 'Nama', 'Peran', 'Password', 'Wali Kelas', 'Mapel', 'Kelas'],
                'example' => [['ibu.siti', 'Siti Rahmawati, S.Pd', 'guru', '', $k1, $m1.', '.$m2, $k1.', '.$k2], ['pak.budi', 'Budi Santoso', 'tu', '', '', '', '']],
                'notes' => ['Wajib: Username (huruf kecil/angka/titik/strip, min. 3), Nama.',
                    'Peran: guru, kepsek, bk, tu (pisahkan koma bila lebih dari satu, mis. "guru, bk"). Kosong = guru. Peran admin tidak bisa diimpor.',
                    'Password kosong = dibuatkan otomatis (ditampilkan SEKALI setelah impor — catat atau cetak). Bila diisi, harus memenuhi aturan password sekolah (Admin → Pengaturan).',
                    'Wali Kelas: satu nama kelas (opsional). Mapel & Kelas: boleh banyak, pisahkan koma; berlaku silang (semua mapel di semua kelas itu).',
                    'Mapel berbeda tiap kelas? Pakai impor "Pasangan Mapel". Username yang sudah ada dilewati.'],
            ],
            'absensi' => [
                'title' => 'Riwayat Absensi', 'icon' => '🗂️', 'file' => 'Template_Impor_Riwayat_Absensi.xlsx',
                'cols' => ['Timestamp', 'Nama Guru', 'Mata Pelajaran', 'Kelas', 'Tanggal', 'Hadir', 'Izin', 'Sakit', 'Alpa'],
                'example' => [['', 'Siti Rahmawati', $m1, $k1, date('Y-m-d', strtotime('-1 day')), '2025001, 2025002', '', '', '']],
                'notes' => ['Format ekspor aplikasi absensi lama: satu baris = satu sesi (kelas + mapel + tanggal); kolom Hadir/Izin/Sakit/Alpa berisi NIS dipisah koma.',
                    'Berkas .xlsx boleh punya banyak sheet — semua sheet yang kolomnya cocok ikut diproses.',
                    'Kelas & Mata Pelajaran harus sudah ada persis seperti di aplikasi (kosong/“Harian” = absen harian). NIS harus sama dengan NIS siswa.',
                    'Catatan absensi yang SUDAH ada (siswa + tanggal + mapel yang sama) tidak ditimpa. Nama Guru yang cocok dengan akun dipakai sebagai pencatat; selain itu dicatat atas nama Admin.',
                    'Kolom Timestamp diabaikan. Tanggal: YYYY-MM-DD atau DD/MM/YYYY; tanggal masa depan dilewati.'],
            ],
            'pasangan' => [
                'title' => 'Pasangan Mapel', 'icon' => '🔗', 'file' => 'Template_Impor_Pasangan_Mapel.xlsx',
                'cols' => ['Username', 'Mapel', 'Kelas'],
                'example' => [['ibu.siti', $m1, $k1], ['ibu.siti', $m2, $k2]],
                'notes' => ['Satu baris = satu guru mengajar satu mapel di satu kelas. Pakai ini bila mapel guru berbeda tiap kelas.', 'Username, Mapel, dan Kelas harus sudah ada. Pasangan yang sudah ada dilewati.'],
            ],
        ];
    }

    /** Lembar-lembar berkas template (Data + Petunjuk). */
    public static function templateSheets(string $type): array
    {
        $t = self::types()[$type] ?? throw new UserError('Jenis impor tidak dikenal.');
        $notes = [['PETUNJUK — '.$t['title']], ['']];
        foreach ($t['notes'] as $i => $n) {
            $notes[] = [($i + 1).'. '.$n];
        }
        $notes[] = [''];
        $notes[] = ['Hapus baris contoh di lembar "Data", isi data Anda mulai baris ke-2 dengan judul kolom tetap di baris 1, lalu unggah di Admin → Impor Data.'];

        return ['Data' => array_merge([$t['cols']], $t['example']), 'Petunjuk' => $notes];
    }

    /**
     * @return array{ok:int, skip:string[], passwords:array<string,string>, dry:bool}
     */
    public static function run(User $actor, string $type, array $hdr, array $rows, bool $dry): array
    {
        if (! isset(self::types()[$type])) {
            throw new UserError('Jenis impor tidak dikenal.');
        }
        if (count($rows) > 5000) {
            throw new UserError('Maksimal 5.000 baris per impor. Pecah berkas menjadi beberapa bagian.');
        }
        $out = ['ok' => 0, 'skip' => [], 'passwords' => [], 'dry' => $dry];
        DB::beginTransaction();
        try {
            match ($type) {
                'kelas' => self::kelas($actor, $hdr, $rows, $out),
                'mapel' => self::mapel($actor, $hdr, $rows, $out),
                'siswa' => self::siswa($actor, $hdr, $rows, $out),
                'guru' => self::guru($actor, $hdr, $rows, $out),
                'pasangan' => self::pasangan($actor, $hdr, $rows, $out),
                'absensi' => self::absensi($actor, $hdr, $rows, $out),
            };
            $dry ? DB::rollBack() : DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();
            throw $e;
        }
        if (! $dry) {
            Audit::log('Impor '.self::types()[$type]['title'], 'Impor', $actor->nama, "{$out['ok']} ditambahkan, ".count($out['skip']).' dilewati');
        }

        return $out;
    }

    private static function cell(array $r, ?int $i): string
    {
        return $i === null ? '' : trim((string) ($r[$i] ?? ''));
    }

    private static function need(array $hdr, array $names, string $label): int
    {
        return \App\Support\Xlsx::col($hdr, $names) ?? throw new UserError("Kolom \"$label\" tidak ditemukan di baris pertama berkas. Unduh template dan pakai judul kolom yang sama.");
    }

    private static function blank(array $r): bool
    {
        return trim(implode('', array_map('strval', $r))) === '';
    }

    private static function kelas(User $a, array $hdr, array $rows, array &$out): void
    {
        $iN = self::need($hdr, ['nama kelas', 'kelas', 'nama'], 'Nama Kelas');
        $iJ = \App\Support\Xlsx::col($hdr, ['jurusan']);
        $iA = \App\Support\Xlsx::col($hdr, ['angkatan', 'tingkat']);
        $iT = \App\Support\Xlsx::col($hdr, ['tahun ajaran']);
        $iS = \App\Support\Xlsx::col($hdr, ['semester']);
        $have = SchoolClass::pluck('name')->map(fn ($n) => mb_strtolower($n))->flip()->all();
        foreach ($rows as $n => $r) {
            if (self::blank($r)) {
                continue;
            }
            $name = self::cell($r, $iN);
            $row = 'Baris '.($n + 2);
            if ($name === '' || mb_strlen($name) > 191) {
                $out['skip'][] = "$row: nama kelas kosong/terlalu panjang";
            } elseif (isset($have[mb_strtolower($name)])) {
                $out['skip'][] = "$row ($name): kelas sudah ada";
            } else {
                $sem = self::cell($r, $iS);
                SchoolClass::create(['name' => $name, 'jurusan' => self::cell($r, $iJ) ?: null, 'angkatan' => self::cell($r, $iA) ?: null,
                    'tahun_ajaran' => self::cell($r, $iT) ?: TahunAjaran::label(), 'semester' => in_array(ucfirst(strtolower($sem)), ['Ganjil', 'Genap'], true) ? ucfirst(strtolower($sem)) : TahunAjaran::semester()]);
                $have[mb_strtolower($name)] = true;
                $out['ok']++;
            }
        }
    }

    private static function norm(string $v): string
    {
        return mb_strtolower(preg_replace('/\s+/u', ' ', trim($v)));
    }

    /** YYYY-MM-DD, DD/MM/YYYY, atau nomor seri tanggal Excel → YYYY-MM-DD (null bila tidak valid). */
    private static function parseDate(string $v): ?string
    {
        $v = trim($v);
        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $v)) {
            $v = substr($v, 0, 10);
        } elseif (preg_match('#^(\d{1,2})[/.-](\d{1,2})[/.-](\d{4})#', $v, $m)) {
            $v = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        } elseif (preg_match('/^\d{5}(\.\d+)?$/', $v) && (int) $v > 30000 && (int) $v < 80000) {
            $v = gmdate('Y-m-d', ((int) $v - 25569) * 86400);
        }

        return \App\Support\Dates::valid($v) ? $v : null;
    }

    /** Riwayat absensi format aplikasi lama (satu baris = satu sesi; kolom status berisi NIS dipisah koma). */
    private static function absensi(User $actor, array $hdr, array $rows, array &$out): void
    {
        $iK = self::need($hdr, ['kelas'], 'Kelas');
        $iT = self::need($hdr, ['tanggal'], 'Tanggal');
        $iM = \App\Support\Xlsx::col($hdr, ['mata pelajaran', 'mapel']);
        $iG = \App\Support\Xlsx::col($hdr, ['nama guru', 'guru']);
        $cols = ['H' => \App\Support\Xlsx::col($hdr, ['hadir']), 'I' => \App\Support\Xlsx::col($hdr, ['izin']), 'S' => \App\Support\Xlsx::col($hdr, ['sakit']), 'A' => \App\Support\Xlsx::col($hdr, ['alpa', 'alfa'])];
        if (! array_filter($cols, fn ($c) => $c !== null)) {
            throw new UserError('Kolom Hadir/Izin/Sakit/Alpa tidak ditemukan di baris pertama berkas.');
        }
        $classes = SchoolClass::get()->keyBy(fn ($c) => self::norm($c->name));
        $subjects = Subject::get()->keyBy(fn ($s) => self::norm($s->name));
        $users = User::get()->keyBy(fn ($u) => self::norm($u->nama));
        $roster = []; // class_id => nis(norm) => student id
        $now = now('UTC')->format('Y-m-d H:i:s');
        foreach ($rows as $n => $r) {
            if (self::blank($r)) {
                continue;
            }
            $row = 'Baris '.($n + 2);
            $cls = $classes[self::norm(self::cell($r, $iK))] ?? null;
            if (! $cls) {
                $out['skip'][] = "$row: kelas '".self::cell($r, $iK)."' tidak ditemukan";
                continue;
            }
            $tgl = self::parseDate(self::cell($r, $iT));
            if (! $tgl) {
                $out['skip'][] = "$row: tanggal '".self::cell($r, $iT)."' tidak valid";
                continue;
            }
            if (\App\Support\Dates::isFuture($tgl)) {
                $out['skip'][] = "$row: tanggal $tgl di masa depan";
                continue;
            }
            $mp = self::cell($r, $iM);
            $subjectId = null;
            if ($mp !== '' && ! in_array(self::norm($mp), ['harian', '-', 'absen harian'], true)) {
                $sub = $subjects[self::norm($mp)] ?? null;
                if (! $sub) {
                    $out['skip'][] = "$row: mapel '$mp' tidak ditemukan";
                    continue;
                }
                $subjectId = $sub->id;
            }
            $roster[$cls->id] ??= \App\Models\Student::where('class_id', $cls->id)->get()->keyBy(fn ($s) => self::norm($s->nis))->map(fn ($s) => $s->id)->all();
            $guru = self::cell($r, $iG);
            $by = ($users[self::norm($guru)] ?? null)?->id ?? $actor->id;
            $via = str_starts_with(self::norm($guru), 'impor manual') ? 'upload_hardcopy' : 'guru';

            $want = []; // student id => status
            foreach ($cols as $st => $ci) {
                foreach (preg_split('/\s*,\s*/', self::cell($r, $ci), -1, PREG_SPLIT_NO_EMPTY) as $nis) {
                    $sid = $roster[$cls->id][self::norm($nis)] ?? null;
                    if (! $sid) {
                        $out['skip'][] = "$row ({$cls->name}, $tgl): NIS '$nis' tidak ada di kelas ini — dilewati";
                    } elseif (isset($want[$sid])) {
                        $out['skip'][] = "$row ({$cls->name}, $tgl): NIS '$nis' muncul di lebih dari satu status — dilewati";
                        $want[$sid] = false;
                    } else {
                        $want[$sid] = $st;
                    }
                }
            }
            $want = array_filter($want);
            if (! $want) {
                $out['skip'][] = "$row: tidak ada siswa valid pada baris ini";
                continue;
            }
            $have = \App\Models\Attendance::whereIn('student_id', array_keys($want))->where('tanggal', $tgl)
                ->when($subjectId === null, fn ($q) => $q->whereNull('subject_id'), fn ($q) => $q->where('subject_id', $subjectId))->pluck('student_id')->all();
            $added = 0;
            foreach ($want as $sid => $st) {
                if (in_array($sid, $have, true)) {
                    continue;
                }
                \App\Models\Attendance::create(['student_id' => $sid, 'class_id' => $cls->id, 'subject_id' => $subjectId, 'tanggal' => $tgl, 'status' => $st,
                    'recorded_by' => $by, 'recorded_via' => $via, 'created_at' => $now, 'updated_at' => $now, 'created_at_millis' => \App\Support\Dates::parse($tgl) * 1000]);
                $added++;
            }
            if ($have) {
                $out['skip'][] = "$row ({$cls->name}, $tgl): ".count($have).' catatan sudah ada — tidak ditimpa';
            }
            if ($added) {
                $out['ok']++;
            }
        }
    }

    private static function mapel(User $a, array $hdr, array $rows, array &$out): void
    {
        $iN = self::need($hdr, ['nama mapel', 'mapel', 'mata pelajaran', 'nama'], 'Nama Mapel');
        $have = Subject::pluck('name')->map(fn ($n) => mb_strtolower($n))->flip()->all();
        foreach ($rows as $n => $r) {
            if (self::blank($r)) {
                continue;
            }
            $name = self::cell($r, $iN);
            $row = 'Baris '.($n + 2);
            if ($name === '' || mb_strlen($name) > 191) {
                $out['skip'][] = "$row: nama mapel kosong/terlalu panjang";
            } elseif (isset($have[mb_strtolower($name)])) {
                $out['skip'][] = "$row ($name): mapel sudah ada";
            } else {
                Subject::create(['name' => $name]);
                $have[mb_strtolower($name)] = true;
                $out['ok']++;
            }
        }
    }

    private static function siswa(User $a, array $hdr, array $rows, array &$out): void
    {
        [$ok, $skip] = AdminService::importStudentRows($a, $hdr, $rows);
        $out['ok'] += $ok;
        $out['skip'] = array_merge($out['skip'], $skip);
    }

    /** "A, B; C" → ['A','B','C'] */
    private static function list(string $v): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/[,;\n]+/', $v) ?: []), fn ($x) => $x !== ''));
    }

    private static function guru(User $a, array $hdr, array $rows, array &$out): void
    {
        $iU = self::need($hdr, ['username', 'user', 'nama pengguna'], 'Username');
        $iN = self::need($hdr, ['nama', 'nama lengkap'], 'Nama');
        $iR = \App\Support\Xlsx::col($hdr, ['peran', 'role']);
        $iP = \App\Support\Xlsx::col($hdr, ['password', 'kata sandi']);
        $iW = \App\Support\Xlsx::col($hdr, ['wali kelas', 'wali']);
        $iM = \App\Support\Xlsx::col($hdr, ['mapel', 'mata pelajaran']);
        $iK = \App\Support\Xlsx::col($hdr, ['kelas', 'kelas diajar']);
        $classes = SchoolClass::pluck('id', 'name')->mapWithKeys(fn ($id, $n) => [mb_strtolower($n) => (int) $id])->all();
        $subjects = Subject::regular()->pluck('id', 'name')->mapWithKeys(fn ($id, $n) => [mb_strtolower($n) => (int) $id])->all();
        $roleMap = ['guru' => 'guru', 'kepsek' => 'kepsek', 'kepala sekolah' => 'kepsek', 'bk' => 'bk', 'guru bk' => 'bk', 'tu' => 'tu', 'tata usaha' => 'tu', 'staf' => 'tu', 'staff' => 'tu'];
        foreach ($rows as $n => $r) {
            if (self::blank($r)) {
                continue;
            }
            $u = strtolower(self::cell($r, $iU));
            $row = 'Baris '.($n + 2)." ($u)";
            try {
                $roles = [];
                foreach (self::list(self::cell($r, $iR)) as $x) {
                    $k = $roleMap[mb_strtolower($x)] ?? throw new UserError("peran \"$x\" tidak dikenal (pakai: guru, kepsek, bk, tu)");
                    $roles[$k] = $k;
                }
                $wali = self::cell($r, $iW);
                $waliId = $wali === '' ? null : ($classes[mb_strtolower($wali)] ?? throw new UserError("kelas wali \"$wali\" tidak ditemukan"));
                $sIds = array_map(fn ($x) => $subjects[mb_strtolower($x)] ?? throw new UserError("mapel \"$x\" tidak ditemukan"), self::list(self::cell($r, $iM)));
                $cIds = array_map(fn ($x) => $classes[mb_strtolower($x)] ?? throw new UserError("kelas \"$x\" tidak ditemukan"), self::list(self::cell($r, $iK)));
                $pw = self::cell($r, $iP);
                $generated = false;
                if ($pw === '') {
                    $pw = PasswordPolicy::generate();
                    $generated = true;
                } else {
                    PasswordPolicy::assert($pw);
                }
                $nama = self::cell($r, $iN);
                if ($nama === '') {
                    throw new UserError('nama kosong');
                }
                // Savepoint per baris agar kegagalan satu baris tidak membatalkan baris lain.
                DB::transaction(fn () => AdminService::addTeacher($a, ['username' => $u, 'nama' => $nama, 'password' => $pw, 'roles' => array_values($roles) ?: ['guru'],
                    'kelas_wali_id' => $waliId, 'subjects' => $sIds, 'classes' => $cIds]));
                if ($generated) {
                    $out['passwords'][$u] = $pw;
                }
                $out['ok']++;
            } catch (UserError $e) {
                $out['skip'][] = "$row: ".$e->getMessage();
            }
        }
    }

    private static function pasangan(User $a, array $hdr, array $rows, array &$out): void
    {
        $iU = self::need($hdr, ['username', 'user'], 'Username');
        $iM = self::need($hdr, ['mapel', 'mata pelajaran'], 'Mapel');
        $iK = self::need($hdr, ['kelas'], 'Kelas');
        $users = User::pluck('id', 'username')->mapWithKeys(fn ($id, $n) => [strtolower($n) => (int) $id])->all();
        $classes = SchoolClass::pluck('id', 'name')->mapWithKeys(fn ($id, $n) => [mb_strtolower($n) => (int) $id])->all();
        $subjects = Subject::regular()->pluck('id', 'name')->mapWithKeys(fn ($id, $n) => [mb_strtolower($n) => (int) $id])->all();
        foreach ($rows as $n => $r) {
            if (self::blank($r)) {
                continue;
            }
            $u = strtolower(self::cell($r, $iU));
            $m = self::cell($r, $iM);
            $k = self::cell($r, $iK);
            $row = 'Baris '.($n + 2)." ($u)";
            $uid = $users[$u] ?? null;
            $sid = $subjects[mb_strtolower($m)] ?? null;
            $cid = $classes[mb_strtolower($k)] ?? null;
            if (! $uid) {
                $out['skip'][] = "$row: username tidak ditemukan";
            } elseif (! $sid) {
                $out['skip'][] = "$row: mapel \"$m\" tidak ditemukan";
            } elseif (! $cid) {
                $out['skip'][] = "$row: kelas \"$k\" tidak ditemukan";
            } elseif (Pairing::where(['user_id' => $uid, 'subject_id' => $sid, 'class_id' => $cid])->exists()) {
                $out['skip'][] = "$row: pasangan sudah ada";
            } else {
                Pairing::create(['user_id' => $uid, 'subject_id' => $sid, 'class_id' => $cid]);
                $out['ok']++;
            }
        }
    }
}
