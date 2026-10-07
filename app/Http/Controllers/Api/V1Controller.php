<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateApiKey;
use App\Models\Attendance;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Services\Rules;
use App\Support\DbUpdate;
use App\Support\Dates;
use App\Support\WhatsApp;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** API v1 (hanya baca). Catatan/keterangan absensi dan data nilai/BK sengaja tidak diekspos. */
class V1Controller extends Controller
{
    private function key(Request $r)
    {
        return $r->attributes->get('api_key');
    }

    private function perPage(Request $r, int $max = 200): int
    {
        return max(1, min($max, (int) $r->query('per_page', 100)));
    }

    private function page($q, Request $r, callable $map, int $max = 200): JsonResponse
    {
        $p = $q->paginate($this->perPage($r, $max), ['*'], 'page', max(1, (int) $r->query('page', 1)));

        return response()->json([
            'data' => collect($p->items())->map($map)->values(),
            'meta' => ['page' => $p->currentPage(), 'per_page' => $p->perPage(), 'total' => $p->total(), 'last_page' => $p->lastPage()],
        ]);
    }

    private function notFound(string $what): JsonResponse
    {
        return AuthenticateApiKey::error('not_found', "$what tidak ditemukan.", 404);
    }

    public function openapi(): JsonResponse
    {
        return response()->json(json_decode((string) file_get_contents(resource_path('openapi/v1.json')), true));
    }

    public function ping(Request $r): JsonResponse
    {
        $k = $this->key($r);

        return response()->json(['data' => ['status' => 'ok', 'aplikasi' => $k->name, 'scopes' => $k->scopes ?? [], 'waktu' => now('UTC')->toIso8601String()]]);
    }

    private static function kelas(SchoolClass $c, ?int $count = null): array
    {
        $o = ['id' => $c->id, 'nama' => $c->name, 'jurusan' => $c->jurusan, 'angkatan' => $c->angkatan, 'tahun_ajaran' => $c->tahun_ajaran, 'semester' => $c->semester];
        if ($count !== null) {
            $o['jumlah_siswa_aktif'] = $count;
        }

        return $o;
    }

    public function kelasIndex(Request $r): JsonResponse
    {
        $counts = Student::active()->selectRaw('class_id, count(*) as n')->groupBy('class_id')->pluck('n', 'class_id');

        return $this->page(SchoolClass::orderBy('name')->orderBy('id'), $r, fn ($c) => self::kelas($c, (int) ($counts[$c->id] ?? 0)));
    }

    public function kelasShow(int $id): JsonResponse
    {
        $c = SchoolClass::find($id);

        return $c ? response()->json(['data' => self::kelas($c, Student::active()->where('class_id', $id)->count())]) : $this->notFound('Kelas');
    }

    public function mapelIndex(Request $r): JsonResponse
    {
        return $this->page(Subject::regular()->orderBy('name')->orderBy('id'), $r, fn ($s) => ['id' => $s->id, 'nama' => $s->name]);
    }

    private function siswa(Student $s, Request $r, array $classNames): array
    {
        $o = ['id' => $s->id, 'nis' => $s->nis, 'nama' => $s->nama, 'jk' => $s->jk, 'kelas_id' => $s->class_id, 'kelas' => $classNames[$s->class_id] ?? null, 'status' => $s->status];
        if ($this->key($r)->can('siswa:kontak') && DbUpdate::parentContactReady()) {
            $o['nama_ortu'] = $s->nama_ortu;
            $o['telp_ortu'] = WhatsApp::normalize($s->telp_ortu);
        }

        return $o;
    }

    public function siswaIndex(Request $r): JsonResponse
    {
        $status = (string) $r->query('status', 'aktif');
        $q = Student::query()
            ->when($status !== 'semua', fn ($x) => $x->where('status', $status))
            ->when($r->filled('kelas_id'), fn ($x) => $x->where('class_id', (int) $r->query('kelas_id')))
            ->when(trim((string) $r->query('q', '')) !== '', function ($x) use ($r) {
                $t = str_replace(['%', '_'], ['\\%', '\\_'], trim((string) $r->query('q')));
                $x->where(fn ($w) => $w->where('nama', 'like', "%$t%")->orWhere('nis', 'like', "%$t%"));
            })
            ->orderBy('nama')->orderBy('id');
        $names = SchoolClass::pluck('name', 'id')->all();

        return $this->page($q, $r, fn ($s) => $this->siswa($s, $r, $names));
    }

    public function siswaShow(Request $r, int $id): JsonResponse
    {
        $s = Student::find($id);

        return $s ? response()->json(['data' => $this->siswa($s, $r, SchoolClass::pluck('name', 'id')->all())]) : $this->notFound('Siswa');
    }

    public function rekap(int $id): JsonResponse
    {
        if (! Student::whereKey($id)->exists()) {
            return $this->notFound('Siswa');
        }
        $s = Rules::attendanceStats($id);

        return response()->json(['data' => [
            'siswa_id' => $id, 'total' => $s['total'], 'hadir' => $s['hadir'], 'izin' => $s['izin'], 'sakit' => $s['sakit'], 'alpa' => $s['alpa'],
            'persen_hadir' => $s['rate'], 'batas_minimal' => $s['threshold'], 'memenuhi' => $s['meets'],
        ]]);
    }

    public function kehadiran(Request $r): JsonResponse
    {
        $dari = (string) $r->query('dari', date('Y-m-d', (Dates::parse(Dates::today()) ?? time()) - 29 * 86400));
        $sampai = (string) $r->query('sampai', Dates::today());
        if (! Dates::valid($dari) || ! Dates::valid($sampai)) {
            return AuthenticateApiKey::error('invalid_request', 'Parameter "dari"/"sampai" harus berformat YYYY-MM-DD.', 422);
        }
        if ($dari > $sampai) {
            return AuthenticateApiKey::error('invalid_request', '"dari" tidak boleh setelah "sampai".', 422);
        }
        if ((Dates::parse($sampai) - Dates::parse($dari)) / 86400 > 365) {
            return AuthenticateApiKey::error('invalid_request', 'Rentang tanggal maksimal 366 hari.', 422);
        }
        $jenis = (string) $r->query('jenis', 'harian');
        if (! in_array($jenis, ['harian', 'mapel', 'semua'], true)) {
            return AuthenticateApiKey::error('invalid_request', '"jenis" harus harian, mapel, atau semua.', 422);
        }
        $q = Attendance::query()->whereBetween('tanggal', [$dari, $sampai])
            ->when($jenis === 'harian', fn ($x) => $x->whereNull('subject_id'))
            ->when($jenis === 'mapel', fn ($x) => $x->whereNotNull('subject_id'))
            ->when($r->filled('kelas_id'), fn ($x) => $x->where('class_id', (int) $r->query('kelas_id')))
            ->when($r->filled('siswa_id'), fn ($x) => $x->where('student_id', (int) $r->query('siswa_id')))
            ->when($r->filled('mapel_id'), fn ($x) => $x->where('subject_id', (int) $r->query('mapel_id')))
            ->orderBy('tanggal')->orderBy('id');

        return $this->page($q, $r, fn ($a) => [
            'id' => $a->id, 'siswa_id' => $a->student_id, 'kelas_id' => $a->class_id, 'mapel_id' => $a->subject_id,
            'tanggal' => $a->tanggal, 'status' => $a->status, 'status_label' => Attendance::STATUS[$a->status] ?? $a->status,
            'dicatat_via' => $a->recorded_via,
        ], 500);
    }
}
