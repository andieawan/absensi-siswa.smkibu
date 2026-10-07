<?php

namespace Tests\Feature;

use App\Models\ApiKey;
use App\Models\AuditLog;
use App\Models\Student;
use App\Support\Dates;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/** API v1 (baca-saja) dengan kunci per aplikasi + menu Admin → Integrasi API. */
class ApiKeyTest extends TestCase
{
    private function makeKey(array $scopes, int $rate = 60, string $name = 'Web Sekolah'): string
    {
        $plain = 'ak_'.bin2hex(random_bytes(20));
        ApiKey::create(['name' => $name, 'key_prefix' => substr($plain, 0, 11), 'key_hash' => ApiKey::hashOf($plain), 'scopes' => $scopes,
            'is_active' => true, 'rate_limit' => $rate, 'created_at' => '2026-10-07 00:00:00']);

        return $plain;
    }

    private function api(string $key, string $path)
    {
        return $this->getJson('/api/v1'.$path, ['Authorization' => 'Bearer '.$key]);
    }

    public function test_only_admin_manages_keys_and_plaintext_is_shown_once(): void
    {
        $this->actingAs($this->wali)->get('/admin/integrasi')->assertForbidden();
        $this->actingAs($this->wali)->post('/admin/integrasi', ['name' => 'x', 'scopes' => ['kelas:baca'], 'rate_limit' => 60])->assertForbidden();

        $r = $this->actingAs($this->admin)->post('/admin/integrasi', ['name' => 'Aplikasi BK', 'scopes' => ['kelas:baca', 'bogus'], 'rate_limit' => 30]);
        $r->assertRedirect();
        $plain = session('new_api_key');
        $this->assertStringStartsWith('ak_', $plain);
        $k = ApiKey::firstOrFail();
        $this->assertSame(['kelas:baca'], $k->scopes);
        $this->assertSame(ApiKey::hashOf($plain), $k->key_hash);
        $this->assertStringNotContainsString($plain, json_encode($k->getAttributes()));
        $this->assertTrue(AuditLog::where('action', 'Buat Kunci API')->exists());
        $this->assertStringNotContainsString($plain, json_encode(AuditLog::all()->toArray()));

        $this->actingAs($this->admin)->get('/admin/integrasi')->assertOk()->assertSee($plain); // tampil sekali (flash)
        $this->actingAs($this->admin)->get('/admin/integrasi')->assertOk()->assertSee('Aplikasi BK')->assertDontSee($plain);
        $this->actingAs($this->admin)->post('/admin/integrasi', ['name' => 'Kosong', 'scopes' => [], 'rate_limit' => 60])->assertSessionHasErrors('scopes');
        $this->assertSame(1, ApiKey::count());
    }

    public function test_revoke_blocks_access_and_delete_requires_revoked(): void
    {
        $plain = $this->makeKey(['kelas:baca']);
        $this->api($plain, '/kelas')->assertOk();
        $k = ApiKey::firstOrFail();
        $this->actingAs($this->admin)->delete("/admin/integrasi/{$k->id}")->assertSessionHas('error');
        $this->assertSame(1, ApiKey::count());
        $this->actingAs($this->admin)->post("/admin/integrasi/{$k->id}/cabut")->assertSessionHas('success');
        $this->app['auth']->forgetGuards();
        $this->api($plain, '/kelas')->assertStatus(401);
        $this->actingAs($this->admin)->delete("/admin/integrasi/{$k->id}")->assertSessionHas('success');
        $this->assertSame(0, ApiKey::count());
    }

    public function test_authentication_failures(): void
    {
        $this->getJson('/api/v1/ping')->assertStatus(401)->assertJsonPath('error.code', 'unauthorized')->assertHeader('WWW-Authenticate', 'Bearer');
        $this->api('ak_salah', '/ping')->assertStatus(401);
        $plain = $this->makeKey(['kelas:baca']);
        $this->getJson('/api/v1/ping?api_key='.$plain)->assertStatus(401); // query string tidak diterima
        $this->getJson('/api/v1/ping', ['X-API-Key' => $plain])->assertOk()->assertJsonPath('data.aplikasi', 'Web Sekolah');
        $this->api($plain, '/siswa')->assertStatus(403)->assertJsonPath('error.code', 'forbidden');
        $this->api($plain, '/mapel')->assertStatus(403);
        $this->api($plain, '/kehadiran')->assertStatus(403);
        $this->postJson('/api/v1/kelas', [], ['Authorization' => 'Bearer '.$plain])->assertStatus(405);
        $this->getJson('/api/v1/tidak-ada', ['Authorization' => 'Bearer '.$plain])->assertStatus(404);
    }

    public function test_openapi_is_public_json(): void
    {
        $this->getJson('/api/v1/openapi.json')->assertOk()->assertJsonPath('openapi', '3.0.3')->assertJsonStructure(['paths' => ['/siswa', '/kehadiran']]);
    }

    public function test_kelas_and_mapel(): void
    {
        $plain = $this->makeKey(['kelas:baca', 'mapel:baca']);
        $r = $this->api($plain, '/kelas')->assertOk();
        $r->assertJsonPath('meta.total', 2);
        $row = collect($r->json('data'))->firstWhere('id', 1);
        $this->assertSame(4, $row['jumlah_siswa_aktif']);
        $this->api($plain, '/kelas/2')->assertOk()->assertJsonPath('data.jumlah_siswa_aktif', 2);
        $this->api($plain, '/kelas/999')->assertStatus(404);
        $ids = collect($this->api($plain, '/mapel')->assertOk()->json('data'))->pluck('id')->all();
        $this->assertNotContains((int) config('absensi.bk_subject_id'), $ids);
        $this->assertContains(1, $ids);
    }

    public function test_siswa_filters_paging_and_contact_scope(): void
    {
        Student::find(1)->update(['nama_ortu' => 'Bapak Satu', 'telp_ortu' => '081234560001']);
        Student::find(4)->update(['status' => 'pindah']);
        $basic = $this->makeKey(['siswa:baca'], 60, 'Dasar');
        $r = $this->api($basic, '/siswa')->assertOk();
        $r->assertJsonPath('meta.total', 5);
        $this->assertArrayNotHasKey('telp_ortu', $r->json('data.0'));
        $this->assertArrayNotHasKey('nama_ortu', $r->json('data.0'));
        $this->assertStringNotContainsString('Bapak Satu', $r->getContent());
        $this->api($basic, '/siswa?status=semua')->assertJsonPath('meta.total', 6);
        $this->api($basic, '/siswa?kelas_id=2')->assertJsonPath('meta.total', 2);
        $this->api($basic, '/siswa?q=Siswa 3')->assertJsonPath('meta.total', 1);
        $p = $this->api($basic, '/siswa?per_page=2&page=2')->assertOk();
        $p->assertJsonPath('meta.last_page', 3)->assertJsonPath('meta.page', 2);
        $this->assertCount(2, $p->json('data'));
        $this->api($basic, '/siswa?per_page=99999')->assertJsonPath('meta.per_page', 200);

        $contact = $this->makeKey(['siswa:baca', 'siswa:kontak'], 60, 'Kontak');
        $d = $this->api($contact, '/siswa/1')->assertOk();
        $d->assertJsonPath('data.nama_ortu', 'Bapak Satu')->assertJsonPath('data.telp_ortu', '6281234560001')->assertJsonPath('data.kelas', 'X RPL 1');
        $this->api($contact, '/siswa/999')->assertStatus(404);
    }

    public function test_kehadiran_and_rekap_without_notes(): void
    {
        $this->mark(1, $this->daysAgo(1), 'S');
        $this->mark(2, $this->daysAgo(2), 'A');
        $this->mark(1, $this->daysAgo(1), 'H', 2);
        $this->mark(1, $this->daysAgo(200), 'A');
        \App\Models\Attendance::query()->update(['notes' => 'RAHASIA-CATATAN']);
        $plain = $this->makeKey(['kehadiran:baca']);

        $r = $this->api($plain, '/kehadiran')->assertOk();
        $this->assertStringNotContainsString('RAHASIA-CATATAN', $r->getContent());
        $r->assertJsonPath('meta.total', 2); // harian, 30 hari
        $this->api($plain, '/kehadiran?jenis=mapel')->assertJsonPath('meta.total', 1);
        $this->api($plain, '/kehadiran?jenis=semua')->assertJsonPath('meta.total', 3);
        $this->api($plain, '/kehadiran?siswa_id=2')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.status_label', 'Alpa');
        $this->api($plain, '/kehadiran?dari='.$this->daysAgo(250).'&sampai='.Dates::today())->assertJsonPath('meta.total', 3);
        $this->api($plain, '/kehadiran?dari='.$this->daysAgo(500).'&sampai='.Dates::today())->assertStatus(422);
        $this->api($plain, '/kehadiran?dari=bukan-tanggal')->assertStatus(422);
        $this->api($plain, '/kehadiran?dari='.Dates::today().'&sampai='.$this->daysAgo(3))->assertStatus(422);
        $this->api($plain, '/kehadiran?jenis=aneh')->assertStatus(422);

        $this->api($plain, '/siswa/1/rekap')->assertOk()->assertJsonPath('data.sakit', 1)->assertJsonPath('data.alpa', 1);
        $this->api($plain, '/siswa/999/rekap')->assertStatus(404);
    }

    public function test_rate_limit_per_key_and_usage_tracking(): void
    {
        RateLimiter::clear('apikey:1');
        $plain = $this->makeKey(['kelas:baca'], 2);
        $this->api($plain, '/ping')->assertOk()->assertHeader('X-RateLimit-Limit', '2');
        $this->api($plain, '/ping')->assertOk();
        $this->api($plain, '/ping')->assertStatus(429)->assertJsonPath('error.code', 'rate_limited')->assertHeader('Retry-After');
        $k = ApiKey::firstOrFail();
        $this->assertNotNull($k->last_used_at);
        $this->assertSame(1, AuditLog::where('action', 'Akses API')->count()); // satu baris per jam
    }
}
