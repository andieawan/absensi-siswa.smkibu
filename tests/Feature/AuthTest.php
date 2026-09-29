<?php

namespace Tests\Feature;

use App\Models\User;
use Tests\TestCase;

class AuthTest extends TestCase
{
    public function test_tamu_diarahkan_ke_login(): void
    {
        $this->get('/')->assertRedirect('/login');
        $this->get('/admin/guru')->assertRedirect('/login');
    }

    public function test_login_berhasil_dan_gagal(): void
    {
        $this->post('/login', ['username' => 'WALI', 'password' => 'password123'])->assertRedirect('/');
        $this->assertAuthenticatedAs($this->wali);
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
        $this->post('/login', ['username' => 'wali', 'password' => 'salah'])->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_akun_nonaktif_tidak_bisa_login(): void
    {
        $this->wali->update(['is_active' => false]);
        $this->post('/login', ['username' => 'wali', 'password' => 'password123'])->assertSessionHasErrors('username');
        $this->assertGuest();
    }

    public function test_hash_lama_sha256_bisa_login_lalu_diganti_bcrypt(): void
    {
        $salt = 'ec1780f6c26cfe170593e74bfebfefe2';
        $this->wali->update(['password_hash' => "sha256:$salt:".hash('sha256', "$salt:rahasiaLama")]);
        $this->post('/login', ['username' => 'wali', 'password' => 'rahasiaLama'])->assertRedirect('/');
        $this->assertStringStartsWith('$2y$', $this->wali->fresh()->password_hash);
    }

    public function test_batas_percobaan_login(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->post('/login', ['username' => 'wali', 'password' => 'x']);
        }
        $r = $this->post('/login', ['username' => 'wali', 'password' => 'password123']);
        $this->assertContains($r->status(), [302, 429]);
        $this->assertGuest();
    }

    public function test_ganti_akun_wajib_password_tujuan(): void
    {
        $this->actingAs($this->wali)->post('/ganti-akun', ['username' => 'guru', 'password' => 'salah'])->assertSessionHasErrors();
        $this->assertAuthenticatedAs($this->wali);
        $this->post('/ganti-akun', ['username' => 'guru', 'password' => 'password123'])->assertRedirect('/');
        $this->assertAuthenticatedAs($this->guru);
    }

    public function test_ganti_password_mandiri(): void
    {
        $this->actingAs($this->wali)->post('/password', ['old' => 'salah', 'new' => 'BaruSekali1', 'new_confirmation' => 'BaruSekali1'])->assertSessionHasErrors('old');
        $this->post('/password', ['old' => 'password123', 'new' => 'BaruSekali1', 'new_confirmation' => 'BaruSekali1'])->assertRedirect('/');
        $this->post('/logout');
        $this->post('/login', ['username' => 'wali', 'password' => 'BaruSekali1'])->assertRedirect('/');
    }

    public function test_akun_dinonaktifkan_admin_langsung_keluar(): void
    {
        $this->actingAs($this->wali)->get('/')->assertOk();
        $this->wali->update(['is_active' => false]);
        $this->get('/')->assertRedirect('/login');
        $this->assertGuest();
    }

    public function test_hak_akses_per_peran(): void
    {
        $this->actingAs($this->wali)->get('/admin/guru')->assertForbidden();
        $this->actingAs($this->wali)->get('/bk')->assertForbidden();
        $this->actingAs($this->bk)->get('/bk')->assertOk();
        $this->actingAs($this->admin)->get('/admin/guru')->assertOk();
        $this->actingAs($this->kepsek)->get('/?v=sekolah')->assertOk()->assertSee('Dashboard Sekolah');
        $this->actingAs($this->wali)->get('/')->assertOk()->assertSee('Dashboard Wali Kelas');
        $this->actingAs($this->guru)->get('/?v=mapel&class=1&subject=2')->assertOk()->assertSee('Dashboard Guru Mapel');
    }
}
